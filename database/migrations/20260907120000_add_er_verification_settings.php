<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Creates `er_verification_settings`, the single-row feature switch that gates
 * the car verification system (#1926).
 *
 * The table holds exactly one row (`id = 1`) whose `enabled` flag turns the
 * verification UI and workflow on or off site-wide. It is deliberately its own
 * table rather than another column on UserSpice's `settings`: verification is
 * ElanRegistry domain configuration, and `settings` is upstream framework
 * schema that the baseline migration already has to reconcile column-by-column.
 *
 * The switch ships **off** (`enabled = 0`) so deploying the verification code
 * does not expose the feature until an admin turns it on.
 *
 * NAMING — this is the first table under the project-wide `er_` prefix
 * convention: every new ElanRegistry-owned table is named `er_*` from here on,
 * which keeps project tables visually separable from the ~58 stock UserSpice
 * tables sharing the database. Pre-existing project tables (`cars`,
 * `car_models`, `elan_factory_info`, …) predate the convention and are not
 * renamed — renaming them would break every query, trigger, and audit table
 * that references them for no functional gain.
 *
 * No audit trail: unlike `cars`, this table carries no `*_hist` companion or
 * triggers. It stores one operator-set boolean with no historical value, and
 * admin changes to it are already recorded through `logger()`.
 *
 * up()/down() are used instead of change() even though a create+insert looks
 * like the ideal change() candidate, because the two cannot be combined with
 * the hasTable() idempotency guard. Phinx rolls a change() back by replaying
 * the method body to record which operations it would need to invert; on
 * rollback the table still exists, so the guard returns early, nothing is
 * recorded, and `migrate:rollback` reports "reverted" while leaving the table
 * in place. Verified empirically on Phinx 0.16.12 — the rollback logged
 * success and `SHOW TABLES` still listed the table. This is the same trap
 * documented on 20260817040000_drop_dead_frontend_settings_columns.php, which
 * hit it from the opposite direction with a guarded removeColumn().
 * down() therefore drops the table explicitly.
 *
 * DDL note: MySQL issues an implicit commit on `CREATE TABLE`, so this
 * migration is not atomic. If it fails after the table is created but before
 * the row is inserted, re-running it is safe — Phinx will not have recorded
 * the migration in `phinxlog`, and up() re-seeds the row separately from the
 * create.
 */
final class AddErVerificationSettings extends AbstractMigration
{
    private const TABLE = 'er_verification_settings';

    public function up(): void
    {
        if (!$this->hasTable(self::TABLE)) {
            $this->table(self::TABLE, ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'integer', [
                    'identity' => false,
                    'signed' => false,
                    'null' => false,
                    'comment' => 'Always 1 — this table holds a single settings row.',
                ])
                ->addColumn('enabled', 'boolean', [
                    'default' => 0,
                    'null' => false,
                    'comment' => 'Feature switch for the car verification system (0 = off).',
                ])
                ->create();
        }

        // Seeded separately from the create so a re-run after a part-applied
        // migration (see the DDL note above) still lands the row. INSERT
        // IGNORE leaves an existing row's `enabled` value alone — re-running
        // this migration must never silently switch the feature back off.
        $this->execute(
            'INSERT IGNORE INTO `' . self::TABLE . '` (`id`, `enabled`) VALUES (1, 0)'
        );
    }

    public function down(): void
    {
        $this->table(self::TABLE)->drop()->save();
    }
}
