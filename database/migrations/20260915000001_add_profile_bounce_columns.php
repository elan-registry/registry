<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Issue #1884: owner-level record of an admin's "Mark Bounced" action.
 *
 * Adds `email_bounced` and `email_bounced_address` to `profiles` — matching
 * `cars.email_bounced` (TINYINT(1) NOT NULL DEFAULT 0) and
 * `cars.email_bounced_address` (VARCHAR(155) NULL) exactly, both added for
 * the per-car Brevo bounce tracking in
 * 20260907141816_add_car_bounce_state_columns.php.
 *
 * WHY A SECOND COLUMN, GIVEN cars.email_bounced ALREADY EXISTS
 *
 * The two answer different questions and neither substitutes for the other:
 *
 * - `cars.email_bounced` / `cars.email_bounced_address` are per-car state and
 *   the fan-out *target* of a bounce. They are also written by the (future)
 *   Brevo webhook bounce path, so a set flag there does not distinguish "an
 *   admin marked this owner bounced" from "Brevo reported this address as
 *   bounced".
 * - `profiles.email_bounced` / `profiles.email_bounced_address` is the
 *   owner-level RECORD of an admin's Mark Bounced action (issue #1884). It is
 *   written by a new `CarVerificationManager::setBouncedForOwner()` method,
 *   which fans the decision out to every car the owner has — mirroring how
 *   `setSuppressedForOwner()` (see 20260914093000) fans out
 *   `profiles.email_suppressed`.
 *
 * NO HISTORY MIRROR: unlike `cars`, the `profiles` table has no `*_hist`
 * audit table and no audit triggers in this schema, so there is no mirror
 * column to add and no trigger body to rebuild. That is why this migration
 * is a plain pair of guarded column operations and, unlike 20260907141816,
 * needs none of its trigger machinery.
 *
 * up() + down() rather than change(): consistent with the other schema
 * migrations in this directory. Both steps are guarded with hasColumn(), so
 * re-running after a partial failure is safe and does no duplicate work.
 */
final class AddProfileBounceColumns extends AbstractMigration
{
    public function up(): void
    {
        $profiles = $this->table('profiles');

        if (!$profiles->hasColumn('email_bounced')) {
            // 'boolean' is Phinx's TINYINT(1) — the same type
            // 20260907141816 used for cars.email_bounced.
            $profiles->addColumn('email_bounced', 'boolean', [
                'null'    => false,
                'default' => 0,
            ])->update();
        }

        if (!$profiles->hasColumn('email_bounced_address')) {
            // varchar(155) matches cars.email_bounced_address's own length.
            $profiles->addColumn('email_bounced_address', 'string', [
                'limit' => 155,
                'null'  => true,
            ])->update();
        }
    }

    public function down(): void
    {
        $profiles = $this->table('profiles');

        if ($profiles->hasColumn('email_bounced_address')) {
            $profiles->removeColumn('email_bounced_address')->update();
        }

        if ($profiles->hasColumn('email_bounced')) {
            $profiles->removeColumn('email_bounced')->update();
        }
    }
}
