<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Test server globals initialization module
 *
 * Verifies that usersc/includes/server_globals.php correctly derives
 * $php_self, $is_https, $host, $method, $request_uri, $current_url,
 * $current_origin, $remote_addr, $referer, and $user_agent from $_SERVER.
 *
 * server_globals.php calls Server::get() (users/classes/Server.php) for every
 * value. Server.php is upstream UserSpice and lives under users/, which is
 * gitignored — see CLAUDE.md's Template Customization Rules — so it is absent
 * on a fresh checkout and in CI (composer install does not fetch it). This
 * makes true behavioral testing of server_globals.php impossible inside the
 * normal PHPUnit process for the unit tier: there's no stub of Server that
 * would exercise the real sanitization logic, and a hand-written stub would
 * only test the stub.
 *
 * Behavioral coverage below therefore runs Server.php and server_globals.php
 * in an isolated `php` subprocess (bypassing the framework, autoloader, and
 * PHPUnit process state entirely), with $_SERVER fixtures injected via
 * stdin/JSON and the resulting globals dumped as JSON. This is real
 * execution of the real sanitization/derivation logic, not a text-grep
 * substitute — but it only runs where Server.php actually exists on disk.
 * Every behavioral test is tagged #[Group('requires-upstream-install')] and
 * skips (not fails) when it doesn't, matching the established convention in
 * SecurityHeadersTest::testUpstreamScriptHashesMatchActualFiles() — see
 * composer.json's test:quick:ci script, which excludes this group in CI.
 *
 * testServerGlobalsFileIsSyntacticallyValid() and
 * testFileDoesNotOutputAnything() need neither Server.php nor a subprocess
 * and always run.
 *
 * Known harness limitation: Server::get('PHP_SELF', ...) branches on
 * Server::isCli() (true for any `php` process, including this harness) and
 * derives PHP_SELF from SCRIPT_FILENAME/DOCUMENT_ROOT discovery rather than
 * consulting the injected $_SERVER['PHP_SELF'] fixture at all — see
 * Server::cliFallback(). That derivation path is therefore not exercised
 * here; $php_self is intentionally left unasserted in the tests below.
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

    /**
     * Test that the globals file can be included without syntax errors
     */
    public function testServerGlobalsFileIsSyntacticallyValid(): void
    {
        $output = [];
        $returnCode = 0;
        exec('php -l ' . escapeshellarg($this->globalsFile), $output, $returnCode);
        $this->assertEquals(0, $returnCode);
    }

    /**
     * Test file does not output anything to buffer
     *
     * CRITICAL per server_globals.php's own header: it is included from
     * loader.php in parser files and API calls, so any stray output would
     * corrupt the response.
     */
    public function testFileDoesNotOutputAnything(): void
    {
        $content = (string) file_get_contents($this->globalsFile);
        $this->assertStringNotContainsString('echo(', $content);
        $this->assertStringNotContainsString('print(', $content);
        $this->assertStringNotContainsString('var_dump(', $content);
        $this->assertStringNotContainsString('print_r(', $content);
    }

    /**
     * Normal HTTPS request: scheme, host, method, and derived URL fields must
     * all reflect a validated, secure request.
     */
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
        // $php_self is not asserted here — see this class's docblock "Known harness limitation".
        $this->assertSame('203.0.113.5', $globals['remote_addr']);
        $this->assertSame('https://elanregistry.org', $globals['current_origin']);
        $this->assertSame(
            'https://elanregistry.org/app/owner/cars/details.php?id=123',
            $globals['current_url']
        );
    }

    /**
     * Normal HTTP request: is_https must be false and current_origin/current_url
     * must use the http:// scheme.
     */
    #[Group('requires-upstream-install')]
    public function testHttpRequestDerivesInsecureGlobals(): void
    {
        $globals = $this->runServerGlobals([
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'test.elanregistry.org',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/app/api/cars/save.php',
            'PHP_SELF' => '/app/api/cars/save.php',
            'REMOTE_ADDR' => '198.51.100.9',
        ]);

        $this->assertFalse($globals['is_https']);
        $this->assertSame('http://test.elanregistry.org', $globals['current_origin']);
        $this->assertSame(
            'http://test.elanregistry.org/app/api/cars/save.php',
            $globals['current_url']
        );
        $this->assertSame('POST', $globals['method']);
    }

    /**
     * X-Forwarded-Proto: https must upgrade scheme detection to HTTPS even when
     * REQUEST_SCHEME reports http — the reverse-proxy / Cloudflare Tunnel case
     * documented in server_globals.php, where SSL is terminated upstream.
     */
    #[Group('requires-upstream-install')]
    public function testForwardedProtoHttpsUpgradesScheme(): void
    {
        $globals = $this->runServerGlobals([
            'REQUEST_SCHEME' => 'http',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_HOST' => 'elanregistry.org',
        ]);

        $this->assertTrue($globals['is_https']);
        $this->assertSame('https://elanregistry.org', $globals['current_origin']);
    }

    /**
     * Missing $_SERVER keys must fall back to the safe defaults documented in
     * server_globals.php: http scheme, GET method, '/' for URI/script path,
     * and empty strings for host/referer/user_agent/remote_addr.
     */
    #[Group('requires-upstream-install')]
    public function testMissingServerKeysFallBackToSecureDefaults(): void
    {
        $globals = $this->runServerGlobals([]);

        $this->assertFalse($globals['is_https']);
        $this->assertSame('GET', $globals['method']);
        $this->assertSame('/', $globals['request_uri']);
        // $php_self is not asserted here — see this class's docblock "Known harness limitation".
        $this->assertSame('', $globals['host']);
        $this->assertSame('', $globals['referer']);
        $this->assertSame('', $globals['user_agent']);
        $this->assertSame('', $globals['remote_addr']);
        $this->assertSame('http://', $globals['current_origin']);
        $this->assertSame('http:///', $globals['current_url']);
    }

    /**
     * A spoofed HTTP_HOST containing CRLF/control characters must have those
     * characters stripped by Server::get() before reaching $host — this is
     * the "control character stripping" and "CRLF injection prevention"
     * security feature server_globals.php's header documents. The sanitizer
     * strips control characters rather than rejecting the whole value
     * outright. The remainder is not one of this application's hosts, so the
     * host allowlist then drops it and the host ends up ''.
     */
    #[Group('requires-upstream-install')]
    public function testSpoofedHostStripsControlCharacters(): void
    {
        $globals = $this->runServerGlobals([
            'HTTP_HOST' => "evil\r\nHost: attacker.example",
        ]);

        $this->assertStringNotContainsString("\r", $globals['host']);
        $this->assertStringNotContainsString("\n", $globals['host']);
        $this->assertSame('', $globals['host']);
    }

    /**
     * A syntactically invalid HTTP_HOST (illegal characters that survive
     * control-character stripping, e.g. an underscore, which is not a valid
     * DNS label character) must be rejected outright to an empty string by
     * Server::sanitize_host()'s DNS label validation.
     */
    #[Group('requires-upstream-install')]
    public function testInvalidDnsLabelHostIsRejectedToEmptyString(): void
    {
        $globals = $this->runServerGlobals([
            'HTTP_HOST' => 'invalid_host_name!.example.com',
        ]);

        $this->assertSame('', $globals['host']);
    }

    /**
     * A well-formed host that this application does not serve must be dropped.
     *
     * The host builds the links in password-reset and verification emails
     * (getBaseUrl()), so an untrusted Host header must not reach them. It
     * becomes '', the same as a cron or CLI request, and getBaseUrl() then
     * falls back to the email.verify_url setting. The lookalikes pin that the
     * check is an exact match, not a prefix, suffix or substring match.
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
            'unrelated domain'        => ['attacker.example'],
            'trusted host as prefix'  => ['elanregistry.org.attacker.example'],
            'trusted host as suffix'  => ['attacker-elanregistry.org'],
            'subdomain of trusted'    => ['attacker.elanregistry.org'],
        ];
    }

    /**
     * Every host this application serves must be kept, including after the
     * port is removed and the value is lowercased. Losing one of these would
     * send that environment's emails to email.verify_url instead of the host
     * the visitor used.
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
            'production'          => ['elanregistry.org', 'elanregistry.org'],
            'production www'      => ['www.elanregistry.org', 'www.elanregistry.org'],
            'test'                => ['test.elanregistry.org', 'test.elanregistry.org'],
            'localhost'           => ['localhost', 'localhost'],
            'loopback address'    => ['127.0.0.1', '127.0.0.1'],
            'localhost with port' => ['localhost:8002', 'localhost'],
            'production with port'=> ['elanregistry.org:443', 'elanregistry.org'],
            'mixed case'          => ['ElanRegistry.ORG', 'elanregistry.org'],
        ];
    }

    /**
     * A known HTTP method must be uppercased and preserved by
     * Server::sanitize_request_method()'s allow-list.
     */
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

    /**
     * REQUEST_URI is sanitized: control characters and CRLF are stripped,
     * preventing CRLF/header injection via a crafted request line.
     */
    #[Group('requires-upstream-install')]
    public function testRequestUriStripsCrlfInjection(): void
    {
        $globals = $this->runServerGlobals([
            'REQUEST_URI' => "/app/owner/cars/details.php?id=1\r\nX-Injected: evil",
        ]);

        $this->assertStringNotContainsString("\r", $globals['request_uri']);
        $this->assertStringNotContainsString("\n", $globals['request_uri']);
    }

    /**
     * HTTP_USER_AGENT longer than 512 characters must be truncated by
     * Server::sanitize_user_agent(), per server_globals.php's documented
     * "truncated to 512 chars for safety" behavior.
     */
    #[Group('requires-upstream-install')]
    public function testUserAgentIsTruncatedTo512Chars(): void
    {
        $globals = $this->runServerGlobals([
            'HTTP_USER_AGENT' => str_repeat('A', 1000),
        ]);

        $this->assertSame(512, strlen($globals['user_agent']));
    }

    /**
     * Run server_globals.php in an isolated PHP subprocess with the given
     * $_SERVER fixture, after first defining the real Server class from
     * users/classes/Server.php. Returns the resulting globals as an
     * associative array, decoded from JSON.
     *
     * Skips (does not fail) when users/classes/Server.php is absent — see
     * this class's docblock.
     *
     * @param array<string, string> $serverFixture Keys/values to seed $_SERVER with
     * @return array<string, mixed>
     */
    private function runServerGlobals(array $serverFixture): array
    {
        if (!is_file($this->serverClassFile)) {
            $this->markTestSkipped(
                'users/classes/Server.php not found in this checkout — it is upstream ' .
                'UserSpice, gitignored, and absent in CI (see CLAUDE.md\'s Template ' .
                'Customization Rules and this class\'s docblock). This is a local-only ' .
                'behavioral check: run it on a machine with a full UserSpice install.'
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
