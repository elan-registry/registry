<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds `er_cron_job_runs.last_sent_count`, `.last_skipped_count` and
 * `.last_failed_count` (#1885) — the outcome tally of a job's most recent
 * actual run.
 *
 * Needed because the admin dashboard has to show what the last *automatic*
 * run did, on a page load that may happen hours after cron fired. The two
 * jobs already on this table (#1889, #1923) only log their outcome, and a log
 * line cannot be rendered back into a status panel. Persisting three scalars
 * beside the existing `last_run_at` gives the panel its numbers without
 * storing a full summary object or adding a per-job table.
 *
 * Deliberately generic, not scoped to `send_verification_batch`: the column
 * names describe outcomes any cron job in this table could report, matching
 * how `last_run_at`/`enabled`/`last_skip_logged_at` are already shared by all
 * jobs rather than duplicated per job (#2034's rationale for the table).
 *
 * Nullable with no default, so every existing row — including the
 * 'reconciliation' row seeded by 20260908203118_create_cron_job_runs.php
 * (renamed to 'brevo_reconciliation' by 20260918133038_rename_reconciliation_job.php,
 * #2129) — starts NULL. NULL reads as "this job has never recorded counts", which is
 * distinct from a recorded run that sent zero; a `0` default would erase that
 * distinction. A job's `execute()` writes these after each run.
 *
 * Explicit up()/down() rather than change(): consistent with the other
 * schema migrations in this directory. Each step is guarded with hasColumn(),
 * so re-running after a partial failure is safe and does no duplicate work.
 */
final class AddCronJobRunsLastOutcomeCounts extends AbstractMigration
{
    private const TABLE = 'er_cron_job_runs';

    /** @var list<string> */
    private const COLUMNS = ['last_sent_count', 'last_skipped_count', 'last_failed_count'];

    public function up(): void
    {
        $table = $this->table(self::TABLE);

        foreach (self::COLUMNS as $column) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, 'integer', [
                    'null'    => true,
                    'default' => null,
                    'comment' => 'Outcome count from this job\'s most recent run; NULL until it records one.',
                ]);
            }
        }

        $table->update();
    }

    public function down(): void
    {
        $table = $this->table(self::TABLE);

        foreach (self::COLUMNS as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }

        $table->update();
    }
}
