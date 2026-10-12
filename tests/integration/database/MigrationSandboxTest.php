<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationSandbox.php';

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

    public function testDropRemovesSchemaAfterAFailingCallback(): void
    {
        $sandbox = $this->newSandbox()->create();
        $name = $sandbox->name();

        try {
            (static function (): void {
                throw new \DomainException('simulated test failure');
            })();
        } catch (\DomainException) {
            // Proves that drop() still removes the schema after a test failure, and that a
            // second drop() is a no-op.
        } finally {
            $sandbox->drop();
        }

        $this->assertFalse($this->schemaExists($name), "{$name} must be gone after drop()");
        $sandbox->drop();
        $this->assertFalse($this->schemaExists($name), 'A second drop() must be a no-op');
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
        $default = MigrationSandbox::defaultPdoFactory();
        $sandbox = $this->newSandbox(static function (?string $dbName) use ($default): \PDO {
            $real = $default($dbName);

            return new class ($real) extends \PDO {
                public function __construct(private \PDO $real)
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
                        throw new \PDOException("SQLSTATE[42000]: Access denied for user to database (simulated)");
                    }

                    return $this->real->exec($statement);
                }
            };
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(MigrationSandbox::GRANT_FILE);

        $sandbox->create();
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

        return (int) $statement->fetchColumn();
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
