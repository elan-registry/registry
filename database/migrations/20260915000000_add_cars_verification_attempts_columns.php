<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Issue #1884: schema foundation for verification-email resend attempt/cap
 * tracking.
 *
 * Adds two columns to `cars` (and their mirrors on `cars_hist`):
 *
 * - `verification_attempts`       — count of verification emails sent for
 *   the car's current unverified state, used to cap resends.
 * - `verification_attempts_since` — when that attempt count started
 *   accruing, so it can be reset/expired independently of `vericode_sent_at`
 *   (the most recent send timestamp).
 *
 * up() + down() are used instead of change() because this migration mixes
 * reversible DDL with trigger rebuilds that Phinx cannot auto-reverse. See
 * database/migrations/README.md — "Only fall back to explicit up() + down()".
 * This migration is modeled directly on
 * 20260907141816_add_car_bounce_state_columns.php, which established the
 * same pattern for the bounce-state columns.
 *
 * NOT ATOMIC: MySQL issues an implicit commit on every DDL statement, so the
 * ALTER TABLE and CREATE TRIGGER steps below cannot be wrapped in a
 * transaction. Every step is guarded (hasColumn(), DROP TRIGGER IF EXISTS),
 * so re-running the migration after a partial failure is safe and does no
 * duplicate work.
 */
final class AddCarsVerificationAttemptsColumns extends AbstractMigration
{
    public function up(): void
    {
        // --- 1. New columns on `cars` -------------------------------------
        $cars = $this->table('cars');

        if (!$cars->hasColumn('verification_attempts')) {
            $cars->addColumn('verification_attempts', 'integer', [
                'limit'   => MysqlAdapter::INT_SMALL,
                'signed'  => false,
                'null'    => false,
                'default' => 0,
            ])->update();
        }
        if (!$cars->hasColumn('verification_attempts_since')) {
            $cars->addColumn('verification_attempts_since', 'datetime', ['null' => true])->update();
        }

        // --- 2. Mirror the columns onto `cars_hist` -----------------------
        $hist = $this->table('cars_hist');

        if (!$hist->hasColumn('verification_attempts')) {
            $hist->addColumn('verification_attempts', 'integer', [
                'limit'   => MysqlAdapter::INT_SMALL,
                'signed'  => false,
                'null'    => false,
                'default' => 0,
            ])->update();
        }
        if (!$hist->hasColumn('verification_attempts_since')) {
            $hist->addColumn('verification_attempts_since', 'datetime', ['null' => true])->update();
        }

        // --- 3. Rebuild the audit triggers with the new columns -----------
        // MySQL has no CREATE TRIGGER IF NOT EXISTS and no partial ALTER for a
        // trigger body, so each trigger is dropped and recreated in full. The
        // bodies below are 20260907141816's with the two new columns appended
        // to the column list and VALUES tuple, symmetric on OLD.*/NEW.* for
        // both (no reason for asymmetry like chassis_override's).
        $this->createTriggers(
            "owner_last_updated, vericode_sent_at, email_bounced, email_bounced_address, email_suppressed, verification_attempts, verification_attempts_since",
            "NEW.owner_last_updated, NEW.vericode_sent_at, NEW.email_bounced, NEW.email_bounced_address, NEW.email_suppressed, NEW.verification_attempts, NEW.verification_attempts_since",
            "OLD.owner_last_updated, OLD.vericode_sent_at, OLD.email_bounced, OLD.email_bounced_address, OLD.email_suppressed, OLD.verification_attempts, OLD.verification_attempts_since"
        );
        $this->assertCarsTriggersPresent();
    }

    public function down(): void
    {
        // --- 1. Restore the pre-migration trigger bodies ------------------
        // Must happen before the `cars_hist`/`cars` columns are dropped: MySQL
        // refuses to DROP a column that a trigger body still references, and
        // restoring first means there is never a window where the installed
        // trigger bodies reference already-dropped columns.
        $this->createTriggers(
            "owner_last_updated, vericode_sent_at, email_bounced, email_bounced_address, email_suppressed",
            "NEW.owner_last_updated, NEW.vericode_sent_at, NEW.email_bounced, NEW.email_bounced_address, NEW.email_suppressed",
            "OLD.owner_last_updated, OLD.vericode_sent_at, OLD.email_bounced, OLD.email_bounced_address, OLD.email_suppressed"
        );
        $this->assertCarsTriggersPresent();

        // --- 2. Drop the cars_hist columns --------------------------------
        $hist = $this->table('cars_hist');
        foreach (['verification_attempts', 'verification_attempts_since'] as $column) {
            if ($hist->hasColumn($column)) {
                $hist->removeColumn($column)->update();
            }
        }

        // --- 3. Drop the cars columns -------------------------------------
        $cars = $this->table('cars');
        foreach (['verification_attempts', 'verification_attempts_since'] as $column) {
            if ($cars->hasColumn($column)) {
                $cars->removeColumn($column)->update();
            }
        }
    }

    /**
     * Verifies all three `cars` audit triggers exist after (re)creation.
     *
     * A CREATE TRIGGER can silently fail to leave a trigger installed if the
     * migration user's privileges are borderline (e.g. TRIGGER grant present
     * but log_bin_trust_function_creators still off and SUPER absent on some
     * managed hosts) — {@see enableTrustFunctionCreators()} deliberately
     * continues rather than aborting in that case. This check turns a
     * silently-missing trigger into a loud migration failure instead of an
     * unaudited `cars` table discovered later.
     *
     * @throws \RuntimeException If any of cars_insert, cars_update, cars_delete
     *                           is missing after (re)creation.
     */
    private function assertCarsTriggersPresent(): void
    {
        $expected = ['cars_insert', 'cars_update', 'cars_delete'];

        $rows = $this->fetchAll(
            "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS "
            . "WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'cars'"
        );
        $present = array_column($rows, 'TRIGGER_NAME');
        $missing = array_diff($expected, $present);

        if ($missing !== []) {
            throw new \RuntimeException(
                'Trigger(s) not present after (re)creation on `cars`: ' . implode(', ', $missing) . '. '
                . 'These triggers were dropped and not recreated — writes to `cars` are currently '
                . 'UNAUDITED (no cars_hist rows will be written). Fix the migration user\'s privileges '
                . '(grant TRIGGER; if binary logging is on, also set log_bin_trust_function_creators=1 '
                . 'or grant SUPER/SYSTEM_VARIABLES_ADMIN) and re-run `composer migrate`.'
            );
        }
    }

    /**
     * Drops and recreates the three `cars` audit triggers.
     *
     * The three arguments carry the verification columns: pass the column list
     * and the matching NEW / OLD value lists to create the post-migration
     * bodies, or 20260907141816's values to restore its trigger bodies.
     *
     * @param string $extraColumns   Trailing column names.
     * @param string $extraNewValues Matching NEW.* expressions.
     * @param string $extraOldValues Matching OLD.* expressions.
     */
    private function createTriggers(
        string $extraColumns,
        string $extraNewValues,
        string $extraOldValues
    ): void {
        $cols   = $extraColumns !== '' ? ', ' . $extraColumns : '';
        $newVal = $extraNewValues !== '' ? ', ' . $extraNewValues : '';
        $oldVal = $extraOldValues !== '' ? ', ' . $extraOldValues : '';

        $trustFunctionCreatorsSet = $this->enableTrustFunctionCreators();

        // A thrown CREATE TRIGGER must not leave log_bin_trust_function_creators
        // permanently relaxed server-wide — reset in finally so any exception
        // between enable and the end of trigger creation still restores it.
        try {
            $this->execute('DROP TRIGGER IF EXISTS `cars_insert`');
            $this->execute(
                "CREATE TRIGGER `cars_insert` AFTER INSERT ON `cars` FOR EACH ROW BEGIN
                     INSERT INTO cars_hist(
                         operation, car_id, ctime, mtime, model, series, variant,
                         year, type, chassis, chassis_override, color, engine, purchasedate, solddate, comments,
                         image, user_id, email, fname, lname, join_date, city, state, country,
                         lat, lon, website{$cols}
                     )
                     VALUES (
                         'INSERT', NEW.id, NEW.ctime, NEW.mtime, NEW.model,
                         NEW.series, NEW.variant, NEW.year, NEW.type, NEW.chassis, NEW.chassis_override,
                         NEW.color, NEW.engine, NEW.purchasedate, NEW.solddate, NEW.comments, NEW.image,
                         NEW.user_id, NEW.email, NEW.fname, NEW.lname, NEW.join_date, NEW.city,
                         NEW.state, NEW.country, NEW.lat, NEW.lon, NEW.website{$newVal}
                     );
                 END"
            );

            // The deliberate asymmetry — every value OLD.* except chassis_override,
            // which records NEW — is reproduced verbatim from 20260709000000.
            $this->execute('DROP TRIGGER IF EXISTS `cars_update`');
            $this->execute(
                "CREATE TRIGGER `cars_update` AFTER UPDATE ON `cars` FOR EACH ROW BEGIN
                     IF @disable_triggers IS NULL THEN
                         INSERT INTO cars_hist(
                             operation, car_id, ctime, mtime, model, series, variant,
                             year, type, chassis, chassis_override, color, engine, purchasedate, solddate, comments,
                             image, user_id, email, fname, lname, join_date, city, state, country,
                             lat, lon, website{$cols}
                         )
                         VALUES (
                             'UPDATE', OLD.id, OLD.ctime, OLD.mtime, OLD.model,
                             OLD.series, OLD.variant, OLD.year, OLD.type, OLD.chassis, NEW.chassis_override,
                             OLD.color, OLD.engine, OLD.purchasedate, OLD.solddate, OLD.comments, OLD.image,
                             OLD.user_id, OLD.email, OLD.fname, OLD.lname, OLD.join_date, OLD.city,
                             OLD.state, OLD.country, OLD.lat, OLD.lon, OLD.website{$oldVal}
                         );
                     END IF;
                 END"
            );

            $this->execute('DROP TRIGGER IF EXISTS `cars_delete`');
            $this->execute(
                "CREATE TRIGGER `cars_delete` AFTER DELETE ON `cars` FOR EACH ROW BEGIN
                     INSERT INTO cars_hist(
                         operation, car_id, ctime, mtime, model, series, variant,
                         year, type, chassis, chassis_override, color, engine, purchasedate, solddate, comments,
                         image, user_id, email, fname, lname, join_date, city, state, country,
                         lat, lon, website{$cols}
                     )
                     VALUES (
                         'DELETE', OLD.id, OLD.ctime, OLD.mtime, OLD.model,
                         OLD.series, OLD.variant, OLD.year, OLD.type, OLD.chassis, OLD.chassis_override,
                         OLD.color, OLD.engine, OLD.purchasedate, OLD.solddate, OLD.comments, OLD.image,
                         OLD.user_id, OLD.email, OLD.fname, OLD.lname, OLD.join_date, OLD.city,
                         OLD.state, OLD.country, OLD.lat, OLD.lon, OLD.website{$oldVal}
                     );
                 END"
            );
        } finally {
            $this->resetTrustFunctionCreators($trustFunctionCreatorsSet);
        }
    }

    /**
     * CREATE TRIGGER requires either SUPER privilege or log_bin_trust_function_creators=1
     * when binary logging is enabled. Attempt to set it globally; if the migration user
     * lacks SUPER/SYSTEM_VARIABLES_ADMIN, continue anyway — the variable may already be
     * set globally (common on managed hosting panels), otherwise the DBA must set it in
     * MySQL config (log_bin_trust_function_creators=1 in my.cnf).
     *
     * @return bool True if this call set the variable (and so should reset it afterward).
     */
    private function enableTrustFunctionCreators(): bool
    {
        try {
            $this->execute('SET GLOBAL log_bin_trust_function_creators = 1');
            return true;
        } catch (\RuntimeException $e) {
            if (isset($this->output)) {
                $this->output->writeln(
                    '<comment>Warning: Could not SET GLOBAL log_bin_trust_function_creators=1 '
                    . '— continuing. If CREATE TRIGGER fails below, set this variable in my.cnf.</comment>'
                );
            }
            return false;
        }
    }

    /**
     * Resets log_bin_trust_function_creators if this migration run set it — limits the
     * window of elevated trust to only the trigger creation steps.
     *
     * @throws \RuntimeException If the reset fails after we had relaxed the flag — this
     *                           leaves it relaxed server-wide, which must fail the deploy
     *                           visibly rather than warn and continue.
     */
    private function resetTrustFunctionCreators(bool $wasSet): void
    {
        if (!$wasSet) {
            return;
        }
        try {
            $this->execute('SET GLOBAL log_bin_trust_function_creators = 0');
        } catch (\RuntimeException $e) {
            if (isset($this->output)) {
                $this->output->writeln(
                    '<error>Could not reset log_bin_trust_function_creators=0: '
                    . $e->getMessage()
                    . ' — log_bin_trust_function_creators is left relaxed (=1) server-wide. '
                    . 'Reset it manually in MySQL or my.cnf.</error>'
                );
            }
            throw $e;
        }
    }
}
