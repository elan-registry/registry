<?php

declare(strict_types=1);

require_once __DIR__ . '/DeliverabilityMigrationSandboxTestCase.php';

use Phinx\Migration\AbstractMigration;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `migrate --dry-run` and `rollback --dry-run` of migration 20261012000000 (#2101).
 *
 * In a Phinx dry run, execute() runs nothing but query() and fetchAll() still
 * run. Without the guard, phase 1 would read a column that the dry run never
 * added. `composer migrate:dry-run` is a pre-deploy step, so it must not fail.
 */
#[Group('integration')]
#[Group('migration')]
final class DeliverabilityMigrationDryRunSandboxTest extends DeliverabilityMigrationSandboxTestCase
{
    private const DRY_RUN_MESSAGE =
        'Dry run: this migration has no dry-run output. It reads data that earlier steps create.';

    protected static function targetVersion(): int
    {
        return self::VERSION_BEFORE;
    }

    public function testDryRunOfUpAndDownChangesNothingAndSaysSo(): void
    {
        $before = $this->schemaSnapshot();
        $this->assertCount(3, $before['triggers'], 'The sandbox must start with the three cars triggers');
        $profilesBefore = $this->profileSnapshot();

        $this->assertSame(self::DRY_RUN_MESSAGE, $this->dryRun('up'));
        $this->assertSame($before, $this->schemaSnapshot(), 'A dry-run up() must not change the schema');
        $this->assertSame($profilesBefore, $this->profileSnapshot(), 'A dry-run up() must not change profiles rows');

        // down() needs a migrated schema, or its guard would hide behind a missing column.
        $this->upWithOutput();
        $migrated = $this->schemaSnapshot();
        $this->assertNotSame($before, $migrated, 'The real up() must change the schema');

        $this->assertSame(self::DRY_RUN_MESSAGE, $this->dryRun('down'));
        $this->assertSame($migrated, $this->schemaSnapshot(), 'A dry-run down() must not change the schema');
    }

    /**
     * Run one direction with `--dry-run` on the migration and its adapter, as Phinx does.
     *
     * @param 'up'|'down' $direction
     *
     * @return string The trimmed migration output
     */
    private function dryRun(string $direction): string
    {
        $migration = $this->migration(self::MIGRATION);
        $this->enableDryRun($migration);
        $output = new BufferedOutput();
        $migration->setOutput($output);
        $this->sandbox()->run($migration, $direction);

        return trim($output->fetch());
    }

    private function enableDryRun(AbstractMigration $migration): void
    {
        $input = new ArrayInput(
            ['--dry-run' => true],
            new InputDefinition([new InputOption('dry-run', null, InputOption::VALUE_NONE)])
        );
        $migration->setInput($input);
        $migration->getAdapter()->setInput($input);
    }

    /**
     * @return array{profiles: list<string>, cars: list<string>, cars_hist: list<string>, triggers: array<string, string>}
     */
    private function schemaSnapshot(): array
    {
        return [
            'profiles'  => $this->columnNames('profiles'),
            'cars'      => $this->columnNames('cars'),
            'cars_hist' => $this->columnNames('cars_hist'),
            'triggers'  => $this->carsTriggerBodies(),
        ];
    }
}
