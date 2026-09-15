<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for migration 20260914093000_add_profile_email_suppressed
 * (issue #1883).
 *
 * Verifies the post-migration schema of `profiles.email_suppressed`:
 * TINYINT(1) NOT NULL DEFAULT 0, matching `cars.email_suppressed`'s type
 * exactly, and that a bare insert (no explicit value) actually gets the
 * default rather than relying on application code to always supply one.
 *
 * The NOT NULL/DEFAULT 0 assertions are load-bearing, not cosmetic:
 * CarRepository::findProfileEmailSuppressed() does `(int) $row->email_suppressed`
 * with no null handling. If the column were nullable and a row held NULL,
 * that cast silently reads as 0 ("not opted out") — re-enabling verification
 * email for an owner who explicitly opted out. This mirrors the reasoning in
 * CarVerificationTimestampMigrationTest, whose own docblock explains that the
 * #1953 defect shipped because no prior test asserted nullability/defaults,
 * only column existence or type.
 */
#[Group('integration')]
#[Group('migration')]
#[Group('car-verification')]
final class AddProfileEmailSuppressedMigrationTest extends IntegrationTestCase
{
    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->testUserId = $this->createTestUser();
        $this->loginAsTestUser($this->testUserId);
    }

    /**
     * Skip unless migration 20260914093000 has actually been applied.
     */
    private function requireMigrationApplied(): void
    {
        if ($this->columnInfo('profiles', 'email_suppressed') === null) {
            $this->markTestSkipped(
                'Migration 20260914093000 has not been applied — profiles.email_suppressed '
                . 'does not exist. Run: composer migrate'
            );
        }
    }

    private function columnInfo(string $table, string $column): ?object
    {
        return $this->db->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = ?
               AND COLUMN_NAME  = ?
             LIMIT 1",
            [$table, $column]
        )->first();
    }

    #[Group('fast')]
    public function testColumnIsTinyintOneNotNullDefaultZero(): void
    {
        $this->requireMigrationApplied();

        $row = $this->columnInfo('profiles', 'email_suppressed');

        $this->assertNotNull($row, 'Column profiles.email_suppressed must exist');
        $this->assertSame(
            'tinyint(1)',
            strtolower((string) $row->COLUMN_TYPE),
            'profiles.email_suppressed must be TINYINT(1)'
        );
        $this->assertSame(
            'NO',
            $row->IS_NULLABLE,
            'profiles.email_suppressed must be NOT NULL — a nullable column would let '
            . 'findProfileEmailSuppressed()\'s (int) cast silently read a NULL as 0 (not opted out)'
        );
        $this->assertSame(
            '0',
            (string) $row->COLUMN_DEFAULT,
            'profiles.email_suppressed must default to 0'
        );
    }

    /**
     * Type parity with cars.email_suppressed, read from information_schema on
     * both sides rather than hardcoding the expected type twice, so the two
     * columns cannot silently drift apart from each other.
     */
    #[Group('fast')]
    public function testColumnTypeMatchesCarsEmailSuppressedExactly(): void
    {
        $this->requireMigrationApplied();

        $profilesColumn = $this->columnInfo('profiles', 'email_suppressed');
        $carsColumn = $this->columnInfo('cars', 'email_suppressed');

        $this->assertNotNull($profilesColumn, 'Column profiles.email_suppressed must exist');
        $this->assertNotNull($carsColumn, 'Column cars.email_suppressed must exist (added by 20260907141816)');

        $this->assertSame(
            strtolower((string) $carsColumn->COLUMN_TYPE),
            strtolower((string) $profilesColumn->COLUMN_TYPE),
            'profiles.email_suppressed must match cars.email_suppressed\'s COLUMN_TYPE exactly'
        );
        $this->assertSame(
            $carsColumn->IS_NULLABLE,
            $profilesColumn->IS_NULLABLE,
            'profiles.email_suppressed must match cars.email_suppressed\'s IS_NULLABLE exactly'
        );
    }

    /**
     * Proves the DEFAULT actually applies on a real insert, not merely that
     * information_schema reports one. createTestUser($withProfile: true)
     * relies on this: it inserts a profiles row without specifying
     * email_suppressed at all.
     */
    #[Group('fast')]
    public function testBareInsertGetsDefaultZero(): void
    {
        $this->requireMigrationApplied();

        $userId = $this->createTestUser();

        $inserted = $this->db->insert('profiles', [
            'user_id' => $userId,
            'bio'     => '',
            'city'    => '',
            'state'   => '',
            'country' => '',
        ]);
        $this->assertTrue($inserted, 'Test setup: bare profiles insert (no email_suppressed) must succeed');

        $row = $this->db->query(
            'SELECT email_suppressed FROM profiles WHERE user_id = ?',
            [$userId]
        )->first();

        $this->assertNotNull($row, 'Test setup: inserted profiles row must be readable');
        $this->assertSame(
            0,
            (int) $row->email_suppressed,
            'A bare insert omitting email_suppressed must read back as 0 (the schema default), '
            . 'not NULL or any other value — createTestUser($withProfile: true) and every real '
            . 'owner-creation path depend on this default actually applying'
        );
    }

    /**
     * up()/down() are both guarded with hasColumn(), so re-running up() after
     * it has already applied must be a safe no-op rather than an error.
     * Exercises the real migration class rather than paraphrasing its logic.
     */
    #[Group('fast')]
    public function testUpIsIdempotentWhenColumnAlreadyExists(): void
    {
        $this->requireMigrationApplied();

        // The migration class is not PSR-4 autoloaded (Phinx loads it itself
        // at migrate-time) — require the file directly to reach the class.
        require_once __DIR__ . '/../../../database/migrations/20260914093000_add_profile_email_suppressed.php';

        $this->assertTrue(
            class_exists('AddProfileEmailSuppressed'),
            'Migration class AddProfileEmailSuppressed must be defined by its file'
        );

        // No assertion beyond "no exception" — up()'s hasColumn() guard means
        // a second call is a no-op. Constructing and invoking it directly
        // exercises the real guarded code path without a Phinx harness.
        $migration = new AddProfileEmailSuppressed('test', 20260914093000);
        $migration->setAdapter($this->phinxAdapter());
        $migration->up();

        $row = $this->columnInfo('profiles', 'email_suppressed');
        $this->assertNotNull($row, 'Column must still exist after a redundant up() call');
    }

    /**
     * Phinx's Table API needs an Adapter to operate against — build one over
     * the existing PDO connection this test case already holds, rather than
     * opening a second connection or standing up a full Phinx harness.
     */
    private function phinxAdapter(): \Phinx\Db\Adapter\MysqlAdapter
    {
        $name = (string) ($_ENV['DB_NAME'] ?? getenv('DB_NAME'));
        $pdo = $this->pdoForPhinx();
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
