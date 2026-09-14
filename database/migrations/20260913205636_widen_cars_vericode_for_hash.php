<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class WidenCarsVericodeForHash extends AbstractMigration
{
    // up() + down() are used instead of change() because changeColumn() is not
    // auto-reversible (Phinx records only the new column definition, not the
    // original) — mixing it with addIndex() means the whole migration needs
    // explicit up()/down(), per database/migrations/README.md.
    //
    // cars.vericode is being converted from plaintext storage to an
    // HMAC-SHA256 hash (64-char lowercase hex) ahead of the first live
    // verification batch — see issue #1928. The existing varchar(32) column
    // does not fit a 64-char digest, so it must be widened first.
    //
    // Legacy plaintext codes DO exist (confirmed against production-shaped
    // data: ~1080 cars.vericode rows are non-empty 32-char plaintext hex,
    // ~408 of them on cars with last_verified set). No feature reads this
    // column today (zero callers in app/ as of #1928), so these codes are
    // unreachable dead credentials — hashing lookups going forward makes
    // them permanently unresolvable if left as-is, and leaves a plaintext
    // secret corpus in the DB, which is exactly what this issue removes.
    // up() therefore nulls every existing value before widening, rather
    // than leaving stale plaintext behind.
    //
    // Every step is guarded (column-type check, hasIndex()) so the migration
    // is idempotent and safe to re-run if a previous attempt was interrupted
    // before the phinxlog entry was committed.
    public function up(): void
    {
        $this->execute("UPDATE cars SET vericode = NULL WHERE vericode IS NOT NULL AND vericode <> ''");

        $cars = $this->table('cars');

        if (stripos((string) $this->columnType('cars', 'vericode'), 'varchar(64)') === false) {
            $cars->changeColumn('vericode', 'string', ['limit' => 64, 'null' => true])
                 ->update();
        }

        if (!$cars->hasIndex(['vericode'])) {
            $cars->addIndex(['vericode'])->update();
        }
    }

    // down() reverts schema only — the nulled legacy codes from up() are not
    // (and cannot be) restored; they were unreachable dead credentials, not
    // live data, so this is not a data-loss concern. The index is dropped
    // before the column is narrowed back to varchar(32); any hash value
    // longer than 32 chars would otherwise be truncated silently by the
    // column shrink.
    public function down(): void
    {
        $cars = $this->table('cars');

        if ($cars->hasIndex(['vericode'])) {
            $cars->removeIndex(['vericode'])->update();
        }

        if (stripos((string) $this->columnType('cars', 'vericode'), 'varchar(32)') === false) {
            $cars->changeColumn('vericode', 'string', ['limit' => 32, 'null' => true])
                 ->update();
        }
    }

    /**
     * Returns the MySQL COLUMN_TYPE (e.g. "varchar(32)") for the given
     * table/column, or null if the column does not exist.
     */
    private function columnType(string $table, string $column): ?string
    {
        // $table/$column are always hardcoded literals from call sites in this
        // migration, never user input — Phinx's fetchRow() does not support
        // parameter binding.
        $row = $this->fetchRow(sprintf(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = '%s'
               AND COLUMN_NAME = '%s'",
            $table,
            $column
        ));

        return $row ? (string) $row['COLUMN_TYPE'] : null;
    }
}
