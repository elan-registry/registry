<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests proving the four ADR-019 public-read-endpoint rate
 * limits (car_history, cars_list, factory_list, statistics_request) actually
 * enforce, not just that they're configured with the right numbers.
 *
 * These four keys replaced CSRF as the sole abuse control on
 * app/api/cars/{history,list,factory-list}.php and
 * app/api/shared/statistics.php (#1913/#1951, ADR-019). Before this suite,
 * tests/unit/system/RateLimitConfigTest.php only pinned the configured
 * integers and tests/unit/cars/CarActionsHistoryAndValidationWiringTest.php
 * only regex-matched the endpoint source text — neither proves
 * RateLimit::check() actually rejects a request once total_max is reached.
 * A missing or mistyped key makes checkRateLimit() return true
 * unconditionally (fail OPEN, per RateLimit::check()'s "no limits defined"
 * branch), which is exactly the failure mode this suite closes: it would
 * leave all four endpoints with no abuse control whatsoever while every
 * other test in the suite stayed green. (This is also how the
 * statistics_request under-sizing bug survived a full milestone: its three
 * sibling keys had this kind of dedicated coverage and it did not.)
 *
 * total_max, not ip_max/user_max, is the operative limit for all four keys:
 * each endpoint calls recordRateLimit($action, true, ...) on every admitted
 * request and never records a failure, so ip_max/user_max (which only count
 * failed attempts) can never trip. Bypassing checkRateLimit()/recordRateLimit()
 * to insert rows directly at the DB layer (rather than looping the full
 * total_max times through the real functions) keeps this suite fast — the
 * two list endpoints have a total_max of 10000.
 */
#[Group('database')]
final class AdrNineteenRateLimitEnforcementTest extends IntegrationTestCase
{
    /**
     * Insert $count already-recorded attempt rows directly, bypassing
     * recordRateLimit() for speed. Mirrors RateLimit::record()'s row shape
     * exactly: identifier_key is sha256('ip::' . $ip), matching
     * RateLimit::buildIdentifierKey(), so a subsequent real
     * checkRateLimit($action, null, null, ['ip' => $ip]) call reads these
     * rows as if RateLimit::record() had written them.
     */
    private function seedTotalAttempts(string $action, string $ip, int $count): void
    {
        $identifierKey = hash('sha256', 'ip::' . $ip);
        // Chunk the multi-row INSERT so a single statement never gets
        // unreasonably large; total_max tops out at 10000 for these keys.
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
     * @return array<string, array{0: string, 1: int}> action => [action, total_max]
     */
    public static function adrNineteenActionsProvider(): array
    {
        return [
            'car_history'        => ['car_history', 5000],
            'cars_list'          => ['cars_list', 10000],
            'factory_list'       => ['factory_list', 10000],
            'statistics_request' => ['statistics_request', 5000],
        ];
    }

    #[DataProvider('adrNineteenActionsProvider')]
    public function testTotalMaxBlocksAfterConfiguredThreshold(string $action, int $totalMax): void
    {
        $this->requireDatabase();

        $ip = '203.0.113.' . random_int(1, 254); // TEST-NET-3 (RFC 5737) — never a real client IP

        $this->assertTrue(
            checkRateLimit($action, null, null, ['ip' => $ip]),
            "A fresh IP must be allowed before any attempts are recorded for {$action}"
        );

        $this->seedTotalAttempts($action, $ip, $totalMax);

        $this->assertFalse(
            checkRateLimit($action, null, null, ['ip' => $ip]),
            "After {$totalMax} recorded attempts (the configured total_max), {$action} must reject "
                . 'the next request — this is the entire abuse control for this endpoint since ADR-019 '
                . 'removed its CSRF check. A missing or mistyped rate-limit key would make this pass '
                . 'unconditionally (RateLimit::check() fails OPEN when no limit is configured), leaving '
                . 'the endpoint with no protection at all.'
        );
    }

    #[DataProvider('adrNineteenActionsProvider')]
    public function testTotalMaxIsKeyedPerIpNotGlobal(string $action, int $totalMax): void
    {
        $this->requireDatabase();

        $exhaustedIp = '203.0.113.' . random_int(1, 254);
        $this->seedTotalAttempts($action, $exhaustedIp, $totalMax);
        $this->assertFalse(checkRateLimit($action, null, null, ['ip' => $exhaustedIp]));

        $freshIp = '198.51.100.' . random_int(1, 254); // a different TEST-NET-2 block
        $this->assertTrue(
            checkRateLimit($action, null, null, ['ip' => $freshIp]),
            "A different IP must not be affected by another IP exhausting {$action}'s limit — "
                . 'total_max is scoped per identifier, not site-wide.'
        );
    }
}
