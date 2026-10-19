<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationSandboxTestCase.php';
require_once __DIR__ . '/EmailEventRekeyMigrationFixture.php';

use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Shared steps for the sandbox tests of migration 20261013000000 (#2358).
 *
 * The sandbox stands at the version before the migration. The first test of a
 * class writes {@see EmailEventRekeyMigrationFixture} into it. The migration
 * deletes and merges rows, and down() cannot restore them. A test that needs
 * the fixture again calls {@see self::resetFixture()}.
 */
abstract class EmailEventRekeySandboxTestCase extends MigrationSandboxTestCase
{
    protected const MIGRATION = '20261013000000_key_er_email_events_by_user.php';

    protected const VERSION_BEFORE = 20261012000001;

    /** @var array<class-string, true> */
    private static array $seeded = [];

    protected static function targetVersion(): int
    {
        return self::VERSION_BEFORE;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!isset(self::$seeded[static::class])) {
            EmailEventRekeyMigrationFixture::seed($this->pdo());
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
     * Bring the sandbox back to the pre-migration schema with the fixture rows.
     */
    protected function resetFixture(): void
    {
        if ($this->columnInfo('er_email_events', 'user_id') !== null) {
            $this->runDown(self::MIGRATION);
        }
        EmailEventRekeyMigrationFixture::clear($this->pdo());
        EmailEventRekeyMigrationFixture::seed($this->pdo());
    }

    /**
     * The event rows, keyed by ID.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function eventRows(): array
    {
        $statement = $this->pdo()->query('SELECT * FROM er_email_events ORDER BY id');
        $this->assertNotFalse($statement);

        $rows = [];
        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    /**
     * The IDs of the event rows, in ascending order.
     *
     * @return list<int>
     */
    protected function eventIds(): array
    {
        return array_keys($this->eventRows());
    }

    /**
     * The key of `er_email_events`: index name => [unique, columns in order].
     *
     * @return array<string, array{unique: bool, columns: list<string>}>
     */
    protected function indexes(): array
    {
        $statement = $this->pdo()->query(
            "SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'er_email_events'
              ORDER BY INDEX_NAME, SEQ_IN_INDEX"
        );
        $this->assertNotFalse($statement);

        $indexes = [];
        /** @var array{INDEX_NAME: string, NON_UNIQUE: string|int, COLUMN_NAME: string} $row */
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $name = $row['INDEX_NAME'];
            $indexes[$name] ??= ['unique' => (int) $row['NON_UNIQUE'] === 0, 'columns' => []];
            $indexes[$name]['columns'][] = $row['COLUMN_NAME'];
        }

        return $indexes;
    }

    /**
     * The name of the unique index on exactly these columns, or null.
     *
     * @param list<string> $columns
     */
    protected function uniqueIndexOn(array $columns): ?string
    {
        foreach ($this->indexes() as $name => $index) {
            if ($index['unique'] && $index['columns'] === $columns) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Whether any index (unique or not) has exactly these columns.
     *
     * @param list<string> $columns
     */
    protected function hasIndexOn(array $columns): bool
    {
        foreach ($this->indexes() as $index) {
            if ($index['columns'] === $columns) {
                return true;
            }
        }

        return false;
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
