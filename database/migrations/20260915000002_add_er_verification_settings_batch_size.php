<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Issue #1884: admin-configurable batch size for the verification-email send
 * tool's per-run limit.
 *
 * Adds `er_verification_settings.batch_size` — an unsigned TINYINT, NOT NULL,
 * DEFAULT 5 — read by `VerificationSettings::batchSize()` to cap how many
 * verification emails a single run of the send tool processes. This
 * migration only adds the column for reading; no writer is added here — a
 * settings-dashboard control to change the value is a future issue's scope.
 *
 * The schema default alone correctly seeds the existing single settings row
 * (`id = 1`, created by 20260907120000_add_er_verification_settings.php):
 * `ALTER TABLE ... ADD COLUMN ... DEFAULT 5` backfills that default into
 * every existing row, so no separate `UPDATE ... WHERE id = 1` is needed.
 *
 * up() + down() rather than change(): consistent with the other schema
 * migrations in this directory. Both steps are guarded with hasColumn(), so
 * re-running after a partial failure is safe and does no duplicate work.
 */
final class AddErVerificationSettingsBatchSize extends AbstractMigration
{
    private const TABLE = 'er_verification_settings';

    public function up(): void
    {
        $table = $this->table(self::TABLE);

        if (!$table->hasColumn('batch_size')) {
            $table->addColumn('batch_size', 'integer', [
                'limit'   => MysqlAdapter::INT_TINY,
                'signed'  => false,
                'null'    => false,
                'default' => 5,
                'comment' => 'Per-run limit for the verification-email send tool.',
            ])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table(self::TABLE);

        if ($table->hasColumn('batch_size')) {
            $table->removeColumn('batch_size')->update();
        }
    }
}
