<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ChangeCarsYearAndDropModifiedby extends AbstractMigration
{
    // up() + down() are used instead of change() because Phinx cannot auto-reverse
    // a changeColumn() that alters type, and the ModifiedBy column carries no
    // functional data — dropping it is a one-way schema simplification that we
    // want to be able to reverse structurally (down() re-adds a nullable column
    // but does not restore historical ModifiedBy values). The trigger rebuilds
    // are also not auto-reversible.
    //
    // Every DDL step is guarded by an existence/type check so the migration is
    // idempotent and safe to re-run if a previous attempt was interrupted before
    // the phinxlog entry was committed.
    public function up(): void
    {
        // Drop triggers first — MySQL will refuse to DROP a column referenced by
        // a trigger body, so the triggers must go before ModifiedBy is removed.
        // DROP IF EXISTS makes these idempotent.
        $this->execute('DROP TRIGGER IF EXISTS cars_insert');
        $this->execute('DROP TRIGGER IF EXISTS cars_update');
        $this->execute('DROP TRIGGER IF EXISTS cars_delete');

        // cars_hist contains legacy rows with invalid dates (e.g. '1999-06-00') that
        // predate strict-mode enforcement. MySQL re-validates the whole table on every
        // ALTER TABLE, so strict mode would reject those rows even though we are only
        // changing the year column. Disable it for the session so the ALTER proceeds;
        // strict mode resumes automatically when the connection closes.
        $this->execute("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");

        $trustFunctionCreatorsSet = $this->enableTrustFunctionCreators();

        // Normalize each table's year column before the type change.
        // Under NO_ENGINE_SUBSTITUTION, ALTER TABLE MODIFY COLUMN silently coerces
        // empty strings to 0 rather than NULL — so we must clear sentinels first.
        // Both tables need the same treatment even though only cars_hist had known
        // empty-string rows in production; cars.year was NOT NULL so it needs to be
        // made nullable before the UPDATE can set rows to NULL.
        foreach (['cars', 'cars_hist'] as $tbl) {
            $info = $this->fetchRow(
                "SELECT COLUMN_TYPE, IS_NULLABLE
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = '{$tbl}'
                   AND COLUMN_NAME = 'year'"
            );
            if ($info && stripos((string) $info['COLUMN_TYPE'], 'varchar') !== false) {
                if ($info['IS_NULLABLE'] === 'NO') {
                    // Make nullable first so the UPDATE can SET year = NULL.
                    $this->execute("ALTER TABLE `{$tbl}` MODIFY COLUMN year varchar(4) NULL");
                }
                $this->execute("UPDATE `{$tbl}` SET year = NULL WHERE year = '' OR year = '0'");
            }
        }

        // Change year to SMALLINT UNSIGNED NULL on cars and cars_hist — skip whichever
        // table has already been converted (idempotent re-run support).
        if (stripos((string) $this->columnType('cars', 'year'), 'varchar') !== false) {
            $this->table('cars')
                 ->changeColumn('year', 'smallinteger', ['signed' => false, 'null' => true])
                 ->update();
        }
        if (stripos((string) $this->columnType('cars_hist', 'year'), 'varchar') !== false) {
            $this->table('cars_hist')
                 ->changeColumn('year', 'smallinteger', ['signed' => false, 'null' => true])
                 ->update();
        }

        // Drop ModifiedBy — skip if already removed.
        if ($this->table('cars')->hasColumn('ModifiedBy')) {
            $this->table('cars')->removeColumn('ModifiedBy')->update();
        }
        if ($this->table('cars_hist')->hasColumn('ModifiedBy')) {
            $this->table('cars_hist')->removeColumn('ModifiedBy')->update();
        }

        // Recreate the three cars triggers without any ModifiedBy references.
        // Preserved verbatim from the pre-migration bodies except for the
        // removal of ModifiedBy from the column list and VALUES tuple.
        $this->execute(<<<'SQL'
CREATE TRIGGER `cars_delete` AFTER DELETE ON `cars` FOR EACH ROW BEGIN
    INSERT INTO cars_hist(
        operation, car_id, ctime, mtime, model, series, variant,
        year, type, chassis, chassis_override, color, engine, purchasedate, solddate, comments,
        image, user_id, email, fname, lname, join_date, city, state, country,
        lat, lon, website
    )
    VALUES (
        'DELETE', OLD.id, OLD.ctime, OLD.mtime, OLD.model,
        OLD.series, OLD.variant, OLD.year, OLD.type, OLD.chassis, OLD.chassis_override,
        OLD.color, OLD.engine, OLD.purchasedate, OLD.solddate, OLD.comments, OLD.image,
        OLD.user_id, OLD.email, OLD.fname, OLD.lname, OLD.join_date, OLD.city,
        OLD.state, OLD.country, OLD.lat, OLD.lon, OLD.website
    );
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER `cars_insert` AFTER INSERT ON `cars` FOR EACH ROW BEGIN
    INSERT INTO cars_hist(
        operation, car_id, ctime, mtime, model, series, variant,
        year, type, chassis, chassis_override, color, engine, purchasedate, solddate, comments,
        image, user_id, email, fname, lname, join_date, city, state, country,
        lat, lon, website
    )
    VALUES (
        'INSERT', NEW.id, NEW.ctime, NEW.mtime, NEW.model,
        NEW.series, NEW.variant, NEW.year, NEW.type, NEW.chassis, NEW.chassis_override,
        NEW.color, NEW.engine, NEW.purchasedate, NEW.solddate, NEW.comments, NEW.image,
        NEW.user_id, NEW.email, NEW.fname, NEW.lname, NEW.join_date, NEW.city,
        NEW.state, NEW.country, NEW.lat, NEW.lon, NEW.website
    );
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER `cars_update` AFTER UPDATE ON `cars` FOR EACH ROW BEGIN
    IF @disable_triggers IS NULL THEN
        INSERT INTO cars_hist(
            operation, car_id, ctime, mtime, model, series, variant,
            year, type, chassis, chassis_override, color, engine, purchasedate, solddate, comments,
            image, user_id, email, fname, lname, join_date, city, state, country,
            lat, lon, website
        )
        VALUES (
            'UPDATE', OLD.id, OLD.ctime, OLD.mtime, OLD.model,
            OLD.series, OLD.variant, OLD.year, OLD.type, OLD.chassis, NEW.chassis_override,
            OLD.color, OLD.engine, OLD.purchasedate, OLD.solddate, OLD.comments, OLD.image,
            OLD.user_id, OLD.email, OLD.fname, OLD.lname, OLD.join_date, OLD.city,
            OLD.state, OLD.country, OLD.lat, OLD.lon, OLD.website
        );
    END IF;
END
SQL);

        $this->resetTrustFunctionCreators($trustFunctionCreatorsSet);
    }

    // down() reverts schema only. Rows in cars_hist whose year was normalized
    // from '' or '0' to NULL in up() are restored to '' (empty string) — the
    // distinction between '' and '0' is lost. The column reverts to NOT NULL varchar(4)
    // so those rows need a non-NULL value.
    public function down(): void
    {
        // Same legacy-date and trigger-privilege setup as up().
        $this->execute("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        $trustFunctionCreatorsSet = $this->enableTrustFunctionCreators();

        // Drop the ModifiedBy-less triggers before restoring the column.
        $this->execute('DROP TRIGGER IF EXISTS cars_insert');
        $this->execute('DROP TRIGGER IF EXISTS cars_update');
        $this->execute('DROP TRIGGER IF EXISTS cars_delete');

        // Re-add ModifiedBy on both tables — skip if already present (idempotent).
        // Values cannot be recovered — the column comes back nullable with NULL.
        if (!$this->table('cars')->hasColumn('ModifiedBy')) {
            $this->table('cars')
                 ->addColumn('ModifiedBy', 'string', [
                     'limit' => 30,
                     'null'  => true,
                     'after' => 'last_verified',
                 ])
                 ->update();
        }
        if (!$this->table('cars_hist')->hasColumn('ModifiedBy')) {
            $this->table('cars_hist')
                 ->addColumn('ModifiedBy', 'string', [
                     'limit' => 30,
                     'null'  => true,
                     'after' => 'mtime',
                 ])
                 ->update();
        }

        // Revert year back to varchar(4) NOT NULL on both tables — skip if already reverted.
        // NULL values must be replaced with '' before the NOT NULL revert, because
        // up() may have normalized '' to NULL and the original column was NOT NULL.
        // The distinction between '' and '0' that existed before up() cannot be recovered.
        if (stripos((string) $this->columnType('cars', 'year'), 'smallint') !== false) {
            $this->execute("UPDATE cars SET year = '' WHERE year IS NULL");
            $this->table('cars')
                 ->changeColumn('year', 'string', ['limit' => 4, 'null' => false, 'default' => ''])
                 ->update();
        }

        if (stripos((string) $this->columnType('cars_hist', 'year'), 'smallint') !== false) {
            $this->execute("UPDATE cars_hist SET year = '' WHERE year IS NULL");
            $this->table('cars_hist')
                 ->changeColumn('year', 'string', ['limit' => 4, 'null' => false, 'default' => ''])
                 ->update();
        }

        // Recreate the original triggers with ModifiedBy references (verbatim
        // from database/1-schema.sql at the time this migration was written).
        $this->execute(<<<'SQL'
CREATE TRIGGER `cars_delete` AFTER DELETE ON `cars` FOR EACH ROW BEGIN
    INSERT INTO cars_hist(
        operation, car_id, ctime, mtime, ModifiedBy, model, series, variant,
        year, type, chassis, chassis_override, color, engine, purchasedate, solddate, comments,
        image, user_id, email, fname, lname, join_date, city, state, country,
        lat, lon, website
    )
    VALUES (
        'DELETE', OLD.id, OLD.ctime, OLD.mtime, OLD.ModifiedBy, OLD.model,
        OLD.series, OLD.variant, OLD.year, OLD.type, OLD.chassis, OLD.chassis_override,
        OLD.color, OLD.engine, OLD.purchasedate, OLD.solddate, OLD.comments, OLD.image,
        OLD.user_id, OLD.email, OLD.fname, OLD.lname, OLD.join_date, OLD.city,
        OLD.state, OLD.country, OLD.lat, OLD.lon, OLD.website
    );
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER `cars_insert` AFTER INSERT ON `cars` FOR EACH ROW BEGIN
    INSERT INTO cars_hist(
        operation, car_id, ctime, mtime, ModifiedBy, model, series, variant,
        year, type, chassis, chassis_override, color, engine, purchasedate, solddate, comments,
        image, user_id, email, fname, lname, join_date, city, state, country,
        lat, lon, website
    )
    VALUES (
        'INSERT', NEW.id, NEW.ctime, NEW.mtime, NEW.ModifiedBy, NEW.model,
        NEW.series, NEW.variant, NEW.year, NEW.type, NEW.chassis, NEW.chassis_override,
        NEW.color, NEW.engine, NEW.purchasedate, NEW.solddate, NEW.comments, NEW.image,
        NEW.user_id, NEW.email, NEW.fname, NEW.lname, NEW.join_date, NEW.city,
        NEW.state, NEW.country, NEW.lat, NEW.lon, NEW.website
    );
END
SQL);

        $this->execute(<<<'SQL'
CREATE TRIGGER `cars_update` AFTER UPDATE ON `cars` FOR EACH ROW BEGIN
    IF @disable_triggers IS NULL THEN
        INSERT INTO cars_hist(
            operation, car_id, ctime, mtime, ModifiedBy, model, series, variant,
            year, type, chassis, chassis_override, color, engine, purchasedate, solddate, comments,
            image, user_id, email, fname, lname, join_date, city, state, country,
            lat, lon, website
        )
        VALUES (
            'UPDATE', OLD.id, OLD.ctime, OLD.mtime, OLD.ModifiedBy, OLD.model,
            OLD.series, OLD.variant, OLD.year, OLD.type, OLD.chassis, NEW.chassis_override,
            OLD.color, OLD.engine, OLD.purchasedate, OLD.solddate, OLD.comments, OLD.image,
            OLD.user_id, OLD.email, OLD.fname, OLD.lname, OLD.join_date, OLD.city,
            OLD.state, OLD.country, OLD.lat, OLD.lon, OLD.website
        );
    END IF;
END
SQL);

        $this->resetTrustFunctionCreators($trustFunctionCreatorsSet);
    }

    /**
     * CREATE TRIGGER requires either SUPER privilege or log_bin_trust_function_creators=1
     * when binary logging is enabled. Attempt to set it globally; if the migration user
     * lacks SUPER/SYSTEM_VARIABLES_ADMIN, continue anyway — the variable may already be
     * set globally (common on managed hosting panels), otherwise the DBA must set it in
     * MySQL config (log_bin_trust_function_creators=1 in my.cnf).
     *
     * @return bool True if this call set the variable (and so should reset it afterward).
     */
    private function enableTrustFunctionCreators(): bool
    {
        try {
            $this->execute("SET GLOBAL log_bin_trust_function_creators = 1");
            return true;
        } catch (\RuntimeException $e) {
            // Privilege denied — the variable may already be set globally (common on managed
            // hosting). If the subsequent CREATE TRIGGER calls fail with a privilege error,
            // the DBA must add log_bin_trust_function_creators=1 to my.cnf.
            if (isset($this->output)) {
                $this->output->writeln(
                    '<comment>Warning: Could not SET GLOBAL log_bin_trust_function_creators=1 '
                    . '— continuing. If CREATE TRIGGER fails below, set this variable in my.cnf.</comment>'
                );
            }
            return false;
        }
    }

    /**
     * Resets log_bin_trust_function_creators if this migration run set it — limits the
     * window of elevated trust to only the trigger creation steps.
     */
    private function resetTrustFunctionCreators(bool $wasSet): void
    {
        if (!$wasSet) {
            return;
        }
        try {
            $this->execute("SET GLOBAL log_bin_trust_function_creators = 0");
        } catch (\Exception $e) {
            // Non-fatal — triggers are already created; DBA can reset manually.
            if (isset($this->output)) {
                $this->output->writeln(
                    '<comment>Warning: Could not reset log_bin_trust_function_creators=0: '
                    . $e->getMessage()
                    . ' — set it manually in MySQL or my.cnf.</comment>'
                );
            }
        }
    }

    /**
     * Returns the MySQL COLUMN_TYPE (e.g. "varchar(4)", "smallint(5) unsigned") for the
     * given table/column, or null if the column does not exist.
     */
    private function columnType(string $table, string $column): ?string
    {
        // $table/$column are always hardcoded literals from call sites in this migration,
        // never user input — Phinx's fetchRow() does not support parameter binding.
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
