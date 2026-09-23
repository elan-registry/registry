<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Renames the `er_cron_job_runs.job_name` primary-key value from
 * 'reconciliation' to 'brevo_reconciliation' (#2129) — cosmetic consistency
 * with its sibling job names ('brevo_suppression_sync',
 * 'send_verification_batch'), all of which carry a `brevo_` prefix.
 *
 * This is a data-only rename of a live primary-key value seeded by
 * 20260908203118_create_cron_job_runs.php. Confirmed no other table has a
 * foreign key referencing er_cron_job_runs.job_name, and er_cron_job_runs
 * carries no audit triggers, so a plain UPDATE is safe.
 *
 * MUST deploy in lockstep with the matching code changes in this same PR
 * (usersc/classes/Cron/CronJobGuard.php's ALLOWED_JOB_NAMES and
 * usersc/classes/Cron/BrevoEventReconciliationJob.php's JOB_NAME constant)
 * — a code-only rename with no matching row would make
 * CronJobGuard::claim()'s lookup miss silently: the job would stop claiming
 * and never run again, with no error. The production deploy hook runs
 * `composer install` + migrations + `npm run build` atomically in one push,
 * so this ordering risk does not apply to a real deploy; it matters only if
 * this migration or the code change is ever applied/reverted by hand out of
 * step with the other.
 *
 * Explicit up()/down() rather than change(): a raw UPDATE cannot be
 * auto-reversed by Phinx (see database/migrations/README.md). Both are
 * naturally idempotent — a second run's WHERE clause matches zero rows, making
 * the UPDATE a no-op — so no separate existence-check guard is needed (unlike
 * an INSERT, which would error on duplicate key).
 */
final class RenameReconciliationJob extends AbstractMigration
{
    private const OLD_NAME = 'reconciliation';
    private const NEW_NAME = 'brevo_reconciliation';

    public function up(): void
    {
        $this->renameJob(self::OLD_NAME, self::NEW_NAME);
    }

    public function down(): void
    {
        $this->renameJob(self::NEW_NAME, self::OLD_NAME);
    }

    private function renameJob(string $from, string $to): void
    {
        $adapter = $this->getAdapter();
        $adapter->beginTransaction();
        try {
            $this->execute(
                "UPDATE er_cron_job_runs SET job_name = '$to' WHERE job_name = '$from'"
            );
            $adapter->commitTransaction();
        } catch (\Throwable $e) {
            $adapter->rollbackTransaction();
            throw $e;
        }
    }
}
