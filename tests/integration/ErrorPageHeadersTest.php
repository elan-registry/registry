<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Test security headers on error pages
 *
 * Verifies that error pages (403, 404, 500) return proper
 * anti-clickjacking headers even if init.php fails to load.
 */
#[Group('integration')]
#[Group('security')]
#[Group('error-pages')]
class ErrorPageHeadersTest extends TestCase
{
    /**
     * Test that error page files include security header fallbacks
     */
    #[DataProvider('errorPageProvider')]
    public function testErrorPageIncludesSecurityHeaders(string $pageName): void
    {
        $errorPageFile = dirname(__DIR__, 2) . '/error/' . $pageName;

        if (!is_file($errorPageFile)) {
            $this->markTestSkipped("Error page {$pageName} not found");
        }

        $content = file_get_contents($errorPageFile);
        if ($content === false) {
            $this->markTestSkipped("Unable to read {$pageName}");
        }

        $pageContent = (string) $content;

        // Should set X-Frame-Options header
        $this->assertStringContainsString(
            'X-Frame-Options',
            $pageContent,
            "{$pageName} should set X-Frame-Options header"
        );

        // Should set CSP header with frame-ancestors
        $this->assertStringContainsString(
            "Content-Security-Policy",
            $pageContent,
            "{$pageName} should set Content-Security-Policy header"
        );

        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            $pageContent,
            "{$pageName} should include frame-ancestors in CSP"
        );
    }

    /**
     * Test that error pages set headers before init.php load attempt
     *
     * Headers should be set early in the file, before any includes that
     * might fail (like init.php)
     */
    #[DataProvider('errorPageProvider')]
    public function testErrorPageHeadersBeforeInitPhp(string $pageName): void
    {
        $errorPageFile = dirname(__DIR__, 2) . '/error/' . $pageName;

        if (!is_file($errorPageFile)) {
            $this->markTestSkipped("Error page {$pageName} not found");
        }

        $content = file_get_contents($errorPageFile);
        if ($content === false) {
            $this->markTestSkipped("Unable to read {$pageName}");
        }

        $pageContent = (string) $content;

        // Check that headers are in the early section (before line 30 in most cases)
        $lines = explode("\n", $pageContent);

        $hasHeaderCall = false;
        $hasInitCall = false;
        $headerLineNum = PHP_INT_MAX;
        $initLineNum = PHP_INT_MAX;

        foreach ($lines as $lineNum => $line) {
            if (strpos($line, 'header("X-Frame-Options') !== false) {
                $hasHeaderCall = true;
                $headerLineNum = $lineNum;
            }
            if (strpos($line, 'require_once') !== false && strpos($line, 'init.php') !== false) {
                $hasInitCall = true;
                $initLineNum = $lineNum;
            }
        }

        // Verify headers are set (they should be)
        $this->assertTrue(
            $hasHeaderCall,
            "{$pageName} should set security headers with header() call"
        );

        // If both exist, headers should come before init.php
        if ($hasHeaderCall && $hasInitCall) {
            $this->assertLessThan(
                $initLineNum,
                $headerLineNum,
                "{$pageName} should set X-Frame-Options before loading init.php"
            );
        }
    }

    /**
     * Test that error pages use SAMEORIGIN policy (not DENY)
     *
     * Error pages allow framing within same origin (more user-friendly)
     * while still protecting against cross-origin clickjacking
     */
    #[DataProvider('errorPageProvider')]
    public function testErrorPageUsesSameoriginPolicy(string $pageName): void
    {
        $errorPageFile = dirname(__DIR__, 2) . '/error/' . $pageName;

        if (!is_file($errorPageFile)) {
            $this->markTestSkipped("Error page {$pageName} not found");
        }

        $content = file_get_contents($errorPageFile);
        if ($content === false) {
            $this->markTestSkipped("Unable to read {$pageName}");
        }

        $pageContent = (string) $content;

        // Should use SAMEORIGIN (allows same-origin framing)
        $this->assertStringContainsString(
            'SAMEORIGIN',
            $pageContent,
            "{$pageName} should use SAMEORIGIN policy"
        );
    }

    /**
     * Data provider: error page filenames to test
     *
     * @return array<array<string>>
     */
    public static function errorPageProvider(): array
    {
        return [
            ['403.php'],
            ['404.php'],
            ['500.php'],
        ];
    }
}
