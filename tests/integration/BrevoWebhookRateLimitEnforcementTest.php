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

    // brevo_webhook_auth_failure (#2087): unlike brevo_webhook above, its
    // ip_max — not total_max — is the operative limit. brevo.php's
    // auth-failure branch calls recordRateLimit(..., false, ...) on every
    // rejected request, so ip_max (which counts only failed attempts) trips
    // well before total_max could. A prior fix attempt for this exact key
    // was reverted after checkRateLimit() mysteriously never returned false
    // despite matching DB rows — these tests exercise RateLimit::check()
    // directly against seeded rows to catch that class of limiter-wiring bug
    // independent of the HTTP layer.
    //
    // This constant is intentionally the RAW configured value (10), not the
    // dev-environment-multiplied effective value used by
    // BrevoWebhookEndpointTest.php's HTTP-subprocess tests. That multiplier
    // (usersc/includes/rate_limits_dev_override.php, applied when
    // US_ENVIRONMENT=development) is loaded only via
    // usersc/includes/loader.php, which real requests reach through the
    // normal init.php chain. This file calls checkRateLimit()/RateLimit
    // directly, in-process, inside PHPUnit's integration bootstrap — that
    // bootstrap never reaches loader.php (see tests/bootstrap-integration.php's
    // "did not reach usersc/includes/loader.php" fallback and
    // RateLimit::__construct(), which lazily requires
    // users/includes/rate_limits.php itself when $rateLimits isn't already
    // set — never the dev-override file). Confirmed empirically: a fresh
    // RateLimit() constructed from this exact bootstrap context sees
    // ip_max === 10, not 1000. Applying the x100 multiplier here would seed
    // 1000 rows against an actual in-process threshold of 10 and make these
    // assertions wrong, not right.
    private const AUTH_FAILURE_ACTION = 'brevo_webhook_auth_failure';
    private const AUTH_FAILURE_IP_MAX = 10;

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

    /**
     * Insert $count already-recorded FAILED attempt rows directly, bypassing
     * recordRateLimit() for speed. Same row shape as seedTotalAttempts()
     * above, but success=0 — this is what RateLimit::check()'s ip_max branch
     * counts (getAttemptCount($identifier, $action, $windowSeconds, false)),
     * as opposed to total_max, which counts all rows regardless of success.
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
