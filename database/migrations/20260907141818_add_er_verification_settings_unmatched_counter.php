<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Issue #1887: adds `unmatched_webhook_recipient_count` to
 * `er_verification_settings`, the single-row settings table (#1926).
 *
 * Tracks how many inbound Brevo webhook events matched no car by recipient
 * email — a rising count with verification enabled indicates recipients
 * whose emails no longer match any `cars.email` value (e.g. after an owner
 * changed email without re-verifying). No dashboard rendering of this value
 * is in scope here (#1887); a follow-up issue owns the UI.
 *
 * up()/down() (not change()) matches 20260907120000_add_er_verification_settings.php's
 * own style, since that migration modifies the same table and hit the
 * documented change()-can't-guard-hasTable()/hasColumn() rollback trap.
 * Guarded addColumn()/removeColumn() via hasColumn() mirrors the same
 * idempotency pattern used by 20260902104755_add_car_verification_columns.php.
 */
final class AddErVerificationSettingsUnmatchedCounter extends AbstractMigration
{
    private const TABLE = 'er_verification_settings';

    public function up(): void
    {
        $table = $this->table(self::TABLE);

        if (!$table->hasColumn('unmatched_webhook_recipient_count')) {
            $table->addColumn('unmatched_webhook_recipient_count', 'integer', [
                'signed'  => false,
                'null'    => false,
                'default' => 0,
                'comment' => 'Count of inbound Brevo webhook events whose recipient matched no car.',
            ])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table(self::TABLE);

        if ($table->hasColumn('unmatched_webhook_recipient_count')) {
            $table->removeColumn('unmatched_webhook_recipient_count')->update();
        }
    }
}
