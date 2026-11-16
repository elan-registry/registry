<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;

/**
 * #1406: the 'registration_recovery_email' limit enforces through the real
 * check -> record -> check loop. checkRateLimit() only counts recorded rows, so
 * config alone proves nothing. email_max comes from
 * usersc/includes/rate_limits.php.
 *
 * No test here covers how usersc/join.php normalizes the email before it
 * builds the rate-limit key. #2733 holds that gap.
 *
 * Each test passes its own IntegrationTestCase::fakeDocumentationIp().
 * Without an explicit IP, one shared bucket (ip_max) fills across tests and
 * blocks before email_max.
 */
#[Group('database')]
final class RateLimitEnforcementTest extends IntegrationTestCase
{
    /** @var array{ip: string} */
    private array $ip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ip = ['ip' => $this->fakeDocumentationIp()];
    }

    /**
     * Read email_max for registration_recovery_email from the project-owned
     * rate-limit file.
     */
    private function configuredEmailMax(): int
    {
        $rateLimits = [];
        require dirname(__DIR__, 2) . '/usersc/includes/rate_limits.php';

        $this->assertArrayHasKey('registration_recovery_email', $rateLimits);
        $this->assertArrayHasKey('email_max', $rateLimits['registration_recovery_email']);

        return (int) $rateLimits['registration_recovery_email']['email_max'];
    }

    public function testEmailMaxLimitBlocksAfterConfiguredThreshold(): void
    {
        $this->requireDatabase();

        $emailMax = $this->configuredEmailMax();
        $email = 'rate-limit-test-' . uniqid('', true) . '@example.com';

        for ($i = 0; $i < $emailMax; $i++) {
            $this->assertTrue(
                checkRateLimit('registration_recovery_email', null, $email, $this->ip),
                'Attempt ' . ($i + 1) . ' of ' . $emailMax . ' should be allowed (within the configured limit)'
            );
            // Mirrors usersc/join.php: success=false rows count toward email_max;
            // success=true rows count only toward the higher total_max.
            recordRateLimit('registration_recovery_email', false, null, $email, $this->ip);
        }

        $this->assertFalse(
            checkRateLimit('registration_recovery_email', null, $email, $this->ip),
            'Attempt ' . ($emailMax + 1) . ' must be blocked — this is the control that prevents '
                . 'email-bombing a victim via repeated registration attempts with their address (#1406)'
        );
    }

    public function testLimitIsKeyedByEmailNotGlobal(): void
    {
        $this->requireDatabase();

        $emailMax = $this->configuredEmailMax();
        $exhaustedEmail = 'rate-limit-exhausted-' . uniqid('', true) . '@example.com';
        for ($i = 0; $i < $emailMax; $i++) {
            recordRateLimit('registration_recovery_email', false, null, $exhaustedEmail, $this->ip);
        }
        $this->assertFalse(checkRateLimit('registration_recovery_email', null, $exhaustedEmail, $this->ip));

        $freshEmail = 'rate-limit-fresh-' . uniqid('', true) . '@example.com';
        $this->assertTrue(
            checkRateLimit('registration_recovery_email', null, $freshEmail, $this->ip),
            'A different email must not be affected by another email exhausting its own limit'
        );
    }
}
