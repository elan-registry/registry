<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * checkRateLimit() silently no-ops for an action that is not configured.
 *
 * usersc/includes/rate_limits.php replaces the upstream $rateLimits array
 * wholesale (no merge), so it is the whole active config. The upstream file is
 * gitignored, so the test loads the project file directly.
 */
#[Group('fast')]
final class RateLimitConfigTest extends TestCase
{
    /**
     * @return array<string, array<string, int>>
     */
    private function loadRateLimits(string $action): array
    {
        /** @var array<string, array<string, int>> $rateLimits */
        $rateLimits = [];
        require dirname(__DIR__, 3) . '/usersc/includes/rate_limits.php';

        $this->assertIsArray($rateLimits);
        $this->assertArrayHasKey(
            $action,
            $rateLimits,
            "{$action} must be configured in usersc/includes/rate_limits.php, or checkRateLimit() silently no-ops"
        );

        return $rateLimits;
    }

    /** usersc/join.php recovery email (#1406). */
    public function testRegistrationRecoveryEmailActionIsConfigured(): void
    {
        $rateLimits = $this->loadRateLimits('registration_recovery_email');

        $this->assertSame(4, $rateLimits['registration_recovery_email']['email_max']);
        $this->assertSame(3600, $rateLimits['registration_recovery_email']['email_window']);
    }

    /**
     * LocationService swallows rate-limiter errors, so a missing key fails open
     * (#1582). ip_max is PHP_INT_MAX only to pass the validator; total_max keyed
     * by IP is the real limit. Sizing history (#2122, #1952) is in
     * usersc/includes/rate_limits.php.
     */
    public function testLocationSearchActionIsConfigured(): void
    {
        $rateLimits = $this->loadRateLimits('location_search');

        $this->assertSame(PHP_INT_MAX, $rateLimits['location_search']['ip_max']);
        $this->assertSame(60, $rateLimits['location_search']['ip_window']);
        $this->assertSame(1500, $rateLimits['location_search']['total_max']);
        $this->assertSame(300, $rateLimits['location_search']['total_window']);
    }

    /**
     * The only volume control on the unauthenticated Brevo webhook (#1887). No
     * user keys: the webhook has no session.
     */
    public function testBrevoWebhookActionIsConfigured(): void
    {
        $rateLimits = $this->loadRateLimits('brevo_webhook');

        $this->assertSame(750, $rateLimits['brevo_webhook']['ip_max']);
        $this->assertSame(300, $rateLimits['brevo_webhook']['ip_window']);
        $this->assertSame(3000, $rateLimits['brevo_webhook']['total_max']);
        $this->assertSame(300, $rateLimits['brevo_webhook']['total_window']);
        $this->assertArrayNotHasKey('user_max', $rateLimits['brevo_webhook']);
        $this->assertArrayNotHasKey('user_window', $rateLimits['brevo_webhook']);
    }

    /**
     * Brute-force guard on verification codes (#1881). Each guess is a new
     * token bucket, so ip_max and total_max bound a code-grinding attack.
     */
    public function testVerificationCodeAttemptActionIsConfigured(): void
    {
        $rateLimits = $this->loadRateLimits('verification_code_attempt');

        $this->assertSame(50, $rateLimits['verification_code_attempt']['ip_max']);
        $this->assertSame(300, $rateLimits['verification_code_attempt']['ip_window']);
        $this->assertSame(10, $rateLimits['verification_code_attempt']['token_max']);
        $this->assertSame(1800, $rateLimits['verification_code_attempt']['token_window']);
        $this->assertSame(200, $rateLimits['verification_code_attempt']['total_max']);
        $this->assertSame(300, $rateLimits['verification_code_attempt']['total_window']);
    }

    /** Gates the Brevo webhook auth-failure log line, not the 401 (#2087). */
    public function testBrevoWebhookAuthFailureActionIsConfigured(): void
    {
        $rateLimits = $this->loadRateLimits('brevo_webhook_auth_failure');

        $this->assertSame(10, $rateLimits['brevo_webhook_auth_failure']['ip_max']);
        $this->assertSame(300, $rateLimits['brevo_webhook_auth_failure']['ip_window']);
        $this->assertSame(100, $rateLimits['brevo_webhook_auth_failure']['total_max']);
        $this->assertSame(300, $rateLimits['brevo_webhook_auth_failure']['total_window']);
        $this->assertArrayNotHasKey('user_max', $rateLimits['brevo_webhook_auth_failure']);
        $this->assertArrayNotHasKey('user_window', $rateLimits['brevo_webhook_auth_failure']);
    }
}
