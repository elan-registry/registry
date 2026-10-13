<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1926: app/api/admin/verification-toggle.php at the HTTP layer. The key
 * case: disabling must succeed while Brevo is broken.
 *
 * Each request runs in its own `php -r` subprocess because
 * ApiResponse::send() calls exit.
 */
#[Group('integration')]
final class VerificationToggleEndpointBehaviorTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            // Restore the safe default so no later test sees "enabled".
            $this->db->query('UPDATE er_verification_settings SET enabled = 0 WHERE id = 1');
            if ($this->db->error()) {
                fwrite(STDERR, "NOTE: tearDown() failed to reset er_verification_settings.enabled: {$this->db->errorString()}\n");
            }
        }

        parent::tearDown();
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
        $this->assertFalse($this->db->error(), 'Failed to read er_verification_settings.enabled: ' . $this->db->errorString());
        // first() returns [] when no row matches. A cast would read that as "disabled".
        $this->assertNotEmpty($row, 'er_verification_settings must have the row with id = 1');
        return (bool) $row->enabled;
    }

    /**
     * @param string|null $enabledValue Raw POST 'enabled' value, or null to omit it entirely.
     * @return array{status: bool, message: string, httpCode: int|null, exitCode: int, raw: string, json: array<string, mixed>|null}
     */
    private function invokeToggleEndpoint(?int $loggedInUserId, ?string $enabledValue, bool $withValidCsrf = true): array
    {
        return $this->invokeEndpoint(
            'app/api/admin/verification-toggle.php',
            $enabledValue === null ? [] : ['enabled' => $enabledValue],
            $loggedInUserId,
            $withValidCsrf ? 'valid' : 'invalid'
        );
    }

    // =========================================================================
    // Non-admin (editor) POST -> 403, setting unchanged
    // =========================================================================

    public function testEditorCannotToggleAndSettingStaysUnchanged(): void
    {
        $editorUserId = $this->createTestUser();
        $this->grantTestPermission($editorUserId, 3); // 3 = Editor per CLAUDE.md's role hierarchy

        $this->setSwitchEnabled(false);

        $result = $this->invokeToggleEndpoint($editorUserId, '1');

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(403, $result['httpCode'], $result['raw']);
        $this->assertFalse($result['status'], 'Editor must not be able to toggle verification: ' . $result['raw']);
        $this->assertStringContainsString('Admin access required', $result['message']);
        $this->assertFalse($this->currentSwitchState(), 'Setting must remain unchanged after a rejected editor request');
    }

    public function testLoggedOutUserCannotToggle(): void
    {
        $this->setSwitchEnabled(false);

        $result = $this->invokeToggleEndpoint(null, '1');

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(403, $result['httpCode'], $result['raw']);
        $this->assertFalse($result['status']);
        $this->assertStringContainsString('Admin access required', $result['message']);
        $this->assertFalse($this->currentSwitchState());
    }

    // =========================================================================
    // Admin POST enabling while brevoReady() false -> 422 naming the reason
    // =========================================================================

    public function testAdminEnablingWhileBrevoNotReadyGetsValidationErrorNamingBrevo(): void
    {
        $adminUserId = $this->createTestUser();
        $this->grantTestPermission($adminUserId, 2); // 2 = Admin

        $this->setSwitchEnabled(false);

        // An empty API key makes Brevo not ready on every checkout, also one
        // with ready plugin files. The finally block restores the key.
        $existingKeyRow = $this->db->query('SELECT id, `key` FROM plg_sendinblue LIMIT 1')->first();
        $hasKeyRow = is_object($existingKeyRow) && isset($existingKeyRow->id);
        if ($hasKeyRow) {
            $this->db->query('UPDATE plg_sendinblue SET `key` = ? WHERE id = ?', ['', $existingKeyRow->id]);
            $this->assertFalse($this->db->error(), 'Brevo key fixture write failed: ' . $this->db->errorString());
        }

        try {
            $this->assertNotNull(
                (new VerificationSettings($this->db))->brevoNotReadyReason(),
                'Brevo must be not ready for this test'
            );

            $result = $this->invokeToggleEndpoint($adminUserId, '1');

            $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
            $this->assertSame(422, $result['httpCode'], $result['raw']);
            $this->assertFalse($result['status'], 'Enabling must be refused when Brevo is not ready: ' . $result['raw']);
            $this->assertStringContainsString('Brevo', $result['message'], 'The validation error must name Brevo as the failed prerequisite');
            $this->assertFalse($this->currentSwitchState(), 'Setting must remain unchanged after a rejected enable attempt');
        } finally {
            if ($hasKeyRow) {
                // Report, do not assert: an assert in finally would hide the test's own failure.
                $this->db->query('UPDATE plg_sendinblue SET `key` = ? WHERE id = ?', [$existingKeyRow->key, $existingKeyRow->id]);
                if ($this->db->error()) {
                    fwrite(STDERR, 'Restoring the Brevo key failed: ' . $this->db->errorString() . PHP_EOL);
                }
            }
        }
    }

    // =========================================================================
    // Admin POST disabling while switch is on and Brevo is broken -> succeeds
    // (the critical non-inversion case, exercised at the HTTP layer)
    // =========================================================================

    public function testAdminCanDisableEvenWhileBrevoIsBroken(): void
    {
        $adminUserId = $this->createTestUser();
        $this->grantTestPermission($adminUserId, 2);

        // Force the switch on directly, bypassing the gate.
        $this->setSwitchEnabled(true);

        $result = $this->invokeToggleEndpoint($adminUserId, '0');

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(200, $result['httpCode'], $result['raw']);
        $this->assertTrue(
            $result['status'],
            'Disabling verification must always succeed, even while Brevo is broken (non-invertible gate): ' . $result['raw']
        );
        $this->assertFalse($this->currentSwitchState(), 'Switch must actually be off after a successful disable');
    }

    public function testAdminCanEnableWhenBrevoIsReady(): void
    {
        // The test saves an API key. The other readiness checks use the real
        // plugin files on this checkout (see docs/development/ENVIRONMENT.md,
        // "Brevo plugin local setup"). The finally block restores the key row.
        $keyInsertedByThisTest = false;
        $existingKeyRow = $this->db->query('SELECT id, `key` FROM plg_sendinblue LIMIT 1')->first();

        try {
            if (is_object($existingKeyRow) && isset($existingKeyRow->id)) {
                $this->db->query('UPDATE plg_sendinblue SET `key` = ? WHERE id = ?', ['sib-test-key', $existingKeyRow->id]);
            } else {
                $this->db->insert('plg_sendinblue', ['key' => 'sib-test-key']);
                $keyInsertedByThisTest = true;
            }
            $this->assertFalse($this->db->error(), 'Brevo key fixture write failed: ' . $this->db->errorString());

            $brevoNotReadyReason = (new VerificationSettings($this->db))->brevoNotReadyReason();
            if ($brevoNotReadyReason !== null) {
                // A fail, not a skip: a skip would hide a bug that blocks enable.
                $this->fail(
                    'Brevo is not ready on this checkout (see ENVIRONMENT.md, "Brevo plugin local setup"): '
                        . $brevoNotReadyReason
                );
            }

            $adminUserId = $this->createTestUser();
            $this->grantTestPermission($adminUserId, 2);
            $this->setSwitchEnabled(false);

            $result = $this->invokeToggleEndpoint($adminUserId, '1');

            $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
            $this->assertSame(200, $result['httpCode'], $result['raw']);
            $this->assertTrue($result['status'], 'Enabling must succeed when Brevo is fully ready: ' . $result['raw']);
            $this->assertTrue($this->currentSwitchState(), 'Switch must actually be on after a successful enable');
        } finally {
            // Report, do not assert: an assert in finally would hide the test's own failure.
            if ($keyInsertedByThisTest) {
                $this->db->query('DELETE FROM plg_sendinblue WHERE `key` = ?', ['sib-test-key']);
                if ($this->db->error()) {
                    fwrite(STDERR, 'Deleting the Brevo test key failed: ' . $this->db->errorString() . PHP_EOL);
                }
            } elseif (is_object($existingKeyRow) && isset($existingKeyRow->id)) {
                $this->db->query('UPDATE plg_sendinblue SET `key` = ? WHERE id = ?', [$existingKeyRow->key, $existingKeyRow->id]);
                if ($this->db->error()) {
                    fwrite(STDERR, 'Restoring the Brevo key failed: ' . $this->db->errorString() . PHP_EOL);
                }
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
        $this->grantTestPermission($adminUserId, 2);
        $this->setSwitchEnabled(false);

        $result = $this->invokeToggleEndpoint($adminUserId, $badValue);

        $this->assertSame(0, $result['exitCode'], 'A malformed enabled value must never fatal the endpoint: ' . $result['raw']);
        $this->assertSame(422, $result['httpCode'], $result['raw']);
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
        $this->grantTestPermission($adminUserId, 2);
        $this->setSwitchEnabled(false);

        $result = $this->invokeEndpoint('app/api/admin/verification-toggle.php', ['enabled' => ['1']], $adminUserId);

        $this->assertSame(0, $result['exitCode'], 'A malformed array enabled value must never fatal the endpoint: ' . $result['raw']);
        $this->assertSame(422, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertFalse($result['status'], 'An array-shaped enabled value must be rejected: ' . $result['raw']);
        $this->assertStringNotContainsStringIgnoringCase('fatal error', $result['raw']);
        $this->assertStringNotContainsStringIgnoringCase('TypeError', $result['raw']);
        $this->assertFalse($this->currentSwitchState());
    }

    // =========================================================================
    // CSRF
    // =========================================================================

    public function testInvalidCsrfTokenRejectedBeforeAdminCheck(): void
    {
        $adminUserId = $this->createTestUser();
        $this->grantTestPermission($adminUserId, 2);
        $this->setSwitchEnabled(false);

        $result = $this->invokeToggleEndpoint($adminUserId, '1', withValidCsrf: false);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(400, $result['httpCode'], $result['raw']);
        $this->assertFalse($result['status']);
        $this->assertStringContainsString('Invalid request token', $result['message']);
        $this->assertFalse($this->currentSwitchState());
    }
}
