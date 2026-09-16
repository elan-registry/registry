<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for migration
 * 20260916000001_seed_send_verification_batch_cron (#1885).
 *
 * No dedicated test file exists for the sibling
 * 20260909134838_seed_brevo_reconciliation_cron migration it was modeled on
 * (checked before writing this one, per the plan's Test Plan) — this is new
 * territory for a seed-style migration. Written anyway because this
 * migration, unlike that sibling, touches two tables in one transaction and
 * its own docblock calls out a real duplicate-insert risk: `crons` carries no
 * unique constraint on `file`, only `PRIMARY KEY (id)`.
 *
 * Runs the real migration class directly (constructed and invoked, not
 * driven through a Phinx CLI harness), matching
 * AddProfileEmailSuppressedMigrationTest's precedent for exercising a
 * migration's actual guarded code path — since Phinx does not autoload
 * migration classes via PSR-4, the file is require_once'd directly.
 */
#[Group('integration')]
#[Group('migration')]
final class SeedSendVerificationBatchCronMigrationTest extends IntegrationTestCase
{
    private const JOB_NAME = 'send_verification_batch';

    private const CRON_FILE = 'send_verification_batch.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->requireMigrationApplied();
    }

    /**
     * Skip unless both migrations this issue shipped have actually been
     * applied to the test schema.
     */
    private function requireMigrationApplied(): void
    {
        $this->db->query(
            "SELECT job_name FROM er_cron_job_runs WHERE job_name = ? LIMIT 1",
            [self::JOB_NAME]
        );
        $row = $this->db->error() ? null : $this->db->first();

        if (!is_object($row)) {
            $this->markTestSkipped(
                'Migration 20260916000001_seed_send_verification_batch_cron has not been'
                . ' applied — the er_cron_job_runs row for "' . self::JOB_NAME . '" does not exist.'
                . ' Run: composer migrate'
            );
        }
    }

    /**
     * Builds a fresh instance of the real migration class against this
     * test's live PDO connection, matching
     * AddProfileEmailSuppressedMigrationTest::phinxAdapter()'s approach.
     */
    private function loadMigration(): \SeedSendVerificationBatchCron
    {
        require_once __DIR__ . '/../../../database/migrations/20260916000001_seed_send_verification_batch_cron.php';

        $this->assertTrue(
            class_exists('SeedSendVerificationBatchCron'),
            'Migration class SeedSendVerificationBatchCron must be defined by its file'
        );

        $migration = new \SeedSendVerificationBatchCron('test', 20260916000001);
        $migration->setAdapter($this->phinxAdapter());

        return $migration;
    }

    /**
     * Phinx's raw execute()/fetchRow() calls (used by this migration for its
     * idempotency guards and the dynamically computed `sort` value) need an
     * Adapter with a live PDO connection, not the Table API used by
     * AddProfileEmailSuppressedMigrationTest's column-existence checks — but
     * the same adapter-construction approach applies.
     */
    private function phinxAdapter(): \Phinx\Db\Adapter\MysqlAdapter
    {
        $name = (string) ($_ENV['DB_NAME'] ?? getenv('DB_NAME'));
        $pdo = $this->pdoForPhinx();
        $adapter = new \Phinx\Db\Adapter\MysqlAdapter(['name' => $name]);
        $adapter->setConnection($pdo);
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

    /** @return array{count: int} */
    private function countRows(string $table, string $column, string $value): array
    {
        $this->db->query("SELECT COUNT(*) AS cnt FROM {$table} WHERE {$column} = ?", [$value]);
        $row = $this->db->first();
        return ['count' => is_object($row) ? (int) $row->cnt : 0];
    }

    // -------------------------------------------------------------------
    // Prerequisite guard — up() must refuse to seed if
    // 20260916000000_add_cron_job_runs_last_outcome_counts has not applied
    // -------------------------------------------------------------------

    /**
     * Simulates the half-applied-migration state the class docblock's
     * "PREREQUISITE GUARD" section describes: `last_sent_count` absent from
     * `er_cron_job_runs`, as if this migration ran before its prerequisite.
     * Temporarily drops the column (reversing
     * 20260916000000_add_cron_job_runs_last_outcome_counts's up() for just
     * that one column) via the real phinx Table API — obtained through
     * `AbstractMigration::table()`, the same public factory
     * 20260916000000's own up()/down() use, since `Adapter` itself exposes
     * no `getTable()` — calls up() and asserts it throws \RuntimeException
     * naming the prerequisite migration, then restores the column in a
     * finally block — guaranteed to run even if the assertion above it
     * fails, unlike this file's own
     * testDownRemovesOnlyTheRowsThisMigrationSeeded(), whose restore step is
     * NOT protected by try/finally and would leave the shared test schema
     * missing its seeded rows for every later test if its assertions ever
     * failed. That is a pre-existing gap in this file, not repeated here.
     */
    #[Group('fast')]
    public function testUpThrowsRuntimeExceptionWhenPrerequisiteColumnIsMissing(): void
    {
        $migration = $this->loadMigration();
        $table = $migration->table('er_cron_job_runs');

        $this->assertTrue(
            $table->hasColumn('last_sent_count'),
            'Test precondition: last_sent_count must exist before this test can safely drop and restore it'
        );

        $table->removeColumn('last_sent_count');
        $table->update();

        try {
            $this->assertFalse(
                $migration->table('er_cron_job_runs')->hasColumn('last_sent_count'),
                'Test setup: last_sent_count must actually be gone before calling up()'
            );

            try {
                $migration->up();
                $this->fail('Expected \RuntimeException when last_sent_count is missing, but up() did not throw');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString(
                    '20260916000000_add_cron_job_runs_last_outcome_counts',
                    $e->getMessage(),
                    'The exception message must name the prerequisite migration so an operator knows what to run'
                );
                $this->assertStringContainsString(
                    'last_sent_count',
                    $e->getMessage(),
                    'The exception message must name the missing column'
                );
            }
        } finally {
            // Restore, no matter what happened above — this column is a
            // prerequisite every other test in this file (and this
            // migration's own idempotency tests) depends on being present.
            $restoreTable = $this->loadMigration()->table('er_cron_job_runs');
            if (!$restoreTable->hasColumn('last_sent_count')) {
                $restoreTable->addColumn('last_sent_count', 'integer', [
                    'null' => true,
                    'default' => null,
                    'comment' => 'Outcome count from this job\'s most recent run; NULL until it records one.',
                ]);
                $restoreTable->update();
            }
        }

        $this->assertTrue(
            $this->loadMigration()->table('er_cron_job_runs')->hasColumn('last_sent_count'),
            'last_sent_count must be restored after this test, regardless of assertion outcome above'
        );
    }

    // -------------------------------------------------------------------
    // Idempotency — running up() twice must not duplicate or error
    // -------------------------------------------------------------------

    #[Group('fast')]
    public function testRunningMigrationTwiceDoesNotDuplicateRows(): void
    {
        $migration = $this->loadMigration();

        // First call: the environment's own `composer migrate` run already
        // seeded both rows (requireMigrationApplied() confirmed this), so
        // this call exercises the "row already exists" guard branch, not a
        // fresh insert — which is exactly the idempotency path under test.
        $migration->up();
        $afterFirst = [
            'runs' => $this->countRows('er_cron_job_runs', 'job_name', self::JOB_NAME)['count'],
            'crons' => $this->countRows('crons', 'file', self::CRON_FILE)['count'],
        ];

        $this->assertSame(1, $afterFirst['runs'], 'Exactly one er_cron_job_runs row must exist for this job');
        $this->assertSame(1, $afterFirst['crons'], 'Exactly one crons row must exist for this file');

        // Second call: proves up() is safe to re-run without duplicating —
        // the real regression this migration's docblock exists to prevent,
        // since `crons.file` carries no unique constraint at the schema level.
        $migration->up();

        $afterSecond = [
            'runs' => $this->countRows('er_cron_job_runs', 'job_name', self::JOB_NAME)['count'],
            'crons' => $this->countRows('crons', 'file', self::CRON_FILE)['count'],
        ];

        $this->assertSame(
            1,
            $afterSecond['runs'],
            'A second up() call must not duplicate the er_cron_job_runs row'
        );
        $this->assertSame(
            1,
            $afterSecond['crons'],
            'A second up() call must not duplicate the crons row — crons.file has no unique constraint,'
            . ' so this guard is the only thing preventing a phantom second row'
        );
    }

    /**
     * Exercises the migration's own idempotency guard directly against a
     * hand-seeded duplicate scenario: a crons row for this file already
     * present (as if added via the admin UI before this migration ever ran),
     * simulating the exact pre-existing-row case the class docblock
     * describes. up() must recognize it and skip the insert rather than
     * erroring or adding a second row.
     */
    #[Group('fast')]
    public function testUpSkipsInsertWhenCronsRowAlreadyExistsUnderADifferentPriorInsert(): void
    {
        // Remove the migration-seeded row so this test starts from a clean
        // slate, then hand-insert a row for the same file — modeling "an
        // admin added this row by hand before the migration shipped".
        $this->db->query("DELETE FROM crons WHERE file = ?", [self::CRON_FILE]);
        $this->assertFalse($this->db->error(), 'Test setup: failed to clear existing crons row');

        $this->db->query(
            "INSERT INTO crons (active, sort, name, file, createdby, created) VALUES (1, 9999, 'Hand-seeded', ?, 1, NOW())",
            [self::CRON_FILE]
        );
        $this->assertFalse($this->db->error(), 'Test setup: failed to hand-seed a duplicate crons row');

        $migration = $this->loadMigration();
        $migration->up();

        $this->assertSame(
            1,
            $this->countRows('crons', 'file', self::CRON_FILE)['count'],
            'up() must recognize the existing hand-seeded row and not insert a second one'
        );
    }

    // -------------------------------------------------------------------
    // Seeded row shape
    // -------------------------------------------------------------------

    #[Group('fast')]
    public function testSeededErCronJobRunsRowHasExpectedShape(): void
    {
        $this->db->query(
            'SELECT enabled, job_name, last_sent_count, last_skipped_count, last_failed_count'
            . ' FROM er_cron_job_runs WHERE job_name = ?',
            [self::JOB_NAME]
        );
        $row = $this->db->first();

        $this->assertIsObject($row, 'The seeded er_cron_job_runs row must exist');
        $this->assertSame(self::JOB_NAME, $row->job_name);
        $this->assertSame(
            0,
            (int) $row->enabled,
            'The row must be seeded enabled=0 (paused) in every environment, including test'
        );
        $this->assertNull($row->last_sent_count, 'last_sent_count must be NULL until the job records its first run');
        $this->assertNull($row->last_skipped_count, 'last_skipped_count must be NULL until the job records its first run');
        $this->assertNull($row->last_failed_count, 'last_failed_count must be NULL until the job records its first run');
    }

    // -------------------------------------------------------------------
    // Seeded crons row
    // -------------------------------------------------------------------

    #[Group('fast')]
    public function testSeededCronsRowFileMatchesTheRealShimUnderUsersCron(): void
    {
        $this->db->query('SELECT file, active FROM crons WHERE file = ?', [self::CRON_FILE]);
        $row = $this->db->first();

        $this->assertIsObject($row, 'The seeded crons row must exist');
        $this->assertSame(self::CRON_FILE, $row->file);
        $this->assertSame(1, (int) $row->active, 'The crons row must be active=1 so the dispatcher includes the shim');

        // cron.php's dispatcher resolves `file` under users/cron/ only (see
        // this repo's CLAUDE.md "cron.php's dispatcher hard-codes that
        // directory as the only path it will resolve a job's file column
        // against") — prove the seeded value actually names a file that
        // exists there, not merely a plausible-looking string.
        $shimPath = dirname(__DIR__, 3) . '/users/cron/' . self::CRON_FILE;
        $this->assertFileExists(
            $shimPath,
            'The seeded crons.file value must resolve to a real file under users/cron/'
            . ' — cron.php\'s dispatcher can only ever run a job placed there'
        );
    }

    // -------------------------------------------------------------------
    // Rollback
    // -------------------------------------------------------------------

    #[Group('fast')]
    public function testDownRemovesOnlyTheRowsThisMigrationSeeded(): void
    {
        $migration = $this->loadMigration();

        $migration->down();

        $this->assertSame(
            0,
            $this->countRows('er_cron_job_runs', 'job_name', self::JOB_NAME)['count'],
            'down() must remove the er_cron_job_runs row'
        );
        $this->assertSame(
            0,
            $this->countRows('crons', 'file', self::CRON_FILE)['count'],
            'down() must remove the crons row'
        );

        // Restore, so this test doesn't leave the shared test schema without
        // its seeded rows for every other test/migration run afterward.
        $migration->up();

        $this->assertSame(1, $this->countRows('er_cron_job_runs', 'job_name', self::JOB_NAME)['count']);
        $this->assertSame(1, $this->countRows('crons', 'file', self::CRON_FILE)['count']);
    }
}
