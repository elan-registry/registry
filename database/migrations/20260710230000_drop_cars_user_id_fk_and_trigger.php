<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DropCarsUserIdFkAndTrigger extends AbstractMigration
{
    // The cars.user_id → users.id ON DELETE SET NULL FK (added in #693) created a
    // race condition: the FK fires immediately on DELETE FROM users, NULLing
    // cars.user_id before after_user_deletion.php can reassign ownership to noowner.
    // The application-level hook (reassignCarsByUser) already handles ownership
    // correctly for all user deletions via deleteUsers() — the FK adds complexity
    // without benefit and introduces silent-failure risks in every code path that
    // reads cars.user_id.
    //
    // The trigger (before_user_delete_reassign_cars) was a short-lived attempted
    // fix that was also reverted — it is dropped here defensively in case it was
    // applied to an environment before the rollback landed.
    //
    // up()   — drop trigger (defensive) + drop FK
    // down() — restore FK only (trigger is not restored; it was the wrong approach)
    //
    // up() + down() are used instead of change() because DROP/ADD FOREIGN KEY
    // is not auto-reversible.
    public function up(): void
    {
        // Drop the trigger defensively — may not exist if migration 20260710220000
        // was never applied or was already rolled back.
        $this->execute("DROP TRIGGER IF EXISTS `before_user_delete_reassign_cars`");

        // Drop the FK. Use IF EXISTS via information_schema check because Phinx's
        // dropForeignKey() throws if the constraint does not exist.
        $result = $this->fetchAll(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cars'
               AND CONSTRAINT_NAME = 'fk_cars_user_id'"
        );
        if ((int) ($result[0]['cnt'] ?? 0) > 0) {
            $this->table('cars')->dropForeignKey('user_id')->save();
        }
    }

    public function down(): void
    {
        // Restore the FK on rollback only if it is absent.
        $result = $this->fetchAll(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = 'cars'
               AND CONSTRAINT_NAME = 'fk_cars_user_id'"
        );
        if ((int) ($result[0]['cnt'] ?? 0) === 0) {
            $this->table('cars')
                ->addForeignKey('user_id', 'users', 'id', [
                    'delete'     => 'SET_NULL',
                    'update'     => 'NO_ACTION',
                    'constraint' => 'fk_cars_user_id',
                ])
                ->save();
        }
    }
}
