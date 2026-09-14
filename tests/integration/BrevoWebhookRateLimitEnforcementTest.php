<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration test proving the 'brevo_webhook' rate limit actually enforces,
 * not just that it's configured with the right numbers.
 *
 * Originally AdrNineteenRateLimitEnforcementTest, covering the four ADR-019
 * public-read-endpoint keys (car_history, cars_list, factory_list,
 * statistics_request). Those keys — and the endpoints' entire app-layer
 * abuse control — were removed by #2018, so this file was retargeted rather
 * than deleted outright: it retained value as the only test proving
 * RateLimit::check() actually rejects at total_max (as opposed to
 * tests/unit/system/RateLimitConfigTest.php, which only pins configured
 * integers and endpoint-source action-name strings) for a key that is still
 * load-bearing. `brevo_webhook` is used here as that representative case: it
 * is, like the four removed endpoints used to be, reachable with no
 * UserSpice session (see ADR-019's endpoint table and
 * docs/development/EMAIL_SYSTEM.md).
 *
 * A missing or mistyped key makes checkRateLimit() return true
 * unconditionally (fail OPEN, per RateLimit::check()'s "no limits defined"
 * branch) — this test closes that failure mode for brevo_webhook.
 *
 * total_max, not ip_max/user_max, is the operative limit here: brevo.php
 * calls recordRateLimit('brevo_webhook', true, ...) on every admitted
 * request and never records a failure, so ip_max/user_max (which only count
 * failed attempts) can never trip. Bypassing checkRateLimit()/recordRateLimit()
 * to insert rows directly at the DB layer (rather than looping the full
 * total_max times through the real functions) keeps this suite fast.
 */
#[Group('database')]
final class BrevoWebhookRateLimitEnforcementTest extends IntegrationTestCase
{
    private const ACTION = 'brevo_webhook';
    private const TOTAL_MAX = 2000;

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
        // unreasonably large.
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
