<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for getBaseUrl() (usersc/includes/custom_functions.php).
 *
 * The unit-test bootstrap defines a mock getBaseUrl() that always returns
 * 'https://test.elanregistry.org'. To exercise the real implementation we
 * use the integration bootstrap, which loads UserSpice and the real
 * custom_functions.php.
 *
 * Caching notes:
 *  - Server::$cache memoises sanitized $_SERVER values per key. setUp()
 *    clears it via reflection so each test sees the SERVER_PORT it sets.
 *  - getBaseUrl() caches its FALLBACK result in a function-level
 *    `static $baseUrl` variable that PHP does not expose to reflection.
 *    As of this implementation, the happy-path branch returns before
 *    reaching the static variable, so happy-path tests neither read nor
 *    write the cache. The fallback test asserts only on properties of the
 *    cached value (non-empty, valid absolute URL) so it passes whether
 *    the cache was warm or cold.
 */
#[Group('integration')]
#[Group('email')]
final class GetBaseUrlTest extends IntegrationTestCase
{
    /**
     * Reset Server class cache so per-test SERVER_PORT changes are observed.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        if (class_exists('Server')) {
            $reflection = new \ReflectionClass('Server');
            if ($reflection->hasProperty('cache')) {
                $cacheProp = $reflection->getProperty('cache');
                $cacheProp->setValue(null, []);
            }
        }
    }

    /**
     * Test that getBaseUrl() returns the constructed URL when server globals
     * ($scheme, $host, $us_url_root) are populated.
     *
     * In this happy-path branch the function does not query the database
     * and does not consult the cached static fallback; it simply assembles
     * scheme + host + path.
     *
     * @return void
     */
    #[Group('integration')]
    public function testGetBaseUrlReturnsConstructedUrlWhenGlobalsSet(): void
    {
        global $scheme, $host, $us_url_root;

        $originalScheme    = $scheme    ?? null;
        $originalHost      = $host      ?? null;
        $originalUsUrlRoot = $us_url_root ?? null;
        $originalPort      = $_SERVER['SERVER_PORT'] ?? null;

        try {
            $scheme               = 'https';
            $host                 = 'test.elanregistry.org';
            $us_url_root          = '/';
            // Default HTTPS port → not appended to the URL
            $_SERVER['SERVER_PORT'] = 443;

            $result = getBaseUrl();

            $this->assertSame('https://test.elanregistry.org', $result);
        } finally {
            $scheme      = $originalScheme;
            $host        = $originalHost;
            $us_url_root = $originalUsUrlRoot;
            if ($originalPort === null) {
                unset($_SERVER['SERVER_PORT']);
            } else {
                $_SERVER['SERVER_PORT'] = $originalPort;
            }
        }
    }

    /**
     * Test that getBaseUrl() falls back to a usable URL when the server
     * globals are empty (CLI / early-boot context).
     *
     * The function attempts to read the `verify_url` column from the `email`
     * table and, if unavailable, falls back to the hardcoded production URL. Either
     * way the result must be a non-empty, well-formed absolute URL with no
     * trailing slash.
     *
     * Asserts only on properties that hold whether or not the function-level
     * static cache has been populated by a prior call in this process, so
     * test-order independence is preserved.
     *
     * @return void
     */
    #[Group('integration')]
    public function testGetBaseUrlFallsBackWhenServerGlobalsEmpty(): void
    {
        global $scheme, $host, $us_url_root;

        $originalScheme    = $scheme    ?? null;
        $originalHost      = $host      ?? null;
        $originalUsUrlRoot = $us_url_root ?? null;

        try {
            $scheme      = '';
            $host        = '';
            $us_url_root = '';

            $result = getBaseUrl();

            $this->assertIsString($result);
            $this->assertNotEmpty($result);
            // Must be a syntactically valid absolute URL (http or https)
            $this->assertNotFalse(
                filter_var($result, FILTER_VALIDATE_URL),
                "getBaseUrl() fallback returned an invalid URL: {$result}"
            );
            $parsedScheme = parse_url($result, PHP_URL_SCHEME);
            $this->assertContains(
                $parsedScheme,
                ['http', 'https'],
                "getBaseUrl() fallback URL has unexpected scheme: {$parsedScheme}"
            );
            // No trailing slash (function rtrim()s the result)
            $this->assertStringEndsNotWith('/', $result);
        } finally {
            $scheme      = $originalScheme;
            $host        = $originalHost;
            $us_url_root = $originalUsUrlRoot;
        }
    }
}
