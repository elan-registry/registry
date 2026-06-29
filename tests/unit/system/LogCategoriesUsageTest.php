<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Test that car-related PHP endpoints use LogCategories constants
 * instead of hardcoded string literals in withLogging() and logger() calls.
 */
#[Group('system')]
#[Group('logging')]
class LogCategoriesUsageTest extends TestCase
{
    /**
     * Car-related PHP files that should use LogCategories constants
     * for all logging category parameters.
     */
    private const CAR_ENDPOINT_FILES = [
        'app/cars/actions/check-chassis.php',
        'app/cars/actions/edit.php',
        'app/cars/actions/history.php',
        'app/cars/actions/validateChassis.php',
        'app/cars/actions/request-transfer.php',
        'app/admin/includes/process-transfer-approve.php',
        'app/admin/includes/process-transfer-deny.php',
        'app/admin/includes/process-car-details.php',
        'app/includes/transfer_email_notifications.php',
    ];

    /**
     * Contact-related PHP files that should use LogCategories constants
     * for all logging category parameters.
     */
    private const CONTACT_ENDPOINT_FILES = [
        'app/contact/send-feedback.php',
        'app/contact/send-owner-email.php',
    ];

    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootDir = dirname(__DIR__, 3);
    }

    #[DataProvider('carEndpointFilesProvider')]
    public function testNoHardcodedWithLoggingStrings(string $relativePath): void
    {
        $filePath = $this->rootDir . '/' . $relativePath;
        if (!file_exists($filePath)) {
            $this->markTestSkipped("File not found: $relativePath");
        }

        $content = file_get_contents($filePath);

        // Match withLogging calls that use string literals for the category parameter
        // Pattern: ->withLogging(anything, 'SomeString', anything)
        // The category is the second argument after the user ID
        $pattern = '/->withLogging\s*\([^,]+,\s*[\'"][A-Za-z]+[\'"]/';

        $matches = [];
        preg_match_all($pattern, $content, $matches);

        $this->assertEmpty(
            $matches[0],
            sprintf(
                "File %s contains hardcoded string literals in withLogging() calls. " .
                "Use LogCategories constants instead.\nFound: %s",
                $relativePath,
                implode(', ', $matches[0])
            )
        );
    }

    #[DataProvider('carEndpointFilesProvider')]
    public function testNoHardcodedLoggerStrings(string $relativePath): void
    {
        $filePath = $this->rootDir . '/' . $relativePath;
        if (!file_exists($filePath)) {
            $this->markTestSkipped("File not found: $relativePath");
        }

        $content = file_get_contents($filePath);

        // Match logger() calls that use string literals for the category parameter
        // Pattern: logger(anything, 'SomeString', anything)
        $pattern = '/\blogger\s*\([^,]+,\s*[\'"][A-Za-z]+[\'"]/';

        $matches = [];
        preg_match_all($pattern, $content, $matches);

        $this->assertEmpty(
            $matches[0],
            sprintf(
                "File %s contains hardcoded string literals in logger() calls. " .
                "Use LogCategories constants instead.\nFound: %s",
                $relativePath,
                implode(', ', $matches[0])
            )
        );
    }

    #[DataProvider('contactEndpointFilesProvider')]
    public function testNoHardcodedWithLoggingStringsInContactFiles(string $relativePath): void
    {
        $filePath = $this->rootDir . '/' . $relativePath;
        if (!file_exists($filePath)) {
            $this->markTestSkipped("File not found: $relativePath");
        }

        $content = file_get_contents($filePath);

        // Match withLogging calls that use string literals for the category parameter
        // Pattern: ->withLogging(anything, 'SomeString', anything)
        // The category is the second argument after the user ID
        $pattern = '/->withLogging\s*\([^,]+,\s*[\'"][A-Za-z]+[\'"]/';

        $matches = [];
        preg_match_all($pattern, $content, $matches);

        $this->assertEmpty(
            $matches[0],
            sprintf(
                "File %s contains hardcoded string literals in withLogging() calls. " .
                "Use LogCategories constants instead.\nFound: %s",
                $relativePath,
                implode(', ', $matches[0])
            )
        );
    }

    #[DataProvider('contactEndpointFilesProvider')]
    public function testNoHardcodedLoggerStringsInContactFiles(string $relativePath): void
    {
        $filePath = $this->rootDir . '/' . $relativePath;
        if (!file_exists($filePath)) {
            $this->markTestSkipped("File not found: $relativePath");
        }

        $content = file_get_contents($filePath);

        // Match logger() calls that use string literals for the category parameter
        // Pattern: logger(anything, 'SomeString', anything)
        $pattern = '/\blogger\s*\([^,]+,\s*[\'"][A-Za-z]+[\'"]/';

        $matches = [];
        preg_match_all($pattern, $content, $matches);

        $this->assertEmpty(
            $matches[0],
            sprintf(
                "File %s contains hardcoded string literals in logger() calls. " .
                "Use LogCategories constants instead.\nFound: %s",
                $relativePath,
                implode(', ', $matches[0])
            )
        );
    }

    /**
     * Test that check-chassis.php uses ApiResponse pattern
     */
    public function testCheckChassisUsesApiResponse(): void
    {
        $filePath = $this->rootDir . '/app/cars/actions/check-chassis.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('check-chassis.php not found');
        }

        $content = file_get_contents($filePath);

        $this->assertStringContainsString('ApiResponse::', $content, 'check-chassis.php should use ApiResponse');
        $this->assertStringNotContainsString("echo 'taken'", $content, 'check-chassis.php should not echo plain text');
        $this->assertStringNotContainsString("echo 'not_taken'", $content, 'check-chassis.php should not echo plain text');
    }

    /**
     * Test that car-related JS files use ElanRegistryAPI instead of $.ajax
     */
    public function testFormPhpJsUsesElanRegistryAPI(): void
    {
        $filePath = $this->rootDir . '/app/cars/edit.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('form.php not found');
        }

        $content = file_get_contents($filePath);

        // Should not contain $.ajax for car-related AJAX calls
        $this->assertStringNotContainsString('$.ajax', $content, 'form.php should use ElanRegistryAPI instead of $.ajax');
        $this->assertStringContainsString('new ElanRegistryAPI()', $content, 'form.php should use new ElanRegistryAPI()');
    }

    /**
     * Test that manage-consolidated.js uses ElanRegistryAPI instead of $.ajax
     */
    public function testManageConsolidatedJsUsesElanRegistryAPI(): void
    {
        $filePath = $this->rootDir . '/app/admin/assets/manage-consolidated.js';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('manage-consolidated.js not found');
        }

        $content = file_get_contents($filePath);

        $this->assertStringNotContainsString('$.ajax', $content, 'manage-consolidated.js should use ElanRegistryAPI instead of $.ajax');
        $this->assertStringContainsString('new ElanRegistryAPI()', $content, 'manage-consolidated.js should use new ElanRegistryAPI()');
    }

    /**
     * Regression test for Issue #639: join.php must capture email() return value and log failures.
     */
    public function testJoinPhpCapturesEmailReturnValue(): void
    {
        $filePath = $this->rootDir . '/usersc/join.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('join.php not found');
        }

        $content = file_get_contents($filePath);

        $this->assertMatchesRegularExpression(
            '/\$\w+\s*=\s*email\s*\(/',
            $content,
            'join.php must capture the return value of email() (Issue #639)'
        );
        $this->assertStringContainsString(
            'LogCategories::LOG_CATEGORY_EMAIL_ERROR',
            $content,
            'join.php must log email failures using LogCategories::LOG_CATEGORY_EMAIL_ERROR (Issue #639)'
        );
    }

    /**
     * Regression test for Issue #656: partial admin alert failure must use LOG_CATEGORY_EMAIL_ERROR.
     */
    public function testTransferAdminAlertLogsPartialFailureUnderErrorCategory(): void
    {
        $filePath = $this->rootDir . '/app/includes/transfer_email_notifications.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('transfer_email_notifications.php not found');
        }

        $content = file_get_contents($filePath);

        $this->assertStringContainsString(
            '$failCount',
            $content,
            'sendTransferRequestAdminAlert() must track $failCount for partial-failure logging (Issue #656)'
        );
        $this->assertMatchesRegularExpression(
            '/logger\s*\([^,]+,\s*LogCategories::LOG_CATEGORY_EMAIL_ERROR[^)]*\$failCount/',
            $content,
            'Partial admin alert failure must log under LOG_CATEGORY_EMAIL_ERROR with $failCount (Issue #656)'
        );
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$failCount\s*>\s*0\s*\)/',
            $content,
            'Error log must be gated on $failCount > 0 (Issue #656)'
        );
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$successCount\s*>\s*0\s*\)/',
            $content,
            'Success log must be gated on $successCount > 0 to prevent false success entries (Issue #656)'
        );
    }

    /**
     * Regression test for Issue #655: all catch blocks in transfer_email_notifications.php
     * must include exception class, file, and line number.
     */
    public function testTransferNotificationCatchBlocksIncludeExceptionDetail(): void
    {
        $filePath = $this->rootDir . '/app/includes/transfer_email_notifications.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('transfer_email_notifications.php not found');
        }

        $content = file_get_contents($filePath);

        $this->assertSame(
            4,
            substr_count($content, 'get_class($e)'),
            'All 4 catch blocks in transfer_email_notifications.php must include get_class($e) (Issue #655)'
        );
        $this->assertSame(
            4,
            substr_count($content, '$e->getFile()'),
            'All 4 catch blocks in transfer_email_notifications.php must include $e->getFile() (Issue #655)'
        );
        $this->assertSame(
            4,
            substr_count($content, '$e->getLine()'),
            'All 4 catch blocks in transfer_email_notifications.php must include $e->getLine() (Issue #655)'
        );
    }

    /**
     * Regression test for Issue #657: user_settings.php must log verify-email delivery failures.
     */
    public function testUserSettingsPhpLogsEmailFailure(): void
    {
        $filePath = $this->rootDir . '/usersc/user_settings.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('user_settings.php not found');
        }

        $content = file_get_contents($filePath);

        $this->assertStringContainsString(
            'LogCategories::LOG_CATEGORY_EMAIL_ERROR',
            $content,
            'user_settings.php must log verify-email failures using LogCategories::LOG_CATEGORY_EMAIL_ERROR (Issue #657)'
        );
    }

    /**
     * Regression test for Issue #658: edit.php must not expose getMessage() in user-facing $errors[].
     */
    public function testEditPhpCatchBlocksUseGetUserMessage(): void
    {
        $this->assertNoGetMessageInErrorsArray(
            'app/cars/actions/edit.php',
            'Use $e->getUserMessage() instead (Issue #658).'
        );
    }

    /**
     * Regression test for Issue #659: manage-consolidated.php must not expose getMessage() in $errors[].
     */
    public function testManageConsolidatedPhpCatchBlocksDoNotExposeGetMessage(): void
    {
        $this->assertNoGetMessageInErrorsArray(
            'app/admin/manage-consolidated.php',
            'Use a safe static message instead (Issue #659).'
        );
    }

    /**
     * Assert that no $errors[] assignment in a file uses $e->getMessage() directly.
     * Such assignments expose technical exception messages to users.
     */
    private function assertNoGetMessageInErrorsArray(string $relativePath, string $hint): void
    {
        $filePath = $this->rootDir . '/' . $relativePath;
        if (!file_exists($filePath)) {
            $this->markTestSkipped("File not found: $relativePath");
        }

        $content = (string)file_get_contents($filePath);
        preg_match_all('/\$errors\[\]\s*=\s*[^;]*\$e->getMessage\(\)/', $content, $matches);

        $this->assertEmpty(
            $matches[0],
            "$relativePath must not use \$e->getMessage() in \$errors[] assignments. $hint Found: "
                . implode(', ', $matches[0])
        );
    }

    /**
     * Regression test for Issue #669: manage-consolidated.php must not use a hardcoded
     * $currentUserId fallback or raw $_SESSION access for the user ID.
     * A fabricated user ID would corrupt the audit trail for destructive admin operations.
     */
    public function testManageConsolidatedPhpHasNoUserIdFallback(): void
    {
        $filePath = $this->rootDir . '/app/admin/manage-consolidated.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('manage-consolidated.php not found');
        }

        $content = (string)file_get_contents($filePath);

        $this->assertStringNotContainsString(
            '$currentUserId = 1',
            $content,
            'manage-consolidated.php must not fall back to a hardcoded user ID — this corrupts the audit trail (Issue #669)'
        );
        $this->assertStringNotContainsString(
            '$_SESSION[\'user\'][\'id\']',
            $content,
            'manage-consolidated.php must not access $_SESSION directly for the user ID — use the $user object (Issue #669)'
        );
    }

    /**
     * Regression test for Issue #650: process-admin-contact.php must check email_body()
     * return value before calling email(), so template failures are distinguishable from
     * Brevo delivery failures in the logs.
     */
    public function testAdminContactChecksEmailBodyReturn(): void
    {
        $filePath = $this->rootDir . '/app/admin/includes/process-admin-contact.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('process-admin-contact.php not found');
        }

        $content = (string)file_get_contents($filePath);

        $this->assertStringContainsString(
            "\$body === ''",
            $content,
            'process-admin-contact.php must check email_body() return for empty string before calling email() (Issue #650)'
        );
    }

    // --- Issue #701: email_body() return value checks — all callers must guard against empty body ---

    /**
     * Regression test for Issue #701: usersc/join.php must check email_body() return value
     * before calling email(), so template failures are detectable and logged.
     *
     * @issue 701
     * @link https://github.com/unibrain1/elanregistry/issues/701
     */
    public function testUsercJoinChecksEmailBodyReturn(): void
    {
        $filePath = $this->rootDir . '/usersc/join.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('usersc/join.php not found');
        }

        $content = (string)file_get_contents($filePath);

        $this->assertStringContainsString(
            "\$body === ''",
            $content,
            'usersc/join.php must check email_body() return for empty string before calling ' .
            'email() — template failures must be logged, not silently sent as blank emails (Issue #701)'
        );
    }

    /**
     * Regression test for Issue #701: usersc/user_settings.php must check email_body() return value
     * before calling email(), so template failures are detectable and logged.
     *
     * @issue 701
     * @link https://github.com/unibrain1/elanregistry/issues/701
     */
    public function testUsercUserSettingsChecksEmailBodyReturn(): void
    {
        $filePath = $this->rootDir . '/usersc/user_settings.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('usersc/user_settings.php not found');
        }

        $content = (string)file_get_contents($filePath);

        $this->assertStringContainsString(
            "\$body === ''",
            $content,
            'usersc/user_settings.php must check email_body() return for empty string before calling ' .
            'email() — template failures must be logged, not silently sent as blank emails (Issue #701)'
        );
    }

    /**
     * Regression test for Issue #600: send-feedback.php modernization checks.
     */
    public function testSendFeedbackPhpIsModernized(): void
    {
        $filePath = $this->rootDir . '/app/contact/send-feedback.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('send-feedback.php not found');
        }

        $content = (string)file_get_contents($filePath);

        // cleanString() must have a PHPDoc block
        $this->assertMatchesRegularExpression(
            '/\/\*\*.*?cleanString/s',
            $content,
            'send-feedback.php cleanString() must have a PHPDoc block (#600)'
        );

        // logger() calls must not use hardcoded user ID 1
        $this->assertDoesNotMatchRegularExpression(
            '/\blogger\s*\(\s*1\s*,/',
            $content,
            'send-feedback.php must not use hardcoded user ID 1 in logger() calls (#600)'
        );
    }

    /**
     * Regression test for Issue #368: _email_template_verify_new.php must not contain
     * a hardcoded admin email address and must call getFeedbackEmail() instead.
     */
    public function testNoHardcodedAdminEmailInVerifyTemplate(): void
    {
        $filePath = $this->rootDir . '/usersc/views/_email_template_verify_new.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('_email_template_verify_new.php not found');
        }

        $content = (string)file_get_contents($filePath);

        $this->assertStringNotContainsString(
            'admin@elanregistry.org',
            $content,
            '_email_template_verify_new.php must not contain hardcoded admin@elanregistry.org (#368)'
        );
        $this->assertStringContainsString(
            'getFeedbackEmail()',
            $content,
            '_email_template_verify_new.php must call getFeedbackEmail() for admin contact (#368)'
        );
    }

    /**
     * Regression test for Issue #368: elan_feedback_email must appear in the
     * processSettingsAutoCreation() defaults array in tab-settings.php,
     * ensuring it is inserted into the database on fresh installs.
     */
    public function testFeedbackEmailSettingIsAutoCreated(): void
    {
        $filePath = $this->rootDir . '/app/admin/includes/tab-settings.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('tab-settings.php not found');
        }

        $content = (string)file_get_contents($filePath);

        $this->assertMatchesRegularExpression(
            '/[\'"]elan_feedback_email[\'"]\s*=>/m',
            $content,
            'tab-settings.php must include elan_feedback_email in settings auto-creation (#368)'
        );
    }

    /**
     * Data provider for car endpoint files
     */
    public static function carEndpointFilesProvider(): array
    {
        $data = [];
        foreach (self::CAR_ENDPOINT_FILES as $file) {
            $data[$file] = [$file];
        }
        return $data;
    }

    /**
     * Data provider for contact endpoint files
     */
    public static function contactEndpointFilesProvider(): array
    {
        $data = [];
        foreach (self::CONTACT_ENDPOINT_FILES as $file) {
            $data[$file] = [$file];
        }
        return $data;
    }
}
