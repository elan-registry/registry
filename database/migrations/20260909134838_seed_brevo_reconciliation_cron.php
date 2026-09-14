<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Registers the nightly Brevo event reconciliation job in UserSpice's
 * `crons` table (#1889), so `users/cron/cron.php`'s dispatcher picks up
 * `users/cron/brevo_event_reconciliation.php` on its next 10-minute pass.
 *
 * This is the first-ever row this codebase has inserted into `crons`.
 * docs/development/DEPLOYMENT.md's "Cron Transport" section is explicit
 * that a new job's row must ship "via a migration/seed, not by hand in the
 * admin UI" — this migration is that seed.
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
 * `created` uses the database clock (SQL NOW()) rather than a PHP-generated
 * timestamp, matching CreateCronJobRuns's (20260908203118) same reasoning
 * for created_at.
 *
 * IDEMPOTENCY: `up()` skips the insert when a `crons` row for this file
 * already exists. `crons` carries only `PRIMARY KEY (id)` — there is no
 * unique constraint on `file` — so nothing at the schema level would stop a
 * second row. That matters precisely because of the hand-seeded case above:
 * an environment where the row was added through the admin UI before this
 * migration existed would otherwise end up with two. The dispatcher's
 * `include_once` means the job would still run only once, so the damage is
 * not double-execution but a permanent phantom second `crons_logs` entry on
 * every cron hit — a misleading artifact far easier to prevent than to
 * explain later.
 */
final class SeedBrevoReconciliationCron extends AbstractMigration
{
    /**
     * Explicit up()/down() rather than change(): the insert is written as
     * raw SQL (needed for the dynamically computed `sort` value and the
     * SQL NOW() call), and per database/migrations/README.md, raw
     * $this->execute() calls cannot be auto-reversed by Phinx — change()
     * would leave rollback and --dry-run unsupported for this migration.
     */
    public function up(): void
    {
        // Idempotency guard — see the class docblock. Checked before the
        // transaction opens: there is nothing to roll back on the skip path.
        $existing = $this->fetchRow(
            "SELECT id FROM crons WHERE file = 'brevo_event_reconciliation.php' LIMIT 1"
        );
        if (!empty($existing)) {
            return;
        }

        $adapter = $this->getAdapter();
        $adapter->beginTransaction();

        $row = $this->fetchRow('SELECT COALESCE(MAX(sort), 0) + 10 AS next_sort FROM crons');
        $nextSort = (int) $row['next_sort'];

        $this->execute(
            sprintf(
                "INSERT INTO crons (active, sort, name, file, createdby, created)
                 VALUES (1, %d, 'Brevo Event Reconciliation', 'brevo_event_reconciliation.php', 1, NOW())",
                $nextSort
            )
        );

        $adapter->commitTransaction();
    }

    public function down(): void
    {
        $adapter = $this->getAdapter();
        $adapter->beginTransaction();

        $this->execute(
            "DELETE FROM crons WHERE file = 'brevo_event_reconciliation.php'"
        );

        $adapter->commitTransaction();
    }
}
