<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for migration
 * 20260922171500_add_cron_job_runs_last_failure_at.
 *
 * The nullability assertions are load-bearing, not cosmetic — the same
 * reasoning AddProfileEmailSuppressedMigrationTest's docblock gives for the
 * opposite requirement on its own column. `CronJobRunsReader::badgeFor()`
 * decides whether a job's most recent run failed by COMPARING
 * `last_failure_at` against `last_run_at`. NULL is the only value that can
 * mean "has never failed": a `0000-00-00` default would parse to a bogus year
 * -1 that silently loses every comparison (hiding real failures behind a green
 * badge), and an epoch default would win none. So "nullable, defaulting to
 * NULL" is what makes the comparison correct, and a later migration quietly
 * adding a default would break failure detection without breaking anything
 * that would look broken.
 *
 * Runs the real migration class directly (constructed and invoked, not driven
 * through a Phinx CLI harness), matching
 * AddProfileEmailSuppressedMigrationTest's precedent for exercising a
 * migration's actual guarded code path — since Phinx does not autoload
 * migration classes via PSR-4, the file is require_once'd directly.
 */
#[Group('integration')]
#[Group('migration')]
final class AddCronJobRunsLastFailureAtMigrationTest extends IntegrationTestCase
{
    private const TABLE = 'er_cron_job_runs';

    private const COLUMN = 'last_failure_at';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    private function requireMigrationApplied(): void
    {
        if ($this->columnInfo(self::TABLE, self::COLUMN) === null) {
            $this->markTestSkipped(
                'Migration 20260922171500 has not been applied — ' . self::TABLE . '.' . self::COLUMN
                . ' does not exist. Run: composer migrate'
            );
        }
    }

    /**
     * Read one column's information_schema row, or null if it does not exist.
     */
    private function columnInfo(string $table, string $column): ?object
    {
        $row = $this->db->query(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = ?
                AND COLUMN_NAME  = ?
              LIMIT 1',
            [$table, $column]
        )->first();

        return is_object($row) ? $row : null;
    }

    #[Group('fast')]
    public function testColumnIsNullableDatetimeDefaultingToNull(): void
    {
        $this->requireMigrationApplied();

        $row = $this->columnInfo(self::TABLE, self::COLUMN);

        $this->assertNotNull($row, self::TABLE . '.' . self::COLUMN . ' must exist');
        $this->assertSame(
            'datetime',
            strtolower((string) $row->COLUMN_TYPE),
            self::COLUMN . ' must be a datetime, matching last_run_at — the two are compared directly'
        );
        $this->assertSame(
            'YES',
            $row->IS_NULLABLE,
            self::COLUMN . ' must be nullable: NULL is the only value that can mean "has never failed",'
            . ' and badgeFor() compares this column against last_run_at'
        );
        $this->assertNull(
            $row->COLUMN_DEFAULT,
            self::COLUMN . ' must default to NULL — any concrete default would make every existing row'
            . ' report a failure it never had'
        );
    }

    /**
     * Type parity with `last_run_at`, read from information_schema on both
     * sides rather than hardcoding the expected type twice, so the two columns
     * cannot silently drift apart from each other. They are compared as
     * DateTimeImmutable values by badgeFor(), which is only meaningful while
     * they share a representation.
     */
    #[Group('fast')]
    public function testColumnTypeMatchesLastRunAtExactly(): void
    {
        $this->requireMigrationApplied();

        $failureColumn = $this->columnInfo(self::TABLE, self::COLUMN);
        $runColumn = $this->columnInfo(self::TABLE, 'last_run_at');

        $this->assertNotNull($failureColumn);
        $this->assertNotNull($runColumn, 'last_run_at must exist — it has existed since the table was created');

        $this->assertSame(
            strtolower((string) $runColumn->COLUMN_TYPE),
            strtolower((string) $failureColumn->COLUMN_TYPE),
            self::COLUMN . ' must match last_run_at\'s COLUMN_TYPE exactly'
        );
    }

    /**
     * Every pre-existing row must start NULL rather than being backfilled:
     * this migration adds no data, and a seeded job that has never failed must
     * not be reported as having failed the moment the column arrives.
     */
    #[Group('fast')]
    public function testExistingRowsStartNull(): void
    {
        $this->requireMigrationApplied();

        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM ' . self::TABLE . ' WHERE ' . self::COLUMN . ' IS NOT NULL'
        )->first();

        $this->assertIsObject($row);
        $this->assertSame(
            0,
            (int) $row->cnt,
            'No seeded row may carry a failure timestamp — nothing has run, let alone failed'
        );
    }

    /**
     * up() is guarded with hasColumn(), so a second call after it has already
     * applied must be a safe no-op rather than an error (Phinx would otherwise
     * raise a duplicate-column error). Exercises the real migration class
     * rather than paraphrasing its logic.
     */
    #[Group('fast')]
    public function testUpIsIdempotentWhenColumnAlreadyExists(): void
    {
        $this->requireMigrationApplied();

        $migration = $this->loadMigration();
        $migration->up();

        $this->assertNotNull(
            $this->columnInfo(self::TABLE, self::COLUMN),
            'The column must still exist after a redundant up() call'
        );
    }

    private function loadMigration(): \AddCronJobRunsLastFailureAt
    {
        require_once __DIR__ . '/../../../database/migrations/20260922171500_add_cron_job_runs_last_failure_at.php';

        $this->assertTrue(
            class_exists('AddCronJobRunsLastFailureAt'),
            'Migration class AddCronJobRunsLastFailureAt must be defined by its file'
        );

        $migration = new \AddCronJobRunsLastFailureAt('test', 20260922171500);
        $migration->setAdapter($this->phinxAdapter());

        return $migration;
    }

    /**
     * Phinx's Table API needs an Adapter to operate against — build one over a
     * PDO connection to the same schema this test case already uses, rather
     * than standing up a full Phinx harness.
     */
    private function phinxAdapter(): \Phinx\Db\Adapter\MysqlAdapter
    {
        $name = (string) ($_ENV['DB_NAME'] ?? getenv('DB_NAME'));
        $adapter = new \Phinx\Db\Adapter\MysqlAdapter(['name' => $name]);
        $adapter->setConnection($this->pdoForPhinx());

        return $adapter;
    }

    private function pdoForPhinx(): \PDO
    {
        $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST'));
        $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306;
        $name = (string) ($_ENV['DB_NAME'] ?? getenv('DB_NAME'));

        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);
        }

        return new \PDO(
            "mysql:host={$host};port={$port};dbname={$name}",
            (string) ($_ENV['DB_USER'] ?? getenv('DB_USER')),
            (string) ($_ENV['DB_PASS'] ?? getenv('DB_PASS'))
        );
    }
}
