<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class MigrateLogsToInnodb extends AbstractMigration
{
    // Engine change (MyISAM → InnoDB) requires raw SQL — Phinx's Table API
    // has no first-class support for altering an existing table's ENGINE.
    // DDL triggers an implicit commit in MySQL, so the ALTER cannot be
    // wrapped in a transaction; MyISAM → InnoDB conversion is atomic at the
    // MySQL level and preserves all rows.
    //
    // up() + down() are used instead of change() because raw SQL is not
    // auto-reversible. The pre-flight row count is captured and re-verified
    // after the swap so a silent data loss is surfaced immediately.
    public function up(): void
    {
        $before = (int) $this->fetchRow("SELECT COUNT(*) AS n FROM `logs`")['n'];

        $this->execute("ALTER TABLE `logs` ENGINE=InnoDB");

        $after = (int) $this->fetchRow("SELECT COUNT(*) AS n FROM `logs`")['n'];
        if ($before !== $after) {
            throw new \RuntimeException(
                "logs row count changed during engine conversion: {$before} → {$after}. " .
                "Investigate immediately — MyISAM → InnoDB conversion should preserve all rows."
            );
        }
    }

    public function down(): void
    {
        $before = (int) $this->fetchRow("SELECT COUNT(*) AS n FROM `logs`")['n'];

        $this->execute("ALTER TABLE `logs` ENGINE=MyISAM");

        $after = (int) $this->fetchRow("SELECT COUNT(*) AS n FROM `logs`")['n'];
        if ($before !== $after) {
            throw new \RuntimeException(
                "logs row count changed during engine conversion: {$before} → {$after}. " .
                "Investigate immediately — InnoDB → MyISAM conversion should preserve all rows."
            );
        }
    }
}
