<?php

declare(strict_types=1);

use ElanRegistry\Migration\DeliverabilityMigrationQueries;
use Phinx\Migration\AbstractMigration;

/**
 * Issue #2101: the owner profile becomes the system of record for email
 * deliverability.
 *
 * Before this migration, `cars` had `email_bounced`, `email_bounced_address`
 * and `email_suppressed`, and `profiles` had `email_suppressed`,
 * `email_bounced` and `email_bounced_address`. The two tables could disagree,
 * and a car added after a flag did not get it (#2319). The address belongs to
 * the owner, so the flag does too.
 *
 * up() runs three phases. MySQL commits each DDL statement implicitly, so no
 * phase is atomic with another. Each step is guarded, and a failed run can run
 * again:
 *
 * 1. DDL, guarded. Stop when two `profiles` rows share a `user_id`. Add
 *    UNIQUE (user_id). Rename `profiles.email_suppressed` to `email_opted_out`.
 *    Add the new columns. Backfill the dates and the current address only
 *    where they are NULL, so a re-run keeps a value that a hook wrote. Insert
 *    a `profiles` row for each car owner with none.
 * 2. Data, in one transaction, only while `cars.email_bounced` exists. Copy
 *    the bounces on the current address, restore car-only opt-outs, then copy
 *    the blocks. {@see DeliverabilityMigrationQueries} decides every write.
 * 3. DDL, guarded. Rebuild the `cars` audit triggers without the three
 *    columns, then drop the columns from `cars` and `cars_hist`. The triggers
 *    come first. MySQL 8 allows the drop, but every later INSERT, UPDATE or
 *    DELETE on `cars` then fails with error 1054 until the triggers change.
 *
 * A re-run after a failure in phase 3 runs phase 2 again. Phase 2 writes only
 * where the profile flag is 0 or set for another address, so a re-run does not
 * change a flag that the first run set. An admin must not clear a flag
 * between the failure and the re-run.
 *
 * Run with the Brevo webhook and the cron jobs off. Output names user IDs and
 * car IDs only, never an address.
 *
 * down() puts the car columns back and copies the profile flags back to
 * every car of the owner. It loses the data listed in the issue (cars_hist
 * values, block cause, dates, dropped old-address flags). The database backup
 * is the only full rollback.
 *
 * The older migrations that name the car columns are not edited. A fresh
 * migrate runs them in order, and this down() restores the columns for a
 * multi-step rollback.
 */
final class MoveDeliverabilityFlagsToProfiles extends AbstractMigration
{
    private const UNIQUE_USER_ID_INDEX = 'uniq_profiles_user_id';

    /** The three car flag columns that this migration moves, in their original order. */
    private const CAR_FLAG_COLUMNS = ['email_bounced', 'email_bounced_address', 'email_suppressed'];

    /**
     * The new `profiles` columns. `email_opted_out` is a rename and
     * `email_bounced`/`email_bounced_address` exist already, so they are not here.
     *
     * @var array<string, array{0: string, 1: array<string, mixed>}>
     */
    private const NEW_PROFILE_COLUMNS = [
        'email_opted_out_at'      => ['datetime', ['null' => true, 'default' => null]],
        'email_blocked'           => ['boolean', ['null' => false, 'default' => 0]],
        'email_blocked_address'   => ['string', ['limit' => 155, 'null' => true, 'default' => null]],
        'email_blocked_at'        => ['datetime', ['null' => true, 'default' => null]],
        'email_blocked_cause'     => ['string', ['limit' => 16, 'null' => true, 'default' => null]],
        'email_bounced_at'        => ['datetime', ['null' => true, 'default' => null]],
        'email_bounce_cleared_at' => ['datetime', ['null' => true, 'default' => null]],
        'email_address_since'     => ['datetime', ['null' => true, 'default' => null]],
        'email_address_seen'      => ['string', ['limit' => 155, 'null' => true, 'default' => null]],
    ];

    /** The trigger columns after this migration: 20260915000000's list without the three flags. */
    private const TRIGGER_COLUMNS_AFTER = ['owner_last_updated', 'vericode_sent_at', 'verification_attempts', 'verification_attempts_since'];

    /** The trigger columns before this migration, exactly as 20260915000000 left them. */
    private const TRIGGER_COLUMNS_BEFORE = [
        'owner_last_updated', 'vericode_sent_at', 'email_bounced', 'email_bounced_address',
        'email_suppressed', 'verification_attempts', 'verification_attempts_since',
    ];

    public function up(): void
    {
        if ($this->isDryRun()) {
            return;
        }

        $this->phaseOneSchemaAndBackfill();

        if ($this->table('cars')->hasColumn('email_bounced')) {
            $this->phaseTwoCopyFlags();
        } else {
            $this->say('Phase 2 skipped: cars.email_bounced does not exist, so the flags were copied before.');
        }

        $this->phaseThreeDropCarColumns();
    }

    public function down(): void
    {
        if ($this->isDryRun()) {
            return;
        }

        // The copy-back below must not write cars_hist rows: cars_update
        // skips its insert while @disable_triggers is set.
        $this->execute('SET @disable_triggers = 1');
        try {
            $this->addCarFlagColumns('cars_hist');
            $this->addCarFlagColumns('cars');
            $this->copyFlagsBackToCars();

            $this->createTriggers(self::TRIGGER_COLUMNS_BEFORE);
            $this->assertCarsTriggersPresent();
        } finally {
            // NULL, not 0: the trigger tests IS NULL.
            $this->execute('SET @disable_triggers = NULL');
        }

        $profiles = $this->table('profiles');
        if ($profiles->hasColumn('email_opted_out') && !$profiles->hasColumn('email_suppressed')) {
            $profiles->renameColumn('email_opted_out', 'email_suppressed')->update();
        }

        $profiles = $this->table('profiles');
        foreach (array_keys(self::NEW_PROFILE_COLUMNS) as $column) {
            if ($profiles->hasColumn($column)) {
                $profiles->removeColumn($column);
            }
        }
        if ($profiles->hasIndexByName(self::UNIQUE_USER_ID_INDEX)) {
            $profiles->removeIndexByName(self::UNIQUE_USER_ID_INDEX);
        }
        $profiles->update();
    }

    /**
     * Phase 1: the profile schema, the NULL-guarded backfills and the missing rows.
     *
     * @throws \RuntimeException If two profiles rows share a user_id. Nothing is changed then.
     */
    private function phaseOneSchemaAndBackfill(): void
    {
        $profiles = $this->table('profiles');

        // UNIQUE (user_id) is the guard: when it exists, no duplicate can.
        if (!$profiles->hasIndexByName(self::UNIQUE_USER_ID_INDEX)) {
            $duplicates = $this->queries()->duplicateProfileUserIds();
            if ($duplicates !== []) {
                throw new \RuntimeException(
                    'MoveDeliverabilityFlagsToProfiles: profiles has more than one row for user_id '
                    . implode(', ', $duplicates) . '. Nothing was changed. Merge each user\'s rows into one, '
                    . 'then run the migration again.'
                );
            }
            $profiles->addIndex(['user_id'], ['unique' => true, 'name' => self::UNIQUE_USER_ID_INDEX])->update();
        }

        $profiles = $this->table('profiles');
        if ($profiles->hasColumn('email_suppressed') && !$profiles->hasColumn('email_opted_out')) {
            $profiles->renameColumn('email_suppressed', 'email_opted_out')->update();
        }

        $profiles = $this->table('profiles');
        $previous = 'email_bounced_address';
        foreach (self::NEW_PROFILE_COLUMNS as $column => [$type, $options]) {
            if (!$profiles->hasColumn($column)) {
                $profiles->addColumn($column, $type, $options + ['after' => $previous]);
            }
            $previous = $column;
        }
        $profiles->update();

        // The DDL above committed. These writes are many single-row updates,
        // so one transaction makes them fast and all-or-nothing.
        $queries = $this->queries();
        $this->execute('START TRANSACTION');
        try {
            $this->backfillAddressSince();

            $optedOutAt = $queries->optedOutAtBackfill();
            foreach ($optedOutAt as $userId => $date) {
                $this->execute(
                    'UPDATE profiles SET email_opted_out_at = ?
                      WHERE user_id = ? AND email_opted_out = 1 AND email_opted_out_at IS NULL',
                    [$date, $userId]
                );
            }

            $bouncedAt = $queries->bouncedAtBackfill();
            foreach ($bouncedAt as $userId => $date) {
                $this->execute(
                    'UPDATE profiles SET email_bounced_at = ?
                      WHERE user_id = ? AND email_bounced = 1 AND email_bounced_at IS NULL',
                    [$date, $userId]
                );
            }

            // bio, city, state and country are NOT NULL with no default, and
            // Phinx runs in strict sql_mode, so each gets ''.
            $inserts = $queries->profilesToInsert();
            foreach ($inserts as $row) {
                $this->execute(
                    "INSERT INTO profiles (user_id, bio, city, state, country, email_address_since, email_address_seen)
                     VALUES (?, '', '', '', '', ?, ?)",
                    [$row['user_id'], $row['join_date'], $row['address']]
                );
            }

            $this->execute('COMMIT');
        } catch (\Throwable $e) {
            $this->execute('ROLLBACK');
            throw $e;
        }

        $this->say(sprintf('Phase 1: email_opted_out_at backfilled for %d profile(s).', count($optedOutAt)));
        $this->say(sprintf('Phase 1: email_bounced_at backfilled for %d profile(s).', count($bouncedAt)));
        $this->say(sprintf(
            'Phase 1: inserted %d profiles row(s) for user ID(s): %s',
            count($inserts),
            self::idList(array_column($inserts, 'user_id'))
        ));
    }

    /**
     * Set `email_address_since` and `email_address_seen` where the profile has none.
     *
     * The WHERE keeps a value that a hook wrote after an earlier run. The
     * address goes through the PHP matching rule, not SQL LOWER()/TRIM().
     */
    private function backfillAddressSince(): void
    {
        $rows = $this->fetchAll(
            'SELECT p.id, u.join_date, u.email
               FROM profiles p
               JOIN users u ON u.id = p.user_id
              WHERE p.email_address_since IS NULL'
        );

        foreach ($rows as $row) {
            $this->execute(
                'UPDATE profiles SET email_address_since = ?, email_address_seen = ?
                  WHERE id = ? AND email_address_since IS NULL',
                [
                    $row['join_date'],
                    DeliverabilityMigrationQueries::normalizeAddress($row['email'] === null ? null : (string) $row['email']),
                    (int) $row['id'],
                ]
            );
        }
    }

    /**
     * Phase 2: copy the car flags to the profiles, in one transaction.
     *
     * All decisions come from one read before the first write. The opt-out
     * restore is applied before the block copy, and blockCopies() already
     * counts the restored opt-outs.
     */
    private function phaseTwoCopyFlags(): void
    {
        $queries = $this->queries();
        $bounceCopies = $queries->bounceCopies();
        $droppedBounces = $queries->droppedBounces();
        $restores = $queries->optOutRestores();
        $blockCopies = $queries->blockCopies();
        $droppedSuppressions = $queries->droppedSuppressions();

        $this->execute('START TRANSACTION');
        try {
            foreach ($bounceCopies as $copy) {
                $this->execute(
                    'UPDATE profiles SET email_bounced = 1, email_bounced_address = ?, email_bounced_at = ?
                      WHERE user_id = ?',
                    [$copy['address'], $copy['bounced_at'], $copy['user_id']]
                );
            }

            foreach ($restores as $restore) {
                $this->execute(
                    'UPDATE profiles SET email_opted_out = 1, email_opted_out_at = ?
                      WHERE user_id = ? AND email_opted_out = 0',
                    [$restore['opted_out_at'], $restore['user_id']]
                );
            }

            foreach ($blockCopies as $copy) {
                $this->execute(
                    'UPDATE profiles
                        SET email_blocked = 1, email_blocked_address = ?, email_blocked_at = ?, email_blocked_cause = ?
                      WHERE user_id = ?',
                    [$copy['address'], $copy['blocked_at'], $copy['cause'], $copy['user_id']]
                );
            }

            $this->execute('COMMIT');
        } catch (\Throwable $e) {
            $this->execute('ROLLBACK');
            throw $e;
        }

        $this->say('Phase 2: bounce copied for user ID(s): ' . self::idList(array_column($bounceCopies, 'user_id')));
        $this->say('Phase 2: old-address bounce dropped for ' . self::pairList($droppedBounces));
        $this->say('Phase 2: opt-out restored for user ID(s): ' . self::idList(array_column($restores, 'user_id')));
        $this->say('Phase 2: block copied for user ID(s): ' . self::idList(array_column($blockCopies, 'user_id')));
        $this->say('Phase 2: block with no known cause for user ID(s): ' . self::idList($queries->unknownCauseBlocks()));
        $this->say('Phase 2: old-address suppression dropped for ' . self::pairList($droppedSuppressions));
    }

    /**
     * Phase 3: rebuild the triggers without the car flags, then drop the columns.
     */
    private function phaseThreeDropCarColumns(): void
    {
        $this->createTriggers(self::TRIGGER_COLUMNS_AFTER);
        $this->assertCarsTriggersPresent();

        foreach (['cars', 'cars_hist'] as $tableName) {
            $table = $this->table($tableName);
            $changed = false;
            foreach (self::CAR_FLAG_COLUMNS as $column) {
                if ($table->hasColumn($column)) {
                    $table->removeColumn($column);
                    $changed = true;
                }
            }
            if ($changed) {
                $table->update();
            }
        }
    }

    /**
     * down(): add the three flag columns back with their pre-migration
     * definitions and positions (after `vericode_sent_at`).
     */
    private function addCarFlagColumns(string $tableName): void
    {
        $table = $this->table($tableName);
        $definitions = [
            'email_bounced'         => ['boolean', ['null' => false, 'default' => 0, 'after' => 'vericode_sent_at']],
            'email_bounced_address' => ['string', ['limit' => 155, 'null' => true, 'after' => 'email_bounced']],
            'email_suppressed'      => ['boolean', ['null' => false, 'default' => 0, 'after' => 'email_bounced_address']],
        ];
        $changed = false;
        foreach ($definitions as $column => [$type, $options]) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, $type, $options);
                $changed = true;
            }
        }
        if ($changed) {
            $table->update();
        }
    }

    /**
     * down(): copy the profile flags back to every car of the owner.
     *
     * `mtime = mtime` stops `ON UPDATE CURRENT_TIMESTAMP` from changing
     * `cars.mtime`. The caller has set @disable_triggers, so no cars_hist row
     * is written.
     */
    private function copyFlagsBackToCars(): void
    {
        $profiles = $this->table('profiles');
        $optedOut = $profiles->hasColumn('email_opted_out') ? 'p.email_opted_out = 1' : 'p.email_suppressed = 1';
        $blocked = $profiles->hasColumn('email_blocked') ? 'p.email_blocked = 1 OR ' : '';

        $this->execute('START TRANSACTION');
        try {
            $this->execute(
                "UPDATE cars c
                   JOIN profiles p ON p.user_id = c.user_id
                    SET c.email_suppressed = 1, c.mtime = c.mtime
                  WHERE {$blocked}{$optedOut}"
            );
            $this->execute(
                'UPDATE cars c
                   JOIN profiles p ON p.user_id = c.user_id
                    SET c.email_bounced = 1, c.email_bounced_address = p.email_bounced_address, c.mtime = c.mtime
                  WHERE p.email_bounced = 1'
            );
            $this->execute('COMMIT');
        } catch (\Throwable $e) {
            $this->execute('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Tell whether Phinx runs with --dry-run, and say so when it does.
     *
     * In a dry run, PdoAdapter::execute() runs nothing, but query() and
     * fetchAll() still run. The backfill would then read a column that the
     * dry run did not add. AbstractAdapter::isDryRunEnabled() uses the same
     * check.
     *
     * @return bool True when this is a dry run
     */
    private function isDryRun(): bool
    {
        $input = $this->getInput();
        if ($input === null || !$input->hasOption('dry-run') || !(bool) $input->getOption('dry-run')) {
            return false;
        }

        $this->say('Dry run: this migration has no dry-run output. It reads data that earlier steps create.');

        return true;
    }

    /**
     * A fresh reader on this migration's connection, so it sees the writes so far.
     */
    private function queries(): DeliverabilityMigrationQueries
    {
        return new DeliverabilityMigrationQueries($this->getAdapter()->getConnection());
    }

    private function say(string $message): void
    {
        if (isset($this->output)) {
            $this->output->writeln('<info>' . $message . '</info>');
        }
    }

    /**
     * @param array<int|string> $ids
     */
    private static function idList(array $ids): string
    {
        return $ids === [] ? 'none' : implode(', ', array_map('intval', $ids));
    }

    /**
     * @param list<array{user_id: int, car_id: int}> $pairs
     */
    private static function pairList(array $pairs): string
    {
        if ($pairs === []) {
            return 'none';
        }

        return implode(', ', array_map(
            static fn (array $pair): string => sprintf('car %d (user %d)', $pair['car_id'], $pair['user_id']),
            $pairs
        ));
    }

    /**
     * Verifies all three `cars` audit triggers exist after (re)creation.
     *
     * A copy of the private method in 20260915000000: each migration keeps
     * its own, so an edit to one cannot change what an applied migration did.
     * A CREATE TRIGGER can fail to leave a trigger when the migration user's
     * privileges are borderline, and {@see enableTrustFunctionCreators()}
     * continues in that case. This check turns a missing trigger into a
     * migration failure instead of an unaudited `cars` table.
     *
     * @throws \RuntimeException If any of cars_insert, cars_update, cars_delete is missing.
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
     * The bodies are 20260915000000's. Only the trailing verification columns
     * change: up() passes the list without the three flags, and down() passes
     * 20260915000000's list.
     *
     * @param list<string> $extraColumns Trailing column names, copied from NEW.* or OLD.*
     */
    private function createTriggers(array $extraColumns): void
    {
        $cols   = $extraColumns === [] ? '' : ', ' . implode(', ', $extraColumns);
        $newVal = $extraColumns === [] ? '' : ', NEW.' . implode(', NEW.', $extraColumns);
        $oldVal = $extraColumns === [] ? '' : ', OLD.' . implode(', OLD.', $extraColumns);

        $trustFunctionCreatorsSet = $this->enableTrustFunctionCreators();

        // A thrown CREATE TRIGGER must not leave log_bin_trust_function_creators
        // relaxed server-wide, so the reset is in finally.
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
