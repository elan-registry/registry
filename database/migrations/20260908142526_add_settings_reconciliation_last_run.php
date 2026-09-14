<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds `settings.reconciliation_last_run` (#2027), consumed by the
 * forthcoming `CronJobGuard` class to record the last successful run of the
 * reconciliation cron job.
 *
 * `change()` is used per database/migrations/README.md — "Strongly prefer
 * change()" — since this is a single, cleanly auto-reversible addColumn()
 * with no mixed irreversible operations and no `*_hist`/trigger companion to
 * rebuild (unlike the `cars` column-add migrations).
 */
final class AddSettingsReconciliationLastRun extends AbstractMigration
{
    public function change(): void
    {
        $this->table('settings')
            ->addColumn('reconciliation_last_run', 'datetime', ['null' => true, 'default' => null])
            ->update();
    }
}
