<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test that car-related PHP endpoints use LogCategories constants
 * instead of hardcoded string literals in withLogging() and logger() calls.
 *
 * @group system
 * @group logging
 */
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
        'app/admin/includes/process-transfer-approve.php',
        'app/admin/includes/process-transfer-deny.php',
        'app/admin/includes/process-car-details.php',
        'usersc/includes/transfer_email_notifications.php',
    ];

    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootDir = dirname(__DIR__, 3);
    }

    /**
     * @dataProvider carEndpointFilesProvider
     */
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

    /**
     * @dataProvider carEndpointFilesProvider
     */
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
    public function testEditPhpJsUsesElanRegistryAPI(): void
    {
        $filePath = $this->rootDir . '/app/cars/edit.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('edit.php not found');
        }

        $content = file_get_contents($filePath);

        // Should not contain $.ajax for car-related AJAX calls
        $this->assertStringNotContainsString('$.ajax', $content, 'edit.php should use ElanRegistryAPI instead of $.ajax');
        $this->assertStringContainsString('new ElanRegistryAPI()', $content, 'edit.php should use new ElanRegistryAPI()');
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
        $filePath = $this->rootDir . '/usersc/includes/transfer_email_notifications.php';
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
}
