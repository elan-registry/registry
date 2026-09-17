<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for migration
 * 20260916000002_rename_er_verification_settings_unmatched_counter (#2085).
 *
 * Verifies the post-migration schema of
 * `er_verification_settings.unmatched_recipient_count`: the renamed column
 * exists with the same shape the old `unmatched_webhook_recipient_count`
 * column had (`int unsigned NOT NULL DEFAULT 0`), and the old name is gone —
 * a bare renameColumn() that silently no-oped (e.g. wrong old-column-name
 * string) would otherwise leave both names present, or neither, without any
 * test catching it.
 *
 * Modeled on AddProfileEmailSuppressedMigrationTest's phinxAdapter()/
 * pdoForPhinx() helpers and requireMigrationApplied() skip guard. The
 * migration defines explicit up()/down() methods (not change()) because it
 * also uses changeColumn() to correct the column's MySQL COMMENT, which
 * throws IrreversibleMigrationException if called from within change() — see
 * the migration class's own docblock. That gives this test suite a real
 * down()/up() pair to exercise directly, which
 * testRenameColumnRoundTripsADistinctiveValueThroughDownAndUpAgain() uses to
 * prove data survives both directions of the rename.
 */
#[Group('integration')]
#[Group('migration')]
#[Group('car-verification')]
final class RenameErVerificationSettingsUnmatchedCounterMigrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /**
     * Skip unless migration 20260916000002 has actually been applied.
     */
    private function requireMigrationApplied(): void
    {
        if ($this->columnInfo('er_verification_settings', 'unmatched_recipient_count') === null) {
            $this->markTestSkipped(
                'Migration 20260916000002 has not been applied — '
                . 'er_verification_settings.unmatched_recipient_count does not exist. '
                . 'Run: composer migrate'
            );
        }
    }

    private function columnInfo(string $table, string $column): ?object
    {
        $row = $this->db->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = ?
               AND COLUMN_NAME  = ?
             LIMIT 1",
            [$table, $column]
        )->first();

        return is_object($row) ? $row : null;
    }

    #[Group('fast')]
    public function testRenamedColumnExists(): void
    {
        $this->requireMigrationApplied();

        $row = $this->columnInfo('er_verification_settings', 'unmatched_recipient_count');

        $this->assertNotNull($row, 'Column er_verification_settings.unmatched_recipient_count must exist after the rename');
    }

    #[Group('fast')]
    public function testOldColumnNameNoLongerExists(): void
    {
        $this->requireMigrationApplied();

        $row = $this->columnInfo('er_verification_settings', 'unmatched_webhook_recipient_count');

        $this->assertNull(
            $row,
            'The old column name unmatched_webhook_recipient_count must be gone after the rename — '
            . 'both names present would mean the migration added a new column instead of renaming'
        );
    }

    #[Group('fast')]
    public function testRenamedColumnTypeNullableAndDefaultMatchTheOriginal(): void
    {
        $this->requireMigrationApplied();

        $row = $this->columnInfo('er_verification_settings', 'unmatched_recipient_count');

        $this->assertNotNull($row);
        $this->assertSame(
            'int unsigned',
            strtolower((string) $row->COLUMN_TYPE),
            'unmatched_recipient_count must remain INT UNSIGNED — the type the original '
            . 'unmatched_webhook_recipient_count column had (migration 20260907141818)'
        );
        $this->assertSame(
            'NO',
            $row->IS_NULLABLE,
            'unmatched_recipient_count must remain NOT NULL — VerificationSettings::unmatchedRecipientCount() '
            . 'and incrementUnmatchedRecipientCounter() both assume a numeric value is always present'
        );
        $this->assertSame(
            '0',
            (string) $row->COLUMN_DEFAULT,
            'unmatched_recipient_count must retain its DEFAULT 0'
        );
    }

    /**
     * Proves renameColumn() actually preserves data across both directions of
     * the rename, rather than merely asserting on whatever value happens to
     * already be sitting in the live row.
     *
     * The original version of this test asserted assertIsNumeric() against
     * the live id=1 row's value — which is coincidentally 0 in every real
     * environment (the column's own DEFAULT), so the assertion would pass
     * identically whether the migration preserved data or the column had
     * been dropped and recreated from scratch. That is not a data-
     * preservation guarantee.
     *
     * This version seeds a distinctive non-default value (4242) into the
     * live (already-renamed) column, then actually exercises the migration's
     * reversibility: runs its real down() (renaming back to
     * unmatched_webhook_recipient_count) and up() again (renaming forward),
     * asserting the seeded value survives both directions. The migration
     * defines explicit up()/down() methods (not change()) precisely because
     * changeColumn() — needed here to also correct the column's MySQL
     * COMMENT — throws IrreversibleMigrationException from within change()
     * (see the migration class's own docblock), so both directions are
     * callable directly with no ProxyAdapter reversal machinery needed.
     *
     * The original value is restored in finally regardless of outcome, since
     * this mutates the live singleton er_verification_settings row (id=1)
     * shared by the whole test suite.
     */
    #[Group('fast')]
    public function testRenameColumnRoundTripsADistinctiveValueThroughDownAndUpAgain(): void
    {
        $this->requireMigrationApplied();

        require_once __DIR__ . '/../../../database/migrations/20260916000002_rename_er_verification_settings_unmatched_counter.php';

        $originalRow = $this->db->query(
            'SELECT unmatched_recipient_count FROM er_verification_settings WHERE id = ?',
            [1]
        )->first();
        $this->assertNotNull($originalRow, 'Test setup: the seeded id=1 settings row must exist');
        $originalValue = (int) $originalRow->unmatched_recipient_count;

        $seededValue = 4242;

        try {
            $updated = $this->db->query(
                'UPDATE er_verification_settings SET unmatched_recipient_count = ? WHERE id = ?',
                [$seededValue, 1]
            );
            $this->assertFalse($updated->error(), 'Test setup: seeding the distinctive value must succeed');

            $adapter = $this->phinxAdapter();

            // Reverse the rename: unmatched_recipient_count -> unmatched_webhook_recipient_count.
            $downMigration = new RenameErVerificationSettingsUnmatchedCounter('test', 20260916000002);
            $downMigration->setAdapter($adapter);
            $downMigration->down();

            $afterDown = $this->db->query(
                'SELECT unmatched_webhook_recipient_count FROM er_verification_settings WHERE id = ?',
                [1]
            )->first();
            $this->assertNotNull(
                $afterDown,
                'After reversing the rename, the row must be readable via the old column name'
            );
            $this->assertSame(
                $seededValue,
                (int) $afterDown->unmatched_webhook_recipient_count,
                'The seeded distinctive value must survive the rename being reversed (down) — '
                . 'a drop-and-recreate would have lost it or reset it to the column default'
            );

            // Replay forward: unmatched_webhook_recipient_count -> unmatched_recipient_count.
            $upMigration = new RenameErVerificationSettingsUnmatchedCounter('test', 20260916000002);
            $upMigration->setAdapter($adapter);
            $upMigration->up();

            $afterUp = $this->db->query(
                'SELECT unmatched_recipient_count FROM er_verification_settings WHERE id = ?',
                [1]
            )->first();
            $this->assertNotNull(
                $afterUp,
                'After re-applying the rename, the row must be readable via the new column name'
            );
            $this->assertSame(
                $seededValue,
                (int) $afterUp->unmatched_recipient_count,
                'The seeded distinctive value must survive the rename being re-applied (up) — '
                . 'proving renameColumn() round-trips data rather than merely defaulting to 0'
            );
        } finally {
            $this->db->query(
                'UPDATE er_verification_settings SET unmatched_recipient_count = ? WHERE id = ?',
                [$originalValue, 1]
            );
        }
    }

    /**
     * Confirms the migration class exists and instantiates correctly — the
     * closest equivalent this pure-rename migration has to
     * AddProfileEmailSuppressedMigrationTest's idempotency test, since
     * renameColumn() is not hasColumn()-guarded (see class docblock).
     */
    #[Group('fast')]
    public function testMigrationClassExistsAndInstantiates(): void
    {
        $this->requireMigrationApplied();

        // The migration class is not PSR-4 autoloaded (Phinx loads it itself
        // at migrate-time) — require the file directly to reach the class.
        require_once __DIR__ . '/../../../database/migrations/20260916000002_rename_er_verification_settings_unmatched_counter.php';

        $this->assertTrue(
            class_exists('RenameErVerificationSettingsUnmatchedCounter'),
            'Migration class RenameErVerificationSettingsUnmatchedCounter must be defined by its file'
        );

        // No assertion beyond "no exception" — construction and setAdapter()
        // succeeding is the entire point here, since renameColumn() is not
        // hasColumn()-guarded and there is no redundant-call scenario to
        // exercise the way AddProfileEmailSuppressedMigrationTest's
        // testUpIsIdempotentWhenColumnAlreadyExists() does (see class docblock).
        $migration = new RenameErVerificationSettingsUnmatchedCounter('test', 20260916000002);
        $migration->setAdapter($this->phinxAdapter());
        $this->addToAssertionCount(1);
    }

    /**
     * Phinx's Table API needs an Adapter to operate against — build one over
     * the existing PDO connection this test case already holds, rather than
     * opening a second connection or standing up a full Phinx harness.
     *
     * @param \PDO|null $pdo Reuse an already-built PDO connection (e.g. one
     *   also used directly for setup/verification queries in the same test)
     *   instead of opening a second one via pdoForPhinx().
     */
    private function phinxAdapter(?\PDO $pdo = null): \Phinx\Db\Adapter\MysqlAdapter
    {
        $name = (string) ($_ENV['DB_NAME'] ?? getenv('DB_NAME'));
        $pdo ??= $this->pdoForPhinx();
        // 'name' is the schema-name option Phinx's hasTable()/hasColumn() read
        // internally (MysqlAdapter::hasTable() falls back to $options['name']
        // when the table name carries no explicit schema prefix) — required
        // for the Table API to resolve INFORMATION_SCHEMA queries correctly.
        $adapter = new \Phinx\Db\Adapter\MysqlAdapter(['name' => $name]);
        $adapter->setConnection($pdo);
        return $adapter;
    }

    /**
     * A raw PDO connection to the same test database $this->db uses, built
     * from the same DB_* env vars IntegrationTestCase itself relies on.
     * Phinx's Adapter needs a bare PDO instance, not this project's
     * DatabaseInterface wrapper.
     */
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
