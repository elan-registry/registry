<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FixScriptRunsCharsetAndNullability extends AbstractMigration
{
    // CONVERT TO CHARACTER SET updates the table default and all text column
    // charsets in a single DDL statement. DDL triggers an implicit commit in
    // MySQL and cannot be wrapped in a transaction.
    //
    // up() + down() are used instead of change() because raw SQL is not
    // auto-reversible. Row-count guards verify no data is silently lost during
    // the charset conversion (utf8mb4 is a superset of utf8mb3, so no row
    // loss is expected, but a mismatch would indicate a MySQL-level anomaly).
    public function up(): void
    {
        $before = (int) $this->fetchRow("SELECT COUNT(*) AS n FROM `fix_script_runs`")['n'];

        $this->execute(
            "ALTER TABLE `fix_script_runs`
             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        $this->execute(
            "ALTER TABLE `fix_script_runs`
             MODIFY COLUMN `completed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP"
        );

        $after = (int) $this->fetchRow("SELECT COUNT(*) AS n FROM `fix_script_runs`")['n'];
        if ($before !== $after) {
            throw new \RuntimeException(
                "fix_script_runs row count changed during charset conversion: {$before} → {$after}. " .
                "Investigate immediately — utf8mb3 → utf8mb4 conversion should preserve all rows."
            );
        }
    }

    public function down(): void
    {
        $before = (int) $this->fetchRow("SELECT COUNT(*) AS n FROM `fix_script_runs`")['n'];

        $this->execute(
            "ALTER TABLE `fix_script_runs`
             MODIFY COLUMN `completed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP"
        );
        $this->execute(
            "ALTER TABLE `fix_script_runs`
             CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci"
        );

        $after = (int) $this->fetchRow("SELECT COUNT(*) AS n FROM `fix_script_runs`")['n'];
        if ($before !== $after) {
            throw new \RuntimeException(
                "fix_script_runs row count changed during charset reversion: {$before} → {$after}. " .
                "Investigate immediately — utf8mb4 → utf8mb3 conversion should preserve all rows."
            );
        }
    }
}
