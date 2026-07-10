<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddForeignKeyConstraints extends AbstractMigration
{
    // DDL (ALTER TABLE ... ADD CONSTRAINT) causes an implicit commit in MySQL
    // and cannot be wrapped in a transaction. These constraints are applied
    // directly and reversed via Phinx's dropForeignKey on rollback.
    //
    // up() + down() are used instead of change() because the expires_at fix
    // is not auto-reversible by Phinx — and should not be reversed anyway:
    // '0000-00-00 00:00:00' is invalid in MySQL 8 strict mode.
    //
    // This migration was already applied to dev and test before #1272 was filed.
    // The column type fixes, 13 indexes, and 2 additional FK additions below
    // were absent on prod, which had never had this migration applied at that
    // point. Phinx runs up() on any environment where the migration is pending.
    public function up(): void
    {
        // Fix column types on car_transfer_requests before adding FK constraints.
        //
        // On prod: id and existing_car_id are signed INT; MySQL 8.0.16+ enforces
        // signedness matching on FK columns, so existing_car_id must be UNSIGNED
        // before fk_transfer_existing_car (existing_car_id → cars.id UNSIGNED) can
        // be added. id is promoted to UNSIGNED to match dev/test and 1-schema.sql.
        //
        // request_date is nullable on prod — NOT NULL aligns with dev/test intent.
        //
        // expires_at had DEFAULT '0000-00-00 00:00:00' on prod (MySQL 5.x workaround).
        // MySQL 8 strict mode rejects that default on any ALTER TABLE touching the
        // table, so it is fixed in this same statement before any index/FK work.
        $this->execute(
            "ALTER TABLE `car_transfer_requests`
             MODIFY COLUMN `id`               int UNSIGNED NOT NULL AUTO_INCREMENT,
             MODIFY COLUMN `existing_car_id`  int UNSIGNED NOT NULL,
             MODIFY COLUMN `request_date`     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
             MODIFY COLUMN `expires_at`       timestamp NULL DEFAULT NULL"
        );

        // Add all 13 non-primary indexes present on dev/test but absent on prod.
        // Indexes are added before FKs so MySQL can use them for FK enforcement.
        // The UNIQUE KEY on security_token is the highest operational risk —
        // without it, duplicate transfer tokens can exist on prod.
        $this->table('car_transfer_requests')
             ->addIndex('security_token',                        ['unique' => true, 'name' => 'security_token'])
             ->addIndex('existing_car_id',                       ['name' => 'existing_car_id'])
             ->addIndex('requested_by_user_id',                  ['name' => 'requested_by_user_id'])
             ->addIndex('status',                                ['name' => 'status'])
             ->addIndex('request_date',                          ['name' => 'request_date'])
             ->addIndex('expires_at',                            ['name' => 'expires_at'])
             ->addIndex('submitted_chassis',                     ['name' => 'submitted_chassis'])
             ->addIndex('submitted_type',                        ['name' => 'submitted_type'])
             ->addIndex('created_by',                            ['name' => 'fk_transfer_created_by'])
             ->addIndex(['existing_car_id', 'status'],           ['name' => 'idx_car_pending_transfers'])
             ->addIndex(['requested_by_user_id', 'status'],      ['name' => 'idx_user_transfer_requests'])
             ->addIndex(['status', 'expires_at'],                ['name' => 'idx_expired_requests'])
             ->addIndex(['submitted_type', 'submitted_chassis'],  ['name' => 'idx_submitted_chassis_type'])
             ->update();

        $this->table('cars')
             ->addForeignKey('user_id', 'users', 'id', [
                 'delete'     => 'SET_NULL',
                 'update'     => 'NO_ACTION',
                 'constraint' => 'fk_cars_user_id',
             ])
             ->update();

        // Guard: fk_transfer_created_by and fk_transfer_requested_by CASCADE on
        // DELETE. If any row references a non-existent user MySQL will reject the
        // FK addition with an opaque error. Fail early with a clear message so
        // the operator knows to run the pre-deployment orphan check from the
        // v2.26.2 release notes before migrating.
        $orphans = $this->fetchRow(
            "SELECT COUNT(*) AS n FROM car_transfer_requests
              WHERE created_by NOT IN (SELECT id FROM users)
                 OR requested_by_user_id NOT IN (SELECT id FROM users)"
        );
        if ((int) $orphans['n'] > 0) {
            throw new \RuntimeException(
                "Cannot add FK constraints: {$orphans['n']} orphaned row(s) in car_transfer_requests. " .
                "Run pre-deployment orphan check from the v2.26.2 release notes before migrating."
            );
        }

        // Three FKs on car_transfer_requests — fk_transfer_created_by and
        // fk_transfer_requested_by were in 1-schema.sql but missing from the
        // original migration (#1272). Added here so prod matches dev/test.
        $this->table('car_transfer_requests')
             ->addForeignKey('existing_car_id', 'cars', 'id', [
                 'delete'     => 'CASCADE',
                 'update'     => 'NO_ACTION',
                 'constraint' => 'fk_transfer_existing_car',
             ])
             ->addForeignKey('created_by', 'users', 'id', [
                 'delete'     => 'CASCADE',
                 'update'     => 'NO_ACTION',
                 'constraint' => 'fk_transfer_created_by',
             ])
             ->addForeignKey('requested_by_user_id', 'users', 'id', [
                 'delete'     => 'CASCADE',
                 'update'     => 'NO_ACTION',
                 'constraint' => 'fk_transfer_requested_by',
             ])
             ->update();
    }

    public function down(): void
    {
        // Drop FKs before indexes — MySQL rejects dropping an index that
        // supports an active FK constraint.
        $this->table('car_transfer_requests')
             ->dropForeignKey('existing_car_id')
             ->dropForeignKey('created_by')
             ->dropForeignKey('requested_by_user_id')
             ->update();

        $this->table('cars')
             ->dropForeignKey('user_id')
             ->update();

        // Remove all 13 indexes added in up().
        $this->table('car_transfer_requests')
             ->removeIndexByName('security_token')
             ->removeIndexByName('existing_car_id')
             ->removeIndexByName('requested_by_user_id')
             ->removeIndexByName('status')
             ->removeIndexByName('request_date')
             ->removeIndexByName('expires_at')
             ->removeIndexByName('submitted_chassis')
             ->removeIndexByName('submitted_type')
             ->removeIndexByName('fk_transfer_created_by')
             ->removeIndexByName('idx_car_pending_transfers')
             ->removeIndexByName('idx_user_transfer_requests')
             ->removeIndexByName('idx_expired_requests')
             ->removeIndexByName('idx_submitted_chassis_type')
             ->update();

        // Revert column types to pre-migration (prod) state.
        // expires_at is intentionally left as NULL DEFAULT NULL — zero-date
        // defaults are invalid in MySQL 8 strict mode and should not be restored.
        $this->execute(
            "ALTER TABLE `car_transfer_requests`
             MODIFY COLUMN `id`              int NOT NULL AUTO_INCREMENT,
             MODIFY COLUMN `existing_car_id` int NOT NULL,
             MODIFY COLUMN `request_date`    timestamp NULL DEFAULT CURRENT_TIMESTAMP"
        );
    }
}
