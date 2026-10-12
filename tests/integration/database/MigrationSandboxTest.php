<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationSandbox.php';

use Phinx\Migration\AbstractMigration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Self-tests for the migration sandbox harness (#2167).
 *
 * Each test makes and drops its own schemas, so this class does not use
 * MigrationSandboxTestCase.
 */
#[Group('integration')]
#[Group('migration')]
final class MigrationSandboxTest extends TestCase
{
    /** @var list<MigrationSandbox> */
    private array $sandboxes = [];

    /** @var list<string> */
    private array $extraSchemas = [];

    protected function tearDown(): void
    {
        foreach ($this->sandboxes as $sandbox) {
            $sandbox->drop();
        }
        $cleaner = new MigrationSandbox();
        foreach ($this->extraSchemas as $schema) {
            $cleaner->dropSchema($schema);
        }
        parent::tearDown();
    }

    public function testCreateLoadsVendorSchemaAndMigratesWithCarsTriggers(): void
    {
        $sharedBefore = $this->sharedPhinxlogState();

        $sandbox = $this->newSandbox()->create();
        $sandbox->migrateTo(null);

        $this->assertMatchesRegularExpression(MigrationSandbox::NAME_PATTERN, $sandbox->name());
        $this->assertTrue($this->schemaExists($sandbox->name()), 'create() must make the sandbox schema');
        $this->assertSame(
            1,
            $this->scalar($sandbox->pdo(), "SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings'"),
            'The vendor UserSpice schema must be loaded (settings table)'
        );

        $this->assertSame(
            ['cars_delete', 'cars_insert', 'cars_update'],
            $sandbox->triggerNames('cars'),
            'A full migrate must leave the three cars_* audit triggers in the sandbox'
        );

        $this->assertSame(
            $this->latestMigrationVersion(),
            $this->scalar($sandbox->pdo(), 'SELECT MAX(version) FROM phinxlog'),
            'The sandbox phinxlog must record the latest migration'
        );
        $this->assertSame(
            $sharedBefore,
            $this->sharedPhinxlogState(),
            'migrateTo() must not write to the shared schema phinxlog'
        );
    }

    public function testNameGuardRefusesSharedAndNonMigtestNames(): void
    {
        $guard = $this->newSandbox();
        $sharedName = MigrationSandbox::env('DB_NAME');
        $this->assertNotSame('', $sharedName, 'DB_NAME must be set by the integration bootstrap');

        $refused = [
            $sharedName,
            strtoupper($sharedName),
            'elanregi_spice',
            'elanregi_migtest_',
            'elanregi_migtest_abc_def',
            'elanregi_migtest_1_x`; DROP DATABASE elanregi_spice; --',
            'other_migtest_1700000000_abc',
            "elanregi_migtest_1_abc\n",
        ];
        $existedBefore = array_map(fn (string $name): bool => $this->schemaExists($name), $refused);

        foreach ($refused as $name) {
            try {
                $guard->dropSchema($name);
                $this->fail("dropSchema() must refuse {$name}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Refusing DDL', $e->getMessage());
            }
        }

        $this->assertTrue($this->schemaExists($sharedName), 'The shared schema must still exist');
        foreach ($refused as $index => $name) {
            $this->assertSame($existedBefore[$index], $this->schemaExists($name), "{$name} must be unchanged");
        }
    }

    public function testDropRemovesSchemaAndIsIdempotent(): void
    {
        $sandbox = $this->newSandbox()->create();
        $name = $sandbox->name();

        $sandbox->drop();

        $this->assertFalse($this->schemaExists($name), "{$name} must be gone after drop()");
        $sandbox->drop();
        $this->assertFalse($this->schemaExists($name), 'A second drop() must be a no-op');
    }

    public function testRunFailsWhenATableBuilderIsNotSaved(): void
    {
        $sandbox = $this->newSandbox()->create();
        $migration = $sandbox->bind(new class ('default', 20990101000000) extends AbstractMigration {
            public function up(): void
            {
                // No ->update(): composer migrate rejects this, so the sandbox must too.
                $this->table('settings')->addColumn('migtest_unsaved', 'integer', ['null' => true]);
            }
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pending actions');
        $sandbox->run($migration, 'up');
    }

    public function testRunUsesChangeOverUpAndInvertsChangeOnDown(): void
    {
        $sandbox = $this->newSandbox()->create();
        $migration = $sandbox->bind(new class ('default', 20990101000001) extends AbstractMigration {
            public function change(): void
            {
                $this->table('settings')->addColumn('migtest_change', 'integer', ['null' => true])->update();
            }

            public function up(): void
            {
                throw new \LogicException('Phinx ignores up() when change() exists, so the sandbox must too.');
            }
        });

        $sandbox->run($migration, 'up');
        $this->assertTrue($this->columnExists($sandbox, 'settings', 'migtest_change'), 'up must run change()');

        $sandbox->run($migration, 'down');
        $this->assertFalse($this->columnExists($sandbox, 'settings', 'migtest_change'), 'down must invert change()');
    }

    public function testRunRefusesAMigrationWithoutTheDirectionMethod(): void
    {
        $sandbox = $this->newSandbox()->create();
        $migration = $sandbox->bind(new class ('default', 20990101000002) extends AbstractMigration {
            public function up(): void
            {
            }
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no change() or down() method');
        $sandbox->run($migration, 'down');
    }

    public function testFingerprintDetectsAColumnChange(): void
    {
        $sandbox = $this->newSandbox()->create();
        $before = MigrationSandbox::fingerprint($sandbox->name());

        $sandbox->pdo()->exec('ALTER TABLE settings ADD COLUMN migtest_fingerprint_probe INT NULL');

        $this->assertNotSame($before, MigrationSandbox::fingerprint($sandbox->name()), 'A new column must change the fingerprint');
    }

    public function testFingerprintRefusesAnEmptyNameOrAnEmptySchema(): void
    {
        try {
            MigrationSandbox::fingerprint('');
            $this->fail('fingerprint() must refuse an empty schema name');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('empty name', $e->getMessage());
        }

        $empty = MigrationSandbox::NAME_PREFIX . time() . '_' . bin2hex(random_bytes(4));
        $this->extraSchemas = [$empty];
        (MigrationSandbox::defaultPdoFactory())(null)->exec("CREATE DATABASE `{$empty}`");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has no columns');
        MigrationSandbox::fingerprint($empty);
    }

    public function testSweepDropsOnlyStaleMigtestSchemas(): void
    {
        $server = (MigrationSandbox::defaultPdoFactory())(null);
        $suffix = bin2hex(random_bytes(4));
        $stale = MigrationSandbox::NAME_PREFIX . (time() - 7200) . '_' . $suffix;
        $fresh = MigrationSandbox::NAME_PREFIX . time() . '_' . $suffix;
        $this->extraSchemas = [$stale, $fresh];

        $server->exec("CREATE DATABASE `{$stale}`");
        $server->exec("CREATE DATABASE `{$fresh}`");

        $dropped = $this->newSandbox()->sweepStale(3600);

        $this->assertContains($stale, $dropped);
        $this->assertNotContains($fresh, $dropped);
        $this->assertFalse($this->schemaExists($stale), 'A schema older than the limit must be dropped');
        $this->assertTrue($this->schemaExists($fresh), 'A schema newer than the limit must be kept');
        $this->assertTrue($this->schemaExists(MigrationSandbox::env('DB_NAME')), 'The shared schema must be kept');
    }

    public function testCreateFailureThrowsAndDoesNotSkip(): void
    {
        $sandbox = $this->sandboxWhoseCreateFails(1044);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(MigrationSandbox::GRANT_FILE);

        $sandbox->create();
    }

    public function testCreateFailureNamesTheGrantOnlyForAccessDenied(): void
    {
        // 1007: the schema exists. The grant is not the cause, so the message must not name it.
        $sandbox = $this->sandboxWhoseCreateFails(1007);

        try {
            $sandbox->create();
            $this->fail('create() must throw when CREATE DATABASE fails');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('(simulated 1007)', $e->getMessage());
            $this->assertStringNotContainsString(MigrationSandbox::GRANT_FILE, $e->getMessage());
        }
    }

    /**
     * A sandbox whose CREATE DATABASE throws a PDOException with the given MySQL error code.
     */
    private function sandboxWhoseCreateFails(int $mysqlCode): MigrationSandbox
    {
        $default = MigrationSandbox::defaultPdoFactory();

        return $this->newSandbox(static function (?string $dbName) use ($default, $mysqlCode): \PDO {
            $real = $default($dbName);

            return new class ($real, $mysqlCode) extends \PDO {
                public function __construct(private \PDO $real, private int $mysqlCode)
                {
                    // The real connection does the work. No parent connection is made.
                }

                public function setAttribute(int $attribute, mixed $value): bool
                {
                    return $this->real->setAttribute($attribute, $value);
                }

                public function exec(string $statement): int|false
                {
                    if (str_starts_with($statement, 'CREATE DATABASE')) {
                        $e = new \PDOException("SQLSTATE[42000]: CREATE DATABASE failed (simulated {$this->mysqlCode})");
                        $e->errorInfo = ['42000', $this->mysqlCode, 'simulated'];
                        throw $e;
                    }

                    return $this->real->exec($statement);
                }
            };
        });
    }

    /**
     * @param (callable(?string): \PDO)|null $pdoFactory
     */
    private function newSandbox(?callable $pdoFactory = null): MigrationSandbox
    {
        $sandbox = new MigrationSandbox($pdoFactory);
        $this->sandboxes[] = $sandbox;

        return $sandbox;
    }

    private function columnExists(MigrationSandbox $sandbox, string $table, string $column): bool
    {
        $statement = $sandbox->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function schemaExists(string $name): bool
    {
        $statement = (MigrationSandbox::defaultPdoFactory())(null)->prepare(
            'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
        );
        $statement->execute([$name]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function scalar(\PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        $this->assertNotFalse($statement);
        $value = $statement->fetchColumn();
        // An int cast would turn no row or NULL into 0 and hide it.
        $this->assertNotFalse($value, "The query returned no row: {$sql}");
        $this->assertNotNull($value, "The query returned NULL: {$sql}");

        return (int) $value;
    }

    /**
     * @return array{int, int}
     */
    private function sharedPhinxlogState(): array
    {
        $pdo = (MigrationSandbox::defaultPdoFactory())(MigrationSandbox::env('DB_NAME'));

        return [
            $this->scalar($pdo, 'SELECT COUNT(*) FROM phinxlog'),
            $this->scalar($pdo, 'SELECT COALESCE(MAX(version), 0) FROM phinxlog'),
        ];
    }

    private function latestMigrationVersion(): int
    {
        $files = glob(dirname(__DIR__, 3) . '/database/migrations/*.php');
        $this->assertNotFalse($files);
        $this->assertNotEmpty($files);

        return max(array_map(
            static fn (string $file): int => \Phinx\Util\Util::getVersionFromFileName(basename($file)),
            $files
        ));
    }
}
