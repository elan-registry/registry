<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * Behavioral (real-process, real-DB, real-HTTP-shaped-request) tests for
 * app/api/admin/verification-toggle.php (#1926).
 *
 * Complements VerificationToggleEndpointTest's source-text assertions (unit
 * tier) and VerificationSettingsTest's exhaustive class-level coverage of the
 * asymmetric gate with the actual runtime behavior the plan's Test Plan
 * calls for, exercised at the HTTP layer: a genuine non-admin 403 with the
 * setting left unchanged, a genuine 422 naming Brevo when enabling while
 * unready, and — the single most important non-inversion case — a genuine
 * success response when disabling while Brevo is broken.
 *
 * Invoked in-process via require (not a separate subprocess per call): the
 * endpoint file's own `require_once '../../../users/init.php'` is executed
 * once up front so Token::generate()/session state is available to seed a
 * real CSRF token and a real logged-in user before each `require` of the
 * endpoint itself. Each request still runs as its own `php -r` subprocess
 * (one per test method) because ApiResponse::send() is a hard `exit` that
 * would terminate the PHPUnit process if reached in-process — same
 * constraint documented on ErrorPageHeadersTest::renderErrorPage().
 */
#[Group('integration')]
final class VerificationToggleEndpointBehaviorTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    /** @var int[] user_permission_matches row IDs created during this test, cleaned up in tearDown() */
    private array $createdPermissionMatchIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->createdPermissionMatchIds = [];
    }

    protected function tearDown(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            putenv($var);
        }

        if ($this->databaseConnected) {
            foreach ($this->createdPermissionMatchIds as $id) {
                $this->db->query('DELETE FROM user_permission_matches WHERE id = ?', [$id]);
            }
            // Restore the switch to its safe default so this test never
            // leaks an "enabled" state into whichever test runs next.
            $this->db->query('UPDATE er_verification_settings SET enabled = 0 WHERE id = 1');
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

    private function setSwitchEnabled(bool $enabled): void
    {
        $this->db->query(
            'UPDATE er_verification_settings SET enabled = ? WHERE id = 1',
            [$enabled ? 1 : 0]
        );
        $this->assertFalse($this->db->error(), 'Failed to set up er_verification_settings.enabled for test');
    }

    private function currentSwitchState(): bool
    {
        $row = $this->db->query('SELECT enabled FROM er_verification_settings WHERE id = 1')->first();
        return (bool) $row->enabled;
    }

    /** Grants permission_id to $userId via a real user_permission_matches row. */
    private function grantPermission(int $userId, int $permissionId): void
    {
        $this->db->insert('user_permission_matches', [
            'user_id' => $userId,
            'permission_id' => $permissionId,
        ]);
        $this->assertFalse($this->db->error(), 'Failed to grant permission for test setup: ' . $this->db->errorString());
        $this->createdPermissionMatchIds[] = (int) $this->db->lastId();
    }

    /**
     * @param int|null $loggedInUserId When non-null, the subprocess logs this user in
     *                                 (via the same reflection-based technique as
     *                                 IntegrationTestCase::loginAsTestUser()) before the
     *                                 endpoint runs. When null, no user is logged in.
     * @param string|null $enabledValue Raw POST 'enabled' value, or null to omit it entirely.
     * @return array{status: bool, message: string, exitCode: int, raw: string}
     */
    private function invokeToggleEndpoint(?int $loggedInUserId, ?string $enabledValue, bool $withValidCsrf = true): array
    {
        $endpointFile = dirname(__DIR__, 2) . '/app/api/admin/verification-toggle.php';
        $projectRoot = dirname(__DIR__, 2);

        $this->exposeTestDatabaseToSubprocess();

        $loginSnippet = $loggedInUserId === null
            ? ''
            : sprintf(
                '$loggedInUser = new User(); $loggedInUser->find(%d); ' .
                '$reflection = new ReflectionClass($loggedInUser); ' .
                '$prop = $reflection->getProperty("_isLoggedIn"); $prop->setValue($loggedInUser, true); ' .
                'global $user; $user = $loggedInUser; $GLOBALS["user"] = $loggedInUser;',
                $loggedInUserId
            );

        $enabledSnippet = $enabledValue === null
            ? ''
            : sprintf('$_POST["enabled"] = %s;', var_export($enabledValue, true));

        $csrfSnippet = $withValidCsrf
            ? '$_POST["csrf"] = \Token::generate();'
            : '$_POST["csrf"] = "deliberately-invalid-token";';

        $script = sprintf(
            'require %s; ' .
            '\Dotenv\Dotenv::createMutable(%s, ".env.test.local")->load(); ' .
            '$_SERVER["DOCUMENT_ROOT"] = %s; ' .
            '$_SERVER["PHP_SELF"] = "/app/api/admin/verification-toggle.php"; ' .
            '$_SERVER["REQUEST_METHOD"] = "POST"; ' .
            'chdir(%s); ' .
            'require_once %s; ' .
            '%s ' .
            '%s ' .
            '%s ' .
            'require %s;',
            var_export($projectRoot . '/vendor/autoload.php', true),
            var_export($projectRoot, true),
            var_export($projectRoot, true),
            var_export(dirname($endpointFile), true),
            var_export($projectRoot . '/users/init.php', true),
            $csrfSnippet,
            $loginSnippet,
            $enabledSnippet,
            var_export($endpointFile, true)
        );

        $raw = shell_exec('php -r ' . escapeshellarg($script) . ' 2>&1; echo "___EXIT:$?"');
        $raw = $raw !== null ? $raw : '';

        [$body, $exitMarker] = array_pad(explode('___EXIT:', $raw), 2, '0');
        $body = trim($body);

        $decoded = json_decode($body, true);

        return [
            'status'   => is_array($decoded) ? (bool) ($decoded['success'] ?? false) : false,
            'message'  => is_array($decoded) ? (string) ($decoded['message'] ?? '') : '',
            'exitCode' => (int) trim($exitMarker),
            'raw'      => $body,
        ];
    }

    // =========================================================================
    // Non-admin (editor) POST -> 403, setting unchanged
    // =========================================================================

    public function testEditorCannotToggleAndSettingStaysUnchanged(): void
    {
        $editorUserId = $this->createTestUser();
        $this->grantPermission($editorUserId, 3); // 3 = Editor per CLAUDE.md's role hierarchy

        $this->setSwitchEnabled(false);

        $result = $this->invokeToggleEndpoint($editorUserId, '1');

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertFalse($result['status'], 'Editor must not be able to toggle verification: ' . $result['raw']);
        $this->assertStringContainsString('Admin access required', $result['message']);
        $this->assertFalse($this->currentSwitchState(), 'Setting must remain unchanged after a rejected editor request');
    }

    public function testLoggedOutUserCannotToggle(): void
    {
        $this->setSwitchEnabled(false);

        $result = $this->invokeToggleEndpoint(null, '1');

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertFalse($result['status']);
        $this->assertStringContainsString('Admin access required', $result['message']);
        $this->assertFalse($this->currentSwitchState());
    }

    // =========================================================================
    // Admin POST enabling while brevoReady() false -> 422 naming the reason
    // =========================================================================

    /**
     * The integration test database never has Brevo configured (no
     * plg_sendinblue key, no override.php in this checkout) — brevoReady()
     * is false by construction in this environment, which is exactly the
     * state this test needs.
     */
    public function testAdminEnablingWhileBrevoNotReadyGetsValidationErrorNamingBrevo(): void
    {
        $adminUserId = $this->createTestUser();
        $this->grantPermission($adminUserId, 2); // 2 = Admin

        $this->setSwitchEnabled(false);

        $result = $this->invokeToggleEndpoint($adminUserId, '1');

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertFalse($result['status'], 'Enabling must be refused when Brevo is not configured: ' . $result['raw']);
        $this->assertStringContainsString('Brevo', $result['message'], 'The validation error must name Brevo as the failed prerequisite');
        $this->assertFalse($this->currentSwitchState(), 'Setting must remain unchanged after a rejected enable attempt');
    }

    // =========================================================================
    // Admin POST disabling while switch is on and Brevo is broken -> succeeds
    // (the critical non-inversion case, exercised at the HTTP layer)
    // =========================================================================

    public function testAdminCanDisableEvenWhileBrevoIsBroken(): void
    {
        $adminUserId = $this->createTestUser();
        $this->grantPermission($adminUserId, 2);

        // Force the switch on directly at the DB layer (bypassing the gate,
        // since the point of this test is that disabling must work
        // regardless of how the switch got turned on).
        $this->setSwitchEnabled(true);

        $result = $this->invokeToggleEndpoint($adminUserId, '0');

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertTrue(
            $result['status'],
            'Disabling verification must always succeed, even while Brevo is broken (non-invertible gate): ' . $result['raw']
        );
        $this->assertFalse($this->currentSwitchState(), 'Switch must actually be off after a successful disable');
    }

    public function testAdminCanEnableWhenBrevoIsReady(): void
    {
        // Simulate a ready Brevo by inserting a plg_sendinblue row with a
        // non-empty key and creating the override file at the real path for
        // the duration of this test only, with guaranteed cleanup.
        $overridePath = dirname(__DIR__, 2) . '/usersc/plugins/sendinblue/override.php';
        $overrideCreatedByThisTest = !file_exists($overridePath);

        $keyInsertedByThisTest = false;
        $existingKeyRow = $this->db->query('SELECT id, `key` FROM plg_sendinblue LIMIT 1')->first();

        try {
            if ($overrideCreatedByThisTest) {
                file_put_contents($overridePath, "<?php\n");
            }

            if (is_object($existingKeyRow) && isset($existingKeyRow->id)) {
                $this->db->query('UPDATE plg_sendinblue SET `key` = ? WHERE id = ?', ['sib-test-key', $existingKeyRow->id]);
            } else {
                $this->db->insert('plg_sendinblue', ['key' => 'sib-test-key']);
                $keyInsertedByThisTest = true;
            }

            $adminUserId = $this->createTestUser();
            $this->grantPermission($adminUserId, 2);
            $this->setSwitchEnabled(false);

            $result = $this->invokeToggleEndpoint($adminUserId, '1');

            $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
            $this->assertTrue($result['status'], 'Enabling must succeed when Brevo is fully ready: ' . $result['raw']);
            $this->assertTrue($this->currentSwitchState(), 'Switch must actually be on after a successful enable');
        } finally {
            if ($overrideCreatedByThisTest && file_exists($overridePath)) {
                unlink($overridePath);
            }
            if ($keyInsertedByThisTest) {
                $this->db->query('DELETE FROM plg_sendinblue WHERE `key` = ?', ['sib-test-key']);
            } elseif (is_object($existingKeyRow) && isset($existingKeyRow->id)) {
                $this->db->query('UPDATE plg_sendinblue SET `key` = ? WHERE id = ?', [$existingKeyRow->key, $existingKeyRow->id]);
            }
        }
    }

    // =========================================================================
    // Wrong-typed-value tests at the HTTP layer
    // =========================================================================

    /**
     * @return array<string, array{string|null}>
     */
    public static function wrongTypedEnabledProvider(): array
    {
        return [
            "'maybe' is rejected"      => ['maybe'],
            "'2' is rejected"          => ['2'],
            'omitted entirely'         => [null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('wrongTypedEnabledProvider')]
    public function testWrongTypedEnabledValueGetsValidationErrorNeverFatal(?string $badValue): void
    {
        $adminUserId = $this->createTestUser();
        $this->grantPermission($adminUserId, 2);
        $this->setSwitchEnabled(false);

        $result = $this->invokeToggleEndpoint($adminUserId, $badValue);

        $this->assertSame(0, $result['exitCode'], 'A malformed enabled value must never fatal the endpoint: ' . $result['raw']);
        $this->assertFalse($result['status'], 'A malformed enabled value must be rejected: ' . $result['raw']);
        $this->assertStringContainsString('Invalid value', $result['message']);
        $this->assertStringNotContainsStringIgnoringCase('fatal error', $result['raw']);
        $this->assertStringNotContainsStringIgnoringCase('TypeError', $result['raw']);
        $this->assertFalse($this->currentSwitchState(), 'Setting must remain unchanged after a rejected malformed request');
    }

    public function testArrayShapedEnabledValueGetsValidationErrorNeverFatal(): void
    {
        // enabled[]=1 arrives as $_POST['enabled'] = ['1'] — Input::raw()
        // returns null for array values, so this must land in the same
        // validation-error branch as any other malformed value.
        $adminUserId = $this->createTestUser();
        $this->grantPermission($adminUserId, 2);
        $this->setSwitchEnabled(false);

        $endpointFile = dirname(__DIR__, 2) . '/app/api/admin/verification-toggle.php';
        $projectRoot = dirname(__DIR__, 2);
        $this->exposeTestDatabaseToSubprocess();

        $script = sprintf(
            'require %s; ' .
            '\Dotenv\Dotenv::createMutable(%s, ".env.test.local")->load(); ' .
            '$_SERVER["DOCUMENT_ROOT"] = %s; ' .
            '$_SERVER["PHP_SELF"] = "/app/api/admin/verification-toggle.php"; ' .
            '$_SERVER["REQUEST_METHOD"] = "POST"; ' .
            'chdir(%s); ' .
            'require_once %s; ' .
            '$_POST["csrf"] = \Token::generate(); ' .
            '$loggedInUser = new User(); $loggedInUser->find(%d); ' .
            '$reflection = new ReflectionClass($loggedInUser); ' .
            '$prop = $reflection->getProperty("_isLoggedIn"); $prop->setValue($loggedInUser, true); ' .
            'global $user; $user = $loggedInUser; $GLOBALS["user"] = $loggedInUser; ' .
            '$_POST["enabled"] = ["1"]; ' .
            'require %s;',
            var_export($projectRoot . '/vendor/autoload.php', true),
            var_export($projectRoot, true),
            var_export($projectRoot, true),
            var_export(dirname($endpointFile), true),
            var_export($projectRoot . '/users/init.php', true),
            $adminUserId,
            var_export($endpointFile, true)
        );

        $raw = shell_exec('php -r ' . escapeshellarg($script) . ' 2>&1; echo "___EXIT:$?"');
        $raw = $raw !== null ? $raw : '';
        [$body, $exitMarker] = array_pad(explode('___EXIT:', $raw), 2, '0');
        $body = trim($body);
        $decoded = json_decode($body, true);

        $this->assertSame(0, (int) trim($exitMarker), 'A malformed array enabled value must never fatal the endpoint: ' . $body);
        $this->assertIsArray($decoded, 'Response must be valid JSON: ' . $body);
        $this->assertFalse((bool) ($decoded['success'] ?? true), 'An array-shaped enabled value must be rejected: ' . $body);
        $this->assertStringNotContainsStringIgnoringCase('fatal error', $body);
        $this->assertStringNotContainsStringIgnoringCase('TypeError', $body);
        $this->assertFalse($this->currentSwitchState());
    }

    // =========================================================================
    // CSRF
    // =========================================================================

    public function testInvalidCsrfTokenRejectedBeforeAdminCheck(): void
    {
        $adminUserId = $this->createTestUser();
        $this->grantPermission($adminUserId, 2);
        $this->setSwitchEnabled(false);

        $result = $this->invokeToggleEndpoint($adminUserId, '1', withValidCsrf: false);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertFalse($result['status']);
        $this->assertStringContainsString('Invalid request token', $result['message']);
        $this->assertFalse($this->currentSwitchState());
    }
}
