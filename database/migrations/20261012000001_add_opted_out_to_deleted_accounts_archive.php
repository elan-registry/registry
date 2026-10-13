<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds `email_opted_out` and `email_opted_out_at` to `deleted_accounts_archive`
 * (#2101).
 *
 * An owner's opt-out is a refusal of non-system mail. `archiveAccounts()`
 * keeps it with the archived account, so that `restoreArchivedAccount()` can
 * put it back on the new `profiles` row. Without these columns, a restore
 * silently enables mail to an owner who opted out.
 *
 * The types match `profiles.email_opted_out` (TINYINT(1) NOT NULL DEFAULT 0)
 * and `profiles.email_opted_out_at` (DATETIME NULL). The block and bounce
 * flags are not archived: a restore does not copy them (#2101).
 *
 * `change()` is used per database/migrations/README.md: two `addColumn()`
 * calls reverse cleanly, and the table has no `*_hist` table or trigger.
 */
final class AddOptedOutToDeletedAccountsArchive extends AbstractMigration
{
    public function change(): void
    {
        $this->table('deleted_accounts_archive')
            ->addColumn('email_opted_out', 'boolean', ['null' => false, 'default' => 0])
            ->addColumn('email_opted_out_at', 'datetime', ['null' => true, 'default' => null])
            ->update();
    }
}
