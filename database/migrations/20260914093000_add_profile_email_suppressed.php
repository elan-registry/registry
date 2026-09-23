<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Issue #1883: owner-level verification-email opt-out flag.
 *
 * Adds `email_suppressed` to `profiles` — a TINYINT(1) NOT NULL DEFAULT 0
 * matching `cars.email_suppressed`'s type exactly (added for the per-car
 * Brevo bounce/suppression tracking in
 * 20260907141816_add_car_bounce_state_columns.php).
 *
 * WHY A SECOND COLUMN, GIVEN `cars.email_suppressed` ALREADY EXISTS
 *
 * The two answer different questions and neither substitutes for the other:
 *
 * - `cars.email_suppressed` is per-car state and the fan-out *target* of the
 *   opt-out. It is also written by the Brevo webhook and suppression-sync
 *   paths, so a set flag there does not distinguish "the owner asked us to
 *   stop" from "Brevo reported this address as suppressed".
 * - `profiles.email_suppressed` is the owner-level RECORD of the opt-out
 *   decision itself. As of this migration it is written by
 *   `CarVerificationManager::setSuppressedForOwner()` but not yet read by
 *   any eligibility query — `findVerificationEligible()` still gates solely
 *   on the per-car `cars.email_suppressed` flag, which the same fan-out
 *   keeps in sync with this one. The column does NOT yet independently
 *   survive the owner's car list changing (a car un-sold, merged, or added
 *   after the opt-out could become eligible again despite this flag being
 *   set) — closing that gap means wiring this column into eligibility,
 *   which is not part of #1883's scope and is tracked as a follow-up.
 *
 * This is the original design in
 * docs/plans/car-owner-verification/car-owner-verification-frd.md, whose
 * acceptance criteria state the opt-out "sets profiles.email_suppressed = 1
 * on the owner and syncs email_suppressed = 1 to every car they have".
 *
 * NOTE FOR A FOLLOW-UP: that FRD carries a note saying this column was never
 * built. As of this migration that note is stale and needs reconciling — it
 * is deliberately not edited here.
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
final class AddProfileEmailSuppressed extends AbstractMigration
{
    public function up(): void
    {
        $profiles = $this->table('profiles');

        if (!$profiles->hasColumn('email_suppressed')) {
            // 'boolean' is Phinx's TINYINT(1) — the same type
            // 20260907141816 used for cars.email_suppressed.
            $profiles->addColumn('email_suppressed', 'boolean', [
                'null'    => false,
                'default' => 0,
            ])->update();
        }
    }

    public function down(): void
    {
        $profiles = $this->table('profiles');

        if ($profiles->hasColumn('email_suppressed')) {
            $profiles->removeColumn('email_suppressed')->update();
        }
    }
}
