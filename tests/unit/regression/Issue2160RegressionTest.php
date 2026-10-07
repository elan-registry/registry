<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #2160: in PHPUnit 12 a CLI --exclude-group replaces the XML
 * <groups><exclude> list instead of merging with it, so a composer script
 * that passed one silently re-enabled the live-network tests. The
 * integration suite does not run in CI, so this unit test is the only CI
 * check on that wiring.
 *
 * @issue 2160
 * @link https://github.com/elan-registry/registry/issues/2160
 */
#[Group('regression')]
final class Issue2160RegressionTest extends TestCase
{
    private const INTEGRATION_CONFIG = 'phpunit-integration.xml';

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 3);
    }

    public function testNoIntegrationComposerScriptPassesExcludeGroup(): void
    {
        $offenders = [];
        $integrationCommandCount = 0;

        foreach ($this->composerScripts() as $scriptName => $commands) {
            foreach ($commands as $command) {
                if (!str_contains($command, self::INTEGRATION_CONFIG)) {
                    continue;
                }
                $integrationCommandCount++;
                if (str_contains($command, '--exclude-group')) {
                    $offenders[] = sprintf('composer script "%s": %s', $scriptName, $command);
                }
            }
        }

        $this->assertGreaterThan(
            0,
            $integrationCommandCount,
            'Expected at least one composer script referencing ' . self::INTEGRATION_CONFIG
                . ' — if the config was renamed, update this test'
        );
        $this->assertSame(
            [],
            $offenders,
            "These composer scripts pass --exclude-group to phpunit-integration.xml. In PHPUnit 12 "
                . "that REPLACES the XML <groups><exclude> list, silently re-enabling live-network "
                . "tests (#2160). Remove the flag and exclude groups in the XML instead:\n"
                . implode("\n", $offenders)
        );
    }

    public function testIntegrationConfigExcludesLiveNetworkAndKnownBroken(): void
    {
        $path = $this->projectRoot . '/' . self::INTEGRATION_CONFIG;
        $this->assertFileExists($path);

        $xml = simplexml_load_file($path);
        $this->assertNotFalse($xml, self::INTEGRATION_CONFIG . ' is not valid XML');

        $excluded = [];
        foreach ($xml->xpath('/phpunit/groups/exclude/group') ?: [] as $group) {
            $excluded[] = trim((string) $group);
        }

        foreach (['live-network', 'known-broken'] as $required) {
            $this->assertContains(
                $required,
                $excluded,
                sprintf(
                    '%s <groups><exclude> must list "%s" (found: %s)',
                    self::INTEGRATION_CONFIG,
                    $required,
                    $excluded === [] ? 'none' : implode(', ', $excluded)
                )
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function liveNetworkTestProvider(): array
    {
        return [
            'RobotsTxtAsServedTest' => ['RobotsTxtAsServedTest'],
            'VendorBootstrapMapsScriptTest' => ['VendorBootstrapMapsScriptTest'],
        ];
    }

    #[DataProvider('liveNetworkTestProvider')]
    public function testLiveNetworkIntegrationTestCarriesGroupAttribute(string $className): void
    {
        $file = $this->projectRoot . '/tests/integration/' . $className . '.php';
        $this->assertFileExists($file);
        require_once $file;

        $this->assertTrue(class_exists($className, false), "tests/integration/{$className}.php did not declare {$className}");

        $groups = [];
        foreach ((new ReflectionClass($className))->getAttributes(Group::class) as $attribute) {
            $groups[] = $attribute->newInstance()->name();
        }

        $this->assertContains(
            'live-network',
            $groups,
            "tests/integration/{$className}.php must carry #[Group('live-network')] so "
                . self::INTEGRATION_CONFIG . ' keeps it out of the default integration run (#2160)'
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function composerScripts(): array
    {
        $path = $this->projectRoot . '/composer.json';
        $this->assertFileExists($path);
        $json = file_get_contents($path);
        $this->assertIsString($json);

        $composer = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($composer);
        $this->assertIsArray($composer['scripts'] ?? null, 'composer.json has no "scripts" object');

        $scripts = [];
        foreach ($composer['scripts'] as $name => $value) {
            $commands = is_array($value) ? $value : [$value];
            $scripts[(string) $name] = array_values(array_filter($commands, 'is_string'));
        }

        return $scripts;
    }
}
