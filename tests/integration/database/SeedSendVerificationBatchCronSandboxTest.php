<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationSandboxTestCase.php';

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Runs 20260916000001_seed_send_verification_batch_cron up and down in a sandbox (#2167).
 *
 * The tests change sandbox state, so a #[Depends] chain fixes their order.
 */
#[Group('integration')]
#[Group('migration')]
final class SeedSendVerificationBatchCronSandboxTest extends MigrationSandboxTestCase
{
    private const MIGRATION_FILE = '20260916000001_seed_send_verification_batch_cron.php';

    private const JOB_NAME = 'send_verification_batch';

    private const CRON_FILE = 'send_verification_batch.php';

    private const UNRELATED_JOB_NAME = 'migtest_unrelated_job';

    private const UNRELATED_CRON_FILE = 'migtest_unrelated.php';

    protected static function targetVersion(): int
    {
        return 20260916000000;
    }

    public function testUpTwiceLeavesOneRowInEachTable(): void
    {
        $this->assertSame(0, $this->cronsRowCount(self::CRON_FILE), 'The sandbox must start without the seeded crons row');
        $this->assertSame(0, $this->jobRunsRowCount(self::JOB_NAME), 'The sandbox must start without the seeded job row');

        $this->runUp(self::MIGRATION_FILE);
        $this->runUp(self::MIGRATION_FILE);

        $this->assertSame(1, $this->cronsRowCount(self::CRON_FILE), 'A second up() must not add a second crons row');
        $this->assertSame(1, $this->jobRunsRowCount(self::JOB_NAME), 'A second up() must not add a second job row');
        $this->assertSame(0, $this->jobEnabled(self::JOB_NAME), 'up() must seed the job paused (enabled = 0)');
    }

    #[Depends('testUpTwiceLeavesOneRowInEachTable')]
    public function testDownRemovesOnlyTheRowsItSeeded(): void
    {
        $this->pdo()->prepare(
            'INSERT INTO crons (active, sort, name, file, createdby, created) VALUES (1, 999, ?, ?, 1, NOW())'
        )->execute(['Migtest Unrelated', self::UNRELATED_CRON_FILE]);
        $this->pdo()->prepare(
            'INSERT INTO er_cron_job_runs (job_name, enabled, last_run_at, created_at) VALUES (?, 1, NULL, NOW())'
        )->execute([self::UNRELATED_JOB_NAME]);
        $cronsBefore = $this->tableRowCount('crons');
        $jobRunsBefore = $this->tableRowCount('er_cron_job_runs');

        $this->runDown(self::MIGRATION_FILE);

        $this->assertSame(0, $this->cronsRowCount(self::CRON_FILE), 'down() must delete the seeded crons row');
        $this->assertSame(0, $this->jobRunsRowCount(self::JOB_NAME), 'down() must delete the seeded job row');
        $this->assertSame(1, $this->cronsRowCount(self::UNRELATED_CRON_FILE), 'down() must keep an unrelated crons row');
        $this->assertSame(1, $this->jobRunsRowCount(self::UNRELATED_JOB_NAME), 'down() must keep an unrelated job row');
        $this->assertSame($cronsBefore - 1, $this->tableRowCount('crons'), 'down() must delete one crons row only');
        $this->assertSame($jobRunsBefore - 1, $this->tableRowCount('er_cron_job_runs'), 'down() must delete one job row only');
    }

    #[Depends('testDownRemovesOnlyTheRowsItSeeded')]
    public function testUpAfterDownSeedsAgain(): void
    {
        $this->runUp(self::MIGRATION_FILE);

        $this->assertSame(1, $this->cronsRowCount(self::CRON_FILE), 'up() after down() must seed the crons row again');
        $this->assertSame(1, $this->jobRunsRowCount(self::JOB_NAME), 'up() after down() must seed the job row again');
        $this->assertSame(0, $this->jobEnabled(self::JOB_NAME), 'up() after down() must seed the job paused (enabled = 0)');
    }

    private function cronsRowCount(string $file): int
    {
        return $this->scalar('SELECT COUNT(*) FROM crons WHERE file = ?', [$file]);
    }

    private function jobRunsRowCount(string $jobName): int
    {
        return $this->scalar('SELECT COUNT(*) FROM er_cron_job_runs WHERE job_name = ?', [$jobName]);
    }

    private function jobEnabled(string $jobName): int
    {
        return $this->scalar('SELECT enabled FROM er_cron_job_runs WHERE job_name = ?', [$jobName]);
    }

    /**
     * @param 'crons'|'er_cron_job_runs' $table
     */
    private function tableRowCount(string $table): int
    {
        return $this->scalar("SELECT COUNT(*) FROM {$table}");
    }
}
