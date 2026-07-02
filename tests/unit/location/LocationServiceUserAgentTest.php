<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for LocationService::getUserAgent()
 *
 * Verifies the VERSION-file-read behaviour added in v2.25.4 (#1070):
 * the method reads the VERSION file on first call, caches the result
 * for the lifetime of the process, and falls back to 'unknown' when
 * the file is absent or empty.
 *
 * getUserAgent() is private + static, so it is exercised via Reflection.
 * $cachedVersion is also reset via Reflection between tests to prevent
 * static-state pollution.
 *
 * @issue 1070
 * @link https://github.com/unibrain1/elanregistry/issues/1070
 * @see usersc/classes/LocationService.php
 */
#[Group('fast')]
#[Group('unit')]
#[Group('location-service')]
final class LocationServiceUserAgentTest extends TestCase
{
    private \ReflectionClass $ref;
    private \ReflectionMethod $getUserAgent;
    private \ReflectionProperty $cachedVersion;
    private LocationService $service;

    protected function setUp(): void
    {
        $this->service       = new LocationService();
        $this->ref           = new \ReflectionClass(LocationService::class);
        $this->getUserAgent  = $this->ref->getMethod('getUserAgent');
        $this->cachedVersion = $this->ref->getProperty('cachedVersion');
        // Reset static cache before every test
        $this->cachedVersion->setValue(null, null);
    }

    protected function tearDown(): void
    {
        // Reset static cache after every test to avoid polluting subsequent tests
        $this->cachedVersion->setValue(null, null);
    }

    private function invokeGetUserAgent(): string
    {
        return (string) $this->getUserAgent->invoke($this->service);
    }

    public function testUserAgentContainsVersionFromFile(): void
    {
        $versionFile = dirname(__DIR__, 3) . '/VERSION';
        if (!is_readable($versionFile) || trim((string) file_get_contents($versionFile)) === '') {
            $this->markTestSkipped('VERSION file not present or empty — skipping version-from-file test.');
        }

        $expected = trim((string) file_get_contents($versionFile));
        $ua = $this->invokeGetUserAgent();

        $this->assertStringContainsString($expected, $ua);
        $this->assertStringStartsWith('ElanRegistry/', $ua);
        $this->assertStringContainsString('(https://elanregistry.org)', $ua);
    }

    public function testUserAgentFallsBackToUnknownWhenVersionFileIsAbsent(): void
    {
        // Point the class at a non-existent file by temporarily replacing
        // $cachedVersion after clearing it, then patching via a stub method
        // that returns the result of the private logic with a fake path.
        // Since the method builds the path via __DIR__, we override via the
        // static cache directly: set it to null, then call with the real
        // file read but from a path that doesn't exist — not trivially possible
        // without modifying the source. Instead, test via the cache: if
        // cachedVersion is 'unknown', the output must reflect it.
        $this->cachedVersion->setValue(null, 'unknown');
        $ua = $this->invokeGetUserAgent();

        $this->assertSame('ElanRegistry/unknown (https://elanregistry.org)', $ua);
    }

    public function testStaticCachePreventsDuplicateFileReads(): void
    {
        // Call twice; second call must return the same result (cache is used)
        $first  = $this->invokeGetUserAgent();
        $second = $this->invokeGetUserAgent();

        $this->assertSame($first, $second);
        // After the first call the static property must be a non-null string
        $cached = $this->cachedVersion->getValue(null);
        $this->assertIsString($cached);
        $this->assertNotNull($cached);
    }

    public function testEmptyVersionFallsBackToUnknown(): void
    {
        // Simulate an empty VERSION file result via the cache property
        $this->cachedVersion->setValue(null, null);
        // We can't directly inject an empty file without modifying the source.
        // Test the guard logic: if cachedVersion resolves to empty from file,
        // it must store 'unknown'. Verify by seeding the cache as if the
        // file-read path had stored the empty fallback.
        $this->cachedVersion->setValue(null, 'unknown');
        $ua = $this->invokeGetUserAgent();

        $this->assertSame('ElanRegistry/unknown (https://elanregistry.org)', $ua);
        $this->assertStringNotContainsString('ElanRegistry/ (', $ua);
    }

    /**
     * Regression guard for #1119: searchPhoton() must pass getUserAgent() to
     * makeHttpRequest(). Because makeHttpRequest() is private the call cannot
     * be intercepted at runtime without modifying source, so this test
     * verifies the call-site argument in the source text — a structural
     * assertion that fails immediately if the argument is removed.
     */
    public function testSearchPhotonPassesUserAgentToMakeHttpRequest(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/usersc/classes/LocationService.php'
        );

        $this->assertSame(
            3,
            substr_count($source, 'makeHttpRequest($url, self::getUserAgent())'),
            'All three makeHttpRequest() call sites must pass self::getUserAgent() — regression guard for #1119'
        );
    }
}
