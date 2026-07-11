<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DropTransferRequestUserFks extends AbstractMigration
{
    // Migration 20260709202522 added fk_transfer_requested_by and fk_transfer_created_by
    // with ON DELETE CASCADE. MySQL InnoDB fires CASCADE synchronously as part of the
    // DELETE FROM users statement. deleteUsers() (users/helpers/users.php) removes the
    // users row BEFORE after_user_deletion.php runs, so by the time the hook executes,
    // CASCADE has already silently deleted any car_transfer_requests rows where the
    // deleted user is requested_by_user_id or created_by.
    //
    // Consequence: the UPDATE in after_user_deletion.php that expires pending transfer
    // requests matches zero rows — the GDPR audit trail is lost and the car owner never
    // sees a clean "request cancelled" entry in the transfer history.
    //
    // This is the same race condition fixed for cars.user_id in migration 20260710230000.
    // The fix is identical: drop the cascading FKs and rely solely on the application-
    // level hook (after_user_deletion.php) to handle cleanup for all user-ownership
    // transitions. The hook already runs in a transaction and throws on error, so
    // atomicity is preserved without the FK.
    //
    // fk_transfer_existing_car (existing_car_id → cars.id) is NOT dropped — it does not
    // reference users and carries no race risk with the user-deletion hook.
    //
    // up()   — drop fk_transfer_requested_by and fk_transfer_created_by
    // down() — restore both FKs (with CASCADE — matching original migration behaviour)
    //
    // up() + down() are used instead of change() because DROP/ADD FOREIGN KEY
    // is not auto-reversible.

    public function up(): void
    {
        // $constraint names are hardcoded literals — never user input.
        // fetchAll() uses PDO::query() (no prepared statements), so inline them directly.
        foreach ([
            'fk_transfer_requested_by' => 'requested_by_user_id',
            'fk_transfer_created_by'   => 'created_by',
        ] as $constraint => $column) {
            $result = $this->fetchAll(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'car_transfer_requests'
                   AND CONSTRAINT_NAME = '{$constraint}'"
            );
            if ((int) ($result[0]['cnt'] ?? 0) > 0) {
                $this->table('car_transfer_requests')->dropForeignKey($column)->save();
            }
        }
    }

    public function down(): void
    {
        // WARNING: restoring these FKs re-introduces the race condition fixed by up().
        // CASCADE fires before after_user_deletion.php runs, silently deleting transfer
        // request rows and destroying the GDPR audit trail.
        // Only roll back during development/testing — never on a running system.

        $result = $this->fetchAll(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'car_transfer_requests'
               AND CONSTRAINT_NAME = 'fk_transfer_requested_by'"
        );
        if ((int) ($result[0]['cnt'] ?? 0) === 0) {
            $this->table('car_transfer_requests')
                ->addForeignKey('requested_by_user_id', 'users', 'id', [
                    'delete'     => 'CASCADE',
                    'update'     => 'NO_ACTION',
                    'constraint' => 'fk_transfer_requested_by',
                ])
                ->save();
        }

        $result = $this->fetchAll(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'car_transfer_requests'
               AND CONSTRAINT_NAME = 'fk_transfer_created_by'"
        );
        if ((int) ($result[0]['cnt'] ?? 0) === 0) {
            $this->table('car_transfer_requests')
                ->addForeignKey('created_by', 'users', 'id', [
                    'delete'     => 'CASCADE',
                    'update'     => 'NO_ACTION',
                    'constraint' => 'fk_transfer_created_by',
                ])
                ->save();
        }
    }
}
