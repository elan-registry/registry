<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test security_headers.php HTTPS detection migration
 *
 * Verifies that security_headers.php uses validated $is_https
 * global instead of raw $_SERVER proxy headers.
 *
 * @group security
 * @group security-headers
 * @group server-globals
 */
class SecurityHeadersTest extends TestCase
{
    private string $securityHeadersFile;
    private string $fileContent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->securityHeadersFile = dirname(__DIR__, 3) . '/usersc/includes/security_headers.php';

        // Load file once with error handling
        if (!is_file($this->securityHeadersFile)) {
            $this->markTestSkipped("security_headers.php not found at {$this->securityHeadersFile}");
        }

        $content = file_get_contents($this->securityHeadersFile);
        if ($content === false) {
            $this->markTestSkipped("Unable to read security_headers.php");
        }

        $this->fileContent = (string) $content;
    }

    /**
     * Test that security_headers.php doesn't redefine $is_https
     */
    public function testDoesNotRedefineIsHttps(): void
    {
        // Should not have assignment to $is_https
        $this->assertStringNotContainsString(
            '$is_https =',
            $this->fileContent,
            'security_headers.php should not redefine $is_https (use server global instead)'
        );
    }

    /**
     * Test that security_headers.php doesn't access $_SERVER for HTTPS detection
     */
    public function testNoServerAccessForHttpsDetection(): void
    {
        // Should not check $_SERVER['HTTPS']
        $this->assertStringNotContainsString(
            "['HTTPS']",
            $this->fileContent,
            'security_headers.php should not check $_SERVER[\'HTTPS\'] directly'
        );

        // Should not check $_SERVER['SERVER_PORT']
        $this->assertStringNotContainsString(
            "['SERVER_PORT']",
            $this->fileContent,
            'security_headers.php should not check $_SERVER[\'SERVER_PORT\'] directly'
        );

        // Should not check X-Forwarded-Proto
        $this->assertStringNotContainsString(
            'X_FORWARDED_PROTO',
            $this->fileContent,
            'security_headers.php should not check X-Forwarded-Proto header directly'
        );
    }

    /**
     * Test that HSTS header uses $is_https global
     */
    public function testHstsHeaderUsesIsHttpsGlobal(): void
    {
        // Should check $is_https for HSTS
        $this->assertStringContainsString(
            'if ($is_https)',
            $this->fileContent,
            'security_headers.php should use $is_https global for HSTS logic'
        );

        // Should set HSTS header
        $this->assertStringContainsString(
            'Strict-Transport-Security',
            $this->fileContent,
            'security_headers.php should set HSTS header'
        );
    }

    /**
     * Test that file documents server_globals.php usage
     */
    public function testDocumentsServerGlobalsUsage(): void
    {
        // Should reference server_globals.php in comments
        $this->assertStringContainsString(
            'server_globals.php',
            $this->fileContent,
            'security_headers.php should document use of server_globals.php'
        );
    }

    /**
     * Test that file sets all expected security headers
     */
    public function testSetsAllSecurityHeaders(): void
    {
        $expectedHeaders = [
            'Content-Security-Policy',
            'X-Frame-Options',
            'X-XSS-Protection',
            'X-Content-Type-Options',
            'Referrer-Policy',
        ];

        foreach ($expectedHeaders as $header) {
            $this->assertStringContainsString(
                $header,
                $this->fileContent,
                "security_headers.php should set {$header} header"
            );
        }
    }

    /**
     * Test that no direct $_SERVER array access for HTTPS detection exists
     */
    public function testNoHttpsDetectionLogic(): void
    {
        // Should not have complex boolean logic with $_SERVER
        $this->assertStringNotContainsString(
            '!empty($_SERVER[\'HTTPS\'])',
            $this->fileContent,
            'security_headers.php should not contain HTTPS detection logic'
        );
    }

    /**
     * Test that file doesn't have unvalidated proxy header checks
     */
    public function testNoUnvalidatedProxyHeaders(): void
    {
        // Should not check HTTP_X_FORWARDED_PROTO
        $this->assertStringNotContainsString(
            'HTTP_X_FORWARDED_PROTO',
            $this->fileContent,
            'security_headers.php should not check unvalidated X-Forwarded-Proto header'
        );
    }

    /**
     * Test that file references correct Server class implementation
     */
    public function testDocumentsServerClassUsage(): void
    {
        // Should mention Server::getScheme() in comments
        $this->assertStringContainsString(
            'Server::getScheme()',
            $this->fileContent,
            'security_headers.php should document Server::getScheme() usage'
        );
    }
}
