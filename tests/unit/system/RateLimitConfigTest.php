<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

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

    /**
     * Each row pins the values of one action. Keys in the third column must be
     * absent: an action with no session must not get a per-user bucket.
     *
     * @return array<string, array{0: string, 1: array<string, int>, 2: list<string>}>
     */
    public static function configuredActionProvider(): array
    {
        return [
            // usersc/join.php recovery email (#1406).
            'registration_recovery_email' => [
                'registration_recovery_email',
                ['email_max' => 4, 'email_window' => 3600],
                [],
            ],
            // LocationService swallows rate-limiter errors, so a missing key
            // fails open (#1582). ip_max is PHP_INT_MAX only to pass the
            // validator; total_max keyed by IP is the real limit. Sizing
            // history (#2122, #1952) is in usersc/includes/rate_limits.php.
            'location_search' => [
                'location_search',
                ['ip_max' => PHP_INT_MAX, 'ip_window' => 60, 'total_max' => 1500, 'total_window' => 300],
                [],
            ],
            // The only volume control on the unauthenticated Brevo webhook
            // (#1887). No user keys: the webhook has no session.
            'brevo_webhook' => [
                'brevo_webhook',
                ['ip_max' => 750, 'ip_window' => 300, 'total_max' => 3000, 'total_window' => 300],
                ['user_max', 'user_window'],
            ],
            // Brute-force guard on verification codes (#1881). Each guess is a
            // new token bucket, so ip_max and total_max bound a code-grinding
            // attack.
            'verification_code_attempt' => [
                'verification_code_attempt',
                [
                    'ip_max' => 50,
                    'ip_window' => 300,
                    'token_max' => 10,
                    'token_window' => 1800,
                    'total_max' => 200,
                    'total_window' => 300,
                ],
                [],
            ],
            // Gates the Brevo webhook auth-failure log line, not the 401 (#2087).
            'brevo_webhook_auth_failure' => [
                'brevo_webhook_auth_failure',
                ['ip_max' => 10, 'ip_window' => 300, 'total_max' => 100, 'total_window' => 300],
                ['user_max', 'user_window'],
            ],
        ];
    }

    /**
     * @param array<string, int> $expected
     * @param list<string> $absentKeys
     */
    #[DataProvider('configuredActionProvider')]
    public function testActionIsConfigured(string $action, array $expected, array $absentKeys): void
    {
        $rateLimits = $this->loadRateLimits($action);

        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $rateLimits[$action]);
            $this->assertSame($value, $rateLimits[$action][$key], "{$action}.{$key}");
        }
        foreach ($absentKeys as $key) {
            $this->assertArrayNotHasKey($key, $rateLimits[$action]);
        }
    }

    /**
     * Presence check for the two buckets of the join flow (#1690):
     * usersc/join.php checks registration_attempt, and the join-failure beacon
     * checks join_failure_beacon.
     */
    public function testRegistrationAttemptAndJoinFailureBeaconActionsArePresent(): void
    {
        $this->loadRateLimits('registration_attempt');
        $this->loadRateLimits('join_failure_beacon');
    }

    /**
     * The beacon has its own bucket so that beacon traffic cannot use up the
     * tight registration_attempt cap for all visitors behind one shared IP
     * (#1690). Its ceiling must stay higher.
     */
    public function testJoinFailureBeaconIpMaxIsHigherThanRegistrationAttemptIpMax(): void
    {
        $this->loadRateLimits('registration_attempt');
        $rateLimits = $this->loadRateLimits('join_failure_beacon');
        $this->assertArrayHasKey('ip_max', $rateLimits['registration_attempt']);
        $this->assertArrayHasKey('ip_max', $rateLimits['join_failure_beacon']);

        $this->assertGreaterThan(
            $rateLimits['registration_attempt']['ip_max'],
            $rateLimits['join_failure_beacon']['ip_max'],
            'join_failure_beacon.ip_max must be greater than registration_attempt.ip_max'
        );
    }
}
