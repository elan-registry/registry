<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test Infrastructure Validation
 *
 * Simple integration test to verify our test infrastructure works
 * and demonstrate successful test execution for Issue #317
 */
final class TestInfrastructureValidationTest extends TestCase
{
    /**
     * Test that PHP is working correctly
     */
    public function testPhpVersion(): void
    {
        $version = PHP_VERSION;
        $this->assertNotEmpty($version);
        $this->assertStringStartsWith('8.', $version, 'Should be running PHP 8.x');
    }

    /**
     * Test that file system operations work
     */
    public function testFileSystemAccess(): void
    {
        $projectRoot = dirname(__DIR__, 2);

        // Test that we can access project files
        $this->assertDirectoryExists($projectRoot . '/app');
        $this->assertDirectoryExists($projectRoot . '/usersc');
        $this->assertFileExists($projectRoot . '/composer.json');
        $this->assertFileExists($projectRoot . '/package.json');
    }

    /**
     * Test that our test directories are properly organized
     */
    public function testTestDirectoryStructure(): void
    {
        $testsDir = dirname(__DIR__);

        $this->assertDirectoryExists($testsDir . '/unit');
        $this->assertDirectoryExists($testsDir . '/integration');
        $this->assertDirectoryExists($testsDir . '/regression');
        $this->assertDirectoryExists($testsDir . '/playwright');
    }

    /**
     * Test that configurations are in place
     */
    public function testConfigurationFiles(): void
    {
        $projectRoot = dirname(__DIR__, 2);

        $this->assertFileExists($projectRoot . '/phpunit.xml');
        $this->assertFileExists($projectRoot . '/playwright.config.js');

        // Verify configurations contain expected content
        $phpunitContent = file_get_contents($projectRoot . '/phpunit.xml');
        $this->assertStringContainsString('testsuite name="Unit"', $phpunitContent);
        $this->assertStringContainsString('testsuite name="Integration"', $phpunitContent);

        $playwrightContent = file_get_contents($projectRoot . '/playwright.config.js');
        $this->assertStringContainsString('testDir: \'./tests/playwright\'', $playwrightContent);
    }
}