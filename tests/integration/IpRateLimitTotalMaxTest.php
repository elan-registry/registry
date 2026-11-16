<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The anonymous rate limits that count by IP with total_max trip at the
 * configured threshold, and each IP has its own bucket.
 *
 * - `join_failure_beacon`: since #2227 the endpoint has no CSRF check, so this
 *   limit is its only abuse control (ADR-019). JoinFailureReportEndpointTest
 *   proves the endpoint records the rows. total_max applies because the
 *   endpoint records only successes.
 * - `location_search` (#1582, #2122): buckets anonymous callers by IP, not
 *   globally. The LocationService logic is in
 *   tests/unit/location/LocationServiceRateLimitTest.php.
 *
 * RateLimit::check() fails OPEN for an unconfigured key, so each row reads
 * total_max from usersc/includes/rate_limits.php. The tests call
 * checkRateLimit()/recordRateLimit() directly and seed rows directly.
 *
 * Each call passes IntegrationTestCase::fakeDocumentationIp() as the 'ip'
 * identifier. A collision would let a seeded total_max bucket block the
 * "fresh" IP.
 */
#[Group('database')]
final class IpRateLimitTotalMaxTest extends IntegrationTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function actionProvider(): array
    {
        return [
            'join_failure_beacon' => ['join_failure_beacon'],
            'location_search' => ['location_search'],
        ];
    }

    #[DataProvider('actionProvider')]
    public function testTotalMaxBlocksAfterConfiguredThreshold(string $action): void
    {
        $this->requireDatabase();

        $totalMax = $this->configuredTotalMax($action);
        $ip = $this->fakeDocumentationIp();

        $this->assertTrue(
            checkRateLimit($action, null, null, ['ip' => $ip]),
            'A fresh IP must be allowed before any attempts are recorded'
        );

        $this->seedTotalAttempts($action, $ip, $totalMax);

        $this->assertFalse(
            checkRateLimit($action, null, null, ['ip' => $ip]),
            "After {$totalMax} recorded attempts (the configured total_max), {$action} must refuse "
                . 'the next request.'
        );
    }

    #[DataProvider('actionProvider')]
    public function testTotalMaxIsKeyedPerIpNotGlobal(string $action): void
    {
        $this->requireDatabase();

        $exhaustedIp = $this->fakeDocumentationIp();
        $this->seedTotalAttempts($action, $exhaustedIp, $this->configuredTotalMax($action));
        $this->assertFalse(checkRateLimit($action, null, null, ['ip' => $exhaustedIp]));

        $freshIp = $this->fakeDocumentationIp();
        // A random collision would make this a same-IP test.
        while ($freshIp === $exhaustedIp) {
            $freshIp = $this->fakeDocumentationIp();
        }

        $this->assertTrue(
            checkRateLimit($action, null, null, ['ip' => $freshIp]),
            "Another IP must not be affected: {$action} total_max is counted per identifier, not site-wide"
        );
    }

    /** #2122: 20 requests (about double the incident's 11) from one typing session are admitted. */
    public function testAdmitsRealisticTypingSession(): void
    {
        $this->requireDatabase();

        $ip = ['ip' => $this->fakeDocumentationIp()];
        $realisticRequestCount = 20;

        for ($i = 0; $i < $realisticRequestCount; $i++) {
            $this->assertTrue(
                checkRateLimit('location_search', null, null, $ip),
                'Request ' . ($i + 1) . ' of ' . $realisticRequestCount . ' should be allowed — '
                    . 'this is the exact volume that tripped the old 10/60 threshold and blocked a '
                    . 'real registrant typing a full address (#2122).'
            );
            recordRateLimit('location_search', true, null, null, $ip);
        }
    }

    /** #2122: a blocked location_search attempt is still recorded with success=0. */
    public function testBlockedLocationAttemptIsRecordedWithSuccessZero(): void
    {
        $this->requireDatabase();

        $testIp = $this->fakeDocumentationIp();
        $this->seedTotalAttempts('location_search', $testIp, $this->configuredTotalMax('location_search'));
        $this->assertFalse(checkRateLimit('location_search', null, null, ['ip' => $testIp]));

        recordRateLimit('location_search', false, null, null, ['ip' => $testIp]);

        $identifierKey = hash('sha256', 'ip::' . $testIp);
        $this->db->query(
            'SELECT success FROM us_rate_limits '
                . 'WHERE action = ? AND identifier_key = ? ORDER BY id DESC LIMIT 1',
            ['location_search', $identifierKey]
        );
        $row = $this->db->first(true);

        $this->assertIsArray($row, 'Expected the just-recorded location_search attempt to be readable back');
        $this->assertSame(
            0,
            (int) ($row['success'] ?? null),
            'A blocked attempt must still be recorded with success=0 — the limiter counts every '
                . 'attempt toward the rolling window regardless of admission (#2122).'
        );
    }

    /**
     * Read total_max for $action from the project-owned rate-limit file.
     */
    private function configuredTotalMax(string $action): int
    {
        $rateLimits = [];
        require dirname(__DIR__, 2) . '/usersc/includes/rate_limits.php';

        $this->assertArrayHasKey($action, $rateLimits, "{$action} must have a configured limit");
        $this->assertArrayHasKey('total_max', $rateLimits[$action]);

        return (int) $rateLimits[$action]['total_max'];
    }

    /**
     * Insert attempt rows directly, in the shape RateLimit::record() writes:
     * identifier_key = sha256('ip::' . $ip), as RateLimit::buildIdentifierKey()
     * builds it. Up to 1500 real recordRateLimit() calls are slow. The insert is
     * chunked to keep each statement's placeholder count small.
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
}
