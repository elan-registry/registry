<?php

declare(strict_types=1);

use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\Exceptions\VerificationConfigException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\VerificationSettingsFakeDatabase;

require_once __DIR__ . '/../../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../../Support/VerificationSettingsFakeDatabase.php';

/**
 * Unit tests for VerificationSettings — the feature switch and readiness
 * probes for the car verification system (#1926).
 *
 * `brevoReady()`'s override-file check calls the real `file_exists()` against
 * a path built from the `$abs_us_root`/`$us_url_root` globals — a filesystem
 * boundary that cannot be mocked through the DatabaseInterface double. Rather
 * than reach for a namespaced `file_exists()` override (which would only
 * intercept calls made from within the ElanRegistry\Car namespace — which
 * VerificationSettings::brevoReady() is, so this would actually work, but the
 * plan calls this out as a judgment call and the simpler, more realistic
 * option is used instead), the brevoReady()-dependent tests exercise the real
 * filesystem: they point $abs_us_root/$us_url_root at a throwaway temp
 * directory and create/delete the override file there for the duration of
 * each test, with guaranteed cleanup in tearDown(). This is the same strategy
 * the implementing agent used to verify the class against the real dev DB
 * (see the plan file's Test Plan section).
 */
#[Group('fast')]
final class VerificationSettingsTest extends TestCase
{
    /** @var string|false Snapshot of the ambient $abs_us_root global, restored in tearDown(). */
    private string|false $originalAbsUsRoot = false;

    /** @var string|false Snapshot of the ambient $us_url_root global, restored in tearDown(). */
    private string|false $originalUsUrlRoot = false;

    /** Throwaway directory standing in for the site root for file_exists() checks. */
    private ?string $tempRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        global $abs_us_root, $us_url_root, $mockLogEntries;
        $this->originalAbsUsRoot = $abs_us_root ?? false;
        $this->originalUsUrlRoot = $us_url_root ?? false;
        $mockLogEntries = [];
    }

    protected function tearDown(): void
    {
        global $abs_us_root, $us_url_root;
        $abs_us_root = $this->originalAbsUsRoot === false ? null : $this->originalAbsUsRoot;
        $us_url_root = $this->originalUsUrlRoot === false ? null : $this->originalUsUrlRoot;

        if ($this->tempRoot !== null && is_dir($this->tempRoot)) {
            $overrideDir = $this->tempRoot . '/usersc/plugins/sendinblue';
            $overrideFile = $overrideDir . '/override.php';
            if (file_exists($overrideFile)) {
                unlink($overrideFile);
            }
            if (is_dir($overrideDir)) {
                rmdir($overrideDir);
            }
            if (is_dir($this->tempRoot . '/usersc/plugins')) {
                rmdir($this->tempRoot . '/usersc/plugins');
            }
            if (is_dir($this->tempRoot . '/usersc')) {
                rmdir($this->tempRoot . '/usersc');
            }
            rmdir($this->tempRoot);
        }

        parent::tearDown();
    }

    /**
     * Points $abs_us_root/$us_url_root at a fresh temp directory (so
     * brevoReady()'s file_exists() check is against a controlled location)
     * and optionally creates the override file there.
     */
    private function useTempSiteRoot(bool $withOverrideFile): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/verification_settings_test_' . uniqid('', true);
        $overrideDir = $this->tempRoot . '/usersc/plugins/sendinblue';
        mkdir($overrideDir, 0777, true);

        if ($withOverrideFile) {
            file_put_contents($overrideDir . '/override.php', "<?php\n");
        }

        global $abs_us_root, $us_url_root;
        $abs_us_root = $this->tempRoot . '/';
        $us_url_root = '';
    }

    // =========================================================================
    // isEnabled()
    // =========================================================================

    public function testIsEnabledReturnsFalseByDefault(): void
    {
        $db = new VerificationSettingsFakeDatabase(firstRowValue: (object) ['enabled' => 0]);

        $this->assertFalse((new VerificationSettings($db))->isEnabled());
    }

    public function testIsEnabledReturnsTrueWhenRowEnabled(): void
    {
        $db = new VerificationSettingsFakeDatabase(firstRowValue: (object) ['enabled' => 1]);

        $this->assertTrue((new VerificationSettings($db))->isEnabled());
    }

    /**
     * A missing settings row is a schema problem (the migration seeds id=1 and
     * nothing deletes it), not a deliberate "off" — isEnabled() now logs a
     * VerificationConfigWarning line in this case rather than staying silent,
     * so an admin isn't left guessing why enabling keeps getting rejected.
     */
    public function testIsEnabledReturnsFalseWhenNoRow(): void
    {
        global $mockLogEntries;

        // Default firstRowValue is [] — the real \DB "no rows" value.
        $db = new VerificationSettingsFakeDatabase();

        $this->assertFalse((new VerificationSettings($db))->isEnabled());

        $this->assertCount(1, $mockLogEntries, 'A missing settings row must be logged, not silent');
        $this->assertSame(LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('missing', $mockLogEntries[0]['message']);
    }

    public function testIsEnabledReturnsFalseOnQueryError(): void
    {
        global $mockLogEntries;

        $db = new VerificationSettingsFakeDatabase(queryErrors: true);

        $this->assertFalse((new VerificationSettings($db))->isEnabled());

        $this->assertCount(1, $mockLogEntries, 'A failed read must be logged');
        $this->assertSame(LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, $mockLogEntries[0]['category']);
    }

    // =========================================================================
    // setEnabled() — the asymmetric gate
    // =========================================================================

    public function testSetEnabledTrueSucceedsWhenBrevoReady(): void
    {
        $this->useTempSiteRoot(withOverrideFile: true);

        // plg_sendinblue row with a non-empty key
        $db = new VerificationSettingsFakeDatabase(firstRowValue: (object) ['key' => 'sib-fake-api-key']);

        $settings = new VerificationSettings($db);
        $this->assertTrue($settings->setEnabled(true));
        $this->assertTrue($db->wasUpdateCalled());
    }

    /**
     * setEnabled()'s $actingUserId parameter must reach logger() — it exists
     * specifically so the success/failure log lines are attributed to the
     * admin who made the change, not hardcoded to 0/system.
     */
    public function testSetEnabledPassesActingUserIdThroughToSuccessLog(): void
    {
        global $mockLogEntries;
        $this->useTempSiteRoot(withOverrideFile: true);

        $db = new VerificationSettingsFakeDatabase(firstRowValue: (object) ['key' => 'sib-fake-api-key']);

        $this->assertTrue((new VerificationSettings($db))->setEnabled(true, 42));

        $changed = array_values(array_filter(
            $mockLogEntries,
            static fn (array $entry): bool => $entry['category'] === LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_CHANGED
        ));
        $this->assertCount(1, $changed, 'setEnabled() must log exactly one success line under LOG_CATEGORY_VERIFICATION_CONFIG_CHANGED');
        $this->assertSame(42, $changed[0]['user_id'], 'The acting user id must be passed through to the success log');
    }

    /**
     * The success log line uses the new, distinct LOG_CATEGORY_VERIFICATION_CONFIG_CHANGED
     * category — kept separate from LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, which is now
     * reserved for failure/refusal paths only.
     */
    public function testSetEnabledLogsSuccessUnderChangedCategoryNotWarningCategory(): void
    {
        global $mockLogEntries;
        $this->useTempSiteRoot(withOverrideFile: true);

        $db = new VerificationSettingsFakeDatabase(firstRowValue: (object) ['key' => 'sib-fake-api-key']);

        $this->assertTrue((new VerificationSettings($db))->setEnabled(true, 7));

        $warnings = array_filter(
            $mockLogEntries,
            static fn (array $entry): bool => $entry['category'] === LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING
        );
        $this->assertSame([], $warnings, 'A successful setEnabled() must not log under the warning category');
    }

    /**
     * Regression test for the exact bug this review round fixed: setEnabled()
     * used to treat PDO's rowCount()-derived count() === 0 after the UPDATE as
     * "row missing", but rowCount() reports rows CHANGED, not rows MATCHED —
     * so re-setting a value the row already has legitimately yields count() === 0
     * and would have false-positived as a failure. The fix replaces that check
     * with a follow-up `SELECT id ...` that only fails if the row is truly gone.
     * Calling setEnabled() with the value it already has must still return true.
     */
    public function testSetEnabledSucceedsWhenReSettingTheSameValueItAlreadyHas(): void
    {
        // Disabling never gates on Brevo, so this exercises the confirm-SELECT
        // path directly without needing a ready Brevo. The fake DB's default
        // confirmSelectRowValue is null, which falls back to answering the
        // confirmation SELECT the same as any other query — firstRowValue
        // default [] would look like "row missing", so it must be pinned
        // explicitly to a present row via $confirmSelectRowValue.
        $db = new VerificationSettingsFakeDatabase(
            confirmSelectRowValue: (object) ['id' => 1],
        );

        $settings = new VerificationSettings($db);

        // setEnabled(false) when it is already false — the exact re-set-same-value scenario.
        $this->assertTrue($settings->setEnabled(false), 'Re-setting the same value must still report success');
        $this->assertTrue($db->wasUpdateCalled());
    }

    /**
     * Same regression, but on the enable path, where the confirmation SELECT
     * runs after brevoReady() has already succeeded.
     */
    public function testSetEnabledTrueSucceedsWhenReSettingTheSameValueItAlreadyHas(): void
    {
        $this->useTempSiteRoot(withOverrideFile: true);

        $db = new VerificationSettingsFakeDatabase(
            firstRowValue: (object) ['key' => 'sib-fake-api-key'],
            confirmSelectRowValue: (object) ['id' => 1],
        );

        $this->assertTrue((new VerificationSettings($db))->setEnabled(true), 'Re-enabling when already enabled must still report success');
    }

    /**
     * The inverse of the regression test above: when the confirmation SELECT
     * genuinely finds no row (the settings row really is missing), setEnabled()
     * must still report failure.
     */
    public function testSetEnabledReturnsFalseWhenConfirmationSelectFindsNoRow(): void
    {
        $db = new VerificationSettingsFakeDatabase(
            confirmSelectRowValue: [],
        );

        $this->assertFalse((new VerificationSettings($db))->setEnabled(false));
    }

    /**
     * And when the confirmation SELECT itself errors (e.g. connection dropped
     * between the UPDATE and the SELECT), setEnabled() must report failure too.
     */
    public function testSetEnabledReturnsFalseWhenConfirmationSelectErrors(): void
    {
        $db = new VerificationSettingsFakeDatabase(
            confirmSelectRowValue: (object) ['id' => 1],
            confirmSelectErrors: true,
        );

        $this->assertFalse((new VerificationSettings($db))->setEnabled(false));
    }

    public function testSetEnabledTrueThrowsWhenBrevoNotReadyAndDoesNotWrite(): void
    {
        $this->useTempSiteRoot(withOverrideFile: false);

        // No plg_sendinblue key configured.
        $db = new VerificationSettingsFakeDatabase(firstRowValue: (object) ['key' => '']);

        $settings = new VerificationSettings($db);

        try {
            $settings->setEnabled(true);
            $this->fail('Expected VerificationConfigException');
        } catch (VerificationConfigException) {
            // Only brevoReady()'s own SELECT should have run — no UPDATE
            // statement should ever have been issued.
            $this->assertFalse($db->wasUpdateCalled(), 'setEnabled(true) must not write when the gate rejects the enable');
        }
    }

    public function testSetEnabledTrueThrowsVerificationConfigExceptionWithExpectedMessage(): void
    {
        $this->useTempSiteRoot(withOverrideFile: false);

        $db = new VerificationSettingsFakeDatabase(firstRowValue: (object) ['key' => '']);

        $this->expectException(VerificationConfigException::class);
        $this->expectExceptionMessageMatches('/Brevo/i');

        (new VerificationSettings($db))->setEnabled(true);
    }

    /**
     * The single most important test in this suite: setEnabled(false) must
     * always succeed, regardless of brevoReady() — an admin must always be
     * able to turn verification off, including mid-incident. This is an
     * explicit regression test for the must-never-invert gating rule.
     */
    public function testSetEnabledFalseAlwaysSucceedsRegardlessOfBrevoReadiness(): void
    {
        // Brevo definitively NOT ready: no override file, and (belt and
        // braces) a DB double that tracks whether plg_sendinblue was ever
        // queried at all.
        $this->useTempSiteRoot(withOverrideFile: false);

        $db = new VerificationSettingsFakeDatabase();

        $settings = new VerificationSettings($db);
        $this->assertTrue($settings->setEnabled(false));
        $this->assertFalse($db->wasBrevoTableQueried(), 'setEnabled(false) must never consult brevoReady()');
    }

    public function testSetEnabledFalseSucceedsEvenWithoutAnySiteRootConfigured(): void
    {
        // No useTempSiteRoot() call at all — $abs_us_root/$us_url_root are
        // whatever ambient bootstrap left them. If setEnabled(false) ever
        // started consulting brevoReady(), this would be the test most
        // likely to catch it exploding on a bad file_exists() path.
        $db = new VerificationSettingsFakeDatabase();

        $this->assertTrue((new VerificationSettings($db))->setEnabled(false));
    }

    public function testSetEnabledReturnsFalseWhenUpdateFails(): void
    {
        $this->useTempSiteRoot(withOverrideFile: true);

        // brevoReady()'s SELECT succeeds (key present); the write itself fails.
        $db = new VerificationSettingsFakeDatabase(
            firstRowValue: (object) ['key' => 'sib-fake-api-key'],
            errorAfterUpdateOnly: true,
        );

        $this->assertFalse((new VerificationSettings($db))->setEnabled(true));
    }

    // =========================================================================
    // brevoReady()
    // =========================================================================

    public function testBrevoReadyFalseWhenKeyEmptyAndOverridePresent(): void
    {
        $this->useTempSiteRoot(withOverrideFile: true);

        $db = new VerificationSettingsFakeDatabase(firstRowValue: (object) ['key' => '']);

        $this->assertFalse((new VerificationSettings($db))->brevoReady());
    }

    public function testBrevoReadyFalseWhenKeyPresentAndOverrideMissing(): void
    {
        $this->useTempSiteRoot(withOverrideFile: false);

        $db = new VerificationSettingsFakeDatabase(firstRowValue: (object) ['key' => 'sib-fake-api-key']);

        $this->assertFalse((new VerificationSettings($db))->brevoReady());
    }

    public function testBrevoReadyFalseWhenBothMissing(): void
    {
        $this->useTempSiteRoot(withOverrideFile: false);

        $db = new VerificationSettingsFakeDatabase(firstRowValue: (object) ['key' => '']);

        $this->assertFalse((new VerificationSettings($db))->brevoReady());
    }

    public function testBrevoReadyTrueOnlyWhenBothHold(): void
    {
        $this->useTempSiteRoot(withOverrideFile: true);

        $db = new VerificationSettingsFakeDatabase(firstRowValue: (object) ['key' => 'sib-fake-api-key']);

        $this->assertTrue((new VerificationSettings($db))->brevoReady());
    }

    /**
     * SQLSTATE 42S02 ("table missing") is the expected, non-alarming shape of
     * failure here — the sendinblue plugin was simply never installed. Default
     * errorInfoValue reporting is a *non*-42S02 triple, so pin it explicitly to
     * assert the "expected" branch's log wording.
     */
    public function testBrevoReadyFalseWhenPluginTableMissing(): void
    {
        global $mockLogEntries;
        $this->useTempSiteRoot(withOverrideFile: true);

        $db = new VerificationSettingsFakeDatabase(
            queryErrors: true,
            errorInfoValue: ['42S02', 1146, "Table 'test.plg_sendinblue' doesn't exist"],
        );

        $this->assertFalse((new VerificationSettings($db))->brevoReady());

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('not installed', $mockLogEntries[0]['message']);
    }

    /**
     * Any OTHER SQLSTATE (connection lost, grants revoked, lock timeout, ...)
     * is a genuine fault, distinguished in the log message from the expected
     * "table missing" case so an admin isn't told to double-check a key/override
     * that may already be correct.
     */
    public function testBrevoReadyFalseAndLogsGenuineFaultWhenErrorIsNotTableMissing(): void
    {
        global $mockLogEntries;
        $this->useTempSiteRoot(withOverrideFile: true);

        $db = new VerificationSettingsFakeDatabase(
            queryErrors: true,
            errorInfoValue: ['HY000', 2006, 'MySQL server has gone away'],
        );

        $this->assertFalse((new VerificationSettings($db))->brevoReady());

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('FAILED', $mockLogEntries[0]['message']);
        $this->assertStringContainsString('may in fact be configured', $mockLogEntries[0]['message']);
    }

    // =========================================================================
    // cronReady() — strict "< 20 minutes" boundary
    // =========================================================================

    public function testCronReadyTrueWithinTwentyMinutes(): void
    {
        $recentTimestamp = (new DateTimeImmutable('-19 minutes -59 seconds'))->format('Y-m-d H:i:s');

        $db = new VerificationSettingsFakeDatabase(cronRequestRowValue: (object) ['last_cron_request_at' => $recentTimestamp]);

        $this->assertTrue((new VerificationSettings($db))->cronReady());
    }

    public function testCronReadyFalseAtExactlyTwentyMinutesElapsed(): void
    {
        // Strict `<` boundary per the class docblock: exactly 20:00 elapsed
        // counts as stalled, not ready.
        $exactlyTwentyMinutesAgo = (new DateTimeImmutable('-20 minutes'))->format('Y-m-d H:i:s');

        $db = new VerificationSettingsFakeDatabase(cronRequestRowValue: (object) ['last_cron_request_at' => $exactlyTwentyMinutesAgo]);

        $this->assertFalse((new VerificationSettings($db))->cronReady());
    }

    public function testCronReadyFalseBeyondTwentyMinutes(): void
    {
        $staleTimestamp = (new DateTimeImmutable('-25 minutes'))->format('Y-m-d H:i:s');

        $db = new VerificationSettingsFakeDatabase(cronRequestRowValue: (object) ['last_cron_request_at' => $staleTimestamp]);

        $this->assertFalse((new VerificationSettings($db))->cronReady());
    }

    public function testCronReadyFalseWhenNoCronHistory(): void
    {
        // Default cronRequestRowValue is [] — no matching row (column NULL / never run).
        $db = new VerificationSettingsFakeDatabase();

        $this->assertFalse((new VerificationSettings($db))->cronReady());
    }

    public function testCronStaleAfterSecondsDerivesToTwentyMinutes(): void
    {
        // Pins #2001's value-preserving invariant directly: the shared
        // CRON_TRANSPORT_INTERVAL_MINUTES constant (10, per tests/bootstrap-unit.php)
        // doubled for slack must still equal 1200 seconds — the same 20-minute
        // boundary the tests above pin behaviorally. This catches a regression in
        // the derivation formula itself (e.g. dropping the `* 2` slack multiplier),
        // which those tests cannot: they run against cronReady()'s comparison
        // outcome, not the derived value it compares against.
        $db = new VerificationSettingsFakeDatabase();
        $method = new \ReflectionMethod(VerificationSettings::class, 'cronStaleAfterSeconds');

        $this->assertSame(1200, $method->invoke(new VerificationSettings($db)));
    }

    // =========================================================================
    // lastCronRequestAt() — reads er_verification_settings.last_cron_request_at (#1974)
    // =========================================================================

    public function testLastCronRequestAtReturnsNullWhenNoRowReturned(): void
    {
        // Default cronRequestRowValue is [] — the real \DB empty-result shape
        // for "no matching row," which is how a NULL column value in a
        // matched row also presents through empty(): both collapse to the
        // same "cron has never run" outcome at this class's isEmpty() check.
        $db = new VerificationSettingsFakeDatabase();

        $this->assertNull((new VerificationSettings($db))->lastCronRequestAt());
    }

    public function testLastCronRequestAtReturnsCorrectDateTimeImmutable(): void
    {
        $expected = '2026-09-01 12:34:56';

        $db = new VerificationSettingsFakeDatabase(cronRequestRowValue: (object) ['last_cron_request_at' => $expected]);

        $result = (new VerificationSettings($db))->lastCronRequestAt();

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame($expected, $result->format('Y-m-d H:i:s'));
    }

    /**
     * MySQL's zero-date (`0000-00-00 00:00:00`) is the realistic garbage value
     * for a `datetime` column — a known MySQL foot-gun distinct from an
     * arbitrary malformed string — so it gets its own case rather than only
     * testing an unrelated bad string.
     */
    public function testLastCronRequestAtReturnsNullWhenColumnIsZeroDate(): void
    {
        global $mockLogEntries;

        $db = new VerificationSettingsFakeDatabase(cronRequestRowValue: (object) ['last_cron_request_at' => '0000-00-00 00:00:00']);

        $this->assertNull((new VerificationSettings($db))->lastCronRequestAt());

        $warnings = array_values(array_filter(
            $mockLogEntries,
            static fn (array $entry): bool => $entry['category'] === LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING
        ));
        $this->assertCount(1, $warnings, 'An unparseable column value must be logged, not silently swallowed');
        $this->assertStringContainsString('Unparseable', $warnings[0]['message']);
    }

    public function testLastCronRequestAtReturnsNullWhenColumnUnparseable(): void
    {
        global $mockLogEntries;

        $db = new VerificationSettingsFakeDatabase(cronRequestRowValue: (object) ['last_cron_request_at' => 'not-a-real-date']);

        $this->assertNull((new VerificationSettings($db))->lastCronRequestAt());

        $warnings = array_values(array_filter(
            $mockLogEntries,
            static fn (array $entry): bool => $entry['category'] === LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING
        ));
        $this->assertCount(1, $warnings, 'An unparseable column value must be logged, not silently swallowed');
        $this->assertStringContainsString('Unparseable', $warnings[0]['message']);
    }

    public function testLastCronRequestAtReturnsNullOnQueryError(): void
    {
        $db = new VerificationSettingsFakeDatabase(cronRequestQueryErrors: true);

        $this->assertNull((new VerificationSettings($db))->lastCronRequestAt());
    }

    // =========================================================================
    // recordCronRequest() (#1974) — replaces cron.php's unconditional log line
    // =========================================================================

    public function testRecordCronRequestReturnsTrueOnSuccess(): void
    {
        $db = new VerificationSettingsFakeDatabase();

        $this->assertTrue((new VerificationSettings($db))->recordCronRequest());
        $this->assertTrue($db->wasCronRequestUpdateCalled());
    }

    public function testRecordCronRequestReturnsFalseAndLogsOnDatabaseError(): void
    {
        global $mockLogEntries;

        $db = new VerificationSettingsFakeDatabase(cronRequestUpdateErrors: true);

        $this->assertFalse((new VerificationSettings($db))->recordCronRequest());

        $this->assertCount(1, $mockLogEntries);
        // Logged under VERIFICATION_CONFIG_WARNING, not CRON_REQUEST — this is a
        // bookkeeping-write failure in the verification subsystem, matching every
        // other write-failure log in this class, not cron transport noise.
        $this->assertSame(LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('Failed to record cron request', $mockLogEntries[0]['message']);
    }

    public function testRecordCronRequestReturnsFalseAndLogsWhenSettingsRowMissing(): void
    {
        global $mockLogEntries;

        $db = new VerificationSettingsFakeDatabase(cronRequestUpdateCount: 0);

        $this->assertFalse((new VerificationSettings($db))->recordCronRequest());

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('not found', $mockLogEntries[0]['message']);
    }

    // =========================================================================
    // toggleShouldBeDisabled() — the admin tab checkbox's `disabled` gate
    // =========================================================================

    /**
     * Exhaustive truth table for the admin tab's `$vsToggleDisabled`
     * computation, extracted from tab-verification.php into
     * VerificationSettings::toggleShouldBeDisabled() specifically so this
     * table can be unit-tested (flagged as the single highest-value missing
     * test in #1926's review round).
     *
     * The critical, easy-to-accidentally-break case is row
     * "canToggle=true, isEnabled=true, brevoReady=false" (an admin trying to
     * turn OFF verification while Brevo is broken): the result MUST be false
     * (not disabled). Dropping the `!$isEnabled &&` conjunct from the
     * expression would silently re-introduce the exact incident this whole
     * feature exists to prevent.
     *
     * @return array<string, array{bool, bool, bool, bool}> [canToggle, isEnabled, brevoReady, expectedDisabled]
     */
    public static function toggleShouldBeDisabledProvider(): array
    {
        return [
            'no permission, enabled, brevo ready'         => [false, true,  true,  true],
            'no permission, enabled, brevo not ready'     => [false, true,  false, true],
            'no permission, disabled, brevo ready'        => [false, false, true,  true],
            'no permission, disabled, brevo not ready'    => [false, false, false, true],
            'can toggle, enabled, brevo ready'            => [true,  true,  true,  false],
            // The critical non-inversion case: must always be able to turn OFF.
            'can toggle, enabled, brevo NOT ready'        => [true,  true,  false, false],
            'can toggle, disabled, brevo ready'           => [true,  false, true,  false],
            'can toggle, disabled, brevo not ready'       => [true,  false, false, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('toggleShouldBeDisabledProvider')]
    public function testToggleShouldBeDisabledTruthTable(bool $canToggle, bool $isEnabled, bool $brevoReady, bool $expectedDisabled): void
    {
        $this->assertSame(
            $expectedDisabled,
            VerificationSettings::toggleShouldBeDisabled($canToggle, $isEnabled, $brevoReady)
        );
    }

    /**
     * Named explicitly (in addition to the data-provider row above) because
     * this is the exact regression scenario called out in the review: an
     * admin must be able to turn verification OFF during a Brevo outage, and
     * the checkbox must not render `disabled` in that state.
     */
    public function testToggleShouldBeDisabledNeverBlocksTurningOffDuringBrevoOutage(): void
    {
        $this->assertFalse(
            VerificationSettings::toggleShouldBeDisabled(canToggle: true, isEnabled: true, brevoReady: false),
            'An admin must always be able to turn verification off, even while Brevo is broken'
        );
    }

    // =========================================================================
    // Wrong-typed-value test — N/A for this class
    // =========================================================================

    /**
     * No wrong-typed-value test is written for VerificationSettings itself:
     * every public method parameter (`bool $enabled` on setEnabled()) is a
     * scalar typed parameter, and PHP's type system already enforces the
     * type at the language boundary — passing a non-bool triggers a
     * TypeError before the method body ever runs, so there is no runtime
     * shape violation for this class's own API to test. The wrong-typed-value
     * coverage this project's convention calls for lives instead at the HTTP
     * boundary in VerificationToggleEndpointTest, where request input really
     * does arrive untyped from the wire.
     */
    public function testWrongTypedValueNotApplicableToThisClassDocumented(): void
    {
        // No assertion to make — see the docblock above for why this class has
        // no wrong-typed-value case of its own. Counted so the test still
        // reports as run rather than risky/incomplete.
        $this->addToAssertionCount(1);
    }
}
