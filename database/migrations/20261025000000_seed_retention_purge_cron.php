<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Registers the daily data retention purge job (#2384) in both tables a cron
 * job needs: `er_cron_job_runs` (the run and pause record that CronJobGuard
 * reads) and UserSpice's `crons` table (what `users/cron/cron.php` runs), so
 * the dispatcher picks up `users/cron/retention_purge.php`.
 *
 * Follows 20260909134838_seed_brevo_reconciliation_cron.php and
 * 20260916000001_seed_send_verification_batch_cron.php. Their docblocks give
 * the full reasons for `createdby = 1`, `sort` = MAX + 10, and `NOW()`.
 *
 * `enabled = 1`: the job enforces the 90-day promise in
 * app/owner/privacy.php. It sends no mail, so there is no reason to seed it
 * paused, as the send batch is.
 *
 * IDEMPOTENCY: each insert has its own existence check. `crons` has no unique
 * key on `file`, so a row added by hand would otherwise get a duplicate. A
 * half-seeded environment gets the missing row.
 */
final class SeedRetentionPurgeCron extends AbstractMigration
{
    private const JOB_NAME = 'retention_purge';

    private const CRON_FILE = 'retention_purge.php';

    /**
     * Explicit up()/down() rather than change(): raw $this->execute() calls
     * cannot be auto-reversed by Phinx (database/migrations/README.md).
     */
    public function up(): void
    {
        $adapter = $this->getAdapter();
        $adapter->beginTransaction();

        $existingRun = $this->fetchRow(sprintf(
            "SELECT job_name FROM er_cron_job_runs WHERE job_name = '%s' LIMIT 1",
            self::JOB_NAME
        ));

        if (empty($existingRun)) {
            $this->execute(sprintf(
                "INSERT INTO er_cron_job_runs (job_name, enabled, last_run_at, created_at)
                 VALUES ('%s', 1, NULL, NOW())",
                self::JOB_NAME
            ));
        }

        $existingCron = $this->fetchRow(sprintf(
            "SELECT id FROM crons WHERE file = '%s' LIMIT 1",
            self::CRON_FILE
        ));

        if (empty($existingCron)) {
            $row = $this->fetchRow('SELECT COALESCE(MAX(sort), 0) + 10 AS next_sort FROM crons');
            $nextSort = (int) $row['next_sort'];

            $this->execute(sprintf(
                "INSERT INTO crons (active, sort, name, file, createdby, created)
                 VALUES (1, %d, 'Retention Purge', '%s', 1, NOW())",
                $nextSort,
                self::CRON_FILE
            ));
        }

        $adapter->commitTransaction();
    }

    /**
     * Removes only the two rows this migration seeded.
     */
    public function down(): void
    {
        $adapter = $this->getAdapter();
        $adapter->beginTransaction();

        $this->execute(sprintf(
            "DELETE FROM crons WHERE file = '%s'",
            self::CRON_FILE
        ));

        $this->execute(sprintf(
            "DELETE FROM er_cron_job_runs WHERE job_name = '%s'",
            self::JOB_NAME
        ));

        $adapter->commitTransaction();
    }
}
