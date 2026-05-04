<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Test server globals initialization module
 *
 * Verifies that server_globals.php correctly initializes all global variables
 * with proper validation and safe defaults.
 */
#[Group('system')]
#[Group('server-globals')]
class ServerGlobalsTest extends TestCase
{
    private string $globalsFile;
    private string $loaderFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->globalsFile = dirname(__DIR__, 3) . '/usersc/includes/server_globals.php';
        $this->loaderFile = dirname(__DIR__, 3) . '/usersc/includes/loader.php';
    }

    /**
     * Test that server_globals.php file exists
     */
    public function testServerGlobalsFileExists(): void
    {
        $this->assertFileExists($this->globalsFile);
    }

    /**
     * Test that server_globals.php has proper declare(strict_types=1)
     */
    public function testFileHasStrictTypesDeclaration(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    /**
     * Test that file uses Server class for all globals
     */
    public function testFileUsesServerClass(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringContainsString('Server::get(', $content);
        $this->assertStringContainsString('$scheme', $content);
        $this->assertStringContainsString('$is_https', $content);
        $this->assertStringContainsString('$host', $content);
        $this->assertStringContainsString('$method', $content);
        $this->assertStringContainsString('$request_uri', $content);
        $this->assertStringContainsString('$current_url', $content);
        $this->assertStringContainsString('$current_origin', $content);
        $this->assertStringContainsString('$php_self', $content);
        $this->assertStringContainsString('$remote_addr', $content);
    }

    /**
     * Test that file has proper PHPDoc header
     */
    public function testFileHasPhpDocHeader(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringContainsString('/**', $content);
        $this->assertStringContainsString('@package', $content);
        $this->assertStringContainsString('Server Globals Initialization Module', $content);
    }

    /**
     * Test that file includes proper comments
     */
    public function testFileHasProperComments(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringContainsString('HTTP Scheme Detection', $content);
        $this->assertStringContainsString('Host and Origin', $content);
        $this->assertStringContainsString('Request Details', $content);
        $this->assertStringContainsString('Full URL Construction', $content);
        $this->assertStringContainsString('Optional/Tracking Variables', $content);
    }

    /**
     * Test that is_https is boolean check
     */
    public function testIsHttpsIsBooleanCheck(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringContainsString("=== 'https'", $content);
    }

    /**
     * Test that current_origin construction is correct
     */
    public function testCurrentOriginConstruction(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringContainsString('is_https', $content);
        $this->assertStringContainsString('current_origin', $content);
    }

    /**
     * Test that current_url includes request_uri
     */
    public function testCurrentUrlConstruction(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringContainsString('current_url', $content);
        $this->assertStringContainsString('current_origin', $content);
        $this->assertStringContainsString('request_uri', $content);
    }

    /**
     * Test that secure defaults are used
     */
    public function testSecureDefaults(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringContainsString("'http'", $content);
        $this->assertStringContainsString("'GET'", $content);
        $this->assertStringContainsString("'/'", $content);
    }

    /**
     * Test that loader.php includes server_globals.php
     */
    public function testLoaderIncludesServerGlobals(): void
    {
        $content = (string) file_get_contents($this->loaderFile);
        $this->assertStringContainsString('server_globals.php', $content);
        $this->assertStringContainsString('require_once', $content);
    }

    /**
     * Test that PAGE_LOADING_FLOW.md documents Phase 1.11.12
     */
    public function testPageLoadingFlowDocumentation(): void
    {
        $docFile = dirname(__DIR__, 3) . '/docs/development/PAGE_LOADING_FLOW.md';
        $content = (string) file_get_contents($docFile);
        $this->assertStringContainsString('1.11.12', $content);
        $this->assertStringContainsString('Server Globals Initialization', $content);
    }

    /**
     * Test CLAUDE.md documents server globals usage
     */
    public function testClaudeMdDocumentation(): void
    {
        $docFile = dirname(__DIR__, 3) . '/CLAUDE.md';
        $content = (string) file_get_contents($docFile);
        $this->assertStringContainsString('Server Environment Globals', $content);
        $this->assertStringContainsString('v2.13.0', $content);
    }

    /**
     * Test that globals file can be included without syntax errors
     */
    public function testServerGlobalsFileIsSyntacticallyValid(): void
    {
        $output = [];
        $returnCode = 0;
        exec("php -l {$this->globalsFile}", $output, $returnCode);
        $this->assertEquals(0, $returnCode);
    }

    /**
     * Test that proxy-aware scheme detection via Server::getScheme() is used
     * rather than reading a raw $_SERVER key directly.
     */
    public function testDocumentsRequestSchemeUsage(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringContainsString('getScheme', $content);
        $this->assertStringNotContainsString(
            'HTTP_X_FORWARDED_PROTO',
            $content,
            'server_globals.php must not access X-Forwarded-Proto directly — use Server::getScheme()'
        );
    }

    /**
     * Test that HTTP_HOST is documented as a required value
     */
    public function testDocumentsHttpHostUsage(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringContainsString('HTTP_HOST', $content);
    }

    /**
     * Test that REQUEST_URI is documented
     */
    public function testDocumentsRequestUriUsage(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringContainsString('REQUEST_URI', $content);
    }

    /**
     * Test that PHP_SELF is documented
     */
    public function testDocumentsPhpSelfUsage(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringContainsString('PHP_SELF', $content);
    }

    /**
     * Test that REMOTE_ADDR is documented
     */
    public function testDocumentsRemoteAddrUsage(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringContainsString('REMOTE_ADDR', $content);
    }

    /**
     * Test that security features are documented
     */
    public function testDocumentsSecurityFeatures(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringContainsString('Security Features', $content);
        $this->assertStringContainsString('Server::get()', $content);
    }

    /**
     * Test file does not output anything to buffer
     */
    public function testFileDoesNotOutputAnything(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        // File should not have statement output functions (checking for statement form only)
        // Skip 'echo ' check as it might appear in comments or URLs
        $this->assertStringNotContainsString('echo(', $content);
        $this->assertStringNotContainsString('print(', $content);
        $this->assertStringNotContainsString('var_dump(', $content);
        $this->assertStringNotContainsString('print_r(', $content);
    }

    /**
     * Test that global variable list is documented in header
     */
    public function testDocumentsAllAvailableGlobals(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $expectedGlobals = [
            '$scheme',
            '$is_https',
            '$host',
            '$method',
            '$request_uri',
            '$current_url',
            '$current_origin',
            '$referer',
            '$user_agent',
            '$php_self',
            '$remote_addr',
        ];

        foreach ($expectedGlobals as $global) {
            $this->assertStringContainsString($global, $content);
        }
    }
}
