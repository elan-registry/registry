<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Issue #1953: convert the car timestamp columns from TIMESTAMP to DATETIME and
 * make `cars.owner_last_updated` NOT NULL, so verification freshness no longer
 * needs a `COALESCE(owner_last_updated, mtime)` fallback.
 *
 * Six columns convert TIMESTAMP -> DATETIME:
 *
 * - `cars.ctime`, `cars.mtime`, `cars.last_verified`
 * - `cars_hist.ctime`, `cars_hist.mtime`, `cars_hist.timestamp`
 *
 * and `cars.owner_last_updated` moves from `DATETIME NULL` to
 * `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` (with **no** `ON UPDATE`
 * clause — its absence is the entire point of the issue: the column must record
 * owner activity only, never incidental row writes).
 *
 * `cars_hist.timestamp` is deliberately included even though nothing in the
 * freshness expression reads it. ADR-003 has `cars_hist` mirror the structure
 * of `cars` (not type-for-type — the `mtime` nullability asymmetry below is a
 * deliberate exception), and every trigger fire writes `NEW.ctime`/`NEW.mtime`
 * (now DATETIME) into `cars_hist`. Leaving any `cars_hist` timestamp column as
 * TIMESTAMP would reintroduce a silent DATETIME->TIMESTAMP conversion on every
 * audit row — precisely what this migration removes — and would fail outright
 * on any value past 2038-01-19. `cars_hist.timestamp` is converted alongside
 * its siblings so the audit table has no remaining TIMESTAMP column and no
 * mixed-type surprise for a future reader. Its `idx_cars_hist_timestamp` index
 * must survive the conversion, which is why raw `MODIFY COLUMN` is used rather
 * than Phinx's `changeColumn()` (see "Why raw SQL" below).
 *
 * SESSION TIMEZONE ASSUMPTION (the highest-risk property of this migration).
 * `ALTER TABLE ... MODIFY COLUMN <ts> DATETIME` reads each stored TIMESTAMP
 * (held internally as UTC), renders it as a wall-clock string **in the
 * session's `time_zone`**, and stores that literal verbatim. The rendered
 * string is therefore correct only if the session zone matches the zone the
 * application writes and reads its dates in.
 *
 * That zone is `America/Los_Angeles`: users/init.php pins it on every web
 * request, so it is the zone every stored value was written in. Do not assume
 * PHP and MySQL agree merely by sharing a host — measured on production
 * 2026-09-05, three clocks disagree: MySQL `SYSTEM` => MST (-7), web PHP
 * America/Los_Angeles => PDT (-7), and CLI PHP America/New_York (-4). Phinx
 * bootstraps only vendor/autoload.php, never init.php, so a migration's own
 * ambient PHP clock is the CLI one and is NOT the clock that wrote the data.
 *
 * Where the session zone and the application zone differ, every converted value
 * shifts permanently and silently, and the shifted values are still
 * well-formed, so no later check catches it. {@see assertClockAlignment()}
 * enforces the assumption — against {@see APPLICATION_TIMEZONE}, not against
 * ambient PHP — before any ALTER runs.
 *
 * up() + down() are used instead of change() because this migration mixes
 * column-type changes with a one-time data repair, a corrective backfill, and
 * trigger rebuilds, none of which Phinx can auto-reverse. See
 * database/migrations/README.md — "Only fall back to explicit up() + down()".
 *
 * Why raw SQL rather than Phinx's `changeColumn()`: the exact `ON UPDATE
 * CURRENT_TIMESTAMP` / `DEFAULT` / nullability triple differs per column here
 * (`cars.mtime` keeps `ON UPDATE`, `cars.owner_last_updated` must not gain
 * one, `cars.mtime` is NOT NULL while `cars_hist.mtime` is NULL), and a
 * TIMESTAMP->DATETIME `changeColumn()` can silently drop a dependent index such
 * as `idx_cars_hist_timestamp`. `MODIFY COLUMN` states the full target
 * definition explicitly, so what is written is what is applied.
 *
 * NOT ATOMIC: MySQL issues an implicit commit on every DDL statement, so the
 * `ALTER TABLE` and `CREATE TRIGGER` steps below cannot be wrapped in a
 * transaction. Only the date repair and the backfill are transactional. A
 * failure part-way therefore leaves the schema partly converted. Re-running is
 * safe; every step is individually guarded:
 *
 * 1. {@see assertClockAlignment()} is re-evaluated from scratch on each run and
 *    aborts before touching anything if the clocks disagree. A migration that
 *    throws is never recorded in `phinxlog`, so Phinx retries it on the next
 *    run once the environment is corrected.
 * 2. Every `MODIFY COLUMN` is idempotent: re-applying the identical target
 *    definition to an already-DATETIME column is a no-op ALTER, and applying it
 *    to a still-TIMESTAMP column completes the conversion. No `hasColumn()`
 *    guard is needed because no column is added or dropped.
 * 3. The partial-date repair is predicated on
 *    `(DAY(...) = 0 OR MONTH(...) = 0) AND YEAR(...) > 0`, which matches
 *    nothing once the rows are normalized.
 * 4. The backfill is unconditional over active rows and idempotent in effect:
 *    re-running rewrites the same rows to a value derived from `NOW()`, which
 *    remains comfortably older than the one-year freshness boundary.
 * 5. Triggers use `DROP TRIGGER IF EXISTS` followed by `CREATE TRIGGER`, and
 *    {@see assertCarsTriggersPresent()} turns a silently-missing trigger into a
 *    loud failure rather than an unaudited `cars` table.
 *
 * ONE-WAY STEPS — `down()` deliberately does not reverse these:
 *
 * - **The partial-date repair.** The 15 `cars_hist` rows carrying a zero day or
 *   zero month (`1999-06-00`, `2001-00-00`) cannot be restored: the target
 *   `sql_mode` (`NO_ZERO_IN_DATE`) rejects writing such values back, and the
 *   repair is not injective anyway — a normalized `1999-06-01` is
 *   indistinguishable from a row that genuinely read `1999-06-01` all along.
 *   The repair loses no information (it promotes an unknown component to `01`,
 *   the convention already applied to the live `cars` table by a prior repair)
 *   and is correct to keep after a rollback. A zero-*year* row is deliberately
 *   left unrepaired rather than promoted — see
 *   {@see normalizePartialHistoryDates()} for why inventing a year is not the
 *   same kind of operation as promoting a day.
 * - **The backfill.** The prior `owner_last_updated` values are overwritten in
 *   place and are not journaled anywhere, so there is nothing to restore. They
 *   were themselves wrong — copied from `mtime` by 20260902104755, which is the
 *   defect this migration corrects — so restoring them would be undesirable
 *   even if it were possible. A rollback therefore returns the schema to its
 *   prior shape while leaving the corrected data in place.
 */
final class ConvertCarTimestampsToDatetime extends AbstractMigration
{
    /**
     * Maximum tolerated difference, in seconds, between MySQL's clock and PHP's
     * clock before the conversion is refused. Generous enough to absorb the
     * round-trip and any sub-minute NTP drift, far tighter than the smallest
     * real timezone offset (15 minutes).
     */
    private const CLOCK_SKEW_TOLERANCE_SECONDS = 120;

    /**
     * The timezone the application writes datetimes in.
     *
     * Must match `$timezone_string` in users/init.php, which pins every web
     * request. Kept as a literal rather than read from init.php because Phinx
     * does not bootstrap the application: requiring init.php here would pull in
     * the full UserSpice stack (session, DB singleton, plugin loader) purely to
     * read one string. If init.php's zone changes, change this with it — the
     * clock guard is comparing against the wrong zone otherwise.
     */
    private const APPLICATION_TIMEZONE = 'America/Los_Angeles';

    /**
     * The corrective backfill, extracted verbatim so the integration test can
     * execute this exact statement rather than duplicating the string.
     *
     * Every **active** row (`solddate IS NULL`) is set unconditionally to just
     * past the one-year freshness boundary, so the whole active registry reads
     * as due-for-verification on day one.
     *
     * Unconditional is deliberate, and each half matters:
     *
     * - It must **overwrite** the values 20260902104755 backfilled from
     *   `mtime`. Measured against production 2026-09-05, 1410 of 1505 active
     *   cars (93.7%) carry an `mtime` inside the last year — from routine
     *   fix-script maintenance, not owner activity — so those inherited values
     *   make the registry read as freshly-confirmed and would suppress ~94% of
     *   the verification email this system exists to send, while logging
     *   successful cron runs nightly. (A snapshot, not an invariant; the shape
     *   of the argument holds regardless of the exact figure.)
     * - It must not be narrowed to `WHERE last_verified IS NULL` or
     *   `WHERE owner_last_updated IS NULL`. Any such guard leaves the skipped
     *   rows carrying the new column default — the migration's own run time —
     *   freezing them as falsely fresh for a full year.
     *
     * `mtime = mtime` is load-bearing, not a no-op: `cars.mtime` is declared
     * `ON UPDATE CURRENT_TIMESTAMP`, so any `UPDATE` touching a row silently
     * rewrites `mtime` to the migration's run time and destroys every real
     * modification timestamp in the table. Omitting the column from `SET` does
     * **not** suppress the clause; assigning it explicitly does. Precedent:
     * 20260902104755 (rationale at :57-62, statement at :71-73).
     */
    public const BACKFILL_SQL = 'UPDATE cars'
        . ' SET owner_last_updated = DATE_SUB(NOW(), INTERVAL 366 DAY), mtime = mtime'
        . ' WHERE solddate IS NULL';

    /**
     * The pre-ALTER NULL repair, exposed for the same reason as BACKFILL_SQL:
     * the integration test executes this exact statement rather than a copy
     * that could drift from it.
     *
     * Runs before the `NOT NULL` ALTER because MySQL does not coerce an
     * existing NULL to the new DEFAULT — it aborts the statement with
     * ERROR 1138 (Invalid use of NULL value). {@see BACKFILL_SQL} cannot cover
     * this: it is scoped `WHERE solddate IS NULL`, so a *sold* car holding a
     * NULL is repaired by no other step.
     *
     * `mtime = mtime` suppresses the ON UPDATE bump, exactly as in BACKFILL_SQL.
     */
    public const NULL_REPAIR_SQL = 'UPDATE cars'
        . ' SET owner_last_updated = COALESCE(owner_last_updated, mtime), mtime = mtime'
        . ' WHERE owner_last_updated IS NULL';

    /**
     * The partial-date repair, as a sprintf template taking the column name.
     *
     * Exposed for the same reason as the two constants above: the integration
     * test executes this exact statement rather than a copy that could drift.
     * The column name is interpolated, not bound — it comes only from this
     * class's own hardcoded ['purchasedate', 'solddate'] list, never from
     * input. See {@see normalizePartialHistoryDates()} for the year guard and
     * the parenthesization, both of which are load-bearing.
     */
    public const PARTIAL_DATE_REPAIR_SQL_TEMPLATE = 'UPDATE cars_hist'
        . ' SET `%1$s` = MAKEDATE(YEAR(`%1$s`), 1)'
        . ' + INTERVAL (GREATEST(MONTH(`%1$s`), 1) - 1) MONTH'
        . ' WHERE (DAY(`%1$s`) = 0 OR MONTH(`%1$s`) = 0)'
        . ' AND YEAR(`%1$s`) > 0';

    public function up(): void
    {
        // --- 1. Refuse to convert under a mismatched clock -----------------
        // Must run before any ALTER: the conversion is irreversible in effect
        // (a shifted value is still well-formed and no later check detects it).
        $this->assertClockAlignment();

        // --- 1a. Clear any NULL owner_last_updated before the NOT NULL ALTER
        // MySQL does not coerce an existing NULL to the new DEFAULT; it aborts
        // the whole statement with ERROR 1138 (Invalid use of NULL value).
        // Verified against production 2026-09-05: 0 of 1593 rows are NULL, so
        // this touches nothing today. It runs anyway because the corrective
        // backfill in step 5 is scoped `WHERE solddate IS NULL` — a *sold* car
        // that acquired a NULL between now and the deploy is repaired by no
        // other step, and would abort the migration here, after the earlier
        // DDL has already implicit-committed.
        //
        // `mtime = mtime` for the same reason as BACKFILL_SQL: suppress the
        // ON UPDATE CURRENT_TIMESTAMP bump.
        //
        // @disable_triggers is required here, not optional: this runs while the
        // pre-migration cars_update trigger is still installed, so without it
        // every repaired row writes a spurious 'UPDATE' cars_hist entry for
        // what is pure internal bookkeeping, not an owner edit. Same guard and
        // same reason as 20260902104755's equivalent backfill, whose behaviour
        // is pinned by CarVerificationColumnsHistTest::
        // testMigrationBackfillGuardSuppressesUpdateHistory().
        $adapter = $this->getAdapter();
        $adapter->beginTransaction();
        $this->execute('SET @disable_triggers = 1');

        try {
            $this->execute(self::NULL_REPAIR_SQL);
            // Commit inside the try: the next statement is a DDL which
            // implicit-commits, so an open transaction left by a throw here
            // would be silently committed by that ALTER rather than rolled back.
            $adapter->commitTransaction();
        } catch (\Throwable $e) {
            try {
                $adapter->rollbackTransaction();
            } catch (\Throwable $rollbackFailure) {
                throw new \RuntimeException(
                    'Rollback failed while handling: ' . $e->getMessage()
                    . ' (rollback error: ' . $rollbackFailure->getMessage() . ')',
                    0,
                    $e
                );
            }

            throw $e;
        } finally {
            $this->execute('SET @disable_triggers = NULL');
        }

        // --- 2. Convert the `cars` columns --------------------------------
        // owner_last_updated: NOT NULL with a CURRENT_TIMESTAMP default and
        // deliberately NO `ON UPDATE` clause. The default only covers rows
        // inserted after this migration; existing rows are corrected by the
        // backfill in step 4. NOT NULL is what removes the need for the
        // COALESCE fallback in the freshness expression.
        $this->execute(
            'ALTER TABLE `cars` MODIFY COLUMN `owner_last_updated` '
            . 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
        );

        // ctime / last_verified stay nullable; mtime stays NOT NULL and keeps
        // both its DEFAULT and its ON UPDATE clause. ON UPDATE
        // CURRENT_TIMESTAMP is legal on DATETIME but is silently lost if the
        // new definition omits it, which is why it is restated here.
        $this->execute('ALTER TABLE `cars` MODIFY COLUMN `ctime` DATETIME NULL');
        $this->execute(
            'ALTER TABLE `cars` MODIFY COLUMN `mtime` '
            . 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
        $this->execute('ALTER TABLE `cars` MODIFY COLUMN `last_verified` DATETIME NULL');

        // --- 3. Repair partial dates before the cars_hist ALTER ------------
        $this->normalizePartialHistoryDates();

        // --- 4. Convert the `cars_hist` columns ---------------------------
        // The nullability asymmetry against `cars` is deliberate and preserved:
        // `cars_hist.mtime` is NULL while `cars.mtime` is NOT NULL, because a
        // history row records whatever the source row held, including nothing.
        // `cars_hist.timestamp` is NOT NULL with a CURRENT_TIMESTAMP default
        // and carries `idx_cars_hist_timestamp`; MODIFY COLUMN preserves the
        // index, which a Phinx changeColumn() on a TIMESTAMP can silently drop.
        $this->execute('ALTER TABLE `cars_hist` MODIFY COLUMN `ctime` DATETIME NULL');
        $this->execute('ALTER TABLE `cars_hist` MODIFY COLUMN `mtime` DATETIME NULL');
        $this->execute(
            'ALTER TABLE `cars_hist` MODIFY COLUMN `timestamp` '
            . 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
        );

        // --- 5. Corrective backfill ---------------------------------------
        // @disable_triggers suppresses cars_update for this UPDATE; without it
        // the trigger fires once per active row, flooding cars_hist with bogus
        // 'UPDATE' entries for internal bookkeeping that is not a real edit —
        // the project-wide escape hatch defined by ADR-003 and used by the
        // other bulk-write migrations.
        $adapter = $this->getAdapter();
        $adapter->beginTransaction();
        $this->execute('SET @disable_triggers = 1');

        try {
            $this->execute(self::BACKFILL_SQL);
            $adapter->commitTransaction();
        } catch (\Throwable $e) {
            // Preserve the original failure if the rollback itself throws. On a
            // dropped connection the ROLLBACK fails too, and an unguarded
            // rollback would replace "the backfill failed because X" with
            // "MySQL server has gone away" — PHP does not chain an exception
            // thrown from inside a catch, so the real cause would be lost
            // outright at exactly the moment an operator needs it.
            try {
                $adapter->rollbackTransaction();
            } catch (\Throwable $rollbackFailure) {
                throw new \RuntimeException(
                    'Rollback failed while handling: ' . $e->getMessage()
                    . ' (rollback error: ' . $rollbackFailure->getMessage() . ')',
                    0,
                    $e
                );
            }

            throw $e;
        } finally {
            // @disable_triggers is a SESSION variable: it survives both the
            // failed statement and the rollback. Leaving it set would silently
            // disable `cars` auditing for every later write on this connection
            // — including a manual replay during partial-failure recovery,
            // which this migration's docblock explicitly plans for.
            $this->execute('SET @disable_triggers = NULL');
        }

        // --- 6. Rebuild the audit triggers --------------------------------
        // Rebuilt only after BOTH tables are converted, so a trigger is never
        // installed against a half-converted pair.
        //
        // MySQL does not strictly require a rebuild for MODIFY COLUMN (that
        // restriction applies to DROP/RENAME), but ADR-003's Trigger
        // Maintenance procedure mandates one for any `cars` schema change, and
        // it guarantees the bodies re-parse against the new column types rather
        // than running from a cached definition.
        //
        // These are the POST-20260902104755 bodies, carrying
        // owner_last_updated / vericode_sent_at / email_bounced. Reverting to
        // the baseline bodies here would silently stop auditing all three
        // verification columns — the single biggest hazard in this migration.
        $this->createTriggers(
            'owner_last_updated, vericode_sent_at, email_bounced',
            'NEW.owner_last_updated, NEW.vericode_sent_at, NEW.email_bounced',
            'OLD.owner_last_updated, OLD.vericode_sent_at, OLD.email_bounced'
        );
        $this->assertCarsTriggersPresent();
    }

    public function down(): void
    {
        // Reverse order of up(). The clock guard applies equally here: a
        // DATETIME -> TIMESTAMP conversion renders the stored literal in the
        // session zone to derive a UTC instant, so a mismatched clock shifts
        // every value on the way back out too.
        $this->assertClockAlignment();

        // --- 1. Restore the cars_hist column types ------------------------
        $this->execute('ALTER TABLE `cars_hist` MODIFY COLUMN `ctime` TIMESTAMP NULL');
        $this->execute('ALTER TABLE `cars_hist` MODIFY COLUMN `mtime` TIMESTAMP NULL');
        $this->execute(
            'ALTER TABLE `cars_hist` MODIFY COLUMN `timestamp` '
            . 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        );

        // --- 2. Restore the cars column types -----------------------------
        // The pre-migration definitions exactly: ctime and last_verified
        // nullable with no default and no extra; mtime NOT NULL with both
        // DEFAULT and ON UPDATE; owner_last_updated back to a plain nullable
        // DATETIME with no default.
        $this->execute('ALTER TABLE `cars` MODIFY COLUMN `last_verified` TIMESTAMP NULL');
        $this->execute(
            'ALTER TABLE `cars` MODIFY COLUMN `mtime` '
            . 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
        $this->execute('ALTER TABLE `cars` MODIFY COLUMN `ctime` TIMESTAMP NULL');
        $this->execute('ALTER TABLE `cars` MODIFY COLUMN `owner_last_updated` DATETIME NULL');

        // --- 3. Rebuild the triggers --------------------------------------
        // Same post-20260902104755 bodies: rolling back the column *types*
        // does not roll back the verification columns themselves, which
        // 20260902104755 owns and its own down() removes.
        $this->createTriggers(
            'owner_last_updated, vericode_sent_at, email_bounced',
            'NEW.owner_last_updated, NEW.vericode_sent_at, NEW.email_bounced',
            'OLD.owner_last_updated, OLD.vericode_sent_at, OLD.email_bounced'
        );
        $this->assertCarsTriggersPresent();
    }

    /**
     * Refuses the conversion unless MySQL's clock and PHP's clock agree.
     *
     * `MODIFY COLUMN <ts> DATETIME` renders each stored value in the session's
     * `time_zone`, so the conversion is value-preserving only if that zone is
     * the one the application reads and writes dates in. Comparing
     * `@@session.time_zone` against `@@global.time_zone` does **not** establish
     * that — both read `SYSTEM` on a machine where MySQL follows the OS while
     * PHP has fallen back to UTC because `date.timezone` is unset, leaving the
     * two seven hours apart while the comparison passes. Comparing the two
     * clocks directly is the only check that catches it.
     *
     * A migration that throws is not recorded in `phinxlog`, so this is safely
     * re-runnable once `date.timezone` is aligned with the MySQL server zone.
     *
     * @throws \RuntimeException If the two clocks differ by more than
     *                           {@see CLOCK_SKEW_TOLERANCE_SECONDS}.
     */
    private function assertClockAlignment(): void
    {
        $row = $this->fetchRow('SELECT NOW() AS db_now');
        $dbNow = is_array($row) ? (string)($row['db_now'] ?? '') : '';

        // Compare MySQL against PHP evaluated in APPLICATION_TIMEZONE, NOT
        // against ambient CLI PHP. The values being converted were written by
        // web PHP, which users/init.php pins to that zone on every request;
        // Phinx bootstraps only vendor/autoload.php, so CLI PHP here is
        // whatever php.ini says and is irrelevant to the conversion.
        //
        // Measured on production 2026-09-05, all three disagree:
        //   MySQL    SYSTEM => MST            (-7)  <- stores the values
        //   web PHP  America/Los_Angeles PDT  (-7)  <- wrote the values
        //   CLI PHP  America/New_York         (-4)  <- irrelevant
        // Comparing CLI PHP would abort this deploy on a 3h skew that cannot
        // affect the data, while a host with CLI PHP and MySQL both on UTC and
        // web PHP still on LA would pass and shift every value by 7-8 hours.
        $phpNow = (new \DateTimeImmutable('now', new \DateTimeZone(self::APPLICATION_TIMEZONE)))
            ->format('Y-m-d H:i:s');

        self::assertClocksAligned($dbNow, $phpNow);
    }

    /**
     * The clock comparison itself, as a pure function of the two clock
     * readings, so it can be exercised directly by a test without standing up a
     * Phinx adapter. {@see assertClockAlignment()} supplies the live readings.
     *
     * @param string $dbNow  MySQL's NOW(), as read from the migration connection
     * @param string $phpNow PHP's current time in the same 'Y-m-d H:i:s' format
     * @throws \RuntimeException If MySQL's clock is unreadable, or the two
     *                           clocks differ by more than
     *                           {@see CLOCK_SKEW_TOLERANCE_SECONDS}.
     */
    public static function assertClocksAligned(string $dbNow, string $phpNow): void
    {
        $dbTime = strtotime($dbNow);
        $phpTime = strtotime($phpNow);

        // Fail closed on either side. $phpNow is generated internally and
        // cannot realistically fail to parse, but this guard's whole purpose is
        // to abort an irreversible conversion, so neither operand is assumed.
        if ($phpTime === false) {
            throw new \RuntimeException(
                'Refusing to convert TIMESTAMP columns: could not parse PHP time '
                . '(got ' . var_export($phpNow, true) . '). The clock alignment check '
                . 'cannot be evaluated, and converting blind would risk shifting every value.'
            );
        }

        if ($dbTime === false) {
            throw new \RuntimeException(
                'Refusing to convert TIMESTAMP columns: could not read MySQL NOW() '
                . '(got ' . var_export($dbNow, true) . '). The clock alignment check '
                . 'cannot be evaluated, and converting blind would risk shifting every value.'
            );
        }

        $skew = abs($dbTime - $phpTime);

        if ($skew > self::CLOCK_SKEW_TOLERANCE_SECONDS) {
            throw new \RuntimeException(sprintf(
                'Refusing to convert TIMESTAMP columns: MySQL NOW() is %s but PHP now is %s '
                . '(%d seconds apart). Converting under a mismatched clock would shift every '
                . 'value permanently. Align date.timezone with the MySQL server timezone first.',
                $dbNow,
                $phpNow,
                $skew
            ));
        }
    }

    /**
     * Normalizes `cars_hist` dates carrying a zero day or zero month.
     *
     * Thirteen `purchasedate` and two `solddate` values read `1999-06-00`,
     * `2001-00-00` and similar — legacy rows from owners who knew the year, and
     * sometimes the month, but not the day. `cars` itself is clean (verified
     * against production 2026-09-05: 0 partial dates in `cars`, 13 + 2 in
     * `cars_hist`); these are audit snapshots of a state `cars` no longer holds.
     *
     * They must be repaired **before** the `cars_hist` ALTER. MySQL revalidates
     * the entire table during a rebuild, so columns this migration does not
     * convert still abort it under a strict `sql_mode`
     * (`STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE`):
     *
     *     ERROR 1292 (22007): Incorrect date value: '1999-06-00'
     *                         for column 'purchasedate' at row 6475
     *
     * Production runs `NO_ENGINE_SUBSTITUTION`, which does not reject these, so
     * the repair is a safeguard for strict-mode environments (CI, a future
     * hardened prod) rather than something today's production requires.
     *
     * `sql_mode` is deliberately NOT relaxed here. An earlier revision did so,
     * on the premise that `DAY()`/`MONTH()` on a zero-component date is
     * rejected under strict mode — that is false. Verified under the full
     * strict mode above: the SELECT returns both rows, and the repair UPDATE
     * writes `1999-06-01` / `2001-01-01` without error. Relaxing the mode only
     * opened a window in which subsequent writes ran unguarded. Do not
     * reintroduce it.
     *
     * `AND YEAR(col) > 0` deliberately EXCLUDES a zero-year row such as
     * `0000-00-00`. This repair promotes an *unknown* component to `01` while
     * keeping what is known; a zero-year row knows nothing, and `MAKEDATE(0, 1)`
     * does not error — it returns `2000-01-01`, because MySQL reads a bare `0`
     * as a two-digit year (`0`=>2000, `69`=>2069, `70`=>1970). Repairing such a
     * row would therefore invent a purchase date 2000 years off, indisputably
     * well-formed and permanently wrong. Excluding it lets it reach the
     * `cars_hist` ALTER and fail loudly (ERROR 1292) under a strict `sql_mode`,
     * which is the correct outcome: a human decides what that date should be,
     * not this migration.
     *
     * No such row exists today — verified against production 2026-09-05: all 15
     * partial dates carry real years (1968-2010), and `cars` has none at all.
     * The guard is for the row that has not arrived yet, and is reachable in
     * principle because users/classes/DB.php sets `sql_mode = ''` on every
     * application connection, so MySQL will accept and store a zero-date.
     *
     * The parentheses around the `DAY(...) = 0 OR MONTH(...) = 0` pair are
     * load-bearing: `AND` binds tighter than `OR`, so without them the year
     * guard would apply only to the `MONTH` half of the predicate.
     *
     * `cars_hist` has no UPDATE trigger of its own, but `@disable_triggers` is
     * set for consistency with every other bulk write in this codebase.
     */
    private function normalizePartialHistoryDates(): void
    {
        $adapter = $this->getAdapter();
        $adapter->beginTransaction();
        $this->execute('SET @disable_triggers = 1');

        try {
            foreach (['purchasedate', 'solddate'] as $column) {
                $this->execute(sprintf(self::PARTIAL_DATE_REPAIR_SQL_TEMPLATE, $column));
            }

            // Commit inside the try: the next statement in up() is a DDL
            // (ALTER TABLE cars_hist) which implicit-commits, so an open
            // transaction left by a throw here would be silently committed by
            // that ALTER rather than rolled back.
            $adapter->commitTransaction();
        } catch (\Throwable $e) {
            // Preserve the original failure if the rollback itself throws. On a
            // dropped connection the ROLLBACK fails too, and an unguarded
            // rollback would replace "the backfill failed because X" with
            // "MySQL server has gone away" — PHP does not chain an exception
            // thrown from inside a catch, so the real cause would be lost
            // outright at exactly the moment an operator needs it.
            try {
                $adapter->rollbackTransaction();
            } catch (\Throwable $rollbackFailure) {
                throw new \RuntimeException(
                    'Rollback failed while handling: ' . $e->getMessage()
                    . ' (rollback error: ' . $rollbackFailure->getMessage() . ')',
                    0,
                    $e
                );
            }

            throw $e;
        } finally {
            $this->execute('SET @disable_triggers = NULL');
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
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS '
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
     * Copied from 20260902104755 (where it is private) so this migration does
     * not depend on another migration class staying loadable. The bodies are
     * the post-20260902104755 ones: pass the verification column list and the
     * matching NEW / OLD value lists.
     *
     * @param string $extraColumns   Trailing column names, or '' for none.
     * @param string $extraNewValues Matching NEW.* expressions, or ''.
     * @param string $extraOldValues Matching OLD.* expressions, or ''.
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
