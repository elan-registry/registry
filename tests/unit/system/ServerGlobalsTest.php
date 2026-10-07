<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * server_globals.php calls Server::get() from users/classes/Server.php, which
 * is upstream and gitignored, so it is absent in CI. The behavioral tests run
 * the real files in a `php` subprocess and are tagged
 * requires-upstream-install, which test:quick:ci excludes.
 *
 * integration/GetBaseUrlTest covers the port rule, the proxy scheme upgrade and
 * the untrusted-host fallback. The tests here cover only what it does not.
 *
 * $php_self is not asserted: Server::get('PHP_SELF') ignores the fixture in
 * CLI and derives the value from SCRIPT_FILENAME (Server::cliFallback()).
 */
#[Group('system')]
#[Group('server-globals')]
class ServerGlobalsTest extends TestCase
{
    private string $globalsFile;
    private string $serverClassFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->globalsFile = dirname(__DIR__, 3) . '/usersc/includes/server_globals.php';
        $this->serverClassFile = dirname(__DIR__, 3) . '/users/classes/Server.php';
    }

    public function testServerGlobalsFileIsSyntacticallyValid(): void
    {
        $output = [];
        $returnCode = 0;
        exec('php -l ' . escapeshellarg($this->globalsFile), $output, $returnCode);
        $this->assertEquals(0, $returnCode);
    }

    /**
     * loader.php includes this file in API endpoints, so any output would
     * corrupt the JSON response.
     */
    public function testFileDoesNotOutputAnything(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringNotContainsString('echo(', $content);
        $this->assertStringNotContainsString('print(', $content);
        $this->assertStringNotContainsString('var_dump(', $content);
        $this->assertStringNotContainsString('print_r(', $content);
    }

    #[Group('requires-upstream-install')]
    public function testHttpsRequestDerivesSecureGlobals(): void
    {
        $globals = $this->runServerGlobals([
            'REQUEST_SCHEME' => 'https',
            'HTTP_HOST' => 'elanregistry.org',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/app/owner/cars/details.php?id=123',
            'PHP_SELF' => '/app/owner/cars/details.php',
            'REMOTE_ADDR' => '203.0.113.5',
        ]);

        $this->assertSame('elanregistry.org', $globals['host']);
        $this->assertTrue($globals['is_https']);
        $this->assertSame('GET', $globals['method']);
        $this->assertSame('/app/owner/cars/details.php?id=123', $globals['request_uri']);
        $this->assertSame('203.0.113.5', $globals['remote_addr']);
        $this->assertSame('https://elanregistry.org', $globals['current_origin']);
        $this->assertSame(
            'https://elanregistry.org/app/owner/cars/details.php?id=123',
            $globals['current_url']
        );
    }

    /**
     * The default port depends on the scheme: 8443 is not https's default
     * (#2228).
     */
    #[Group('requires-upstream-install')]
    public function testNonDefaultHttpsPortIsAppendedToOrigin(): void
    {
        $globals = $this->runServerGlobals([
            'REQUEST_SCHEME' => 'https',
            'HTTP_HOST' => 'localhost:8443',
            'SERVER_PORT' => '8443',
        ]);

        $this->assertSame('https://localhost:8443', $globals['current_origin']);
    }

    /**
     * @param array<string, string> $serverFixture
     */
    #[DataProvider('portOmittedProvider')]
    #[Group('requires-upstream-install')]
    public function testPortIsOmittedFromOrigin(array $serverFixture, string $expectedOrigin): void
    {
        $globals = $this->runServerGlobals($serverFixture);

        $this->assertSame($expectedOrigin, $globals['current_origin']);
        $this->assertSame($expectedOrigin . '/', $globals['current_url']);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function portOmittedProvider(): array
    {
        return [
            'X-Forwarded-Proto http with Apache on 8001' => [
                [
                    'REQUEST_SCHEME' => 'http',
                    'HTTP_X_FORWARDED_PROTO' => 'http',
                    'HTTP_HOST' => 'localhost:8001',
                    'SERVER_PORT' => '8001',
                ],
                'http://localhost',
            ],
            'SERVER_PORT 0' => [
                ['REQUEST_SCHEME' => 'http', 'HTTP_HOST' => 'localhost', 'SERVER_PORT' => '0'],
                'http://localhost',
            ],
            'SERVER_PORT not numeric' => [
                ['REQUEST_SCHEME' => 'http', 'HTTP_HOST' => 'localhost', 'SERVER_PORT' => 'abc'],
                'http://localhost',
            ],
            'SERVER_PORT out of range' => [
                ['REQUEST_SCHEME' => 'http', 'HTTP_HOST' => 'localhost', 'SERVER_PORT' => '70000'],
                'http://localhost',
            ],
        ];
    }

    #[Group('requires-upstream-install')]
    public function testMissingServerKeysFallBackToSecureDefaults(): void
    {
        $globals = $this->runServerGlobals([]);

        $this->assertFalse($globals['is_https']);
        $this->assertSame('GET', $globals['method']);
        $this->assertSame('/', $globals['request_uri']);
        $this->assertSame('', $globals['host']);
        $this->assertSame('', $globals['referer']);
        $this->assertSame('', $globals['user_agent']);
        $this->assertSame('', $globals['remote_addr']);
        $this->assertSame('http://', $globals['current_origin']);
        $this->assertSame('http:///', $globals['current_url']);
    }

    /**
     * The host builds emailed links, so the allowlist must be an exact match,
     * not a prefix, suffix or substring match (GHSA-4g69-gm5q-rx93).
     */
    #[DataProvider('untrustedHostProvider')]
    #[Group('requires-upstream-install')]
    public function testUntrustedHostIsDropped(string $httpHost): void
    {
        $globals = $this->runServerGlobals([
            'HTTPS'          => 'on',
            'REQUEST_SCHEME' => 'https',
            'HTTP_HOST'      => $httpHost,
            'REQUEST_URI'    => '/users/forgot_password.php',
        ]);

        $this->assertSame('', $globals['host'], "Untrusted host {$httpHost} must not be kept");
        $this->assertSame('https://', $globals['current_origin']);
        $this->assertStringNotContainsString('attacker', $globals['current_url']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function untrustedHostProvider(): array
    {
        return [
            'trusted host as prefix'  => ['elanregistry.org.attacker.example'],
            'trusted host as suffix'  => ['attacker-elanregistry.org'],
            'subdomain of trusted'    => ['attacker.elanregistry.org'],
        ];
    }

    /**
     * A dropped trusted host sends that environment's emails to
     * email.verify_url instead of the host the visitor used.
     */
    #[DataProvider('trustedHostProvider')]
    #[Group('requires-upstream-install')]
    public function testTrustedHostIsKept(string $httpHost, string $expectedHost): void
    {
        $globals = $this->runServerGlobals([
            'HTTPS'          => 'on',
            'REQUEST_SCHEME' => 'https',
            'HTTP_HOST'      => $httpHost,
            'REQUEST_URI'    => '/',
        ]);

        $this->assertSame($expectedHost, $globals['host']);
        $this->assertSame('https://' . $expectedHost, $globals['current_origin']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function trustedHostProvider(): array
    {
        return [
            'production www'      => ['www.elanregistry.org', 'www.elanregistry.org'],
            'loopback address'    => ['127.0.0.1', '127.0.0.1'],
            'production with port'=> ['elanregistry.org:443', 'elanregistry.org'],
            'mixed case'          => ['ElanRegistry.ORG', 'elanregistry.org'],
        ];
    }

    #[DataProvider('validMethodProvider')]
    #[Group('requires-upstream-install')]
    public function testKnownMethodIsUppercasedAndPreserved(string $method): void
    {
        $globals = $this->runServerGlobals(['REQUEST_METHOD' => strtolower($method)]);

        $this->assertSame($method, $globals['method']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validMethodProvider(): array
    {
        return [
            'GET' => ['GET'],
            'POST' => ['POST'],
            'PUT' => ['PUT'],
            'DELETE' => ['DELETE'],
        ];
    }

    #[Group('requires-upstream-install')]
    public function testRequestUriStripsCrlfInjection(): void
    {
        $globals = $this->runServerGlobals([
            'REQUEST_URI' => "/app/owner/cars/details.php?id=1\r\nX-Injected: evil",
        ]);

        $this->assertStringNotContainsString("\r", $globals['request_uri']);
        $this->assertStringNotContainsString("\n", $globals['request_uri']);
    }

    #[Group('requires-upstream-install')]
    public function testUserAgentIsTruncatedTo512Chars(): void
    {
        $globals = $this->runServerGlobals([
            'HTTP_USER_AGENT' => str_repeat('A', 1000),
        ]);

        $this->assertSame(512, strlen($globals['user_agent']));
    }

    /**
     * Skips, not fails, when users/classes/Server.php is absent.
     *
     * @param array<string, string> $serverFixture Keys/values to seed $_SERVER with
     * @return array<string, mixed>
     */
    private function runServerGlobals(array $serverFixture): array
    {
        if (!is_file($this->serverClassFile)) {
            $this->markTestSkipped(
                'users/classes/Server.php not found: it is upstream UserSpice and gitignored.'
            );
        }

        $harness = <<<'PHP'
            <?php
            declare(strict_types=1);
            $serverFixture = json_decode(file_get_contents('php://stdin'), true);
            $_SERVER = array_merge($_SERVER, $serverFixture);
            require $argv[1];
            require $argv[2];
            echo json_encode([
                'scheme' => $scheme,
                'is_https' => $is_https,
                'host' => $host,
                'method' => $method,
                'request_uri' => $request_uri,
                'php_self' => $php_self,
                'current_url' => $current_url,
                'current_origin' => $current_origin,
                'referer' => $referer,
                'user_agent' => $user_agent,
                'remote_addr' => $remote_addr,
            ]);
            PHP;

        $harnessFile = tempnam(sys_get_temp_dir(), 'sg_harness_');
        if ($harnessFile === false) {
            $this->fail('Unable to create temporary harness file');
        }
        file_put_contents($harnessFile, $harness);

        try {
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open(
                [
                    'php',
                    '-d',
                    'error_reporting=E_ALL & ~E_DEPRECATED',
                    $harnessFile,
                    $this->serverClassFile,
                    $this->globalsFile,
                ],
                $descriptorSpec,
                $pipes
            );

            if (!is_resource($process)) {
                $this->fail('Unable to start PHP subprocess for server_globals.php harness');
            }

            fwrite($pipes[0], (string) json_encode($serverFixture));
            fclose($pipes[0]);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            $this->assertSame(
                0,
                $exitCode,
                "server_globals.php harness subprocess failed (exit {$exitCode}): {$stderr}"
            );

            $decoded = json_decode((string) $stdout, true);
            $this->assertIsArray($decoded, "Harness did not produce valid JSON output: {$stdout}");

            return $decoded;
        } finally {
            unlink($harnessFile);
        }
    }
}
