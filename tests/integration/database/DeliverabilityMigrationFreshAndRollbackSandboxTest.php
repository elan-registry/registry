<?php

declare(strict_types=1);

require_once __DIR__ . '/DeliverabilityMigrationSandboxTestCase.php';

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `migrate` from an empty schema, and `rollback` past migration 20261012000000 (#2101).
 *
 * The sandbox starts at the vendor schema and migrates to the latest version,
 * so the older migrations that name the removed columns run in order. The older
 * migrations are not edited, so the rollback depends on down() to bring the
 * columns back.
 */
#[Group('integration')]
#[Group('migration')]
final class DeliverabilityMigrationFreshAndRollbackSandboxTest extends DeliverabilityMigrationSandboxTestCase
{
    /** The newest migration. Move it when a later migration lands (#2358: 20261013000000). */
    private const FINAL_VERSION = 20261013000000;

    /** An older migration, before the first migration that adds the removed columns. */
    private const VERSION_OLD = 20260825120000;

    protected static function targetVersion(): ?int
    {
        return null;
    }

    public function testMigrateFromAnEmptySchemaReachesTheFinalSchema(): void
    {
        $this->assertFinalSchema();
        $this->assertSame(
            3,
            $this->scalar('SELECT COUNT(*) FROM phinxlog WHERE version IN (20261012000000, 20261012000001, 20261013000000)')
        );
    }

    #[Depends('testMigrateFromAnEmptySchemaReachesTheFinalSchema')]
    public function testRollbackPastTheNewMigrationsRestoresThePreMigrationSchema(): void
    {
        $this->rollbackTo(self::VERSION_BEFORE);

        $this->assertSame(self::VERSION_BEFORE, $this->scalar('SELECT MAX(version) FROM phinxlog'));
        foreach (['cars', 'cars_hist'] as $table) {
            foreach (['email_bounced', 'email_bounced_address', 'email_suppressed'] as $column) {
                $this->assertNotNull($this->columnInfo($table, $column), "{$table}.{$column} must be back");
            }
        }
        $this->assertNotNull($this->columnInfo('profiles', 'email_suppressed'));
        $this->assertNull($this->columnInfo('profiles', 'email_opted_out'));
        $this->assertNull($this->columnInfo('profiles', 'email_blocked'));
        $this->assertNull($this->columnInfo('er_email_events', 'user_id'), 'The #2358 rollback must remove user_id');
        $this->assertFalse($this->hasIndex('profiles', 'uniq_profiles_user_id'));
        $this->assertNull($this->columnInfo('deleted_accounts_archive', 'email_opted_out'));
        $triggerBodies = $this->carsTriggerBodies();
        $this->assertCount(3, $triggerBodies, 'The rollback must restore all three cars triggers');
        foreach ($triggerBodies as $name => $body) {
            $this->assertStringContainsString('email_bounced_address', $body, "{$name} must name the car columns again");
        }
    }

    #[Depends('testRollbackPastTheNewMigrationsRestoresThePreMigrationSchema')]
    public function testRollbackFurtherThroughTheOlderMigrationsThatNameTheColumns(): void
    {
        $this->rollbackTo(self::VERSION_OLD);

        $this->assertSame(self::VERSION_OLD, $this->scalar('SELECT MAX(version) FROM phinxlog'));
        $this->assertNull($this->columnInfo('cars', 'email_bounced'), 'The older down() drops the column it added');
        $this->assertNull($this->columnInfo('cars_hist', 'email_bounced'));
    }

    #[Depends('testRollbackFurtherThroughTheOlderMigrationsThatNameTheColumns')]
    public function testMigrateAgainAfterTheRollbackReachesTheFinalSchema(): void
    {
        $manager = $this->manager(new BufferedOutput());
        $manager->migrate('default');

        $this->assertFinalSchema();
    }

    private function assertFinalSchema(): void
    {
        $this->assertSame(self::FINAL_VERSION, $this->scalar('SELECT MAX(version) FROM phinxlog'));
        foreach (['cars', 'cars_hist'] as $table) {
            foreach (['email_bounced', 'email_bounced_address', 'email_suppressed'] as $column) {
                $this->assertNull($this->columnInfo($table, $column), "{$table}.{$column} must not exist");
            }
        }
        $this->assertNull($this->columnInfo('profiles', 'email_suppressed'));
        foreach ([
            'email_opted_out', 'email_opted_out_at', 'email_blocked', 'email_blocked_address', 'email_blocked_at',
            'email_blocked_cause', 'email_bounced', 'email_bounced_address', 'email_bounced_at',
            'email_bounce_cleared_at', 'email_address_since', 'email_address_seen',
        ] as $column) {
            $this->assertNotNull($this->columnInfo('profiles', $column), "profiles.{$column} must exist");
        }
        $this->assertTrue($this->hasIndex('profiles', 'uniq_profiles_user_id'));
        $this->assertNotNull($this->columnInfo('er_email_events', 'user_id'), '#2358: er_email_events is keyed by user');
        $this->assertTrue($this->hasIndex('er_email_events', 'uniq_er_email_events_user_message_event'));
        $this->assertNotNull($this->columnInfo('deleted_accounts_archive', 'email_opted_out'));
        $this->assertNotNull($this->columnInfo('deleted_accounts_archive', 'email_opted_out_at'));
        $this->assertSame(['cars_delete', 'cars_insert', 'cars_update'], array_keys($this->carsTriggerBodies()));
    }

    private function rollbackTo(int $version): void
    {
        $output = new BufferedOutput();
        try {
            $this->manager($output)->rollback('default', $version);
        } catch (\Throwable $e) {
            $this->fail('The rollback to ' . $version . ' failed: ' . $e->getMessage() . "\n" . $output->fetch());
        }
    }

    private function manager(BufferedOutput $output): Manager
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 3) . '/phinx.php';
        $config['paths'] = [
            'migrations' => dirname(__DIR__, 3) . '/database/migrations',
            'seeds'      => dirname(__DIR__, 3) . '/database/seeds',
        ];
        $config['environments']['default']['name'] = $this->sandbox()->name();

        return new Manager(new Config($config), new ArrayInput([]), $output);
    }
}
