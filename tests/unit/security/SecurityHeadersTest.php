<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Test security_headers.php HTTPS detection migration
 *
 * Verifies that security_headers.php uses validated $is_https
 * global instead of raw $_SERVER proxy headers.
 */
#[Group('security')]
#[Group('security-headers')]
#[Group('server-globals')]
class SecurityHeadersTest extends TestCase
{
    private string $securityHeadersFile = '';
    private string $fileContent = '';

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

        // X-XSS-Protection was removed (#976): deprecated by all modern browsers,
        // implies protection that isn't actually provided. CSP is the correct mechanism.
        $this->assertStringNotContainsString(
            'X-XSS-Protection',
            $this->fileContent,
            'security_headers.php must not set X-XSS-Protection (deprecated, removed in #976)'
        );
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

    /**
     * Test that CSP includes frame-ancestors directive for anti-clickjacking
     *
     * Modern anti-clickjacking protection via CSP frame-ancestors directive
     * (CSP3 standard, preferred over frame-src for this purpose)
     */
    public function testCspContainsFrameAncestors(): void
    {
        // Should include frame-ancestors directive in CSP
        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            $this->fileContent,
            'CSP should include frame-ancestors directive for anti-clickjacking protection'
        );

        // Verify it appears in the context of the Content-Security-Policy header
        // (account for multiline string concatenation with dot operators)
        $this->assertMatchesRegularExpression(
            '/Content-Security-Policy:.*frame-ancestors\s+\'self\'/s',
            $this->fileContent,
            'CSP header should contain frame-ancestors directive'
        );
    }

    /**
     * Test that CSP includes form-action 'self' directive
     *
     * form-action does not fall back to default-src, so it must be listed
     * explicitly to prevent form hijacking to attacker-controlled origins.
     */
    public function testCspContainsFormAction(): void
    {
        $this->assertMatchesRegularExpression(
            '/Content-Security-Policy:.*form-action\s+\'self\'/s',
            $this->fileContent,
            'CSP header should contain form-action \'self\' (form-action does not fall back to default-src)'
        );
    }

    /**
     * Test that CSP script-src does not include unsafe-eval
     *
     * No custom JavaScript uses eval() or new Function(), so unsafe-eval
     * can and must be omitted from script-src.
     */
    public function testCspDoesNotContainUnsafeEval(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/script-src\s[^;]*\'unsafe-eval\'/',
            $this->fileContent,
            'CSP script-src must not contain \'unsafe-eval\' (no eval() or new Function() usage in custom JS)'
        );
    }

    /**
     * Test that /usersc/join.php doesn't set duplicate X-Frame-Options header
     *
     * Security headers should be set globally via security_headers.php
     * Individual pages should not override them
     */
    public function testUserscJoinNoFrameOptions(): void
    {
        $joinFile = dirname(__DIR__, 3) . '/usersc/join.php';

        if (!is_file($joinFile)) {
            $this->markTestSkipped("usersc/join.php not found at {$joinFile}");
        }

        $content = file_get_contents($joinFile);
        if ($content === false) {
            $this->markTestSkipped("Unable to read usersc/join.php");
        }

        $joinContent = (string) $content;

        // Should NOT have X-Frame-Options header call
        $this->assertStringNotContainsString(
            "header('X-Frame-Options:",
            $joinContent,
            'usersc/join.php should not set X-Frame-Options (relies on global header)'
        );

        // Should have comment explaining why
        $this->assertStringContainsString(
            'Security headers',
            $joinContent,
            'usersc/join.php should have comment about global security headers'
        );
    }
}
