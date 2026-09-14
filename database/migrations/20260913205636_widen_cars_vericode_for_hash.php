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
    // data, as observed before this migration ran: ~1080 cars.vericode rows
    // were non-empty 32-char plaintext hex, ~408 of them on cars with
    // last_verified set). No feature reads this column today (zero callers
    // in app/ as of #1928), so these codes are
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
        // DML step: explicitly transacted per database/migrations/README.md's
        // "Transactions" section. `mtime = mtime` suppresses the ON UPDATE
        // CURRENT_TIMESTAMP bump — omitting the column does NOT suppress it,
        // it would instead overwrite every touched row's real modification
        // timestamp with the migration's run time.
        //
        // @disable_triggers is required here, not optional: this runs while
        // the cars_update trigger is still installed, so without it every
        // nulled row writes a spurious 'UPDATE' cars_hist entry for what is
        // pure internal bookkeeping, not an owner edit. Same guard, same
        // reason, same structure as 20260905172137_convert_car_timestamps_to_datetime.php's
        // NULL_REPAIR_SQL step — see that migration for the precedent.
        $adapter = $this->getAdapter();
        $adapter->beginTransaction();
        $this->execute('SET @disable_triggers = 1');

        try {
            $this->execute(
                "UPDATE cars SET vericode = NULL, mtime = mtime"
                . " WHERE vericode IS NOT NULL AND vericode <> ''"
            );
            // Commit inside the try: the DDL below implicit-commits, so an
            // open transaction left by a throw here would be silently
            // committed by that ALTER rather than rolled back.
            $adapter->commitTransaction();
        } catch (\Throwable $e) {
            try {
                $adapter->rollbackTransaction();
            } catch (\Throwable $rollbackFailure) {
                throw new \RuntimeException(
                    'Rollback failed while handling: ' . $e->getMessage()
                    . ' (rollback error: ' . $rollbackFailure->getMessage() . ')',
                    0,
                    $e
                );
            }

            throw $e;
        } finally {
            // SESSION variable: survives both the failed statement and the rollback.
            $this->execute('SET @disable_triggers = NULL');
        }

        // The DDL steps below are NOT covered by the transaction above —
        // MySQL issues an implicit commit on ALTER TABLE (see README's
        // "Transactions" section), so a failure between changeColumn() and
        // addIndex() leaves the schema half-migrated with no phinxlog row.
        // Both steps are individually idempotent (guarded below), so
        // re-running this migration is the documented recovery.
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
    // live data, so that part is not a data-loss concern. Any *hashed* codes
    // issued after up() ran are a separate concern: a 64-char hash cannot fit
    // varchar(32). Under this project's STRICT_TRANS_TABLES sql_mode the
    // column-shrink ALTER would abort outright ("Data too long"); without
    // strict mode it would silently truncate every hash instead. Either way,
    // a rolled-back hashing scheme leaves no usable codes, so up()'s own
    // pattern is repeated here: null the column first, deliberately, rather
    // than let the shrink itself decide. The index is dropped before the
    // narrow because MySQL will not narrow an indexed column in place — not
    // because it affects truncation.
    public function down(): void
    {
        // Same @disable_triggers guard as up() — see that method's note.
        $adapter = $this->getAdapter();
        $adapter->beginTransaction();
        $this->execute('SET @disable_triggers = 1');

        try {
            $this->execute("UPDATE cars SET vericode = NULL, mtime = mtime WHERE vericode IS NOT NULL");
            $adapter->commitTransaction();
        } catch (\Throwable $e) {
            try {
                $adapter->rollbackTransaction();
            } catch (\Throwable $rollbackFailure) {
                throw new \RuntimeException(
                    'Rollback failed while handling: ' . $e->getMessage()
                    . ' (rollback error: ' . $rollbackFailure->getMessage() . ')',
                    0,
                    $e
                );
            }

            throw $e;
        } finally {
            $this->execute('SET @disable_triggers = NULL');
        }

        // DDL below is not covered by the transaction above — see up()'s note.
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
