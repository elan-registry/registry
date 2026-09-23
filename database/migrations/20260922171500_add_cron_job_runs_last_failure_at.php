<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds `er_cron_job_runs.last_failure_at` — the timestamp of the most recent
 * claimed run whose `execute()` threw.
 *
 * Closes the gap that made a crashing job look healthy. `last_run_at` is
 * stamped by {@see ElanRegistry\Cron\CronJobGuard::claim()} *before*
 * {@see ElanRegistry\Cron\AbstractCronJob::run()} calls `execute()`, because
 * the claim is what prevents a second concurrent run — it cannot wait for the
 * work to finish. That ordering is correct for a guard, but it means
 * `last_run_at` records "a run was claimed", never "a run succeeded". With
 * only that column to read, a job whose `execute()` throws every night still
 * renders the verification tab's green "Ran" badge with a timestamp from
 * minutes ago, and its `last_*_count` columns — written only at the end of a
 * successful run — stand as if they were current. An unattended pipeline that
 * has been dead for a week looks identical to one that ran cleanly.
 *
 * A separate column rather than a nullable "outcome" flag on `last_run_at`:
 * both timestamps are needed at once. The badge has to answer "was the MOST
 * RECENT claimed run the one that failed?", which is a comparison between the
 * two (`last_failure_at >= last_run_at`), not a single tri-state. A later
 * successful run naturally out-dates an earlier failure and the badge goes
 * green again with no explicit clearing step — nothing has to remember to
 * reset a flag, which is exactly the kind of bookkeeping a crashing job is
 * least able to do.
 *
 * Deliberately generic, not scoped to `send_verification_batch`: written by
 * {@see ElanRegistry\Cron\AbstractCronJob::run()}'s own catch-all, so every
 * current and future job on this table gets it, matching how `last_run_at` /
 * `enabled` / `last_skip_logged_at` / the `last_*_count` trio are already
 * shared by all jobs rather than duplicated per job (#2034's rationale for the
 * table).
 *
 * Nullable with no default, so every existing row starts NULL. NULL reads as
 * "this job has never failed", which is distinct from a job that failed and
 * later recovered (a `last_failure_at` older than `last_run_at`) — a
 * zero-date or epoch default would erase that distinction and make the
 * comparison above misreport a never-failed job.
 *
 * Explicit up()/down() rather than change(): consistent with
 * 20260916000000_add_cron_job_runs_last_outcome_counts and the other schema
 * migrations in this directory. Each step is guarded with hasColumn(), so
 * re-running after a partial failure is safe and does no duplicate work.
 */
final class AddCronJobRunsLastFailureAt extends AbstractMigration
{
    private const TABLE = 'er_cron_job_runs';

    private const COLUMN = 'last_failure_at';

    public function up(): void
    {
        $table = $this->table(self::TABLE);

        if (!$table->hasColumn(self::COLUMN)) {
            $table->addColumn(self::COLUMN, 'datetime', [
                'null'    => true,
                'default' => null,
                'comment' => 'When this job\'s execute() last threw; NULL until it fails once.',
            ]);
        }

        $table->update();
    }

    public function down(): void
    {
        $table = $this->table(self::TABLE);

        if ($table->hasColumn(self::COLUMN)) {
            $table->removeColumn(self::COLUMN);
        }

        $table->update();
    }
}
