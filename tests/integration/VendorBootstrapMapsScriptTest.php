<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #1414: scripts/vendor-bootstrap-maps.php run as a real subprocess. The
 * `projectRoot` argument points failure-path tests at a fixture directory.
 * The happy path hits the jsdelivr CDN, so the class is in the excluded
 * `live-network` group. Run it with:
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
        // A real version banner with content that does not match v5.3.8: the
        // script must fetch, compare, and skip rather than vendor a bogus map.
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
        // Real project files and a real jsdelivr round trip, as on deploy.
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
