<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration test proving the 'location_search' rate limit (#1582) buckets
 * anonymous callers by IP, not globally — i.e. two distinct anonymous
 * visitors do not share a single 'location_search' rate-limit bucket, and one
 * visitor exhausting their budget does not affect another (AC #1/#2 of the
 * issue).
 *
 * This exercises the shared RateLimit engine directly via checkRateLimit()/
 * recordRateLimit() (the same global helpers RateLimiterAdapter delegates
 * to) rather than going through LocationService — the property under test
 * here is the engine's per-IP bucket isolation, not LocationService's own
 * cache-then-rate-limit logic (see tests/unit/location/LocationServiceRateLimitTest.php
 * for that).
 *
 * There is no existing IP-faking helper on IntegrationTestCase (compare
 * loginAsTestUser()/restoreGlobalUser(), which snapshot/restore
 * $GLOBALS['user']), so each test method here manually saves and restores
 * $_SERVER['REMOTE_ADDR'] in a try/finally block, mirroring that same
 * save-once/restore-in-tearDown shape at the single-test-method scope.
 *
 * Fake IPs are drawn from TEST-NET-3 (203.0.113.0/24, RFC 5737) — reserved
 * for documentation/testing and guaranteed never to appear in real traffic —
 * with a random last octet per call so concurrent test runs don't collide.
 *
 * Caching note (mirrors tests/integration/GetBaseUrlTest.php): RateLimit's
 * getRealIP() reads REMOTE_ADDR through Server::get(), which memoizes every
 * key it resolves in a private static $cache for the lifetime of the PHP
 * process. Without clearing that cache, a later change to
 * $_SERVER['REMOTE_ADDR'] in the same process is invisible to Server::get()
 * — the rate limiter would keep using whichever IP was first resolved
 * (typically during bootstrap), silently bucketing every "distinct" IP in
 * this test under one real identifier. resetServerCache() clears it via
 * reflection before REMOTE_ADDR is changed, matching GetBaseUrlTest's setUp()
 * pattern.
 */
#[Group('database')]
final class LocationRateLimitIsolationTest extends IntegrationTestCase
{
    private const ACTION = 'location_search';

    /**
     * Matches usersc/includes/rate_limits.php's location_search total_max.
     * Raised from 10 to 1000 (#2122) — the original value refused a real
     * registrant's typed address after 11 debounced requests in 50s. Raised
     * again to 1500 by the v2.30.3 blanket +50% rate-limit increase.
     */
    private const TOTAL_MAX = 1500;

    private function fakeTestNet3Ip(): string
    {
        return '203.0.113.' . random_int(1, 254);
    }

    /**
     * Clear Server::$cache so a subsequent Server::get('REMOTE_ADDR', ...)
     * call (made indirectly via RateLimit::getRealIP()) observes whatever
     * $_SERVER['REMOTE_ADDR'] is set to at call time, rather than a value
     * memoized earlier in this process. See the class docblock's Caching note.
     */
    private function resetServerCache(): void
    {
        if (!class_exists(\Server::class)) {
            return;
        }
        $reflection = new \ReflectionClass(\Server::class);
        if ($reflection->hasProperty('cache')) {
            $reflection->getProperty('cache')->setValue(null, []);
        }
    }

    /**
     * Insert $count already-recorded attempt rows directly, bypassing
     * recordRateLimit() for speed — mirrors
     * BrevoWebhookRateLimitEnforcementTest::seedTotalAttempts() exactly
     * (identifier_key = sha256('ip::' . $ip), matching
     * RateLimit::buildIdentifierKey(), so a subsequent real
     * checkRateLimit() call reads these rows as if recordRateLimit() had
     * written them). Needed here because TOTAL_MAX raised 10 -> 1000
     * (#2122) made looping recordRateLimit() the real function 1000 times
     * measurably slow; only tests genuinely exercising the realistic-typing
     * volume (testAdmitsRealisticTypingSession, 20 calls) still loop the
     * real functions.
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

        $originalRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;

        try {
            $ipOne = $this->fakeTestNet3Ip();
            $this->resetServerCache();
            $_SERVER['REMOTE_ADDR'] = $ipOne;

            $this->seedTotalAttempts(self::ACTION, $ipOne, self::TOTAL_MAX);

            $this->assertFalse(
                checkRateLimit(self::ACTION, null),
                'Attempt ' . (self::TOTAL_MAX + 1) . ' for IP #1 must be blocked — total_max exhausted (AC: budget enforcement).'
            );

            // Switch to a second, distinct fake IP.
            $ipTwo = $this->fakeTestNet3Ip();
            // Guard against the astronomically unlikely random collision,
            // which would silently turn this into a same-IP (non-)test.
            while ($ipTwo === $ipOne) {
                $ipTwo = $this->fakeTestNet3Ip();
            }
            $this->resetServerCache();
            $_SERVER['REMOTE_ADDR'] = $ipTwo;

            $this->assertTrue(
                checkRateLimit(self::ACTION, null),
                'A distinct anonymous IP must have its own independent location_search bucket — '
                    . "it must not be blocked by IP #1 ({$ipOne}) exhausting its own budget (AC #1/#2, #1582)."
            );
        } finally {
            if ($originalRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $originalRemoteAddr;
            }
            // Clear the memoized value so later tests don't observe this
            // test's fake IP through Server::get('REMOTE_ADDR', ...).
            $this->resetServerCache();
        }
    }

    /**
     * Proves #2122's actual fix: a realistic typing session against the
     * join-form's manual location picker no longer trips location_search's
     * limit. The incident this issue fixes was 11 debounced requests in 50s
     * from one typed multi-word address; this asserts double that (20) all
     * admit, matching a realistic-typing-session margin.
     */
    public function testAdmitsRealisticTypingSession(): void
    {
        $this->requireDatabase();

        $originalRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;

        try {
            $this->resetServerCache();
            $_SERVER['REMOTE_ADDR'] = $this->fakeTestNet3Ip();

            $realisticRequestCount = 20;

            for ($i = 0; $i < $realisticRequestCount; $i++) {
                $this->assertTrue(
                    checkRateLimit(self::ACTION, null),
                    'Request ' . ($i + 1) . ' of ' . $realisticRequestCount . ' should be allowed — '
                        . 'this is the exact volume that tripped the old 10/60 threshold and blocked a '
                        . 'real registrant typing a full address (#2122).'
                );
                recordRateLimit(self::ACTION, true, null);
            }
        } finally {
            if ($originalRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $originalRemoteAddr;
            }
            $this->resetServerCache();
        }
    }

    /**
     * The control proving the raised threshold (#2122, later raised again to
     * 1500 — see TOTAL_MAX above) is still a real backstop, not a de facto
     * removal — the issue's own
     * instruction was "do not simply remove the limit" (protects the
     * upstream Nominatim/Photon geocoder from abuse). Also confirms a
     * blocked attempt is still recorded with success=0 in us_rate_limits,
     * matching the project's "record every attempt, gate only admission"
     * convention used elsewhere (see BrevoWebhookRateLimitEnforcementTest).
     */
    public function testTotalMaxStillBlocksAfterConfiguredThreshold(): void
    {
        $this->requireDatabase();

        $originalRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;

        try {
            $testIp = $this->fakeTestNet3Ip();
            $this->resetServerCache();
            $_SERVER['REMOTE_ADDR'] = $testIp;

            $this->seedTotalAttempts(self::ACTION, $testIp, self::TOTAL_MAX);

            $this->assertFalse(
                checkRateLimit(self::ACTION, null),
                'Request ' . (self::TOTAL_MAX + 1) . ' must be blocked — the raised threshold must '
                    . 'still refuse genuinely abusive volume, not just remove the limit entirely (#2122).'
            );

            recordRateLimit(self::ACTION, false, null);

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
        } finally {
            if ($originalRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $originalRemoteAddr;
            }
            $this->resetServerCache();
        }
    }
}
