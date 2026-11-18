<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\BootstrapDeprecationGuard;

/**
 * Issue #2491: PHPUnit reported a PHP deprecation but the run still exited 0,
 * so a new deprecation in project code passed the hooks and CI (#2490 was one).
 * Each phpunit*.xml must fail on deprecations, put every committed PHP file
 * under <source>, and ignore the indirect deprecations that untracked upstream
 * code raises, which this repo cannot fix. BootstrapDeprecationGuard covers
 * the deprecations that PHPUnit does not see: those raised while a bootstrap
 * loads a file. See tests/README.md, "Deprecations Fail the Run".
 *
 * The config tests read the config text. A behavior test would have to start
 * a second PHPUnit run with a planted deprecation, which costs more than the
 * attribute check it replaces.
 *
 * @issue 2491
 * @link https://github.com/elan-registry/registry/issues/2491
 */
#[Group('regression')]
final class Issue2491RegressionTest extends TestCase
{
    private ?string $fixtureRoot = null;

    protected function tearDown(): void
    {
        if ($this->fixtureRoot !== null) {
            array_map('unlink', glob($this->fixtureRoot . '/*.php') ?: []);
            rmdir($this->fixtureRoot);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}> config basename => [absolute path]
     */
    public static function configProvider(): array
    {
        $files = glob(dirname(__DIR__, 3) . '/phpunit*.xml') ?: [];
        sort($files);

        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    #[DataProvider('configProvider')]
    public function testConfigFailsOnDeprecation(string $path): void
    {
        $this->assertSame(
            'true',
            (string) self::load($path)['failOnDeprecation'],
            basename($path) . ' must set failOnDeprecation="true" on <phpunit>, or a new deprecation passes the run'
        );
    }

    #[DataProvider('configProvider')]
    public function testConfigIgnoresIndirectDeprecations(string $path): void
    {
        $source = self::load($path)->source;

        $this->assertCount(1, $source, basename($path) . ' must have one <source> element');
        $this->assertSame(
            'true',
            (string) $source['ignoreIndirectDeprecations'],
            basename($path) . ' must set ignoreIndirectDeprecations="true" on <source>, or an upstream deprecation fails every run'
        );
    }

    /**
     * A new committed directory or root file outside <source> would make its
     * deprecations indirect, and ignoreIndirectDeprecations would skip them.
     */
    #[DataProvider('configProvider')]
    public function testSourceCoversEveryCommittedPhpFile(string $path): void
    {
        $source = self::load($path)->source;
        $includeDirs = self::paths($source->include->directory);
        $includeFiles = self::paths($source->include->file);
        $excludeDirs = self::paths($source->exclude->directory);
        $excludeFiles = self::paths($source->exclude->file);

        $uncovered = [];
        foreach (BootstrapDeprecationGuard::committedPhpFiles(dirname(__DIR__, 3)) as $file) {
            $included = in_array($file, $includeFiles, true) || self::underAny($file, $includeDirs);
            $excluded = in_array($file, $excludeFiles, true) || self::underAny($file, $excludeDirs);
            if (!$included || $excluded) {
                $uncovered[] = $file;
            }
        }

        $this->assertSame(
            [],
            $uncovered,
            basename($path) . ' <source> must cover every committed PHP file outside tests/'
        );
    }

    /**
     * #2490 was an engine deprecation (E_DEPRECATED), not a user one.
     *
     * @return array<string, array{string}>
     */
    public static function deprecationProvider(): array
    {
        return [
            'E_USER_DEPRECATED' => ["trigger_error('fixture', E_USER_DEPRECATED);"],
            'E_DEPRECATED' => ['strlen(null);'],
        ];
    }

    #[DataProvider('deprecationProvider')]
    public function testGuardThrowsWithFileAndLineForCommittedDeprecation(string $statement): void
    {
        $root = $this->fixtureRoot();
        [$thrown, $forwarded] = $this->runGuard($root, ['committed.php'], 'committed.php', $statement);

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertStringContainsString(realpath($root . '/committed.php') . ':4 ', $thrown->getMessage());
        $this->assertSame([], $forwarded, 'a recorded deprecation must not reach the previous handler');
    }

    public function testGuardPassesUpstreamDeprecationToPreviousHandler(): void
    {
        [$thrown, $forwarded] = $this->runGuard($this->fixtureRoot(), ['committed.php'], 'upstream.php', "trigger_error('fixture', E_USER_DEPRECATED);");

        $this->assertNull($thrown, 'a deprecation from an untracked file must not fail the run');
        $this->assertSame([E_USER_DEPRECATED], $forwarded);
    }

    public function testGuardPassesNonDeprecationToPreviousHandler(): void
    {
        [$thrown, $forwarded] = $this->runGuard($this->fixtureRoot(), ['committed.php'], 'committed.php', "trigger_error('fixture', E_USER_NOTICE);");

        $this->assertNull($thrown);
        $this->assertSame([E_USER_NOTICE], $forwarded);
    }

    public function testGuardStopRestoresPreviousHandler(): void
    {
        $previous = static fn (): bool => true;
        set_error_handler($previous);
        try {
            BootstrapDeprecationGuard::start($this->fixtureRoot(), ['committed.php']);
            BootstrapDeprecationGuard::stop();

            $current = set_error_handler(null);
            restore_error_handler();
            $this->assertSame($previous, $current);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Run the fixture file under the guard, with a recording handler as the
     * previous handler.
     *
     * @param list<string> $committed
     * @return array{RuntimeException|null, list<int>} what stop() threw, and
     *                                                the error levels that
     *                                                reached the previous handler
     */
    private function runGuard(string $root, array $committed, string $fixture, string $statement): array
    {
        // No strict_types: strlen(null) must raise the deprecation, not a TypeError.
        file_put_contents("{$root}/{$fixture}", "<?php\n\n// Issue2491RegressionTest fixture\n{$statement}\n");

        $forwarded = [];
        set_error_handler(static function (int $errno) use (&$forwarded): bool {
            $forwarded[] = $errno;
            return true;
        });
        $thrown = null;
        // PHPUnit lowers error_reporting() during a test. A bootstrap runs
        // with the configured E_ALL, and the guard skips masked errors.
        $reporting = error_reporting(E_ALL);
        try {
            BootstrapDeprecationGuard::start($root, $committed);
            require "{$root}/{$fixture}";
            BootstrapDeprecationGuard::stop();
        } catch (RuntimeException $e) {
            $thrown = $e;
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }

        return [$thrown, $forwarded];
    }

    private function fixtureRoot(): string
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/issue2491-' . bin2hex(random_bytes(4));
        mkdir($this->fixtureRoot);

        return $this->fixtureRoot;
    }

    /**
     * @return list<string>
     */
    private static function paths(SimpleXMLElement $elements): array
    {
        $paths = [];
        foreach ($elements as $element) {
            $paths[] = rtrim((string) $element, '/');
        }

        return $paths;
    }

    /**
     * @param list<string> $dirs
     */
    private static function underAny(string $file, array $dirs): bool
    {
        foreach ($dirs as $dir) {
            if (str_starts_with($file, $dir . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function load(string $path): SimpleXMLElement
    {
        $xml = simplexml_load_file($path);
        if ($xml === false) {
            throw new RuntimeException("Cannot parse {$path}");
        }

        return $xml;
    }
}
