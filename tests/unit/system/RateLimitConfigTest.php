<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit test for the 'registration_recovery_email' rate-limit entry (issue #1406).
 *
 * The registration-enumeration fix in usersc/join.php calls
 * checkRateLimit('registration_recovery_email', null, $email) before sending a
 * recovery notification, so an entry must exist in the *actually active*
 * rate-limit config or that call silently no-ops at runtime.
 *
 * IMPORTANT: at runtime, users/includes/rate_limits.php (upstream framework
 * defaults — gitignored, environment-local, NOT present in a fresh checkout)
 * conditionally `include`s the project-owned usersc/includes/rate_limits.php,
 * which reassigns `$rateLimits = [...]` wholesale (not a merge) — so whatever
 * is defined there completely replaces the framework defaults regardless of
 * what the framework file itself contains. This test requires
 * usersc/includes/rate_limits.php directly (the tracked file, always present
 * in CI) rather than going through the untracked framework wrapper, since
 * that wrapper's only relevant effect — the wholesale $rateLimits
 * reassignment — is what this test actually needs to exercise.
 */
#[Group('fast')]
final class RateLimitConfigTest extends TestCase
{
    public function testRegistrationRecoveryEmailActionIsConfigured(): void
    {
        $projectRoot = dirname(__DIR__, 3);

        /** @var array<string, array<string, int>> $rateLimits */
        $rateLimits = [];
        require $projectRoot . '/usersc/includes/rate_limits.php';

        $this->assertIsArray($rateLimits);
        $this->assertArrayHasKey(
            'registration_recovery_email',
            $rateLimits,
            'registration_recovery_email must be configured in usersc/includes/rate_limits.php '
                . '(the project override, which wholesale-replaces the framework defaults) — '
                . 'usersc/join.php calls checkRateLimit() with this action name and will silently '
                . 'no-op if it is missing.'
        );
        // Mirrors the project's actual active password_reset_request limits
        // (usersc/includes/rate_limits.php), not the framework's inflated
        // defaults in users/includes/rate_limits.php.
        $this->assertSame(3, $rateLimits['registration_recovery_email']['email_max']);
        $this->assertSame(3600, $rateLimits['registration_recovery_email']['email_window']);
    }

    /**
     * The 'location_search' rate-limit entry (issue #1582) must be configured
     * in usersc/includes/rate_limits.php — LocationService::searchLocation()
     * and ::reverseGeocode() both call checkRateLimit('location_search', ...)
     * via the RateLimiterAdapter and will silently no-op (fail open) if it is
     * missing, since LocationService's rate-limiter wrapper methods swallow
     * any \Throwable from a misconfigured/missing action.
     */
    public function testLocationSearchActionIsConfigured(): void
    {
        $projectRoot = dirname(__DIR__, 3);

        /** @var array<string, array<string, int>> $rateLimits */
        $rateLimits = [];
        require $projectRoot . '/usersc/includes/rate_limits.php';

        $this->assertIsArray($rateLimits);
        $this->assertArrayHasKey(
            'location_search',
            $rateLimits,
            'location_search must be configured in usersc/includes/rate_limits.php '
                . '(the project override, which wholesale-replaces the framework defaults) — '
                . 'LocationService calls checkRateLimit() with this action name and will silently '
                . 'no-op (fail open) if it is missing.'
        );
        // Mirrors the project's actual active location_search limits
        // (usersc/includes/rate_limits.php). ip_max is PHP_INT_MAX only to
        // satisfy the validator's required-key check and to disable the
        // failed-attempts-only IP sub-limit — total_max is still keyed by
        // identifier (IP, for anonymous callers) and is the limit that
        // actually governs anonymous traffic, shared by searchLocation() and
        // reverseGeocode() under the same 'location_search' action key.
        $this->assertSame(PHP_INT_MAX, $rateLimits['location_search']['ip_max']);
        $this->assertSame(60, $rateLimits['location_search']['ip_window']);
        $this->assertSame(10, $rateLimits['location_search']['total_max']);
        $this->assertSame(60, $rateLimits['location_search']['total_window']);
    }

    /**
     * The 'brevo_webhook' rate-limit entry (issue #1887) must be configured in
     * usersc/includes/rate_limits.php — app/api/webhooks/brevo.php is an
     * unauthenticated endpoint Brevo POSTs to, with no user session to key on,
     * and calls checkRateLimit('brevo_webhook', ...) as its only volume
     * control. It will silently no-op (fail open) if this key is missing or
     * mistyped.
     */
    public function testBrevoWebhookActionIsConfigured(): void
    {
        $projectRoot = dirname(__DIR__, 3);

        /** @var array<string, array<string, int>> $rateLimits */
        $rateLimits = [];
        require $projectRoot . '/usersc/includes/rate_limits.php';

        $this->assertIsArray($rateLimits);
        $this->assertArrayHasKey(
            'brevo_webhook',
            $rateLimits,
            'brevo_webhook must be configured in usersc/includes/rate_limits.php '
                . '(the project override, which wholesale-replaces the framework defaults) — '
                . 'app/api/webhooks/brevo.php calls checkRateLimit() with this action name and '
                . 'will silently no-op if it is missing, leaving the unauthenticated webhook '
                . 'endpoint with no volume control at all.'
        );
        // Mirrors the project's actual active brevo_webhook limits
        // (usersc/includes/rate_limits.php). No user_max/user_window: the
        // webhook carries no UserSpice session, so there is no user
        // identifier to key on (same shape as join_failure_beacon and
        // feedback_submission). Sized generously because every transactional
        // send produces 2-3 webhook calls within seconds from a small, shared
        // set of Brevo egress IPs; total_max is the real backstop.
        $this->assertSame(500, $rateLimits['brevo_webhook']['ip_max']);
        $this->assertSame(300, $rateLimits['brevo_webhook']['ip_window']);
        $this->assertSame(2000, $rateLimits['brevo_webhook']['total_max']);
        $this->assertSame(300, $rateLimits['brevo_webhook']['total_window']);
        $this->assertArrayNotHasKey('user_max', $rateLimits['brevo_webhook']);
        $this->assertArrayNotHasKey('user_window', $rateLimits['brevo_webhook']);
    }

}
