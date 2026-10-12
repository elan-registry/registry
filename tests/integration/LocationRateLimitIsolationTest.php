<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;

/**
 * #1582: the 'location_search' limit buckets anonymous callers by IP, not
 * globally. Calls checkRateLimit()/recordRateLimit() directly; the
 * LocationService logic is in tests/unit/location/LocationServiceRateLimitTest.php.
 *
 * Each call passes IntegrationTestCase::fakeDocumentationIp() as the 'ip'
 * identifier. A collision would let a seeded total_max bucket block the
 * "fresh" IP.
 */
#[Group('database')]
final class LocationRateLimitIsolationTest extends IntegrationTestCase
{
    private const ACTION = 'location_search';

    /** Matches location_search total_max in usersc/includes/rate_limits.php (#2122). */
    private const TOTAL_MAX = 1500;

    /**
     * Seeds rows directly: 1000+ real recordRateLimit() calls are slow.
     * identifier_key = sha256('ip::' . $ip) matches RateLimit::buildIdentifierKey().
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

    public function testSecondIpIsUnaffectedByFirstIpExhaustingItsBudget(): void
    {
        $this->requireDatabase();

        $ipOne = $this->fakeDocumentationIp();
        $this->seedTotalAttempts(self::ACTION, $ipOne, self::TOTAL_MAX);

        $this->assertFalse(
            checkRateLimit(self::ACTION, null, null, ['ip' => $ipOne]),
            'Attempt ' . (self::TOTAL_MAX + 1) . ' for IP #1 must be blocked — total_max exhausted (AC: budget enforcement).'
        );

        $ipTwo = $this->fakeDocumentationIp();
        // A random collision would make this a same-IP test.
        while ($ipTwo === $ipOne) {
            $ipTwo = $this->fakeDocumentationIp();
        }

        $this->assertTrue(
            checkRateLimit(self::ACTION, null, null, ['ip' => $ipTwo]),
            'A distinct anonymous IP must have its own independent location_search bucket — '
                . "it must not be blocked by IP #1 ({$ipOne}) exhausting its own budget (AC #1/#2, #1582)."
        );
    }

    /** #2122: 20 requests (double the incident's 11) from one typing session are admitted. */
    public function testAdmitsRealisticTypingSession(): void
    {
        $this->requireDatabase();

        $ip = ['ip' => $this->fakeDocumentationIp()];
        $realisticRequestCount = 20;

        for ($i = 0; $i < $realisticRequestCount; $i++) {
            $this->assertTrue(
                checkRateLimit(self::ACTION, null, null, $ip),
                'Request ' . ($i + 1) . ' of ' . $realisticRequestCount . ' should be allowed — '
                    . 'this is the exact volume that tripped the old 10/60 threshold and blocked a '
                    . 'real registrant typing a full address (#2122).'
            );
            recordRateLimit(self::ACTION, true, null, null, $ip);
        }
    }

    /**
     * #2122: the raised limit is still a backstop that protects the geocoder.
     * A blocked attempt is still recorded with success=0.
     */
    public function testTotalMaxStillBlocksAfterConfiguredThreshold(): void
    {
        $this->requireDatabase();

        $testIp = $this->fakeDocumentationIp();
        $this->seedTotalAttempts(self::ACTION, $testIp, self::TOTAL_MAX);

        $this->assertFalse(
            checkRateLimit(self::ACTION, null, null, ['ip' => $testIp]),
            'Request ' . (self::TOTAL_MAX + 1) . ' must be blocked — the raised threshold must '
                . 'still refuse genuinely abusive volume, not just remove the limit entirely (#2122).'
        );

        recordRateLimit(self::ACTION, false, null, null, ['ip' => $testIp]);

        $identifierKey = hash('sha256', 'ip::' . $testIp);
        $this->db->query(
            'SELECT success FROM us_rate_limits '
                . 'WHERE action = ? AND identifier_key = ? ORDER BY id DESC LIMIT 1',
            [self::ACTION, $identifierKey]
        );
        $row = $this->db->first(true);

        $this->assertIsArray($row, 'Expected the just-recorded location_search attempt to be readable back');
        $this->assertSame(
            0,
            (int) ($row['success'] ?? null),
            'A blocked attempt must still be recorded with success=0 — the limiter counts every '
                . 'attempt toward the rolling window regardless of admission, matching the pattern '
                . 'this project uses everywhere else a rate limit gates admission separately from '
                . 'recording (#2122).'
        );
    }
}
