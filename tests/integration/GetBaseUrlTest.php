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
 * Request-path tests seed $_SERVER and run the real server_globals.php
 * (see applyServerGlobals()), so they prove that getBaseUrl() follows
 * $current_origin, port rule included (#2228).
 *
 * Caching notes:
 *  - Server::$cache memoises sanitized $_SERVER values per key. setUp() and
 *    tearDown() clear it via reflection so each test sees the $_SERVER
 *    values it sets, and later tests do not see them.
 *  - getBaseUrl() caches its FALLBACK result in a function-level
 *    `static $baseUrl` variable that PHP does not expose to reflection.
 *    The request-path branch returns before reaching the static variable,
 *    so request-path tests neither read nor write the cache. The fallback
 *    test asserts only on properties of the cached value (non-empty, valid
 *    absolute URL) so it passes whether the cache was warm or cold.
 */
#[Group('integration')]
#[Group('email')]
final class GetBaseUrlTest extends IntegrationTestCase
{
    /** Globals that server_globals.php assigns, plus $us_url_root. */
    private const GLOBAL_NAMES = [
        'scheme', 'is_https', 'host', 'current_origin', 'method', 'request_uri',
        'php_self', 'current_url', 'referer', 'user_agent', 'remote_addr', 'us_url_root',
    ];

    /** $_SERVER keys the tests seed. */
    private const SERVER_KEYS = ['REQUEST_SCHEME', 'HTTP_HOST', 'SERVER_PORT', 'HTTP_X_FORWARDED_PROTO'];

    /** @var array<string, mixed> */
    private array $savedGlobals = [];

    /** @var array<string, mixed> */
    private array $savedServer = [];

    /**
     * Save the globals and $_SERVER keys the tests change, and reset the
     * Server class cache.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::GLOBAL_NAMES as $name) {
            if (array_key_exists($name, $GLOBALS)) {
                $this->savedGlobals[$name] = $GLOBALS[$name];
            }
        }
        foreach (self::SERVER_KEYS as $key) {
            $this->savedServer[$key] = $_SERVER[$key] ?? null;
        }
        $this->resetServerCache();
    }

    /**
     * Restore the saved globals and $_SERVER keys.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // A global the test created must be removed, not left as null.
        foreach (self::GLOBAL_NAMES as $name) {
            if (array_key_exists($name, $this->savedGlobals)) {
                $GLOBALS[$name] = $this->savedGlobals[$name];
            } else {
                unset($GLOBALS[$name]);
            }
        }
        foreach ($this->savedServer as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        $this->resetServerCache();
        parent::tearDown();
    }

    /**
     * HTTPS on the default port gives a URL with no port.
     *
     * @return void
     */
    #[Group('integration')]
    public function testGetBaseUrlReturnsConstructedUrlWhenGlobalsSet(): void
    {
        $this->applyServerGlobals([
            'REQUEST_SCHEME' => 'https',
            'HTTP_HOST' => 'test.elanregistry.org',
            'SERVER_PORT' => '443',
        ]);

        $this->assertSame('https://test.elanregistry.org', getBaseUrl());
    }

    /**
     * Docker maps localhost:8001 to Apache, which reports SERVER_PORT 8001.
     * The port must be in the URL that emails link to (#2228).
     *
     * @return void
     */
    #[Group('integration')]
    public function testGetBaseUrlKeepsNonDefaultPort(): void
    {
        $this->applyServerGlobals([
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'localhost:8001',
            'SERVER_PORT' => '8001',
        ]);

        $this->assertSame('http://localhost:8001', getBaseUrl());
    }

    /**
     * Behind a TLS proxy the scheme is https but Apache listens on 80. The
     * URL must not carry ':80' (#2228).
     *
     * @return void
     */
    #[Group('integration')]
    public function testGetBaseUrlOmitsApachePortBehindProxy(): void
    {
        $this->applyServerGlobals([
            'REQUEST_SCHEME' => 'http',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_HOST' => 'abc.trycloudflare.com',
            'SERVER_PORT' => '80',
        ]);

        $this->assertSame('https://abc.trycloudflare.com', getBaseUrl());
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
        $GLOBALS['scheme']         = '';
        $GLOBALS['host']           = '';
        $GLOBALS['current_origin'] = '';
        $GLOBALS['us_url_root']    = '';

        $this->assertValidFallbackUrl(getBaseUrl());
    }

    /**
     * In the CLI, server_globals.php still runs: $host is '' but
     * $current_origin is 'http://', which is not empty. The $host check must
     * send this to the fallback, not return 'http:' (#2228).
     *
     * @return void
     */
    #[Group('integration')]
    public function testGetBaseUrlFallsBackForCliServerGlobals(): void
    {
        $this->applyServerGlobals([]);

        $this->assertSame('http://', $GLOBALS['current_origin']);
        $this->assertValidFallbackUrl(getBaseUrl());
    }

    /**
     * Assert the properties every fallback URL has, whether or not the
     * function-level static cache was already populated in this process.
     *
     * @param string $result getBaseUrl() output
     * @return void
     */
    private function assertValidFallbackUrl(string $result): void
    {
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
    }

    /**
     * Seed $_SERVER and run the real server_globals.php so that its globals
     * are set the same way as on a web request. $us_url_root is '/'.
     *
     * @param array<string, string> $server $_SERVER keys to set; other SERVER_KEYS are removed
     * @return void
     */
    private function applyServerGlobals(array $server): void
    {
        foreach (self::SERVER_KEYS as $key) {
            unset($_SERVER[$key]);
        }
        foreach ($server as $key => $value) {
            $_SERVER[$key] = $value;
        }
        $this->resetServerCache();

        (static function (): void {
            // The global statement binds the file's assignments to the globals
            // that getBaseUrl() reads, not to this closure's scope.
            global $scheme, $is_https, $host, $current_origin, $method, $request_uri,
                $php_self, $current_url, $referer, $user_agent, $remote_addr;
            require dirname(__DIR__, 2) . '/usersc/includes/server_globals.php';
        })();

        $GLOBALS['us_url_root'] = '/';
    }

    /**
     * Clear the memoised $_SERVER values in the Server class.
     *
     * @return void
     */
    private function resetServerCache(): void
    {
        if (class_exists(\Server::class)) {
            $reflection = new \ReflectionClass(\Server::class);
            if ($reflection->hasProperty('cache')) {
                $reflection->getProperty('cache')->setValue(null, []);
            }
        }
    }
}
