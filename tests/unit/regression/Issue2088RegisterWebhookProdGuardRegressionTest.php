<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #2088: `--create` accepts the production receiver URL only with
 * `--allow-prod`, and only as an exact match.
 *
 * The script runs its CLI entry point on include, so this test runs it as a
 * child process. `--env` points at an empty directory. A URL that passes the
 * guard then stops in loadConfig() with exit 1 and "No .env file". A URL that
 * fails the guard stops first with exit 2. So no case reads a real .env or
 * calls Brevo. The accepted cases match loadConfig()'s message "No .env file
 * found in", so a change to that message fails them too.
 *
 * @issue 2088
 * @link https://github.com/elan-registry/registry/issues/2088
 */
#[Group('regression')]
final class Issue2088RegisterWebhookProdGuardRegressionTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../scripts/spike-1888/brevo-register-webhook.php';

    private const PROD_URL = 'https://elanregistry.org/app/api/webhooks/brevo.php';

    private const TEST_URL = 'https://test.elanregistry.org/app/api/webhooks/brevo.php';

    private const TOKEN = 'SECRET123';

    // No test writes to it, so all tests share one directory.
    private static string $emptyEnvDir;

    public static function setUpBeforeClass(): void
    {
        $dir = sys_get_temp_dir() . '/issue2088-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir), 'The test must create an empty --env directory');
        self::$emptyEnvDir = $dir;
    }

    public static function tearDownAfterClass(): void
    {
        rmdir(self::$emptyEnvDir);
    }

    /**
     * Run the script with `--create` and the given extra arguments
     *
     * @param list<string> $args
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCreate(array $args): array
    {
        $command = array_merge(
            [PHP_BINARY, self::SCRIPT, '--create', '--env=' . self::$emptyEnvDir, '--token=' . self::TOKEN],
            $args
        );

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'The script must start');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * URLs that must stop at the guard with exit 2
     *
     * @return array<string, array{list<string>, string}>
     */
    public static function rejectedCases(): array
    {
        $prodOnly = '--allow-prod accepts only';
        $testOnly = '--url must start with https://test.';

        return [
            'prod URL, no flag' => [['--url=' . self::PROD_URL], $testOnly],
            'look-alike host, flag' => [['--allow-prod', '--url=https://elanregistry.org.evil.example/app/api/webhooks/brevo.php'], $prodOnly],
            'other query, flag' => [['--allow-prod', '--url=' . self::PROD_URL . '?x=1'], $prodOnly],
            'other path, flag' => [['--allow-prod', '--url=https://elanregistry.org/app/api/webhooks/other.php'], $prodOnly],
            'http scheme, flag' => [['--allow-prod', '--url=http://elanregistry.org/app/api/webhooks/brevo.php'], $prodOnly],
            'test URL, flag' => [['--allow-prod', '--url=' . self::TEST_URL], $prodOnly],
            'other host, no flag' => [['--url=https://example.com/hook'], $testOnly],
            'prod URL with trailing space, flag' => [['--allow-prod', '--url=' . self::PROD_URL . ' '], $prodOnly],
            'prod URL with leading space, flag' => [['--allow-prod', '--url= ' . self::PROD_URL], $prodOnly],
            'uppercase prod host, flag' => [['--allow-prod', '--url=https://ELANREGISTRY.ORG/app/api/webhooks/brevo.php'], $prodOnly],
            'uppercase test prefix, no flag' => [['--url=HTTPS://TEST.elanregistry.org/app/api/webhooks/brevo.php'], $testOnly],
            'http test URL, no flag' => [['--url=http://test.elanregistry.org/app/api/webhooks/brevo.php'], $testOnly],
        ];
    }

    /** @param list<string> $args */
    #[DataProvider('rejectedCases')]
    public function testRejectedUrlExitsTwoBeforeConfigLoad(array $args, string $guardMessage): void
    {
        $result = $this->runCreate($args);

        $this->assertSame(2, $result['exit'], 'STDERR: ' . $result['stderr']);
        $this->assertStringNotContainsString('No .env file', $result['stderr'], 'The guard must run before loadConfig()');
        $this->assertStringContainsString(
            $guardMessage,
            $result['stderr'],
            'The URL guard, not an earlier argument check, must reject the URL'
        );
        $this->assertStringNotContainsString(self::TOKEN, $result['stdout'], 'The token must never be printed');
        $this->assertStringNotContainsString(self::TOKEN, $result['stderr'], 'The token must never be printed');
    }

    /**
     * getopt drops an empty --url, so the missing-argument check stops it
     * before the guard runs.
     */
    public function testEmptyUrlStopsAtRequiredArgumentCheck(): void
    {
        foreach ([['--url='], ['--allow-prod', '--url=']] as $args) {
            $result = $this->runCreate($args);

            $this->assertSame(2, $result['exit'], 'STDERR: ' . $result['stderr']);
            $this->assertStringContainsString('--create requires --url and --token.', $result['stderr']);
            $this->assertStringNotContainsString(self::TOKEN, $result['stdout'] . $result['stderr']);
        }
    }

    public function testProdUrlWithoutFlagNamesAllowProd(): void
    {
        $result = $this->runCreate(['--url=' . self::PROD_URL]);

        $this->assertSame(2, $result['exit']);
        $this->assertStringContainsString('--allow-prod', $result['stderr']);
    }

    /**
     * URLs that must pass the guard and reach loadConfig()
     *
     * @return array<string, array{list<string>}>
     */
    public static function acceptedCases(): array
    {
        return [
            'prod URL, flag' => [['--allow-prod', '--url=' . self::PROD_URL]],
            'test URL, no flag' => [['--url=' . self::TEST_URL]],
        ];
    }

    /** @param list<string> $args */
    #[DataProvider('acceptedCases')]
    public function testAcceptedUrlReachesConfigLoad(array $args): void
    {
        $result = $this->runCreate($args);

        $this->assertSame(1, $result['exit'], 'STDERR: ' . $result['stderr']);
        $this->assertStringContainsString('No .env file found in ' . self::$emptyEnvDir, $result['stderr']);
        $this->assertStringNotContainsString(self::TOKEN, $result['stdout'] . $result['stderr']);
    }

    public function testUrlGuardRunsBeforeConfigLoadInMainFlow(): void
    {
        $source = file_get_contents(self::SCRIPT);
        self::assertIsString($source, 'The registration script must be readable');

        $mainFlow = strstr($source, '$args = parseArgs();');
        self::assertIsString($mainFlow, 'The script must have a main flow that starts at parseArgs()');

        $guard = strpos($mainFlow, 'createUrlError(');
        $load = strpos($mainFlow, 'loadConfig(');

        $this->assertIsInt($guard, 'The main flow must call createUrlError()');
        $this->assertIsInt($load, 'The main flow must call loadConfig()');
        $this->assertLessThan($load, $guard, 'createUrlError() must run before loadConfig()');
    }
}
