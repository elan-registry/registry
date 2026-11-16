<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Confirms app/api/shared/join-failure-report.php (the Issue #1690
 * client-side failure beacon) and usersc/join.php's real registration flow
 * use two SEPARATE rate-limit buckets ('join_failure_beacon' and
 * 'registration_attempt' respectively) rather than one shared bucket.
 *
 * Why separate buckets: 'registration_attempt' is tight and IP-scoped, so
 * beacon traffic (Turnstile retries, GPS failures, JS exceptions — none of
 * them a real registration attempt) sharing it could
 * exhaust the cap for every visitor behind a shared/NAT IP before any of
 * them could actually submit the form — a lockout vector the original
 * single-POST join.php never exposed this cheaply. The beacon uses its own,
 * higher-ceiling config instead (see usersc/includes/rate_limits.php).
 *
 * Why this is a source-text test rather than a live-request integration
 * test: checkRateLimit() is called with no explicit identifiers at either
 * call site — RateLimit::check() derives the IP identifier internally (see
 * users/classes/RateLimit.php) — and this repo's IntegrationTestCase has no
 * multi-IP request-simulation harness (see
 * tests/integration/IntegrationTestCase.php). Asserting the two files use
 * DIFFERENT literal action strings is the one fact that actually determines
 * bucket separation, since RateLimit scopes all counting by (action,
 * identifiers).
 *
 * @issue 1690
 * @link https://github.com/elan-registry/registry/issues/1690
 */
#[Group('regression')]
#[Group('fast')]
final class JoinFailureReportUsesDedicatedRateLimitBucketRegressionTest extends TestCase
{
    private const JOIN_PHP_PATH = __DIR__ . '/../../../usersc/join.php';
    private const BEACON_PATH = __DIR__ . '/../../../app/api/shared/join-failure-report.php';

    public function testBeaconUsesItsOwnDedicatedRateLimitAction(): void
    {
        $beaconSource = file_get_contents(self::BEACON_PATH);
        $this->assertIsString($beaconSource, 'app/api/shared/join-failure-report.php must be readable');

        $this->assertStringContainsString(
            "checkRateLimit('join_failure_beacon')",
            $beaconSource,
            'The beacon must check its own join_failure_beacon rate limit, not registration_attempt — '
                . 'sharing the tight registration_attempt bucket would let beacon traffic '
                . 'exhaust it for every visitor behind a shared/NAT IP'
        );

        $this->assertStringNotContainsString(
            "checkRateLimit('registration_attempt')",
            $beaconSource,
            'The beacon must not check registration_attempt — that bucket belongs to the real '
                . 'registration flow in join.php and must not be shared with beacon traffic'
        );
    }

    public function testJoinPhpStillUsesRegistrationAttemptForTheRealFlow(): void
    {
        $joinSource = file_get_contents(self::JOIN_PHP_PATH);
        $this->assertIsString($joinSource, 'usersc/join.php must be readable');

        $this->assertStringContainsString(
            "checkRateLimit('registration_attempt')",
            $joinSource,
            'usersc/join.php must still check the registration_attempt rate limit for real submissions'
        );
    }

    /**
     * Neither call site should pass extra identifiers that would further
     * split either bucket beyond the single IP-derived identifier each
     * action already uses.
     */
    public function testNeitherCallSiteAddsExtraIdentifiers(): void
    {
        $joinSource = file_get_contents(self::JOIN_PHP_PATH);
        $this->assertIsString($joinSource);

        $beaconSource = file_get_contents(self::BEACON_PATH);
        $this->assertIsString($beaconSource);

        $this->assertStringNotContainsString(
            "checkRateLimit('registration_attempt', ",
            $joinSource,
            'join.php must not pass extra identifiers to the registration_attempt check'
        );

        $this->assertStringNotContainsString(
            "checkRateLimit('join_failure_beacon', ",
            $beaconSource,
            'The beacon must not pass extra identifiers to the join_failure_beacon check'
        );
    }
}
