<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration test proving the 'registration_recovery_email' rate limit
 * (#1406) actually enforces, not just that it's configured.
 *
 * checkRateLimit() is read-only — it only counts rows already written by
 * recordRateLimit()/handleAuthFailure()/handleAuthSuccess(). A config entry
 * alone (see tests/unit/system/RateLimitConfigTest.php, which pins the
 * exact email_max/email_window values used below — keep both in sync if
 * either changes) proves nothing about enforcement if nothing at the call
 * site actually records an attempt. This test exercises the real
 * check -> record -> check loop against the live `us_rate_limits` table to
 * close that gap.
 */
#[Group('database')]
final class RateLimitEnforcementTest extends IntegrationTestCase
{
    private const EMAIL_MAX = 4;

    public function testEmailMaxLimitBlocksAfterConfiguredThreshold(): void
    {
        $this->requireDatabase();

        $email = 'rate-limit-test-' . uniqid('', true) . '@example.com';

        for ($i = 0; $i < self::EMAIL_MAX; $i++) {
            $this->assertTrue(
                checkRateLimit('registration_recovery_email', null, $email),
                'Attempt ' . ($i + 1) . ' of ' . self::EMAIL_MAX . ' should be allowed (within the configured limit)'
            );
            // Mirrors usersc/join.php's call: RateLimit::check() counts
            // success=false rows toward the tight email_max cap and only
            // counts success=true rows toward the much higher total_max, so
            // recording success=false is what makes email_max actually
            // engage regardless of whether a given attempt's notification
            // itself succeeded or failed — see the comment at the join.php
            // call site for the full reasoning.
            recordRateLimit('registration_recovery_email', false, null, $email);
        }

        $this->assertFalse(
            checkRateLimit('registration_recovery_email', null, $email),
            'Attempt ' . (self::EMAIL_MAX + 1) . ' must be blocked — this is the control that prevents '
                . 'email-bombing a victim via repeated registration attempts with their address (#1406)'
        );
    }

    public function testLimitIsKeyedByEmailNotGlobal(): void
    {
        $this->requireDatabase();

        $exhaustedEmail = 'rate-limit-exhausted-' . uniqid('', true) . '@example.com';
        for ($i = 0; $i < self::EMAIL_MAX; $i++) {
            // success=false — see the comment in the test above for why.
            recordRateLimit('registration_recovery_email', false, null, $exhaustedEmail);
        }
        $this->assertFalse(checkRateLimit('registration_recovery_email', null, $exhaustedEmail));

        $freshEmail = 'rate-limit-fresh-' . uniqid('', true) . '@example.com';
        $this->assertTrue(
            checkRateLimit('registration_recovery_email', null, $freshEmail),
            'A different email must not be affected by another email exhausting its own limit'
        );
    }

    /**
     * usersc/join.php normalizes the rate-limit key to lowercase before calling
     * checkRateLimit()/recordRateLimit() (see the call site comment for why:
     * users.email uses a case-insensitive collation, so the account lookup
     * already treats 'Victim@x.com' and 'victim@x.com' as the same account).
     * This test proves that IF a caller normalizes case consistently — as
     * join.php does — attempts recorded under different casings of the same
     * email collapse into the same rate-limit bucket instead of each getting
     * their own fresh allowance.
     */
    public function testCaseVariationsOfSameEmailShareOneRateLimitBucketWhenNormalized(): void
    {
        $this->requireDatabase();

        $baseEmail = 'rate-limit-case-' . uniqid('', true) . '@example.com';
        $casings = [$baseEmail, strtoupper($baseEmail), ucfirst($baseEmail)];

        // Record EMAIL_MAX attempts split across different casings of the same
        // email, normalized exactly as join.php does before recording.
        for ($i = 0; $i < self::EMAIL_MAX; $i++) {
            recordRateLimit('registration_recovery_email', false, null, mb_strtolower($casings[$i % count($casings)]));
        }

        $this->assertFalse(
            checkRateLimit('registration_recovery_email', null, mb_strtolower($baseEmail)),
            'Attempts recorded under varied casing of the same email, once normalized, must exhaust '
                . 'the single shared bucket for that email — proving case variation cannot be used to '
                . 'dodge email_max.'
        );
    }
}
