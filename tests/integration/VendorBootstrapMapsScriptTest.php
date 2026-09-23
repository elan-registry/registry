<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for scripts/vendor-bootstrap-maps.php (Issue #1414).
 *
 * Like scripts/log-deployment.php (#1424), this is a standalone CLI script
 * with no class/function to unit test directly — invoked as a real
 * subprocess. No database is involved, so this extends PHPUnit's TestCase
 * directly rather than IntegrationTestCase.
 *
 * The script accepts an optional `projectRoot` CLI argument (defaults to the
 * real repo root) specifically so tests can point it at a disposable fixture
 * directory instead of the real project's users/ tree — this lets the
 * non-network-dependent failure paths (missing file, unparseable version) be
 * tested without touching real project files or the network. The happy-path
 * and idempotency-short-circuit tests genuinely hit the official jsdelivr
 * CDN (same as a real deploy would), since that's the actual behavior being
 * verified and the project already assumes deploy-time network access for
 * this exact script. Because of those live calls the class carries
 * `#[Group('live-network')]`, which `phpunit-integration.xml` excludes from
 * the default run. Run it explicitly with:
 *   vendor/bin/phpunit -c phpunit-integration.xml --group live-network
 */
#[Group('integration')]
#[Group('live-network')]
final class VendorBootstrapMapsScriptTest extends TestCase
{
    private const SCRIPT_PATH = __DIR__ . '/../../scripts/vendor-bootstrap-maps.php';

    private string $fixtureRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = sys_get_temp_dir() . '/vendor-bootstrap-maps-test-' . uniqid();
        mkdir($this->fixtureRoot . '/users/css', 0755, true);
        mkdir($this->fixtureRoot . '/users/js', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixtureRoot);
        parent::tearDown();
    }

    public function testMissingVersionFileIsNonFatal(): void
    {
        // No users/js/bootstrap.bundle.min.js at all in the fixture.
        [$returnCode, $output] = $this->runScript($this->fixtureRoot);

        $this->assertSame(0, $returnCode, 'Script must exit 0 when the vendored file is missing. Output: ' . implode("\n", $output));
        $this->assertStringContainsString('not found, skipping', implode("\n", $output));
    }

    public function testUnparseableVersionIsNonFatal(): void
    {
        file_put_contents(
            $this->fixtureRoot . '/users/js/bootstrap.bundle.min.js',
            "/* not a real Bootstrap build, no version banner */\nconsole.log('nope');"
        );

        [$returnCode, $output] = $this->runScript($this->fixtureRoot);

        $this->assertSame(0, $returnCode, 'Script must exit 0 when the version cannot be parsed. Output: ' . implode("\n", $output));
        $this->assertStringContainsString('could not parse Bootstrap version', implode("\n", $output));
        $this->assertFileDoesNotExist($this->fixtureRoot . '/users/js/bootstrap.bundle.min.js.map');
    }

    public function testMismatchedLocalFileSkipsMapWithoutOverwriting(): void
    {
        // A real Bootstrap version banner, but content that won't byte-match the
        // official v5.3.8 release — the script must fetch-and-compare (real
        // network call to jsdelivr) and then skip rather than vendor a bogus map.
        file_put_contents(
            $this->fixtureRoot . '/users/js/bootstrap.bundle.min.js',
            "/*!\n  * Bootstrap v5.3.8 (https://getbootstrap.com/)\n  */\nconsole.log('locally modified, not the real build');"
        );
        file_put_contents(
            $this->fixtureRoot . '/users/css/bootstrap.min.css',
            "/*!\n * Bootstrap  v5.3.8 (https://getbootstrap.com/)\n */\nbody{color:red}"
        );

        [$returnCode, $output] = $this->runScript($this->fixtureRoot);
        $outputText = implode("\n", $output);

        $this->assertSame(0, $returnCode, 'Script must exit 0 on a hash mismatch. Output: ' . $outputText);
        $this->assertStringContainsString('does not byte-match the official', $outputText);
        $this->assertFileDoesNotExist($this->fixtureRoot . '/users/js/bootstrap.bundle.min.js.map');
        $this->assertFileDoesNotExist($this->fixtureRoot . '/users/css/bootstrap.min.css.map');
    }

    public function testRealProjectFilesVendorMatchingMapsAndShortCircuitOnRerun(): void
    {
        // Exercises the real happy path against the actual project files (which are
        // already known-good, verified-matching Bootstrap 5.3.8 builds), including a
        // real network round-trip to jsdelivr — mirroring exactly what a real
        // `git pull` or deploy does. No projectRoot override: uses the real repo.
        $projectRoot = dirname(__DIR__, 2);
        $cssMapPath = $projectRoot . '/users/css/bootstrap.min.css.map';
        $jsMapPath = $projectRoot . '/users/js/bootstrap.bundle.min.js.map';
        $cssSourceHashPath = $cssMapPath . '.source-hash';
        $jsSourceHashPath = $jsMapPath . '.source-hash';

        $this->assertFileExists($projectRoot . '/users/js/bootstrap.bundle.min.js', 'Real project must have a vendored Bootstrap build for this test to be meaningful.');

        [$returnCode, $output] = $this->runScript($projectRoot);
        $this->assertSame(0, $returnCode, 'Script must exit 0 against real project files. Output: ' . implode("\n", $output));

        $this->assertFileExists($cssMapPath);
        $this->assertFileExists($jsMapPath);
        $this->assertJson((string)file_get_contents($cssMapPath), 'Vendored CSS map must be valid JSON.');
        $this->assertJson((string)file_get_contents($jsMapPath), 'Vendored JS map must be valid JSON.');
        $this->assertFileExists($cssSourceHashPath, 'Idempotency sidecar hash must be written after a successful vendor.');
        $this->assertFileExists($jsSourceHashPath, 'Idempotency sidecar hash must be written after a successful vendor.');

        // Re-run: with the sidecar hash now matching, both assets must short-circuit
        // with zero network calls — assert via the absence of any "updated" line.
        [$returnCodeRerun, $outputRerun] = $this->runScript($projectRoot);
        $this->assertSame(0, $returnCodeRerun);
        $this->assertStringNotContainsString('updated (Bootstrap', implode("\n", $outputRerun), 'Second run must short-circuit instead of re-vendoring.');
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function runScript(string $projectRoot): array
    {
        $command = 'php ' . escapeshellarg(self::SCRIPT_PATH) . ' ' . escapeshellarg($projectRoot) . ' 2>&1';

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        return [$returnCode, $output];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $path . '/' . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }
        rmdir($path);
    }
}
