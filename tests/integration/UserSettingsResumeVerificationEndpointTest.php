<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Behavioral (real-process, real-DB) tests for the
 * `resume_verification_emails` POST branch of `usersc/user_settings.php`
 * (#1895 — owner self-service "Resume verification emails").
 *
 * usersc/user_settings.php is a full HTML page, not a JSON API endpoint, and
 * Redirect::to() ends the request with a hard exit() either way (it sends a
 * Location header when possible, or falls through to an exit()-ing JS
 * redirect otherwise — see users/classes/Redirect.php). So, as with
 * VerificationToggleEndpointBehaviorTest's ApiResponse::send() constraint,
 * each request runs in its own `php -r` subprocess. A register_shutdown_function()
 * callback inside that subprocess captures the usSuccess() session flash to a
 * temp file just before PHP tears the process down — the exit() itself always
 * wins the race against any code placed after `require` in the same script.
 * The test then asserts on that captured flash plus real database state
 * (flags, cars_hist rows), never on the rendered page body.
 *
 * securePage($php_self) (called by usersc/user_settings.php) needs a real
 * web-relative $php_self to find its `pages` row. Server::get('PHP_SELF')
 * derives this from $_SERVER['SCRIPT_FILENAME'] under CLI (see
 * users/classes/Server.php's cliFallback()), so SCRIPT_FILENAME is set to the
 * endpoint's real path before users/init.php runs. securePage() also requires
 * the session user to hold the page's permission_page_matches permission (not
 * merely users.permissions != 0), so the fixture user is granted
 * permission_id 1 (Member) via a real user_permission_matches row, mirroring
 * what User::create() does for every real signup.
 *
 * @see usersc/user_settings.php
 * @see https://github.com/elan-registry/registry/issues/1895
 */
#[Group('integration')]
#[Group('car-verification')]
final class UserSettingsResumeVerificationEndpointTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    /** @var list<string> Temp files created by invokeResumeEndpoint(), cleaned up in tearDown(). */
    private array $createdTempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->createdTempFiles = [];
    }

    protected function tearDown(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            putenv($var);
        }
        foreach ($this->createdTempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function exposeTestDatabaseToSubprocess(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== '') {
                putenv("$var=$value");
            }
        }
    }

    private function emailSuppressed(int $carId): int
    {
        $row = $this->db->query('SELECT email_suppressed FROM cars WHERE id = ?', [$carId])->first();
        $this->assertNotNull($row, "Test setup: car {$carId} disappeared");
        return (int) $row->email_suppressed;
    }

    private function emailBounced(int $carId): int
    {
        $row = $this->db->query('SELECT email_bounced FROM cars WHERE id = ?', [$carId])->first();
        $this->assertNotNull($row, "Test setup: car {$carId} disappeared");
        return (int) $row->email_bounced;
    }

    private function emailBouncedAddress(int $carId): ?string
    {
        $row = $this->db->query('SELECT email_bounced_address FROM cars WHERE id = ?', [$carId])->first();
        $this->assertNotNull($row, "Test setup: car {$carId} disappeared");
        return $row->email_bounced_address === null ? null : (string) $row->email_bounced_address;
    }

    private function profileEmailSuppressed(int $ownerId): ?int
    {
        $result = $this->db->query('SELECT email_suppressed FROM profiles WHERE user_id = ?', [$ownerId]);
        if ($this->db->count() === 0) {
            return null;
        }
        return (int) $result->first()->email_suppressed;
    }

    private function profileCity(int $ownerId): string
    {
        $row = $this->db->query('SELECT city FROM profiles WHERE user_id = ?', [$ownerId])->first();
        $this->assertNotNull($row, "Test setup: owner {$ownerId} has no profiles row");
        return (string) $row->city;
    }

    /** @return list<string> operation values, in insertion order */
    private function suppressionCarsHistOperations(int $carId): array
    {
        $rows = $this->db->query(
            "SELECT operation FROM cars_hist WHERE car_id = ? AND operation LIKE '%SUPPRESSION%' ORDER BY id ASC",
            [$carId]
        )->results();
        return array_map(static fn (object $row): string => (string) $row->operation, $rows);
    }

    /**
     * Creates a logged-in, Member-permissioned ($user_permission_matches,
     * permission_id = 1) test user with a profiles row, as
     * usersc/user_settings.php's securePage() gate requires.
     */
    private function createEndpointTestUser(): int
    {
        $ownerId = $this->createTestUser(['permissions' => 1], true);
        $this->db->insert('user_permission_matches', ['user_id' => $ownerId, 'permission_id' => 1]);
        $this->assertFalse($this->db->error(), 'Test setup: failed to grant Member permission: ' . $this->db->errorString());
        return $ownerId;
    }

    /**
     * Runs usersc/user_settings.php as a POST request in its own subprocess,
     * with a real session + CSRF token and a logged-in user via the same
     * reflection-based technique VerificationToggleEndpointBehaviorTest uses.
     *
     * @param array<string, mixed> $extraPost Additional $_POST fields, merged
     *                                         after 'csrf' and
     *                                         'resume_verification_emails'
     *                                         (a field here can override
     *                                         either).
     * @param bool $omitCsrfKey When true, the 'csrf' key is left out of
     *                          $_POST entirely instead of set to a valid or
     *                          invalid value — proves the missing-key case,
     *                          not just the bad-value case. Overrides
     *                          $withValidCsrf.
     * @return array{exitCode: int, raw: string, successFlash: string}
     */
    private function invokeResumeEndpoint(
        int $loggedInUserId,
        bool $withValidCsrf,
        array $extraPost = [],
        bool $omitCsrfKey = false
    ): array {
        $endpointFile = dirname(__DIR__, 2) . '/usersc/user_settings.php';
        $projectRoot = dirname(__DIR__, 2);

        $this->exposeTestDatabaseToSubprocess();

        $captureFile = tempnam(sys_get_temp_dir(), 'resume-endpoint-');
        $this->createdTempFiles[] = $captureFile;

        $csrfSnippet = match (true) {
            $omitCsrfKey => '',
            $withValidCsrf => '$_POST["csrf"] = \Token::generate();',
            default => '$_POST["csrf"] = "deliberately-invalid-token-0000000000000000000000000000000000";',
        };

        $loginSnippet = sprintf(
            '$loggedInUser = new User(); $loggedInUser->find(%d); ' .
            '$reflection = new ReflectionClass($loggedInUser); ' .
            '$prop = $reflection->getProperty("_isLoggedIn"); $prop->setValue($loggedInUser, true); ' .
            'global $user; $user = $loggedInUser; $GLOBALS["user"] = $loggedInUser;',
            $loggedInUserId
        );

        $extraPostSnippet = '';
        foreach ($extraPost as $key => $value) {
            $extraPostSnippet .= sprintf('$_POST[%s] = %s; ', var_export($key, true), var_export($value, true));
        }

        $script = sprintf(
            '$_SERVER["SCRIPT_FILENAME"] = %s; ' .
            'require %s; ' .
            '\Dotenv\Dotenv::createMutable(%s, ".env.test.local")->load(); ' .
            '$_SERVER["DOCUMENT_ROOT"] = %s; ' .
            '$_SERVER["REQUEST_METHOD"] = "POST"; ' .
            'chdir(%s); ' .
            'require_once %s; ' .
            '%s ' .
            '%s ' .
            '$_POST["resume_verification_emails"] = "1"; ' .
            '%s ' .
            '$__captureFile = %s; ' .
            'register_shutdown_function(function () use ($__captureFile) { ' .
            '    $sn = \Config::get("session/session_name"); ' .
            '    file_put_contents($__captureFile, json_encode($_SESSION[$sn . "valSuc"] ?? null)); ' .
            '}); ' .
            'require %s;',
            var_export($endpointFile, true),
            var_export($projectRoot . '/vendor/autoload.php', true),
            var_export($projectRoot, true),
            var_export($projectRoot, true),
            var_export(dirname($endpointFile), true),
            var_export($projectRoot . '/users/init.php', true),
            $csrfSnippet,
            $loginSnippet,
            $extraPostSnippet,
            var_export($captureFile, true),
            var_export($endpointFile, true)
        );

        $raw = shell_exec('php -r ' . escapeshellarg($script) . ' 2>&1; echo "___EXIT:$?"');
        $raw = $raw !== null ? $raw : '';

        [$body, $exitMarker] = array_pad(explode('___EXIT:', $raw), 2, '0');

        $successFlash = '';
        $captured = file_exists($captureFile) ? file_get_contents($captureFile) : '';
        if (is_string($captured) && $captured !== '') {
            $decoded = json_decode($captured, true);
            if (is_string($decoded)) {
                $successFlash = $decoded;
            } elseif (is_array($decoded)) {
                $successFlash = implode(' ', array_map('strval', $decoded));
            }
        }

        return [
            'exitCode' => (int) trim($exitMarker),
            'raw' => trim($body),
            'successFlash' => $successFlash,
        ];
    }

    #[Group('fast')]
    public function testValidClearWithValidCsrfClearsFlagsAndInsertsOneHistoryRowPerCar(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->db->query('UPDATE profiles SET email_suppressed = 1 WHERE user_id = ?', [$ownerId]);
        $carOneId = $this->createTestCar($ownerId, ['email' => 'resume-one@example.com', 'email_suppressed' => 1]);
        $carTwoId = $this->createTestCar($ownerId, ['email' => 'resume-two@example.com', 'email_suppressed' => 1]);

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: true);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertStringNotContainsStringIgnoringCase('fatal error', $result['raw']);

        $this->assertSame(0, $this->emailSuppressed($carOneId));
        $this->assertSame(0, $this->emailSuppressed($carTwoId));
        $this->assertSame(0, $this->profileEmailSuppressed($ownerId));

        foreach ([$carOneId, $carTwoId] as $carId) {
            $ops = $this->suppressionCarsHistOperations($carId);
            $this->assertCount(1, $ops, "Car {$carId} must get exactly one SUPPRESSION cars_hist row");
            $this->assertSame('SUPPRESSION CLEARED BY OWNER', $ops[0]);
        }

        $this->assertStringContainsString(
            'Verification emails have been resumed for your cars.',
            $result['successFlash'],
            'The success flash message must be queued via usSuccess(): ' . $result['raw']
        );
    }

    #[Group('fast')]
    public function testMissingCsrfKeyLeavesEverythingUntouched(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->db->query('UPDATE profiles SET email_suppressed = 1 WHERE user_id = ?', [$ownerId]);
        $carId = $this->createTestCar($ownerId, ['email' => 'no-csrf-key@example.com', 'email_suppressed' => 1]);

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: false, omitCsrfKey: true);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(1, $this->emailSuppressed($carId), 'A missing csrf key must leave the car untouched');
        $this->assertSame(1, $this->profileEmailSuppressed($ownerId), 'A missing csrf key must leave the profile flag untouched');
        $this->assertCount(0, $this->suppressionCarsHistOperations($carId), 'No cars_hist row may be inserted');
    }

    #[Group('fast')]
    public function testInvalidCsrfValueLeavesEverythingUntouched(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->db->query('UPDATE profiles SET email_suppressed = 1 WHERE user_id = ?', [$ownerId]);
        $carId = $this->createTestCar($ownerId, ['email' => 'bad-csrf@example.com', 'email_suppressed' => 1]);

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: false);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(1, $this->emailSuppressed($carId), 'An invalid csrf token must leave the car untouched');
        $this->assertSame(1, $this->profileEmailSuppressed($ownerId), 'An invalid csrf token must leave the profile flag untouched');
        $this->assertCount(0, $this->suppressionCarsHistOperations($carId), 'No cars_hist row may be inserted');
    }

    #[Group('fast')]
    public function testSpoofedOwnerIdInPostBodyIsIgnoredAndOnlySessionOwnerChanges(): void
    {
        $sessionOwnerId = $this->createEndpointTestUser();
        $this->db->query('UPDATE profiles SET email_suppressed = 1 WHERE user_id = ?', [$sessionOwnerId]);
        $sessionOwnerCarId = $this->createTestCar(
            $sessionOwnerId,
            ['email' => 'session-owner@example.com', 'email_suppressed' => 1]
        );

        $spoofedOwnerId = $this->createEndpointTestUser();
        $this->db->query('UPDATE profiles SET email_suppressed = 1 WHERE user_id = ?', [$spoofedOwnerId]);
        $spoofedOwnerCarId = $this->createTestCar(
            $spoofedOwnerId,
            ['email' => 'spoofed-owner@example.com', 'email_suppressed' => 1]
        );

        // The new form carries no id field at all, so this proves the
        // endpoint reads no POST-supplied id — not merely that it ignores
        // one named 'user_id'. A spoofed car id is included too.
        $result = $this->invokeResumeEndpoint($sessionOwnerId, withValidCsrf: true, extraPost: [
            'user_id' => (string) $spoofedOwnerId,
            'owner_id' => (string) $spoofedOwnerId,
            'car_id' => (string) $spoofedOwnerCarId,
        ]);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);

        $this->assertSame(0, $this->emailSuppressed($sessionOwnerCarId), 'The session owner\'s own car must be cleared');
        $this->assertSame(0, $this->profileEmailSuppressed($sessionOwnerId));

        $this->assertSame(1, $this->emailSuppressed($spoofedOwnerCarId), 'The spoofed owner\'s car must remain suppressed');
        $this->assertSame(1, $this->profileEmailSuppressed($spoofedOwnerId), 'The spoofed owner\'s profile flag must remain untouched');

        $this->assertCount(
            0,
            $this->suppressionCarsHistOperations($spoofedOwnerCarId),
            'No history row may be written for the spoofed owner\'s car'
        );
    }

    #[Group('fast')]
    public function testMixedPostWithOrdinaryProfileFieldsDoesNotWriteThoseFields(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->db->query("UPDATE profiles SET email_suppressed = 1, city = 'OriginalCity' WHERE user_id = ?", [$ownerId]);
        $carId = $this->createTestCar($ownerId, ['email' => 'mixed-post@example.com', 'email_suppressed' => 1]);

        $this->assertSame('OriginalCity', $this->profileCity($ownerId), 'Test sanity: city starts at the seeded value');

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: true, extraPost: [
            'city' => 'SpoofedCityShouldNotBeWritten',
        ]);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(0, $this->emailSuppressed($carId), 'The resume action must still have run');
        $this->assertSame(
            'OriginalCity',
            $this->profileCity($ownerId),
            'The elseif branch must fully short-circuit the profile-update logic — city must not change'
        );
    }

    #[Group('fast')]
    public function testCarWithBouncedFlagIsUnaffectedOnBounceColumns(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->db->query('UPDATE profiles SET email_suppressed = 1 WHERE user_id = ?', [$ownerId]);
        $carId = $this->createTestCar($ownerId, [
            'email' => 'bounced-endpoint@example.com',
            'email_suppressed' => 1,
            'email_bounced' => 1,
            'email_bounced_address' => 'bounced-endpoint@example.com',
        ]);

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: true);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(0, $this->emailSuppressed($carId));
        $this->assertSame(1, $this->emailBounced($carId), 'Bounce flag must be left exactly as it was');
        $this->assertSame('bounced-endpoint@example.com', $this->emailBouncedAddress($carId));
    }

    /**
     * Confirms the exact cars_hist.operation string against the live
     * varchar(32) column (no truncation — 'SUPPRESSION CLEARED BY OWNER' is
     * 28 chars) and that it differs from the admin path's
     * 'EMAIL SUPPRESSION CLEARED' string (app/admin/index.php).
     */
    #[Group('fast')]
    public function testCarsHistOperationStringExactAndDistinctFromAdminString(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->db->query('UPDATE profiles SET email_suppressed = 1 WHERE user_id = ?', [$ownerId]);
        $carId = $this->createTestCar($ownerId, ['email' => 'operation-string@example.com', 'email_suppressed' => 1]);

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: true);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);

        $ops = $this->suppressionCarsHistOperations($carId);
        $this->assertNotEmpty($ops, 'A history row must have been inserted');
        $this->assertSame('SUPPRESSION CLEARED BY OWNER', $ops[0]);
        $this->assertSame(
            strlen('SUPPRESSION CLEARED BY OWNER'),
            strlen($ops[0]),
            'The stored operation string must not be truncated against the live varchar(32) column'
        );
        $this->assertNotSame(
            'EMAIL SUPPRESSION CLEARED',
            $ops[0],
            'The owner path\'s operation string must differ from the admin path\'s'
        );
    }
}
