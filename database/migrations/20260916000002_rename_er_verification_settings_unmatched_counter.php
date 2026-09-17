<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Issue #2085: renames `unmatched_webhook_recipient_count` to
 * `unmatched_recipient_count` on `er_verification_settings`, and updates the
 * column's MySQL COMMENT to match — the old comment ("Count of inbound Brevo
 * webhook events...") stated exactly the webhook-only assumption this rename
 * exists to correct, so leaving it in place would ship a comment that
 * contradicts its own column name.
 *
 * The original name (#1887) assumed only the webhook receiver would ever
 * feed this counter. #2085 wires BrevoEventReconciliationJob and
 * BrevoSuppressionSyncJob into the same counter, so the name no longer
 * reflects its callers — this migration renames the column and its comment,
 * no value changes.
 *
 * up()/down() (not change()) is required here: renameColumn() alone is one
 * of Phinx's auto-reversible Table methods per database/migrations/README.md,
 * but changeColumn() — needed to also correct the COMMENT — is not, and
 * throws IrreversibleMigrationException if called from within change().
 *
 * Note: Phinx wraps migrations in a transaction (Environment::
 * executeMigration()), but that wrapping does not make this migration
 * atomic — MySQL implicitly commits before and after every DDL statement,
 * so the enclosing transaction is inert for ALTER TABLE. Each statement
 * below is independently atomic at the server level; there is no
 * transactional rollback across the two ALTERs if the second one failed
 * after the first committed. changeColumn() is idempotent for identical
 * target attributes, so re-running up() after BOTH statements committed is
 * safe. Re-running it after only the rename committed is NOT: Phinx's
 * renameColumn() throws InvalidArgumentException ("The specified column
 * doesn't exist") rather than no-opping when the source name is already
 * gone, so that partial state needs a manual `ALTER TABLE
 * er_verification_settings CHANGE COLUMN unmatched_recipient_count ...` to
 * finish applying the COMMENT. Accepted as a narrow, hand-recoverable window.
 */
final class RenameErVerificationSettingsUnmatchedCounter extends AbstractMigration
{
    private const NEW_COLUMN_COMMENT = 'Count of inbound Brevo signals (webhook events, '
        . 'reconciliation events, suppression-list contacts) whose recipient matched no car.';

    public function up(): void
    {
        $table = $this->table('er_verification_settings');
        $table->renameColumn('unmatched_webhook_recipient_count', 'unmatched_recipient_count')
            ->update();

        $this->table('er_verification_settings')
            ->changeColumn('unmatched_recipient_count', 'integer', [
                'signed'  => false,
                'null'    => false,
                'default' => 0,
                'comment' => self::NEW_COLUMN_COMMENT,
            ])->update();
    }

    public function down(): void
    {
        $this->table('er_verification_settings')
            ->renameColumn('unmatched_recipient_count', 'unmatched_webhook_recipient_count')
            ->update();

        $this->table('er_verification_settings')
            ->changeColumn('unmatched_webhook_recipient_count', 'integer', [
                'signed'  => false,
                'null'    => false,
                'default' => 0,
                'comment' => 'Count of inbound Brevo webhook events whose recipient matched no car.',
            ])->update();
    }
}
