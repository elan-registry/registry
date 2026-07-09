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
    // (changeColumn) is not auto-reversible by Phinx — and should not be
    // reversed anyway: '0000-00-00 00:00:00' is invalid in MySQL 8 strict mode.
    public function up(): void
    {
        // car_transfer_requests.expires_at was created with DEFAULT '0000-00-00 00:00:00',
        // a MySQL 5.x workaround. MySQL 8 strict mode rejects this default on any
        // ALTER TABLE that touches the table. Fix it before adding the FK.
        $this->table('car_transfer_requests')
             ->changeColumn('expires_at', 'timestamp', ['null' => true, 'default' => null])
             ->update();

        $this->table('cars')
             ->addForeignKey('user_id', 'users', 'id', [
                 'delete'     => 'SET_NULL',
                 'update'     => 'NO_ACTION',
                 'constraint' => 'fk_cars_user_id',
             ])
             ->update();

        $this->table('car_transfer_requests')
             ->addForeignKey('existing_car_id', 'cars', 'id', [
                 'delete'     => 'CASCADE',
                 'update'     => 'NO_ACTION',
                 'constraint' => 'fk_transfer_existing_car',
             ])
             ->update();
    }

    public function down(): void
    {
        $this->table('car_transfer_requests')
             ->dropForeignKey('existing_car_id')
             ->update();

        $this->table('cars')
             ->dropForeignKey('user_id')
             ->update();

        // expires_at is intentionally left as NULL DEFAULT NULL — zero-date
        // defaults are invalid in MySQL 8 strict mode and should not be restored.
    }
}
