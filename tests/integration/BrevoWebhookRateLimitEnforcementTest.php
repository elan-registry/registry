<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;

/**
 * Proves the brevo_webhook limits reject, not only that they are configured
 * (RateLimitConfigTest pins the numbers). A missing or mistyped key fails OPEN.
 *
 * total_max is the operative limit: brevo.php records every admitted request as
 * a success, and ip_max/user_max count only failures.
 */
#[Group('database')]
final class BrevoWebhookRateLimitEnforcementTest extends IntegrationTestCase
{
    private const ACTION = 'brevo_webhook';
    private const TOTAL_MAX = 3000;

    // #2087: for brevo_webhook_auth_failure, ip_max is the operative limit
    // (every rejected request records a failure).
    //
    // Trap: this is the RAW value (10), not the x100 dev value that
    // BrevoWebhookEndpointTest uses. The integration bootstrap never loads
    // rate_limits_dev_override.php, so in-process RateLimit sees 10.
    private const AUTH_FAILURE_ACTION = 'brevo_webhook_auth_failure';
    private const AUTH_FAILURE_IP_MAX = 10;

    /**
     * Bypasses recordRateLimit() for speed. The row shape must match
     * RateLimit::record() (identifier_key = sha256('ip::' . $ip)).
     */
    private function seedTotalAttempts(string $action, string $ip, int $count): void
    {
        $identifierKey = hash('sha256', 'ip::' . $ip);
        foreach (array_chunk(range(1, $count), 1000) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, 1, NOW())'));
            $params = [];
            foreach ($chunk as $_) {
                $params[] = $identifierKey;
                $params[] = $action;
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

    /**
     * success=0 rows: the ip_max branch counts only these.
     */
    private function seedFailedAttempts(string $action, string $ip, int $count): void
    {
        $identifierKey = hash('sha256', 'ip::' . $ip);
        foreach (array_chunk(range(1, $count), 1000) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, 0, NOW())'));
            $params = [];
            foreach ($chunk as $_) {
                $params[] = $identifierKey;
                $params[] = $action;
            }
            $result = $this->db->query(
                "INSERT INTO us_rate_limits (identifier_key, action, success, attempt_time) VALUES {$placeholders}",
                $params
            );
            if ($result->error()) {
                throw new \RuntimeException('seedFailedAttempts insert failed: ' . $result->errorString());
            }
        }
    }

    public function testAuthFailureIpMaxBlocksLoggingAfterConfiguredThreshold(): void
    {
        $this->requireDatabase();

        $ip = '203.0.113.' . random_int(1, 254); // TEST-NET-3 (RFC 5737) — never a real client IP

        $this->assertTrue(
            checkRateLimit(self::AUTH_FAILURE_ACTION, null, null, ['ip' => $ip]),
            'A fresh IP must be allowed before any attempts are recorded for ' . self::AUTH_FAILURE_ACTION
        );

        $this->seedFailedAttempts(self::AUTH_FAILURE_ACTION, $ip, self::AUTH_FAILURE_IP_MAX);

        $this->assertFalse(
            checkRateLimit(self::AUTH_FAILURE_ACTION, null, null, ['ip' => $ip]),
            'After ' . self::AUTH_FAILURE_IP_MAX . ' recorded failed attempts (the configured ip_max), '
                . self::AUTH_FAILURE_ACTION . ' must reject the next request. A missing or mistyped '
                . 'rate-limit key would make this pass unconditionally (RateLimit::check() fails OPEN '
                . 'when no limit is configured), silently leaving brevo.php\'s auth-failure logging with '
                . 'no protection at all — the exact regression a prior fix attempt for this key '
                . 'introduced without this test layer catching it.'
        );
    }

    public function testAuthFailureIpMaxIsKeyedPerIpNotGlobal(): void
    {
        $this->requireDatabase();

        $exhaustedIp = '203.0.113.' . random_int(1, 254);
        $this->seedFailedAttempts(self::AUTH_FAILURE_ACTION, $exhaustedIp, self::AUTH_FAILURE_IP_MAX);
        $this->assertFalse(checkRateLimit(self::AUTH_FAILURE_ACTION, null, null, ['ip' => $exhaustedIp]));

        $freshIp = '198.51.100.' . random_int(1, 254); // a different TEST-NET-2 block
        $this->assertTrue(
            checkRateLimit(self::AUTH_FAILURE_ACTION, null, null, ['ip' => $freshIp]),
            'A different IP must not be affected by another IP exhausting ' . self::AUTH_FAILURE_ACTION
                . "'s ip_max limit — ip_max is scoped per identifier, not site-wide."
        );
    }

    public function testTotalMaxBlocksAfterConfiguredThreshold(): void
    {
        $this->requireDatabase();

        $ip = '203.0.113.' . random_int(1, 254); // TEST-NET-3 (RFC 5737) — never a real client IP

        $this->assertTrue(
            checkRateLimit(self::ACTION, null, null, ['ip' => $ip]),
            'A fresh IP must be allowed before any attempts are recorded for ' . self::ACTION
        );

        $this->seedTotalAttempts(self::ACTION, $ip, self::TOTAL_MAX);

        $this->assertFalse(
            checkRateLimit(self::ACTION, null, null, ['ip' => $ip]),
            'After ' . self::TOTAL_MAX . ' recorded attempts (the configured total_max), '
                . self::ACTION . ' must reject the next request. A missing or mistyped rate-limit '
                . 'key would make this pass unconditionally (RateLimit::check() fails OPEN when no '
                . 'limit is configured), silently leaving the endpoint with no protection at all.'
        );
    }

    public function testTotalMaxIsKeyedPerIpNotGlobal(): void
    {
        $this->requireDatabase();

        $exhaustedIp = '203.0.113.' . random_int(1, 254);
        $this->seedTotalAttempts(self::ACTION, $exhaustedIp, self::TOTAL_MAX);
        $this->assertFalse(checkRateLimit(self::ACTION, null, null, ['ip' => $exhaustedIp]));

        $freshIp = '198.51.100.' . random_int(1, 254); // a different TEST-NET-2 block
        $this->assertTrue(
            checkRateLimit(self::ACTION, null, null, ['ip' => $freshIp]),
            'A different IP must not be affected by another IP exhausting ' . self::ACTION
                . "'s limit — total_max is scoped per identifier, not site-wide."
        );
    }
}
