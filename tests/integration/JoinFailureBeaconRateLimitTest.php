<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;

/**
 * Proves that the `join_failure_beacon` rate limit trips, not only that it is
 * configured.
 *
 * Since #2227, app/api/shared/join-failure-report.php checks no CSRF token, so
 * this limit is its only abuse control (ADR-019, "anonymous diagnostic log
 * writes"). RateLimit::check() fails OPEN when an action has no configured
 * limit, so a missing or mistyped key would leave the endpoint with no control
 * while RateLimitConfigTest and the source-inspection tests stayed green.
 *
 * This suite proves the configured total_max rejects the next request. It
 * cannot prove the endpoint records each request, because it seeds rows
 * directly; JoinFailureReportEndpointTest pins that half.
 *
 * total_max is the limit that applies: the endpoint records every admitted
 * request as a success and never records a failure, and ip_max counts only
 * failures. The limit is read from usersc/includes/rate_limits.php, so a change
 * to the number does not need a change here.
 *
 * Moved from AdrNineteenRateLimitEnforcementTest, which #2018 deleted when the
 * read-only endpoints lost their rate limits.
 */
#[Group('database')]
final class JoinFailureBeaconRateLimitTest extends IntegrationTestCase
{
    private const ACTION = 'join_failure_beacon';

    /**
     * A fresh IP is allowed, and the same IP is refused once total_max
     * attempts are recorded.
     *
     * @return void
     */
    public function testTotalMaxBlocksAfterConfiguredThreshold(): void
    {
        $this->requireDatabase();

        $totalMax = $this->configuredTotalMax();
        $ip = '203.0.113.' . random_int(1, 254); // TEST-NET-3 (RFC 5737), never a real client

        $this->assertTrue(
            checkRateLimit(self::ACTION, null, null, ['ip' => $ip]),
            'A fresh IP must be allowed before any attempts are recorded'
        );

        $this->seedTotalAttempts($ip, $totalMax);

        $this->assertFalse(
            checkRateLimit(self::ACTION, null, null, ['ip' => $ip]),
            "After {$totalMax} recorded attempts (the configured total_max), the beacon must refuse "
                . 'the next request. This limit is the endpoint\'s only abuse control since #2227.'
        );
    }

    /**
     * One IP that reaches the limit does not block another IP.
     *
     * @return void
     */
    public function testTotalMaxIsKeyedPerIpNotGlobal(): void
    {
        $this->requireDatabase();

        $totalMax = $this->configuredTotalMax();
        $exhaustedIp = '203.0.113.' . random_int(1, 254);
        $this->seedTotalAttempts($exhaustedIp, $totalMax);
        $this->assertFalse(checkRateLimit(self::ACTION, null, null, ['ip' => $exhaustedIp]));

        $freshIp = '198.51.100.' . random_int(1, 254); // TEST-NET-2, a different block
        $this->assertTrue(
            checkRateLimit(self::ACTION, null, null, ['ip' => $freshIp]),
            'Another IP must not be affected: total_max is counted per identifier, not site-wide'
        );
    }

    /**
     * Read total_max for the beacon from the project-owned rate-limit file.
     *
     * @return int
     */
    private function configuredTotalMax(): int
    {
        $rateLimits = [];
        require dirname(__DIR__, 2) . '/usersc/includes/rate_limits.php';

        $this->assertArrayHasKey(self::ACTION, $rateLimits, 'join_failure_beacon must have a configured limit');
        $this->assertArrayHasKey('total_max', $rateLimits[self::ACTION]);

        return (int) $rateLimits[self::ACTION]['total_max'];
    }

    /**
     * Insert $count recorded-attempt rows directly, bypassing
     * recordRateLimit() for speed. The row shape matches RateLimit::record():
     * identifier_key is sha256('ip::' . $ip), as RateLimit::buildIdentifierKey()
     * builds it, so checkRateLimit() reads these rows as real attempts.
     *
     * @param string $ip
     * @param int    $count
     * @return void
     */
    private function seedTotalAttempts(string $ip, int $count): void
    {
        $identifierKey = hash('sha256', 'ip::' . $ip);
        $placeholders = implode(', ', array_fill(0, $count, '(?, ?, 1, NOW())'));
        $params = [];
        for ($i = 0; $i < $count; $i++) {
            $params[] = $identifierKey;
            $params[] = self::ACTION;
        }

        $result = $this->db->query(
            "INSERT INTO us_rate_limits (identifier_key, action, success, attempt_time) VALUES {$placeholders}",
            $params
        );
        if ($result->error()) {
            throw new \RuntimeException('seedTotalAttempts insert failed: ' . $result->errorString());
        }
    }
}
