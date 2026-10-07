<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real getBaseUrl() (usersc/includes/custom_functions.php); the unit
 * bootstrap mocks it. Request-path tests run the real server_globals.php
 * (#2228).
 *
 * setUp()/tearDown() clear Server::$cache. getBaseUrl() caches its fallback
 * in a function-level static that reflection cannot reset, so the fallback
 * tests assert only properties that hold whether the cache is warm or cold.
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
            'HTTP_HOST' => 'elanregistry.org',
            'SERVER_PORT' => '80',
        ]);

        $this->assertSame('https://elanregistry.org', getBaseUrl());
    }

    /**
     * SERVER_PORT follows the client's Host header, so a public host never
     * gets a port, even a non-default one. Only localhost keeps its port
     * (GHSA-4g69-gm5q-rx93).
     *
     * @param array<string, string> $server  $_SERVER keys to seed
     * @param string                $expected getBaseUrl() result
     * @return void
     */
    #[DataProvider('portHandlingProvider')]
    #[Group('integration')]
    public function testGetBaseUrlKeepsPortOnlyForLocalHosts(array $server, string $expected): void
    {
        $this->applyServerGlobals($server);

        $this->assertSame($expected, getBaseUrl());
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function portHandlingProvider(): array
    {
        return [
            'public host, forged https port' => [
                ['REQUEST_SCHEME' => 'https', 'HTTP_HOST' => 'elanregistry.org:2083', 'SERVER_PORT' => '2083'],
                'https://elanregistry.org',
            ],
            'test host, forged https port' => [
                ['REQUEST_SCHEME' => 'https', 'HTTP_HOST' => 'test.elanregistry.org:8443', 'SERVER_PORT' => '8443'],
                'https://test.elanregistry.org',
            ],
            'localhost, docker port' => [
                ['REQUEST_SCHEME' => 'http', 'HTTP_HOST' => 'localhost:8002', 'SERVER_PORT' => '8002'],
                'http://localhost:8002',
            ],
            'localhost, default port' => [
                ['REQUEST_SCHEME' => 'http', 'HTTP_HOST' => 'localhost', 'SERVER_PORT' => '80'],
                'http://localhost',
            ],
        ];
    }

    /**
     * A Host header for another domain must not reach emailed links. The
     * host is dropped, so getBaseUrl() uses its fallback (GHSA-4g69-gm5q-rx93).
     *
     * @return void
     */
    #[Group('integration')]
    public function testGetBaseUrlIgnoresUntrustedHost(): void
    {
        $this->applyServerGlobals([
            'REQUEST_SCHEME' => 'https',
            'HTTP_HOST' => 'attacker.example',
            'SERVER_PORT' => '443',
        ]);

        $this->assertSame('', $GLOBALS['host']);
        $result = getBaseUrl();
        $this->assertValidFallbackUrl($result);
        $this->assertStringNotContainsString('attacker.example', $result);
    }

    /**
     * With empty server globals (CLI), the result falls back to a non-empty,
     * absolute URL with no trailing slash.
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
