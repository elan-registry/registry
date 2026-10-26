<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationSandboxTestCase.php';

use Phinx\Migration\AbstractMigration;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `migrate --dry-run` and `rollback --dry-run` of migration 20261023000000 (#2419).
 *
 * In a Phinx dry run, execute() runs nothing but fetchAll() still runs. up()
 * skips its trigger check in a dry run, because the trigger cannot exist.
 * Without that skip, the check would throw. `composer migrate:dry-run` is a
 * pre-deploy step, so it must not fail and must not change the schema.
 */
#[Group('integration')]
#[Group('migration')]
final class AddDeletedUserAddressesDryRunSandboxTest extends MigrationSandboxTestCase
{
    private const MIGRATION = '20261023000000_add_deleted_user_addresses_capture.php';

    private const VERSION_BEFORE = 20261013000000;

    private const TABLE = 'er_deleted_user_addresses';

    private const TRIGGER = 'users_delete_capture_addresses';

    protected static function targetVersion(): int
    {
        return self::VERSION_BEFORE;
    }

    public function testDryRunOfUpAndDownFromTheStateBeforeChangesNothing(): void
    {
        $this->assertFalse($this->tableExists(), 'The sandbox must start without the holding table');
        $this->assertFalse($this->triggerExists(), 'The sandbox must start without the capture trigger');
        $before = MigrationSandbox::fingerprint($this->sandbox()->name());
        $trustBefore = $this->trustFunctionCreators();

        $this->dryRun('up');

        $this->assertFalse($this->tableExists(), 'A dry-run up() must not create the holding table');
        $this->assertFalse($this->triggerExists(), 'A dry-run up() must not create the capture trigger');
        $this->assertSame($before, MigrationSandbox::fingerprint($this->sandbox()->name()), 'A dry-run up() must not change the schema');
        $this->assertSame($trustBefore, $this->trustFunctionCreators(), 'A dry-run up() must not change log_bin_trust_function_creators');

        $this->dryRun('down');

        $this->assertFalse($this->tableExists(), 'A dry-run down() must not create the holding table');
        $this->assertFalse($this->triggerExists(), 'A dry-run down() must not create the capture trigger');
        $this->assertSame($before, MigrationSandbox::fingerprint($this->sandbox()->name()), 'A dry-run down() must not change the schema');
        $this->assertSame($trustBefore, $this->trustFunctionCreators(), 'A dry-run down() must not change log_bin_trust_function_creators');
    }

    /**
     * Run one direction with `--dry-run` on the migration and its adapter, as Phinx does.
     *
     * @param 'up'|'down' $direction
     */
    private function dryRun(string $direction): void
    {
        $migration = $this->migration(self::MIGRATION);
        $this->enableDryRun($migration);
        $migration->setOutput(new BufferedOutput());
        $this->sandbox()->run($migration, $direction);
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

    private function tableExists(): bool
    {
        return $this->scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::TABLE]
        ) > 0;
    }

    private function triggerExists(): bool
    {
        return $this->scalar(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [self::TRIGGER]
        ) > 0;
    }

    private function trustFunctionCreators(): int
    {
        return $this->scalar('SELECT @@GLOBAL.log_bin_trust_function_creators');
    }
}
