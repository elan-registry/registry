<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationSandboxTestCase.php';
require_once __DIR__ . '/DeliverabilityMigrationFixture.php';

use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Shared steps for the sandbox tests of migration 20261012000000 (#2101).
 *
 * The sandbox stands at the version before the migration. The first test of a
 * class writes {@see DeliverabilityMigrationFixture} into it.
 */
abstract class DeliverabilityMigrationSandboxTestCase extends MigrationSandboxTestCase
{
    protected const MIGRATION = '20261012000000_move_deliverability_flags_to_profiles.php';

    protected const VERSION_BEFORE = 20261007005444;

    /** @var array<class-string, true> */
    private static array $seeded = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (static::targetVersion() === self::VERSION_BEFORE && !isset(self::$seeded[static::class])) {
            DeliverabilityMigrationFixture::seed($this->pdo());
            self::$seeded[static::class] = true;
        }
    }

    /**
     * Run up() and return the migration output, as Phinx prints it.
     */
    protected function upWithOutput(): string
    {
        $migration = $this->migration(self::MIGRATION);
        $output = new BufferedOutput();
        $migration->setOutput($output);
        $this->sandbox()->run($migration, 'up');

        return $output->fetch();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function profile(int $userId): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM profiles WHERE user_id = ?');
        $statement->execute([$userId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        /** @var array<string, mixed>|false $row */
        return $row === false ? null : $row;
    }

    /**
     * Every profiles row, ordered by user ID.
     *
     * @return list<array<string, mixed>>
     */
    protected function profileSnapshot(): array
    {
        $statement = $this->pdo()->query('SELECT * FROM profiles ORDER BY user_id, id');
        $this->assertNotFalse($statement);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($rows, 'The fixture must leave profiles rows');

        return $rows;
    }

    /**
     * The column names of a table, in table order.
     *
     * @return list<string>
     */
    protected function columnNames(string $table): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([$table]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The text of the three `cars` triggers, keyed by trigger name.
     *
     * @return array<string, string>
     */
    protected function carsTriggerBodies(): array
    {
        $statement = $this->pdo()->query(
            "SELECT TRIGGER_NAME, ACTION_STATEMENT FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'cars' ORDER BY TRIGGER_NAME"
        );
        $this->assertNotFalse($statement);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    protected function hasIndex(string $table, string $index): bool
    {
        $statement = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $statement->execute([$table, $index]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Call a private method of a migration. A re-run test uses it to stand the
     * schema in the state that a failure between two steps leaves.
     *
     * @param list<mixed> $arguments
     */
    protected function callPrivate(object $migration, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($migration, $method);

        return $reflection->invokeArgs($migration, $arguments);
    }
}
