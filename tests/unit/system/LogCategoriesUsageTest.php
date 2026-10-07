<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Single-file regression checks on endpoint and page source. PHPStan's
 * LogCategoryArgumentRule enforces LogCategories constants in logger() and
 * withLogging() calls.
 */
#[Group('system')]
#[Group('logging')]
class LogCategoriesUsageTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootDir = dirname(__DIR__, 3);
    }

    public function testCheckChassisUsesApiResponse(): void
    {
        $content = $this->read('app/api/cars/chassis-availability.php');

        $this->assertStringContainsString('ApiResponse::', $content);
        $this->assertStringNotContainsString("echo 'taken'", $content);
        $this->assertStringNotContainsString("echo 'not_taken'", $content);
    }

    public function testFormPhpJsUsesElanRegistryAPI(): void
    {
        $content = $this->read('app/assets/js/car-edit.js');

        $this->assertStringNotContainsString('$.ajax', $content);
        $this->assertStringContainsString('new ElanRegistryAPI()', $content);
    }

    public function testAdminCoreJsUsesElanRegistryAPI(): void
    {
        $content = $this->read('app/admin/assets/admin-core.js');

        $this->assertStringNotContainsString('$.ajax', $content);
        $this->assertStringContainsString('new ElanRegistryAPI()', $content);
    }

    /** #639 */
    public function testJoinPhpCapturesEmailReturnValue(): void
    {
        $content = $this->read('usersc/join.php');

        $this->assertMatchesRegularExpression('/\$\w+\s*=\s*email\s*\(/', $content, 'join.php must capture the email() result (#639)');
        $this->assertStringContainsString('LogCategories::LOG_CATEGORY_EMAIL_ERROR', $content);
    }

    /** #657 */
    public function testUserSettingsPhpLogsEmailFailure(): void
    {
        $this->assertStringContainsString(
            'LogCategories::LOG_CATEGORY_EMAIL_ERROR',
            $this->read('usersc/user_settings.php'),
            'user_settings.php must log verify-email failures (#657)'
        );
    }

    /** #658 */
    public function testEditPhpCatchBlocksUseGetUserMessage(): void
    {
        $this->assertNoGetMessageInErrorsArray('app/api/cars/save.php');
    }

    /** #659 */
    public function testManageConsolidatedPhpCatchBlocksDoNotExposeGetMessage(): void
    {
        $this->assertNoGetMessageInErrorsArray('app/admin/index.php');
    }

    /** #669: a fabricated user ID corrupts the audit trail of admin operations. */
    public function testManageConsolidatedPhpHasNoUserIdFallback(): void
    {
        $content = $this->read('app/admin/index.php');

        $this->assertStringNotContainsString('$currentUserId = 1', $content);
        $this->assertStringNotContainsString('$_SESSION[\'user\'][\'id\']', $content);
    }

    /** #650, #701: an empty email_body() is a template failure and must not be sent. */
    public function testEmailBodyReturnIsCheckedBeforeSending(): void
    {
        foreach (['app/admin/includes/process-admin-contact.php', 'usersc/join.php', 'usersc/user_settings.php'] as $file) {
            $this->assertStringContainsString("\$body === ''", $this->read($file), "$file must check email_body() for ''");
        }
    }

    /** #600 */
    public function testSendFeedbackPhpHasNoHardcodedLoggerUserId(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\blogger\s*\(\s*1\s*,/', $this->read('app/api/contact/send-feedback.php'));
    }

    /** #976 */
    public function testProcessCarDetailsUsesNotFoundForMissingCar(): void
    {
        $content = $this->read('app/admin/includes/process-car-details.php');

        $this->assertStringContainsString('ApiResponse::notFound(', $content);
        $this->assertStringNotContainsString("ApiResponse::error('Car not found', 200)", $content);
    }

    private function assertNoGetMessageInErrorsArray(string $relativePath): void
    {
        preg_match_all('/\$errors\[\]\s*=\s*[^;]*\$e->getMessage\(\)/', $this->read($relativePath), $matches);

        $this->assertEmpty(
            $matches[0],
            "$relativePath must not put \$e->getMessage() in \$errors[]. Found: " . implode(', ', $matches[0])
        );
    }

    private function read(string $relativePath): string
    {
        $path = $this->rootDir . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
