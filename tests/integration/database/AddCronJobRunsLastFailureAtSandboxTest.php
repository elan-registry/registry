<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationSandboxTestCase.php';

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * down()/up() of migration 20260922171500_add_cron_job_runs_last_failure_at, in a sandbox (#2167).
 *
 * The sandbox starts with the migration applied. The tests share that state,
 * so a #[Depends] chain fixes their order.
 */
#[Group('integration')]
#[Group('migration')]
final class AddCronJobRunsLastFailureAtSandboxTest extends MigrationSandboxTestCase
{
    private const MIGRATION = '20260922171500_add_cron_job_runs_last_failure_at.php';

    private const TABLE = 'er_cron_job_runs';

    private const COLUMN = 'last_failure_at';

    protected static function targetVersion(): int
    {
        return 20260922171500;
    }

    public function testDownDropsTheColumnAndUpRestoresNullableDatetime(): void
    {
        $this->assertNotNull(
            $this->columnInfo(self::TABLE, self::COLUMN),
            'The sandbox must start with the migration applied'
        );

        $this->runDown(self::MIGRATION);
        $this->assertNull($this->columnInfo(self::TABLE, self::COLUMN), 'down() must drop ' . self::COLUMN);

        $this->runUp(self::MIGRATION);
        $failureColumn = $this->columnInfo(self::TABLE, self::COLUMN);
        $runColumn = $this->columnInfo(self::TABLE, 'last_run_at');

        $this->assertNotNull($failureColumn, 'up() must add ' . self::COLUMN . ' again');
        $this->assertNotNull($runColumn, 'last_run_at must exist');
        $this->assertSame(
            'datetime',
            strtolower($failureColumn['COLUMN_TYPE']),
            self::COLUMN . ' must be a datetime'
        );
        $this->assertSame(
            'YES',
            $failureColumn['IS_NULLABLE'],
            self::COLUMN . ' must be nullable: NULL is the only value that means "has never failed"'
        );
        $this->assertNull(
            $failureColumn['COLUMN_DEFAULT'],
            self::COLUMN . ' must default to NULL, or every existing row reports a failure it never had'
        );
        $this->assertSame(
            strtolower($runColumn['COLUMN_TYPE']),
            strtolower($failureColumn['COLUMN_TYPE']),
            self::COLUMN . ' must match the COLUMN_TYPE of last_run_at exactly, because badgeFor() compares them'
        );
    }

    #[Depends('testDownDropsTheColumnAndUpRestoresNullableDatetime')]
    public function testDownTwiceIsANoOp(): void
    {
        $this->runDown(self::MIGRATION);
        $this->runDown(self::MIGRATION);

        $this->assertNull(
            $this->columnInfo(self::TABLE, self::COLUMN),
            'A second down() must be a no-op and leave ' . self::COLUMN . ' dropped'
        );
    }

    /**
     * up() runs again when the column exists but phinxlog has no row for it, so the hasColumn() guard must hold.
     */
    #[Depends('testDownTwiceIsANoOp')]
    public function testUpTwiceIsANoOp(): void
    {
        $this->runUp(self::MIGRATION);
        $this->runUp(self::MIGRATION);

        $column = $this->columnInfo(self::TABLE, self::COLUMN);
        $this->assertNotNull($column, 'A second up() must be a no-op and leave ' . self::COLUMN . ' in place');
        $this->assertSame('datetime', strtolower($column['COLUMN_TYPE']), self::COLUMN . ' must still be a datetime');
    }
}
