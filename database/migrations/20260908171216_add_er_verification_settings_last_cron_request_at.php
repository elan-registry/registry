<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds `er_verification_settings.last_cron_request_at` (#1974), recording the
 * last time the verification cron endpoint was requested — regardless of
 * whether it did any work — so we can distinguish a healthy idle cron from a
 * cron that has stopped running.
 *
 * `change()` is used per database/migrations/README.md — "Strongly prefer
 * change()" — since this is a single, cleanly auto-reversible addColumn()
 * with no mixed irreversible operations and no `*_hist`/trigger companion to
 * rebuild (unlike the `cars` column-add migrations).
 */
final class AddErVerificationSettingsLastCronRequestAt extends AbstractMigration
{
    public function change(): void
    {
        $this->table('er_verification_settings')
            ->addColumn('last_cron_request_at', 'datetime', ['null' => true, 'default' => null])
            ->update();
    }
}
