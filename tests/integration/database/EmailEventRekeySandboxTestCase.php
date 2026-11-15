<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationSandboxTestCase.php';
require_once __DIR__ . '/EmailEventRekeyMigrationFixture.php';

/**
 * Sandbox for the tests of EmailEventRekeyQueries, which the maintenance report
 * for migration 20261013000000 still uses (#2358).
 *
 * The sandbox stands at the version before the migration. The first test of a
 * class writes {@see EmailEventRekeyMigrationFixture} into it.
 */
abstract class EmailEventRekeySandboxTestCase extends MigrationSandboxTestCase
{
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
}
