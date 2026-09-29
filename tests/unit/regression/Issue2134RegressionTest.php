<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #2134: every PHPUnit config must set its own
 * memory_limit, and nothing may set it anywhere else.
 *
 * With no explicit limit, a run inherited the ambient php.ini. At the host
 * CLI's 128M default the unit suite (one process, peak about 128MB) died
 * mid-run with "Premature end of PHP process" and no summary line, which
 * looks like nothing at all to the review workflow's summary-line check.
 *
 * PHPUnit applies `<php><ini>` values with ini_set(), so the XML value
 * overrides php.ini and any `php -d memory_limit`. That makes the configs
 * the single source of truth, and this test pins it:
 *
 * - every phpunit*.xml in the project root declares exactly one
 *   memory_limit, found by glob so a new config is covered automatically;
 * - they all agree, and the value leaves real headroom (not unlimited);
 * - no composer script or git hook passes its own `-d memory_limit` to
 *   PHPUnit, which would be inert today but a second place to change.
 *
 * See tests/README.md, "Suite Dies with ...".
 *
 * @issue 2134
 * @link https://github.com/elan-registry/registry/issues/2134
 * @category regression
 */
#[Group('regression')]
final class Issue2134RegressionTest extends TestCase
{
    /** The configs this was written against; the glob must find at least these. */
    private const KNOWN_CONFIGS = ['phpunit.xml', 'phpunit-unit.xml', 'phpunit-integration.xml'];

    /** About twice the measured unit-suite peak. Below this, the fix is undone. */
    private const MIN_BYTES = 256 * 1024 * 1024;

    private static function projectRoot(): string
    {
        // tests/unit/regression/ is three levels below the project root
        return dirname(__DIR__, 3);
    }

    /**
     * @return array<string, array{string}> config basename => [absolute path]
     */
    public static function configProvider(): array
    {
        $files = glob(self::projectRoot() . '/phpunit*.xml') ?: [];
        sort($files);

        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }
        return $cases;
    }

    public function testGlobFindsEveryKnownConfig(): void
    {
        $found = array_keys(self::configProvider());

        foreach (self::KNOWN_CONFIGS as $config) {
            $this->assertContains(
                $config,
                $found,
                "Glob of phpunit*.xml must find {$config} — if it was renamed, update KNOWN_CONFIGS"
            );
        }
    }

    #[DataProvider('configProvider')]
    public function testConfigDeclaresExactlyOneMemoryLimit(string $path): void
    {
        $values = self::memoryLimitValues($path);

        $this->assertCount(
            1,
            $values,
            basename($path) . ' must declare exactly one <php><ini name="memory_limit"> (found '
                . count($values) . '). Without it the suite inherits php.ini and dies at 128M (#2134).'
        );
    }

    #[DataProvider('configProvider')]
    public function testMemoryLimitLeavesRealHeadroom(string $path): void
    {
        $values = self::memoryLimitValues($path);
        $this->assertNotEmpty($values, basename($path) . ' declares no memory_limit');

        $bytes = self::toBytes($values[0]);

        $this->assertNotSame(
            -1,
            $bytes,
            basename($path) . ': memory_limit must not be unlimited (-1); PHPUnit would never stop a runaway leak'
        );
        $this->assertGreaterThanOrEqual(
            self::MIN_BYTES,
            $bytes,
            basename($path) . ": memory_limit '{$values[0]}' is below 256M, about twice the unit suite's peak"
        );
    }

    public function testAllConfigsAgreeOnTheValue(): void
    {
        $byConfig = [];
        foreach (self::configProvider() as $name => [$path]) {
            $values = self::memoryLimitValues($path);
            $byConfig[$name] = $values[0] ?? '(none)';
        }

        $this->assertCount(
            1,
            array_unique($byConfig),
            'Every phpunit*.xml must set the same memory_limit; found: ' . json_encode($byConfig)
        );
    }

    /**
     * A `-d memory_limit` on a PHPUnit call would be overridden by the XML's
     * ini_set() anyway, so it only adds a second place to change the value.
     */
    public function testNoComposerScriptOrHookSetsItsOwnMemoryLimit(): void
    {
        $offenders = [];

        $composer = json_decode(
            (string) file_get_contents(self::projectRoot() . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach ($composer['scripts'] ?? [] as $name => $commands) {
            foreach ((array) $commands as $command) {
                if (is_string($command) && self::isPhpunitCallSettingMemoryLimit($command)) {
                    $offenders[] = "composer script \"{$name}\": {$command}";
                }
            }
        }

        $hooks = glob(self::projectRoot() . '/.githooks/*') ?: [];
        $this->assertNotEmpty($hooks, 'Expected git hooks under .githooks/ — if they moved, update this test');
        foreach ($hooks as $hook) {
            foreach (file($hook, FILE_IGNORE_NEW_LINES) ?: [] as $lineNo => $line) {
                if (str_starts_with(ltrim($line), '#')) {
                    continue; // comments may explain the rule
                }
                if (self::isPhpunitCallSettingMemoryLimit($line)) {
                    $offenders[] = basename($hook) . ':' . ($lineNo + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These PHPUnit calls set their own memory_limit. Set it in the phpunit*.xml configs instead (#2134).'
        );
    }

    /**
     * @return list<string> every memory_limit value declared under <php>
     */
    private static function memoryLimitValues(string $path): array
    {
        $xml = simplexml_load_file($path);
        if ($xml === false) {
            throw new RuntimeException("Could not parse {$path}");
        }

        $values = [];
        foreach ($xml->xpath('/phpunit/php/ini[@name="memory_limit"]') ?: [] as $node) {
            $values[] = (string) $node['value'];
        }
        return $values;
    }

    private static function isPhpunitCallSettingMemoryLimit(string $line): bool
    {
        return str_contains($line, 'phpunit') && str_contains($line, 'memory_limit');
    }

    /**
     * PHP's shorthand (e.g. "512M", "1G") to bytes; "-1" stays -1.
     */
    private static function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }

        $number = (int) $value;
        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
