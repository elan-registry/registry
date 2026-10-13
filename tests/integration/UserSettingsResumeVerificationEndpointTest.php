<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1895: the `resume_verification_emails` POST branch of
 * usersc/user_settings.php.
 *
 * Redirect::to() always calls exit(), so each request runs in its own
 * `php -r` subprocess, and a shutdown function captures the usSuccess(),
 * usError() and usWarning() flashes to a temp file. POST tests assert on that flash and database state.
 *
 * securePage() needs SCRIPT_FILENAME set before users/init.php (for
 * $php_self) and a real permission_id 1 match for the fixture user.
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

    /** @return array<string, mixed> The owner's profile flag columns, as stored */
    private function flags(int $ownerId): array
    {
        return $this->getOwnerEmailFlags($ownerId);
    }

    /** @return array<string, mixed> The full cars row as stored */
    private function carRow(int $carId): array
    {
        $row = (new CarRepository($this->db))->findById($carId);
        $this->assertNotNull($row, "Test setup: car {$carId} disappeared");

        return (array) $row;
    }

    /**
     * @return list<array{date: string, flag: string, old: int, new: int, source: string, acting_user_id: int}>
     */
    private function flagChanges(int $ownerId): array
    {
        return (new CarRepository($this->db))->recentFlagChanges($ownerId, 100);
    }

    private function profileCity(int $ownerId): string
    {
        $row = $this->db->query('SELECT city FROM profiles WHERE user_id = ?', [$ownerId])->first();
        $this->assertNotNull($row, "Test setup: owner {$ownerId} has no profiles row");
        return (string) $row->city;
    }

    /** Number of cars_hist rows that name a suppression or bounce operation. None may be written (#2101). */
    private function emailOperationHistRows(int $carId): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist
              WHERE car_id = ? AND (operation LIKE '%SUPPRESSION%' OR operation LIKE '%BOUNCE%')",
            [$carId]
        )->first()->cnt;
    }

    /** A logged-in Member (permission_id 1) with a profiles row, for securePage(). */
    private function createEndpointTestUser(): int
    {
        $ownerId = $this->createTestUser(['permissions' => 1], true);
        $this->db->insert('user_permission_matches', ['user_id' => $ownerId, 'permission_id' => 1]);
        $this->assertFalse($this->db->error(), 'Test setup: failed to grant Member permission: ' . $this->db->errorString());
        return $ownerId;
    }

    /**
     * Runs usersc/user_settings.php in its own subprocess with a real session,
     * CSRF token, and logged-in user.
     *
     * @param array<string, mixed> $extraPost Extra $_POST fields; can override 'csrf' and 'resume_verification_emails'
     * @param bool $omitCsrfKey Leave out the 'csrf' key entirely; overrides $withValidCsrf
     * @param bool $asGet Plain GET page load; the POST options are ignored
     * @param bool $withResumeField False sends the ordinary profile-update POST
     * @return array{exitCode: int, raw: string, successFlash: string, errorFlash: string, warningFlash: string}
     */
    private function invokeResumeEndpoint(
        int $loggedInUserId,
        bool $withValidCsrf,
        array $extraPost = [],
        bool $omitCsrfKey = false,
        bool $asGet = false,
        bool $withResumeField = true
    ): array {
        $endpointFile = dirname(__DIR__, 2) . '/usersc/user_settings.php';
        $projectRoot = dirname(__DIR__, 2);

        $this->exposeTestDatabaseToSubprocess();

        $captureFile = tempnam(sys_get_temp_dir(), 'resume-endpoint-');
        $this->createdTempFiles[] = $captureFile;

        $csrfSnippet = match (true) {
            $asGet, $omitCsrfKey => '',
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
        foreach ($asGet ? [] : $extraPost as $key => $value) {
            $extraPostSnippet .= sprintf('$_POST[%s] = %s; ', var_export($key, true), var_export($value, true));
        }

        $script = sprintf(
            '$_SERVER["SCRIPT_FILENAME"] = %s; ' .
            'require %s; ' .
            '\Dotenv\Dotenv::createMutable(%s, ".env.test.local")->load(); ' .
            '$_SERVER["DOCUMENT_ROOT"] = %s; ' .
            '$_SERVER["REQUEST_METHOD"] = %s; ' .
            'chdir(%s); ' .
            'require_once %s; ' .
            '%s ' .
            '%s ' .
            '%s ' .
            '%s ' .
            '$__captureFile = %s; ' .
            'register_shutdown_function(function () use ($__captureFile) { ' .
            '    $sn = \Config::get("session/session_name"); ' .
            '    file_put_contents($__captureFile, json_encode([ ' .
            '        "success" => $_SESSION[$sn . "valSuc"] ?? null, ' .
            '        "error" => $_SESSION[$sn . "valErr"] ?? null, ' .
            '        "warning" => $_SESSION[$sn . "genMsg"] ?? null, ' .
            '    ])); ' .
            '}); ' .
            'require %s;',
            var_export($endpointFile, true),
            var_export($projectRoot . '/vendor/autoload.php', true),
            var_export($projectRoot, true),
            var_export($projectRoot, true),
            var_export($asGet ? 'GET' : 'POST', true),
            var_export(dirname($endpointFile), true),
            var_export($projectRoot . '/users/init.php', true),
            $csrfSnippet,
            $loginSnippet,
            ($asGet || !$withResumeField) ? '' : '$_POST["resume_verification_emails"] = "1";',
            $extraPostSnippet,
            var_export($captureFile, true),
            var_export($endpointFile, true)
        );

        $raw = shell_exec('php -r ' . escapeshellarg($script) . ' 2>&1; echo "___EXIT:$?"');
        $raw = $raw !== null ? $raw : '';

        [$body, $exitMarker] = array_pad(explode('___EXIT:', $raw), 2, '0');

        $captured = file_exists($captureFile) ? file_get_contents($captureFile) : '';
        $decoded = is_string($captured) && $captured !== '' ? json_decode($captured, true) : null;

        return [
            'exitCode' => (int) trim($exitMarker),
            'raw' => trim($body),
            'successFlash' => self::flashText(is_array($decoded) ? ($decoded['success'] ?? null) : null),
            'errorFlash' => self::flashText(is_array($decoded) ? ($decoded['error'] ?? null) : null),
            'warningFlash' => self::flashText(is_array($decoded) ? ($decoded['warning'] ?? null) : null),
        ];
    }

    /** A session flash value (a string or a list of strings) as one string. */
    private static function flashText(mixed $flash): string
    {
        if (is_string($flash)) {
            return $flash;
        }
        return is_array($flash) ? implode(' ', array_map('strval', $flash)) : '';
    }

    #[Group('fast')]
    public function testValidResumeWithValidCsrfClearsTheOptOutAndWritesNothingToCars(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->setOwnerOptedOut($ownerId, true, '2026-01-02 03:04:05');
        $carOneId = $this->createTestCar($ownerId, ['email' => 'resume-one@example.com']);
        $carTwoId = $this->createTestCar($ownerId, ['email' => 'resume-two@example.com']);
        $carsBefore = [$this->carRow($carOneId), $this->carRow($carTwoId)];

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: true);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertStringNotContainsStringIgnoringCase('fatal error', $result['raw']);

        $flags = $this->flags($ownerId);
        $this->assertSame('0', (string) $flags['email_opted_out']);
        $this->assertNull($flags['email_opted_out_at']);
        $this->assertSame($carsBefore, [$this->carRow($carOneId), $this->carRow($carTwoId)], 'No car row may change');
        $this->assertSame(0, $this->emailOperationHistRows($carOneId), 'No email cars_hist row is written any more');
        $this->assertSame(0, $this->emailOperationHistRows($carTwoId));

        $this->assertStringContainsString(
            'Verification emails have been resumed for your cars.',
            $result['successFlash'],
            'The success flash message must be queued via usSuccess(): ' . $result['raw']
        );
    }

    #[Group('fast')]
    public function testResumeWritesOneOwnerSourceLogRowWithTheOwnerAsActingUser(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->setOwnerOptedOut($ownerId);
        $this->createTestCar($ownerId, ['email' => 'log-row@example.com']);

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: true);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $changes = $this->flagChanges($ownerId);
        $this->assertCount(1, $changes, 'One flag change writes exactly one log row');
        $this->assertSame('email_opted_out', $changes[0]['flag']);
        $this->assertSame(1, $changes[0]['old']);
        $this->assertSame(0, $changes[0]['new']);
        $this->assertSame('owner', $changes[0]['source']);
        $this->assertSame($ownerId, $changes[0]['acting_user_id']);
    }

    #[Group('fast')]
    public function testMissingCsrfKeyLeavesEverythingUntouched(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->setOwnerOptedOut($ownerId);
        $this->createTestCar($ownerId, ['email' => 'no-csrf-key@example.com']);
        $before = $this->flags($ownerId);

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: false, omitCsrfKey: true);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame($before, $this->flags($ownerId), 'A missing csrf key must leave the profile untouched');
        $this->assertSame([], $this->flagChanges($ownerId), 'No log row may be written');
    }

    #[Group('fast')]
    public function testInvalidCsrfValueLeavesEverythingUntouched(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->setOwnerOptedOut($ownerId);
        $this->createTestCar($ownerId, ['email' => 'bad-csrf@example.com']);
        $before = $this->flags($ownerId);

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: false);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame($before, $this->flags($ownerId), 'An invalid csrf token must leave the profile untouched');
        $this->assertSame([], $this->flagChanges($ownerId), 'No log row may be written');
    }

    #[Group('fast')]
    public function testSpoofedOwnerIdInPostBodyIsIgnoredAndOnlySessionOwnerChanges(): void
    {
        $sessionOwnerId = $this->createEndpointTestUser();
        $this->setOwnerOptedOut($sessionOwnerId);
        $this->createTestCar($sessionOwnerId, ['email' => 'session-owner@example.com']);

        $spoofedOwnerId = $this->createEndpointTestUser();
        $this->setOwnerOptedOut($spoofedOwnerId);
        $spoofedOwnerCarId = $this->createTestCar($spoofedOwnerId, ['email' => 'spoofed-owner@example.com']);
        $spoofedBefore = $this->flags($spoofedOwnerId);

        // The form carries no id field at all, so this proves the endpoint
        // reads no POST-supplied id, not merely that it ignores one named
        // 'user_id'. A spoofed car id is included too.
        $result = $this->invokeResumeEndpoint($sessionOwnerId, withValidCsrf: true, extraPost: [
            'user_id' => (string) $spoofedOwnerId,
            'owner_id' => (string) $spoofedOwnerId,
            'car_id' => (string) $spoofedOwnerCarId,
        ]);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);

        $this->assertSame('0', (string) $this->flags($sessionOwnerId)['email_opted_out'], 'The session owner is resumed');
        $this->assertSame($spoofedBefore, $this->flags($spoofedOwnerId), 'The spoofed owner\'s profile must be untouched');
        $this->assertSame([], $this->flagChanges($spoofedOwnerId), 'No log row may be written for the spoofed owner');
    }

    #[Group('fast')]
    public function testMixedPostWithOrdinaryProfileFieldsDoesNotWriteThoseFields(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->setOwnerOptedOut($ownerId);
        $this->db->query("UPDATE profiles SET city = 'OriginalCity' WHERE user_id = ?", [$ownerId]);
        $this->createTestCar($ownerId, ['email' => 'mixed-post@example.com']);

        $this->assertSame('OriginalCity', $this->profileCity($ownerId), 'Test sanity: city starts at the seeded value');

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: true, extraPost: [
            'city' => 'SpoofedCityShouldNotBeWritten',
        ]);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame('0', (string) $this->flags($ownerId)['email_opted_out'], 'The resume action must still have run');
        $this->assertSame(
            'OriginalCity',
            $this->profileCity($ownerId),
            'The elseif branch must fully short-circuit the profile-update logic — city must not change'
        );
    }

    #[Group('fast')]
    public function testBouncedAndOptedOutOwnerKeepsTheBounceAfterResume(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->setOwnerOptedOut($ownerId);
        $this->setOwnerBounced($ownerId, 'bounced-endpoint@example.com', '2026-03-04 05:06:07');
        $this->createTestCar($ownerId, ['email' => 'bounced-endpoint@example.com']);

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: true);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $flags = $this->flags($ownerId);
        $this->assertSame('0', (string) $flags['email_opted_out']);
        $this->assertSame('1', (string) $flags['email_bounced'], 'Bounce flag must be left exactly as it was');
        $this->assertSame('bounced-endpoint@example.com', $flags['email_bounced_address']);
        $this->assertSame('2026-03-04 05:06:07', $flags['email_bounced_at']);
    }

    /**
     * An opted-out owner sees the control, with a count of every owned car,
     * also a car added after the opt-out, and Resume clears the flag.
     */
    #[Group('fast')]
    public function testOptedOutOwnerShowsControlWithEveryOwnedCarAndResumeClearsIt(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->setOwnerOptedOut($ownerId);
        $this->createTestCar($ownerId, ['email' => 'profile-count-one@example.com']);
        // A car added after the opt-out is also paused, because the flag is the owner's.
        $this->createTestCar($ownerId, ['email' => 'profile-count-two@example.com']);

        $page = $this->invokeResumeEndpoint($ownerId, withValidCsrf: false, asGet: true);

        $this->assertSame(0, $page['exitCode'], 'Subprocess must exit cleanly: ' . $page['raw']);
        $this->assertStringContainsString('id="resume-emails"', $page['raw'], 'An opt-out must show the control');
        $this->assertStringContainsString('name="resume_verification_emails"', $page['raw']);
        $this->assertStringContainsString(
            'Verification emails are currently paused for 2 of your cars.',
            $page['raw'],
            'The count must be every owned car'
        );

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: true);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame('0', (string) $this->flags($ownerId)['email_opted_out']);
        $this->assertStringContainsString('Verification emails have been resumed for your cars.', $result['successFlash']);
    }

    /**
     * The owner is blocked and never opted out. Only an admin can clear the
     * block. The page hides the button, and a forced POST gives the reason
     * and the next step, and changes nothing.
     */
    #[Group('fast')]
    #[Group('regression')]
    public function testBlockedOnlyOwnerHidesButtonAndPostReportsNothingResumed(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->setOwnerBlocked($ownerId, 'all-complaint@example.com', '2026-03-10 09:30:00', 'spam');
        $this->createTestCar($ownerId, ['email' => 'all-complaint@example.com']);
        $before = $this->flags($ownerId);

        $page = $this->invokeResumeEndpoint($ownerId, withValidCsrf: false, asGet: true);

        $this->assertSame(0, $page['exitCode'], 'Subprocess must exit cleanly: ' . $page['raw']);
        $this->assertStringContainsString('id="resume-emails"', $page['raw'], 'The section must still explain the pause');
        $this->assertStringNotContainsString(
            'name="resume_verification_emails"',
            $page['raw'],
            'The button must not render when it would clear nothing'
        );
        $this->assertStringContainsString('Our email provider flagged your address.', $page['raw']);

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: true);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame($before, $this->flags($ownerId), 'A block must stay');
        $this->assertStringNotContainsString('have been resumed', $result['successFlash']);
        $this->assertSame(
            'Verification emails are paused because our email provider flagged your address. Please contact the registry.',
            $result['errorFlash']
        );
    }

    /**
     * An owner who complained and then opted out: Resume removes the opt-out
     * and tells the owner that emails stay paused. The block, its address,
     * time and cause stay.
     */
    #[Group('fast')]
    #[Group('regression')]
    public function testBlockedAndOptedOutOwnerResumeKeepsTheBlockAndSaysEmailsStayPaused(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->setOwnerBlocked($ownerId, 'complained@example.com', '2026-03-10 09:30:00', 'spam');
        $this->setOwnerOptedOut($ownerId);
        $this->createTestCar($ownerId, ['email' => 'complained@example.com']);

        $page = $this->invokeResumeEndpoint($ownerId, withValidCsrf: false, asGet: true);
        $this->assertStringContainsString('name="resume_verification_emails"', $page['raw']);
        $this->assertStringContainsString('This button does not turn verification emails back on.', $page['raw']);

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: true);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $flags = $this->flags($ownerId);
        $this->assertSame('0', (string) $flags['email_opted_out'], 'Resume clears the opt-out');
        $this->assertSame('1', (string) $flags['email_blocked'], 'Resume must not clear the block');
        $this->assertSame('complained@example.com', $flags['email_blocked_address']);
        $this->assertSame('2026-03-10 09:30:00', $flags['email_blocked_at']);
        $this->assertSame('spam', $flags['email_blocked_cause']);
        $this->assertSame(
            'Your opt-out is removed. Verification emails stay paused because our email provider flagged your address. '
            . 'Please contact the registry.',
            $result['warningFlash'],
            'The message must be a warning, not a success'
        );
        $this->assertSame('', $result['successFlash']);
    }

    /**
     * An owner whose address bounced and who opted out: Resume removes the
     * opt-out, but eligibility still excludes the owner on the bounce. The
     * message must not say that emails resume.
     */
    #[Group('fast')]
    #[Group('regression')]
    public function testBouncedAndOptedOutOwnerResumeSaysEmailsStayPausedForTheBounce(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->setOwnerOptedOut($ownerId);
        $this->setOwnerBounced($ownerId, 'bounced-message@example.com', '2026-03-04 05:06:07');
        $this->createTestCar($ownerId, ['email' => 'bounced-message@example.com']);

        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: true);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame('0', (string) $this->flags($ownerId)['email_opted_out'], 'Resume clears the opt-out');
        $this->assertSame(
            'Your opt-out is removed. Verification emails stay paused because email to your address bounced. '
            . 'Update your email address to turn them back on.',
            $result['warningFlash'],
            'The message must be a warning, not a success'
        );
        $this->assertSame('', $result['successFlash']);
    }

    #[Group('fast')]
    public function testNoFlagHidesControl(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->createTestCar($ownerId, ['email' => 'not-suppressed@example.com']);

        $page = $this->invokeResumeEndpoint($ownerId, withValidCsrf: false, asGet: true);

        $this->assertSame(0, $page['exitCode'], 'Subprocess must exit cleanly: ' . $page['raw']);
        $this->assertStringContainsString('updateAccount', $page['raw'], 'Test sanity: the settings page must have rendered');
        $this->assertStringNotContainsString('id="resume-emails"', $page['raw'], 'No flag set means no control');
    }

    #[Group('fast')]
    public function testBouncedOnlyOwnerHidesControl(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->setOwnerBounced($ownerId, 'bounced-only@example.com');
        $this->createTestCar($ownerId, ['email' => 'bounced-only@example.com']);

        $page = $this->invokeResumeEndpoint($ownerId, withValidCsrf: false, asGet: true);

        $this->assertSame(0, $page['exitCode'], 'Subprocess must exit cleanly: ' . $page['raw']);
        $this->assertStringContainsString('updateAccount', $page['raw'], 'Test sanity: the settings page must have rendered');
        $this->assertStringNotContainsString(
            'id="resume-emails"',
            $page['raw'],
            'A bounce is not an opt-out, so the Resume section stays hidden'
        );
    }

    /**
     * AC3: no path on Account Settings can set the opt-out. The ordinary
     * profile-update form POST (valid CSRF, no resume_verification_emails
     * field) changes the city, and also carries forged flag fields. Every
     * owner flag must stay unchanged.
     */
    #[Group('fast')]
    public function testOrdinaryProfileUpdateCannotSetAnyOwnerFlag(): void
    {
        $ownerId = $this->createEndpointTestUser();
        $this->db->query(
            "UPDATE profiles SET city = 'OriginalCity', state = 'OriginalState', country = 'OriginalCountry' WHERE user_id = ?",
            [$ownerId]
        );
        $this->createTestCar($ownerId, ['email' => 'ac3-one@example.com']);

        $before = $this->flags($ownerId);
        $this->assertSame('0', (string) $before['email_opted_out'], 'Test sanity: opt-out starts at 0');
        $this->assertSame('0', (string) $before['email_blocked'], 'Test sanity: block starts at 0');
        $this->assertSame('0', (string) $before['email_bounced'], 'Test sanity: bounce starts at 0');

        $owner = $this->db->query('SELECT username, fname, lname, email FROM users WHERE id = ?', [$ownerId])->first();
        $this->assertNotNull($owner, 'Test setup: owner row must exist');

        // Unchanged username, names and email keep the request off the
        // password-protected branches. Only the location changes.
        $result = $this->invokeResumeEndpoint($ownerId, withValidCsrf: true, extraPost: [
            'username' => (string) $owner->username,
            'fname' => (string) $owner->fname,
            'lname' => (string) $owner->lname,
            'email' => (string) $owner->email,
            'city' => 'ChangedCity',
            'state' => 'ChangedState',
            'country' => 'ChangedCountry',
            'website' => '',
            'email_opted_out' => '1',
            'email_blocked' => '1',
            'email_bounced' => '1',
        ], withResumeField: false);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertStringNotContainsStringIgnoringCase('fatal error', $result['raw']);
        $this->assertSame(
            'ChangedCity',
            $this->profileCity($ownerId),
            'Test sanity: the ordinary profile-update path must have run: ' . $result['raw']
        );

        $this->assertSame($before, $this->flags($ownerId), 'The profile-update path must not set any owner flag');
    }
}
