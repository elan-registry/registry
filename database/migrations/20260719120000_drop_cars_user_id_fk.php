<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DropCarsUserIdFk extends AbstractMigration
{
    // The fk_cars_user_id foreign key (cars.user_id → users.id ON DELETE SET NULL)
    // was present on some environments but absent from the canonical schema. When
    // present, MySQL's FK cascade fires during DELETE FROM users (inside deleteUsers())
    // and sets cars.user_id = NULL before the after_user_deletion.php hook runs —
    // so the hook finds 0 cars for the deleted user and skips the noowner reassignment.
    //
    // This migration drops the FK constraint if it exists. The accompanying index
    // (also named fk_cars_user_id) is retained for query performance.
    //
    // up() + down() are used instead of change() because DROP FOREIGN KEY is not
    // auto-reversible by Phinx. down() intentionally does NOT restore ON DELETE SET NULL
    // since that behaviour was the bug.
    //
    // DDL note: ALTER TABLE issues an implicit commit in MySQL. This migration
    // cannot be wrapped in a transaction.

    public function up(): void
    {
        $fkExists = $this->fetchRow(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = 'fk_cars_user_id'"
        );

        if ($fkExists) {
            $this->execute("ALTER TABLE `cars` DROP FOREIGN KEY `fk_cars_user_id`");
        }
    }

    public function down(): void
    {
        // Intentionally empty. ON DELETE SET NULL caused the user deletion hook to
        // miss cars (the cascade fired before the hook ran). Re-adding the FK on
        // rollback would restore the bug. The index (fk_cars_user_id on user_id)
        // was never dropped and remains in place.
    }
}
