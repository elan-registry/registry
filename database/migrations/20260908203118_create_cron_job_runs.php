<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Creates er_cron_job_runs — a generic "when did this job last run" table,
 * replacing per-job bespoke settings columns (#2034). Migrates
 * CronJobGuard's one existing job (reconciliation, #2027) onto it and drops
 * the old settings.reconciliation_last_run column, which has never been
 * written to in production (no CronJobGuard caller exists yet).
 *
 * `er_` prefix per docs/development/DATABASE.md's Table Naming convention —
 * ElanRegistry-owned tables created from v2.30.2 onwards use it, alongside
 * sibling v2.30.2 tables er_verification_settings and er_email_events.
 *
 * `enabled` lets an operator pause a single job without touching UserSpice's
 * own crons table (which only supports add/delete, not pause) — see #2034
 * and #1889's shared cron scaffolding, which will check this flag before
 * running a job's work.
 *
 * Not atomic as a whole: up() interleaves DDL (create(), removeColumn())
 * around one DML insert(). MySQL implicit-commits each DDL statement, so a
 * wrapping transaction would be defeated regardless — per
 * database/migrations/README.md's Transactions section, this is documented
 * here rather than wrapped. A failure partway through (e.g. between create()
 * and the seed insert) is not recorded in phinxlog, so Phinx will retry the
 * whole up() on the next `migrate` — but that retry's create() will then hit
 * an already-existing table. Recovery in that case is manual: drop
 * er_cron_job_runs (and restore settings.reconciliation_last_run if it was
 * already dropped) before re-running.
 */
final class CreateCronJobRuns extends AbstractMigration
{
    /**
     * Explicit up()/down() rather than change(): a change() mixing
     * createTable + insert + removeColumn is not safely auto-reversible in
     * this Phinx version — reversal re-runs the insert() as a forward action
     * before the table is dropped, hitting a duplicate-key error against the
     * still-existing seed row and aborting the rollback entirely (verified
     * by running `composer migrate:rollback` against this exact migration).
     */
    public function up(): void
    {
        $this->table('er_cron_job_runs', ['id' => false, 'primary_key' => 'job_name'])
            ->addColumn('job_name', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('enabled', 'boolean', ['default' => true, 'null' => false])
            ->addColumn('last_run_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->create();

        // created_at uses the database clock (SQL NOW(), not PHP date()) for
        // consistency with CronJobGuard::claim(), which deliberately avoids
        // any PHP-derived time value — see that method's own tests.
        $this->execute(
            "INSERT INTO er_cron_job_runs (job_name, enabled, last_run_at, created_at)
             VALUES ('reconciliation', 1, NULL, NOW())"
        );

        $this->table('settings')->removeColumn('reconciliation_last_run')->update();
    }

    public function down(): void
    {
        $this->table('settings')
            ->addColumn('reconciliation_last_run', 'datetime', ['null' => true, 'default' => null])
            ->update();

        $this->table('er_cron_job_runs')->drop()->save();
    }
}
