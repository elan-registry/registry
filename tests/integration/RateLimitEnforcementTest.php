<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;

/**
 * #1406: the 'registration_recovery_email' limit enforces through the real
 * check -> record -> check loop. checkRateLimit() only counts recorded rows, so
 * config alone proves nothing. Keep email_max/email_window in step with
 * tests/unit/system/RateLimitConfigTest.php.
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
            // Mirrors usersc/join.php: success=false rows count toward email_max;
            // success=true rows count only toward the higher total_max.
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
     * Different casings of one email, normalized as join.php does, share one
     * bucket (users.email uses a case-insensitive collation).
     */
    public function testCaseVariationsOfSameEmailShareOneRateLimitBucketWhenNormalized(): void
    {
        $this->requireDatabase();

        $baseEmail = 'rate-limit-case-' . uniqid('', true) . '@example.com';
        $casings = [$baseEmail, strtoupper($baseEmail), ucfirst($baseEmail)];

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
