<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds `er_cron_job_runs.last_skip_logged_at` (#1889) — bookkeeping for
 * {@see ElanRegistry\Cron\AbstractCronJob}'s rate limit on the "job is
 * disabled" skip log line.
 *
 * A separate column from `last_run_at` is required, not merely tidier.
 * `last_run_at` is written *only* by `CronJobGuard::claim()`, and a disabled
 * job never reaches `claim()` — `AbstractCronJob::run()` returns first. So
 * `last_run_at` is frozen for the entire time a job stays paused, and any
 * rate limit derived from it degrades to "log on every hit" once that frozen
 * timestamp falls one interval into the past (immediately, for a job seeded
 * with `last_run_at = NULL` and disabled before its first run). Only a column
 * the skip-logging path itself advances can throttle the skip-logging path.
 *
 * `change()` is used per database/migrations/README.md — "Strongly prefer
 * change()". This is a single auto-reversible `addColumn()` on an existing
 * table: nullable, defaulted to NULL, no backfill and no data migration, so
 * Phinx's generated reversal (a plain `removeColumn()`) is complete and
 * lossless. Existing rows start at NULL, which reads as "never logged a
 * skip" — the correct initial state, so a job paused before this migration
 * ran logs once on the next hit and then throttles normally.
 */
final class AddCronJobRunsLastSkipLoggedAt extends AbstractMigration
{
    public function change(): void
    {
        $this->table('er_cron_job_runs')
            ->addColumn('last_skip_logged_at', 'datetime', ['null' => true, 'default' => null])
            ->update();
    }
}
