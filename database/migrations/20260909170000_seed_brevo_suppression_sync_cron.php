<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Registers the Brevo suppression list import job (#1923) in both tables a
 * cron job needs a row in:
 *
 * - `crons` — UserSpice's own dispatcher table, so `users/cron/cron.php`
 *   picks up `users/cron/brevo_suppression_sync.php` on its next 10-minute
 *   pass. docs/development/DEPLOYMENT.md's "Cron Transport" section is
 *   explicit that a new job's row must ship "via a migration/seed, not by
 *   hand in the admin UI" — this migration is that seed.
 * - `er_cron_job_runs` — the generic "when did this job last run" table that
 *   CronJobGuard reads, carrying this job's `enabled` flag and
 *   `last_run_at` watermark.
 *
 * No table-creation step is needed for `er_cron_job_runs`: it was created by
 * CreateCronJobRuns (20260908203118) for #1889, which seeded the first job's
 * row inline as part of that table's creation. Later jobs — this one — only
 * INSERT.
 *
 * `createdby`: `crons` requires a NOT NULL user id and there is no
 * "system" user convention documented anywhere in this codebase (checked
 * docs/development/DATABASE.md and every existing migration for
 * precedent — none inserts a row needing a user-id-like column). This
 * migration hardcodes `1`, UserSpice's long-standing first/admin account,
 * as an explicit assumption for reviewer sign-off rather than a verified
 * convention — flagging this is the one questionable part of an otherwise
 * pure schema-adjacent change.
 *
 * `sort`: computed at migration-run time via
 * `SELECT COALESCE(MAX(sort), 0) + 10 AS next_sort FROM crons` rather than
 * a hardcoded guess, since dev/test/prod may already carry different rows
 * (e.g. seeded manually before this migration existed) and the dispatcher
 * only cares that `sort` orders rows sensibly, not what the value is. The
 * +10 (rather than +1) increment leaves gaps for future manual reordering;
 * no existing convention for the increment size was found to follow
 * instead.
 *
 * `created` / `created_at` use the database clock (SQL NOW()) rather than a
 * PHP-generated timestamp, matching CreateCronJobRuns's (20260908203118)
 * same reasoning — CronJobGuard::claim() deliberately avoids any
 * PHP-derived time value.
 *
 * IDEMPOTENCY: each insert is guarded by an existing-row check. `crons`
 * carries only `PRIMARY KEY (id)` — there is no unique constraint on
 * `file` — so nothing at the schema level would stop a second row. That
 * matters precisely because of the hand-seeded case above: an environment
 * where the row was added through the admin UI before this migration
 * existed would otherwise end up with two. The dispatcher's `include_once`
 * means the job would still run only once, so the damage is not
 * double-execution but a permanent phantom second `crons_logs` entry on
 * every cron hit — a misleading artifact far easier to prevent than to
 * explain later. `er_cron_job_runs.job_name` *is* the primary key, so a
 * duplicate there would hard-error rather than silently duplicate; the
 * guard turns that into a clean no-op so a re-run of this migration (or a
 * hand-seeded row) does not abort the whole `migrate` pass.
 *
 * Both inserts share one transaction: they are two halves of a single
 * "register this job" action, and a `crons` row without its
 * `er_cron_job_runs` row would leave the dispatcher invoking a job
 * CronJobGuard cannot claim. SeedBrevoReconciliationCron (20260909134838)
 * wrapped only its single insert because it had only one; the scope there
 * excluded a table create purely because MySQL implicit-commits DDL, which
 * does not apply to these two DML statements.
 */
final class SeedBrevoSuppressionSyncCron extends AbstractMigration
{
    /**
     * Explicit up()/down() rather than change(): the inserts are written as
     * raw SQL (needed for the dynamically computed `sort` value and the
     * SQL NOW() calls), and per database/migrations/README.md, raw
     * $this->execute() calls cannot be auto-reversed by Phinx — change()
     * would leave rollback and --dry-run unsupported for this migration.
     */
    public function up(): void
    {
        $adapter = $this->getAdapter();
        $adapter->beginTransaction();

        // Idempotency guards — see the class docblock. Each insert is skipped
        // independently, so an environment carrying only one of the two rows
        // (hand-seeded through the admin UI) still gets the missing one.
        $existingJobRun = $this->fetchRow(
            "SELECT job_name FROM er_cron_job_runs WHERE job_name = 'brevo_suppression_sync' LIMIT 1"
        );
        if (empty($existingJobRun)) {
            $this->execute(
                "INSERT INTO er_cron_job_runs (job_name, enabled, last_run_at, created_at)
                 VALUES ('brevo_suppression_sync', 1, NULL, NOW())"
            );
        }

        $existingCron = $this->fetchRow(
            "SELECT id FROM crons WHERE file = 'brevo_suppression_sync.php' LIMIT 1"
        );
        if (empty($existingCron)) {
            $row = $this->fetchRow('SELECT COALESCE(MAX(sort), 0) + 10 AS next_sort FROM crons');
            $nextSort = (int) $row['next_sort'];

            $this->execute(
                sprintf(
                    "INSERT INTO crons (active, sort, name, file, createdby, created)
                     VALUES (1, %d, 'Brevo Suppression Sync', 'brevo_suppression_sync.php', 1, NOW())",
                    $nextSort
                )
            );
        }

        $adapter->commitTransaction();
    }

    public function down(): void
    {
        $adapter = $this->getAdapter();
        $adapter->beginTransaction();

        $this->execute(
            "DELETE FROM er_cron_job_runs WHERE job_name = 'brevo_suppression_sync'"
        );

        $this->execute(
            "DELETE FROM crons WHERE file = 'brevo_suppression_sync.php'"
        );

        $adapter->commitTransaction();
    }
}
