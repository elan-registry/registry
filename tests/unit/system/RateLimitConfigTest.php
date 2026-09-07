<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * The 'cars_list' rate-limit entry (issue #1913 / ADR-019) must be
     * configured in usersc/includes/rate_limits.php — app/api/cars/list.php
     * calls checkRateLimit('cars_list', ...) in place of the CSRF check
     * removed by ADR-019, and will silently no-op (fail open, per
     * RateLimit::check()'s "unconfigured action" behaviour) if this key is
     * missing or mistyped, leaving the now CSRF-free public endpoint with no
     * abuse control at all.
     */
    public function testCarsListActionIsConfigured(): void
    {
        $projectRoot = dirname(__DIR__, 3);

        /** @var array<string, array<string, int>> $rateLimits */
        $rateLimits = [];
        require $projectRoot . '/usersc/includes/rate_limits.php';

        $this->assertIsArray($rateLimits);
        $this->assertArrayHasKey(
            'cars_list',
            $rateLimits,
            'cars_list must be configured in usersc/includes/rate_limits.php '
                . '(the project override, which wholesale-replaces the framework defaults) — '
                . 'app/api/cars/list.php calls checkRateLimit() with this action name and will '
                . 'silently no-op if it is missing, per ADR-019 the endpoint carries no CSRF '
                . 'check to fall back on.'
        );
        // Mirrors the project's actual active cars_list limits
        // (usersc/includes/rate_limits.php). ip_max/user_max compare against
        // failed attempts only and are effectively inert here since every
        // draw is recorded as success=true; total_max is what actually
        // governs, and it is per-identifier (see RateLimit::check()), not
        // site-wide.
        $this->assertSame(1000, $rateLimits['cars_list']['ip_max']);
        $this->assertSame(300, $rateLimits['cars_list']['ip_window']);
        $this->assertSame(1000, $rateLimits['cars_list']['user_max']);
        $this->assertSame(300, $rateLimits['cars_list']['user_window']);
        $this->assertSame(10000, $rateLimits['cars_list']['total_max']);
        $this->assertSame(300, $rateLimits['cars_list']['total_window']);
    }

    /**
     * The 'factory_list' rate-limit entry (issue #1913 / ADR-019) must be
     * configured in usersc/includes/rate_limits.php —
     * app/api/cars/factory-list.php calls checkRateLimit('factory_list', ...)
     * in place of the CSRF check removed by ADR-019, and will silently no-op
     * (fail open) if this key is missing or mistyped.
     */
    public function testFactoryListActionIsConfigured(): void
    {
        $projectRoot = dirname(__DIR__, 3);

        /** @var array<string, array<string, int>> $rateLimits */
        $rateLimits = [];
        require $projectRoot . '/usersc/includes/rate_limits.php';

        $this->assertIsArray($rateLimits);
        $this->assertArrayHasKey(
            'factory_list',
            $rateLimits,
            'factory_list must be configured in usersc/includes/rate_limits.php '
                . '(the project override, which wholesale-replaces the framework defaults) — '
                . 'app/api/cars/factory-list.php calls checkRateLimit() with this action name and '
                . 'will silently no-op if it is missing, per ADR-019 the endpoint carries no CSRF '
                . 'check to fall back on.'
        );
        // Mirrors the project's actual active factory_list limits
        // (usersc/includes/rate_limits.php). As with cars_list, total_max is
        // the limit that actually governs since every draw is recorded as
        // success=true.
        $this->assertSame(1000, $rateLimits['factory_list']['ip_max']);
        $this->assertSame(300, $rateLimits['factory_list']['ip_window']);
        $this->assertSame(1000, $rateLimits['factory_list']['user_max']);
        $this->assertSame(300, $rateLimits['factory_list']['user_window']);
        $this->assertSame(10000, $rateLimits['factory_list']['total_max']);
        $this->assertSame(300, $rateLimits['factory_list']['total_window']);
    }

    /**
     * The 'car_history' rate-limit entry (issue #1913 / ADR-019) must be
     * configured in usersc/includes/rate_limits.php — app/api/cars/history.php
     * calls checkRateLimit('car_history', ...) in place of the CSRF check
     * removed by ADR-019, and will silently no-op (fail open) if this key is
     * missing or mistyped.
     */
    public function testCarHistoryActionIsConfigured(): void
    {
        $projectRoot = dirname(__DIR__, 3);

        /** @var array<string, array<string, int>> $rateLimits */
        $rateLimits = [];
        require $projectRoot . '/usersc/includes/rate_limits.php';

        $this->assertIsArray($rateLimits);
        $this->assertArrayHasKey(
            'car_history',
            $rateLimits,
            'car_history must be configured in usersc/includes/rate_limits.php '
                . '(the project override, which wholesale-replaces the framework defaults) — '
                . 'app/api/cars/history.php calls checkRateLimit() with this action name and will '
                . 'silently no-op if it is missing, per ADR-019 the endpoint carries no CSRF check '
                . 'to fall back on.'
        );
        // Mirrors the project's actual active car_history limits
        // (usersc/includes/rate_limits.php). Sized lower than cars_list /
        // factory_list because history fires once per car-details view
        // rather than per keystroke. total_max is what actually governs
        // since every draw is recorded as success=true.
        $this->assertSame(600, $rateLimits['car_history']['ip_max']);
        $this->assertSame(300, $rateLimits['car_history']['ip_window']);
        $this->assertSame(600, $rateLimits['car_history']['user_max']);
        $this->assertSame(300, $rateLimits['car_history']['user_window']);
        $this->assertSame(5000, $rateLimits['car_history']['total_max']);
        $this->assertSame(300, $rateLimits['car_history']['total_window']);
    }

    /**
     * The 'statistics_request' rate-limit entry (issue #1951 / ADR-019) must be
     * configured in usersc/includes/rate_limits.php — app/api/shared/statistics.php
     * calls checkRateLimit('statistics_request', ...) in place of the CSRF check
     * removed by ADR-019, and will silently no-op (fail open) if this key is
     * missing or mistyped.
     *
     * This test exists because the pre-ADR-019 sizing (50/25/100) was carried
     * over by mistake when CSRF was dropped from this endpoint: unlike its three
     * sibling public-read keys (cars_list, factory_list, car_history), nothing
     * pinned statistics_request's values, so the stale, too-low sizing survived
     * undetected until a milestone-level review caught it.
     */
    public function testStatisticsRequestActionIsConfigured(): void
    {
        $projectRoot = dirname(__DIR__, 3);

        /** @var array<string, array<string, int>> $rateLimits */
        $rateLimits = [];
        require $projectRoot . '/usersc/includes/rate_limits.php';

        $this->assertIsArray($rateLimits);
        $this->assertArrayHasKey(
            'statistics_request',
            $rateLimits,
            'statistics_request must be configured in usersc/includes/rate_limits.php '
                . '(the project override, which wholesale-replaces the framework defaults) — '
                . 'app/api/shared/statistics.php calls checkRateLimit() with this action name and '
                . 'will silently no-op if it is missing, per ADR-019 the endpoint carries no CSRF '
                . 'check to fall back on.'
        );
        // Mirrors the project's actual active statistics_request limits
        // (usersc/includes/rate_limits.php). Sized like car_history (fires
        // once per statistics tab, not per keystroke) rather than the higher
        // cars_list/factory_list ceiling. total_max is what actually governs
        // since every draw is recorded as success=true.
        $this->assertSame(600, $rateLimits['statistics_request']['ip_max']);
        $this->assertSame(300, $rateLimits['statistics_request']['ip_window']);
        $this->assertSame(600, $rateLimits['statistics_request']['user_max']);
        $this->assertSame(300, $rateLimits['statistics_request']['user_window']);
        $this->assertSame(5000, $rateLimits['statistics_request']['total_max']);
        $this->assertSame(300, $rateLimits['statistics_request']['total_window']);
    }

    /**
     * Every ADR-019 endpoint's action string must match a configured key.
     *
     * The per-key tests above pin the *config* side only: they prove
     * $rateLimits['cars_list'] exists, but the literal in the test is a second
     * independent copy of the string, not a reference to the one in the
     * endpoint. A typo on the endpoint side (checkRateLimit('cars_lst', ...))
     * would leave every one of those tests green while
     * RateLimit::check() takes its `!isset($this->rateLimits[$action])` branch
     * (its `!isset($this->rateLimits[$action])` early return) and returns
     * true — allowing the
     * request. Per ADR-019 these endpoints carry no CSRF check, so that
     * fail-open leaves them with no control at all.
     *
     * This reads the endpoint source and pins both sides with one assertion,
     * following tests/integration/JoinFailureReportUsesDedicatedRateLimitBucketTest.
     */
    #[DataProvider('adr019EndpointProvider')]
    public function testEndpointActionStringMatchesConfiguredKey(
        string $relativePath,
        string $action
    ): void {
        $projectRoot = dirname(__DIR__, 3);

        $rateLimits = [];
        require $projectRoot . '/usersc/includes/rate_limits.php';

        $source = file_get_contents($projectRoot . '/' . $relativePath);
        $this->assertIsString($source, $relativePath . ' must be readable');

        $this->assertStringContainsString(
            "checkRateLimit('" . $action . "'",
            $source,
            $relativePath . " must call checkRateLimit('" . $action . "'). The action string in "
                . 'the endpoint and the key in usersc/includes/rate_limits.php must match exactly: '
                . 'RateLimit::check() returns true (allows the request) for an unconfigured action, '
                . 'so a mistyped string silently disables the limit — and per ADR-019 there is no '
                . 'CSRF check behind it.'
        );

        $this->assertArrayHasKey(
            $action,
            $rateLimits,
            $action . " is used by " . $relativePath . ' but is not configured in '
                . 'usersc/includes/rate_limits.php.'
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function adr019EndpointProvider(): array
    {
        return [
            'cars list'    => ['app/api/cars/list.php', 'cars_list'],
            'factory list' => ['app/api/cars/factory-list.php', 'factory_list'],
            'car history'  => ['app/api/cars/history.php', 'car_history'],
            'statistics'   => ['app/api/shared/statistics.php', 'statistics_request'],
        ];
    }
}
