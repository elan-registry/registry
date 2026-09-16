<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Registers the verification-email batch job (#1885) in both tables a cron
 * job needs a row in: `er_cron_job_runs` (the ElanRegistry-owned run/pause
 * record CronJobGuard reads) and UserSpice's own `crons` table (what
 * `users/cron/cron.php`'s dispatcher iterates), so the dispatcher picks up
 * `users/cron/send_verification_batch.php` on its next 10-minute pass.
 *
 * Follows 20260909134838_seed_brevo_reconciliation_cron.php throughout —
 * that migration's docblock carries the full reasoning for the shared parts,
 * summarised here for the choices this migration repeats:
 *
 * `enabled = 0` — SEEDED PAUSED IN EVERY ENVIRONMENT, test and production
 * alike. This is the one place this migration deliberately diverges from the
 * reconciliation seed (which enables its job immediately). No real
 * verification email should go out automatically until (a) an admin
 * explicitly resumes the job via the Pause/Resume dashboard control added by
 * this same issue, and (b) bounce detection has been verified live in
 * production specifically — a reconciliation job that only reads Brevo events
 * is harmless if it runs unattended; a job that sends mail to owners is not.
 * The flag is seeded unconditionally rather than per-environment because no
 * `APP_ENV`-style environment-detection convention exists anywhere in this
 * codebase (checked `.env.example`, `config.php`, and every existing
 * migration) — a migration cannot tell prod from test, and inventing a
 * detection mechanism to save test one button press is not worth the new
 * convention. Test resumes itself with the same button prod will use.
 *
 * `active = 1` on the `crons` row — UserSpice's `active` is the dispatcher's
 * *inclusion* flag, entirely separate from the pause mechanism above. There
 * is no reason to hide the shim from dispatch: the shim runs, CronJobGuard
 * reads `er_cron_job_runs.enabled = 0`, and the job does no work. Gating in
 * one place (the job's own flag) keeps the Pause/Resume control the single
 * source of truth for whether this job sends.
 *
 * `createdby = 1`: `crons` requires a NOT NULL user id and there is still no
 * "system" user convention documented anywhere in this codebase. This
 * hardcodes `1`, UserSpice's first/admin account, carrying forward the
 * reconciliation seed's explicit assumption rather than a verified
 * convention — flagged here for the same reviewer sign-off.
 *
 * `sort`: computed at migration-run time via
 * `SELECT COALESCE(MAX(sort), 0) + 10 AS next_sort FROM crons` rather than a
 * hardcoded guess, since dev/test/prod may already carry different rows and
 * the dispatcher only cares that `sort` orders rows sensibly, not what the
 * value is. The +10 increment leaves gaps for future manual reordering.
 *
 * `created` / `created_at` use the database clock (SQL `NOW()`) rather than a
 * PHP-generated timestamp, matching both prior migrations' reasoning and
 * CronJobGuard::claim(), which deliberately avoids any PHP-derived time.
 *
 * The three outcome-count columns added by
 * 20260916000000_add_cron_job_runs_last_outcome_counts.php are omitted from
 * the INSERT column list, leaving them NULL — they are nullable with no
 * default, and NULL correctly reads as "this job has never recorded counts",
 * distinct from a run that sent zero.
 *
 * PREREQUISITE GUARD. Because those columns are omitted rather than written,
 * nothing about these two INSERTs would otherwise fail if 20260916000000 had
 * not applied — which is precisely the danger. Phinx runs migrations in
 * filename order, so the ordinary path is safe, but a half-applied pair is
 * reachable by manual intervention or a restored partial schema dump, and the
 * resulting state is the worst one available: the job is fully seeded and
 * dispatchable, sending real email to owners nightly, while
 * SendVerificationBatchJob::recordRunCounts() throws on every run and the
 * dashboard can never show what happened. up() therefore refuses to seed
 * unless the columns are present — failing the migration loudly is strictly
 * better than seeding a job that mails people it cannot account for.
 *
 * IDEMPOTENCY: each insert is guarded by an existence check —
 * `er_cron_job_runs.job_name` and `crons.file` respectively. `job_name` is
 * that table's primary key so a duplicate there would hard-error rather than
 * duplicate, but `crons` carries only `PRIMARY KEY (id)` with no unique
 * constraint on `file`, so an environment where the row was added through the
 * admin UI before this migration ran would otherwise end up with two. The
 * dispatcher's `include_once` means the job would still run only once, so the
 * damage is a permanent phantom second `crons_logs` entry on every cron hit —
 * easier to prevent than to explain later. The two guards are independent so
 * that a half-seeded environment (one row present, the other not) is
 * completed rather than skipped or failed.
 */
final class SeedSendVerificationBatchCron extends AbstractMigration
{
    private const JOB_NAME = 'send_verification_batch';

    private const CRON_FILE = 'send_verification_batch.php';

    /**
     * Explicit up()/down() rather than change(): the inserts are written as
     * raw SQL (needed for the dynamically computed `sort` value and the SQL
     * NOW() calls), and per database/migrations/README.md, raw
     * $this->execute() calls cannot be auto-reversed by Phinx — change()
     * would leave rollback and --dry-run unsupported for this migration.
     */
    public function up(): void
    {
        // See "PREREQUISITE GUARD" in the class docblock: seeding this job
        // without the outcome-count columns would leave it sending mail
        // nightly while unable to record or display any outcome.
        if (!$this->table('er_cron_job_runs')->hasColumn('last_sent_count')) {
            throw new \RuntimeException(
                'er_cron_job_runs.last_sent_count is missing — apply '
                . '20260916000000_add_cron_job_runs_last_outcome_counts first. Seeding the '
                . 'job without it would send mail nightly while being unable to record or '
                . 'display any outcome.'
            );
        }

        $adapter = $this->getAdapter();
        $adapter->beginTransaction();

        $existingRun = $this->fetchRow(sprintf(
            "SELECT job_name FROM er_cron_job_runs WHERE job_name = '%s' LIMIT 1",
            self::JOB_NAME
        ));

        if (empty($existingRun)) {
            $this->execute(sprintf(
                "INSERT INTO er_cron_job_runs (job_name, enabled, last_run_at, created_at)
                 VALUES ('%s', 0, NULL, NOW())",
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
                 VALUES (1, %d, 'Send Verification Batch', '%s', 1, NOW())",
                $nextSort,
                self::CRON_FILE
            ));
        }

        $adapter->commitTransaction();
    }

    /**
     * Removes only the two rows this migration seeded. The prerequisite
     * changes this job also depends on — CronJobGuard::ALLOWED_JOB_NAMES and
     * the outcome-count columns from 20260916000000 — are deliberately not
     * touched here; each is owned by its own migration or code change.
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
