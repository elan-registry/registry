<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';
// Migration classes are not PSR-4 autoloaded (Phinx loads them itself at
// migrate-time) — require the file directly to reach BACKFILL_SQL.
require_once __DIR__ . '/../../../database/migrations/20260905172137_convert_car_timestamps_to_datetime.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for migration 20260905172137_convert_car_timestamps_to_datetime
 * (issue #1953).
 *
 * Verifies the post-migration state of:
 * - cars.owner_last_updated: DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, no ON UPDATE
 * - cars.ctime / cars.last_verified: DATETIME NULL
 * - cars.mtime: DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 * - cars_hist.ctime / cars_hist.mtime: DATETIME NULL (nullability asymmetry vs cars.mtime)
 * - cars_hist.timestamp: DATETIME NOT NULL, idx_cars_hist_timestamp intact
 * - all three cars triggers exist and their bodies still carry
 *   owner_last_updated / vericode_sent_at / email_bounced
 * - the corrective backfill's invariant (BACKFILL_SQL, executed verbatim against a
 *   synthetic row)
 *
 * These are the codebase's first IS_NULLABLE / COLUMN_DEFAULT / EXTRA assertions —
 * every prior schema-assertion test (e.g. CarsYearSmallintMigrationTest) checks only
 * COLUMN_TYPE or existence. That gap is why the #1953 defect (nullable
 * owner_last_updated with a COALESCE(mtime) fallback) shipped undetected.
 *
 * Migration verification ordering problem: by the time this suite runs, the migration
 * has already been applied (or not) — there is no way to observe pre-migration state.
 * The backfill tests therefore do not try to reconstruct history; they re-execute
 * ConvertCarTimestampsToDatetime::BACKFILL_SQL verbatim against a synthetic,
 * test-owned row and assert the invariant it establishes (Test Plan §3).
 */
#[Group('integration')]
#[Group('migration')]
#[Group('car-verification')]
final class CarVerificationTimestampMigrationTest extends IntegrationTestCase
{
    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // No migration gate here — see requireMigrationApplied(), which the
        // individual type/nullability tests call for themselves. Everything
        // else in this class runs against the pre-migration schema too.
        $this->testUserId = $this->createTestUser();
        $this->loginAsTestUser($this->testUserId);
    }

    /**
     * Skip unless migration 20260905172137 has actually been applied.
     *
     * Called per-test rather than from setUp(). Only the assertions that read a
     * post-migration column *type* or nullability genuinely depend on it; the
     * trigger, index and backfill tests all pass against the pre-migration
     * schema and must keep running everywhere. Gating the whole class hid seven
     * working tests on every unmigrated environment — including the trigger-body
     * test, which covers what this migration's own docblock calls its single
     * biggest hazard, and which is therefore exactly the test least affordable
     * to silence.
     */
    private function requireMigrationApplied(): void
    {
        $row = $this->db->query(
            "SELECT IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cars'
               AND COLUMN_NAME  = 'owner_last_updated'
             LIMIT 1"
        )->first();

        if (!$row || $row->IS_NULLABLE !== 'NO') {
            $this->markTestSkipped(
                'Migration 20260905172137 has not been applied — cars.owner_last_updated is ' .
                'still nullable. Run: composer migrate'
            );
        }
    }

    protected function tearDown(): void
    {
        try {
            // Nothing test-local beyond IntegrationTestCase's own createdCarIds/
            // createdUserIds tracking — createTestCar()/createTestUser() register
            // their own cleanup. This try/finally exists so a future test-local
            // fixture added here still gets cleaned up even if an assertion above
            // it fails, per this file's required tearDown() pattern.
        } finally {
            parent::tearDown();
        }
    }

    // -------------------------------------------------------------------------
    // Schema: cars.owner_last_updated
    // -------------------------------------------------------------------------

    #[Group('integration')]
    #[Group('migration')]
    public function testSchema_ownerLastUpdated_isNotNullWithCurrentTimestampDefault(): void
    {
        $this->requireMigrationApplied();

        $row = $this->columnInfo('cars', 'owner_last_updated');

        $this->assertNotNull($row, 'Column cars.owner_last_updated must exist');
        $this->assertStringContainsStringIgnoringCase(
            'datetime',
            (string) $row->COLUMN_TYPE,
            'cars.owner_last_updated must be DATETIME after migration'
        );
        $this->assertSame(
            'NO',
            $row->IS_NULLABLE,
            'cars.owner_last_updated must be NOT NULL after migration'
        );
        $this->assertSame(
            'CURRENT_TIMESTAMP',
            $row->COLUMN_DEFAULT,
            'cars.owner_last_updated must default to CURRENT_TIMESTAMP'
        );
    }

    /**
     * Dedicated test: the absence of ON UPDATE is the entire point of this
     * issue. cars.owner_last_updated must record owner activity only, never
     * incidental row writes (e.g. Owner::syncOwnerFieldsToCars()).
     */
    #[Group('integration')]
    #[Group('migration')]
    public function testSchema_ownerLastUpdated_hasNoOnUpdateClause(): void
    {
        $row = $this->columnInfo('cars', 'owner_last_updated');

        $this->assertNotNull($row, 'Column cars.owner_last_updated must exist');
        $this->assertStringNotContainsStringIgnoringCase(
            'on update',
            (string) $row->EXTRA,
            'cars.owner_last_updated must NOT carry an ON UPDATE clause — its absence is ' .
            'what removes the need for a COALESCE(mtime) fallback in the freshness expression'
        );
    }

    // -------------------------------------------------------------------------
    // Schema: cars.mtime / ctime / last_verified
    // -------------------------------------------------------------------------

    #[Group('integration')]
    #[Group('migration')]
    public function testSchema_carsMtime_isDatetimeWithOnUpdatePreserved(): void
    {
        $this->requireMigrationApplied();

        $row = $this->columnInfo('cars', 'mtime');

        $this->assertNotNull($row, 'Column cars.mtime must exist');
        $this->assertStringContainsStringIgnoringCase(
            'datetime',
            (string) $row->COLUMN_TYPE,
            'cars.mtime must be DATETIME after migration'
        );
        $this->assertSame('NO', $row->IS_NULLABLE, 'cars.mtime must remain NOT NULL');
        $this->assertSame(
            'CURRENT_TIMESTAMP',
            $row->COLUMN_DEFAULT,
            'cars.mtime must keep its CURRENT_TIMESTAMP default'
        );
        $this->assertStringContainsStringIgnoringCase(
            'on update current_timestamp',
            (string) $row->EXTRA,
            'cars.mtime must retain ON UPDATE CURRENT_TIMESTAMP — legal on DATETIME but ' .
            'silently lost if the new column definition omits it'
        );
    }

    #[Group('integration')]
    #[Group('migration')]
    public function testSchema_carsCtime_isDatetimeNullable(): void
    {
        $this->requireMigrationApplied();

        $row = $this->columnInfo('cars', 'ctime');

        $this->assertNotNull($row, 'Column cars.ctime must exist');
        $this->assertStringContainsStringIgnoringCase(
            'datetime',
            (string) $row->COLUMN_TYPE,
            'cars.ctime must be DATETIME after migration'
        );
        $this->assertSame('YES', $row->IS_NULLABLE, 'cars.ctime must remain nullable');
    }

    #[Group('integration')]
    #[Group('migration')]
    public function testSchema_carsLastVerified_isDatetimeNullable(): void
    {
        $this->requireMigrationApplied();

        $row = $this->columnInfo('cars', 'last_verified');

        $this->assertNotNull($row, 'Column cars.last_verified must exist');
        $this->assertStringContainsStringIgnoringCase(
            'datetime',
            (string) $row->COLUMN_TYPE,
            'cars.last_verified must be DATETIME after migration'
        );
        $this->assertSame('YES', $row->IS_NULLABLE, 'cars.last_verified must remain nullable');
    }

    // -------------------------------------------------------------------------
    // Schema: cars_hist
    // -------------------------------------------------------------------------

    #[Group('integration')]
    #[Group('migration')]
    public function testSchema_carsHistCtime_isDatetime(): void
    {
        $this->requireMigrationApplied();

        $row = $this->columnInfo('cars_hist', 'ctime');

        $this->assertNotNull($row, 'Column cars_hist.ctime must exist');
        $this->assertStringContainsStringIgnoringCase(
            'datetime',
            (string) $row->COLUMN_TYPE,
            'cars_hist.ctime must be DATETIME after migration'
        );
        $this->assertSame('YES', $row->IS_NULLABLE, 'cars_hist.ctime must remain nullable');
    }

    /**
     * Pins the deliberate nullability asymmetry against cars.mtime (NOT NULL):
     * a history row records whatever the source row held, including nothing.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function testSchema_carsHistMtime_isDatetimeNullable(): void
    {
        $this->requireMigrationApplied();

        $row = $this->columnInfo('cars_hist', 'mtime');

        $this->assertNotNull($row, 'Column cars_hist.mtime must exist');
        $this->assertStringContainsStringIgnoringCase(
            'datetime',
            (string) $row->COLUMN_TYPE,
            'cars_hist.mtime must be DATETIME after migration'
        );
        $this->assertSame(
            'YES',
            $row->IS_NULLABLE,
            'cars_hist.mtime must be nullable — deliberately asymmetric with cars.mtime (NOT NULL)'
        );
    }

    #[Group('integration')]
    #[Group('migration')]
    public function testSchema_carsHistTimestamp_isDatetimeNotNull(): void
    {
        $this->requireMigrationApplied();

        $row = $this->columnInfo('cars_hist', 'timestamp');

        $this->assertNotNull($row, 'Column cars_hist.timestamp must exist');
        $this->assertStringContainsStringIgnoringCase(
            'datetime',
            (string) $row->COLUMN_TYPE,
            'cars_hist.timestamp must be DATETIME after migration'
        );
        $this->assertSame('NO', $row->IS_NULLABLE, 'cars_hist.timestamp must be NOT NULL');
        $this->assertSame(
            'CURRENT_TIMESTAMP',
            $row->COLUMN_DEFAULT,
            'cars_hist.timestamp must keep its CURRENT_TIMESTAMP default'
        );
    }

    /**
     * A TIMESTAMP -> DATETIME MODIFY COLUMN can silently drop a dependent
     * index if written the wrong way; this asserts idx_cars_hist_timestamp
     * survived the conversion.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function testSchema_idxCarsHistTimestamp_stillExists(): void
    {
        $row = $this->db->query(
            "SELECT 1
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cars_hist'
               AND INDEX_NAME   = 'idx_cars_hist_timestamp'
             LIMIT 1"
        );

        $this->assertGreaterThan(
            0,
            $row->count(),
            'idx_cars_hist_timestamp must still exist on cars_hist after the timestamp conversion'
        );
    }

    // -------------------------------------------------------------------------
    // Triggers
    // -------------------------------------------------------------------------

    #[Group('integration')]
    #[Group('migration')]
    public function testSchema_allThreeCarsTriggersExist(): void
    {
        $triggers = $this->db->query(
            "SELECT TRIGGER_NAME
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND EVENT_OBJECT_TABLE = 'cars'
             ORDER BY TRIGGER_NAME"
        )->results();

        $this->assertNotNull($triggers, 'information_schema.TRIGGERS query must return results');

        $triggerNames = array_map(
            static fn(object $t): string => $t->TRIGGER_NAME,
            $triggers
        );

        $this->assertContains('cars_delete', $triggerNames, 'cars_delete trigger must exist');
        $this->assertContains('cars_insert', $triggerNames, 'cars_insert trigger must exist');
        $this->assertContains('cars_update', $triggerNames, 'cars_update trigger must exist');
    }

    /**
     * Catches a rebuild that reverted to the pre-#1155 baseline trigger bodies
     * instead of the post-20260902104755 bodies — the single biggest hazard
     * this migration's trigger rebuild step carries, per the migration's own
     * docblock.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function testTriggerBodies_captureOwnerLastUpdatedVericodeSentAtEmailBounced(): void
    {
        $triggers = $this->db->query(
            "SELECT TRIGGER_NAME, ACTION_STATEMENT
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND EVENT_OBJECT_TABLE = 'cars'
             ORDER BY TRIGGER_NAME"
        )->results();

        $this->assertNotNull($triggers, 'information_schema.TRIGGERS query must return results');
        $this->assertNotEmpty($triggers, 'At least one trigger must exist on the cars table');

        foreach ($triggers as $trigger) {
            $body = (string) $trigger->ACTION_STATEMENT;
            foreach (['owner_last_updated', 'vericode_sent_at', 'email_bounced'] as $column) {
                $this->assertStringContainsStringIgnoringCase(
                    $column,
                    $body,
                    "Trigger {$trigger->TRIGGER_NAME} must still reference {$column} — a rebuild " .
                    'that reverted to the pre-#1155 baseline bodies would silently stop auditing it'
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Backfill invariant — executed against a synthetic, test-owned row
    // -------------------------------------------------------------------------

    /**
     * Immediately after BACKFILL_SQL runs against an active row, that row must
     * read as STALE — 366 days is deliberately one day PAST the one-year
     * boundary, so the whole active registry reads as due-for-verification on
     * day one. That is the entire corrective purpose of the backfill: the prior
     * migration left 93.7% of active cars carrying an `mtime` inside the last
     * year, which would suppress ~94% of the verification email this system
     * exists to send.
     *
     * Asserted through CarRepository::stalenessSql() rather than by arithmetic
     * on the returned value, so this test is wired to the actual freshness rule
     * — an inverted or retuned expression must fail here rather than pass on a
     * date comparison that happens to still hold.
     *
     * Re-executes ConvertCarTimestampsToDatetime::BACKFILL_SQL verbatim rather
     * than duplicating the string, scoped to a single synthetic car via a
     * WHERE ... AND id = ? tacked onto a copy of the exact statement text.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function testBackfillLeavesEveryActiveRowStaleImmediatelyAfterMigration(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'backfill-fresh@example.com',
            'email_bounced'      => 0,
            'last_verified'      => null,
            'owner_last_updated' => date('Y-m-d H:i:s', strtotime('-5 years')),
            'mtime'              => date('Y-m-d H:i:s', strtotime('-5 years')),
            'solddate'           => null,
        ]);

        $this->runBackfillScopedToCar($carId);

        // Evaluated by MySQL against the same expression production uses, so a
        // change to freshnessSql() that inverted or retuned the rule fails here.
        $stale = CarRepository::stalenessSql('cars');
        $staleRow = $this->db->query(
            "SELECT id FROM cars WHERE id = ? AND {$stale}",
            [$carId]
        );

        $this->assertSame(
            1,
            $staleRow->count(),
            'BACKFILL_SQL must leave an active car STALE (due for verification) immediately '
            . 'after it runs: 366 days is one day past the one-year boundary. A backfilled row '
            . 'reading as fresh would suppress the verification email for a full year.'
        );

        // Guard the interval itself, so a retune to e.g. 30 days that still
        // reads stale today cannot pass unnoticed.
        $ownerLastUpdated = $this->db->query(
            'SELECT owner_last_updated FROM cars WHERE id = ?',
            [$carId]
        )->first()->owner_last_updated;

        $ageInDays = (time() - strtotime((string) $ownerLastUpdated)) / 86400;
        $this->assertGreaterThan(
            365,
            $ageInDays,
            'BACKFILL_SQL must set owner_last_updated PAST the one-year boundary, not inside it'
        );
    }

    /**
     * The backfill's WHERE clause is `solddate IS NULL` — a sold car must be
     * left untouched.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function testBackfill_soldCarsWereNotTouched(): void
    {
        $originalOwnerLastUpdated = date('Y-m-d H:i:s', strtotime('-2 years'));

        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'backfill-sold@example.com',
            'email_bounced'      => 0,
            'last_verified'      => null,
            'owner_last_updated' => $originalOwnerLastUpdated,
            'mtime'              => $originalOwnerLastUpdated,
            'solddate'           => date('Y-m-d', strtotime('-1 year')),
        ]);

        $this->runBackfillScopedToCar($carId);

        $row = $this->db->query(
            'SELECT owner_last_updated FROM cars WHERE id = ?',
            [$carId]
        )->first();

        $this->assertSame(
            $originalOwnerLastUpdated,
            (string) $row->owner_last_updated,
            'BACKFILL_SQL must not touch owner_last_updated for a car with a non-null solddate'
        );
    }

    /**
     * `mtime = mtime` in BACKFILL_SQL is load-bearing, not a no-op: cars.mtime
     * is ON UPDATE CURRENT_TIMESTAMP, so omitting it from SET would silently
     * bump it to the backfill's run time and destroy the row's real
     * modification timestamp. Assert mtime is byte-identical before and after.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function testBackfill_mtimeUnchangedForBackfilledRows(): void
    {
        $originalMtime = date('Y-m-d H:i:s', strtotime('-4 years'));

        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'backfill-mtime@example.com',
            'email_bounced'      => 0,
            'last_verified'      => null,
            'owner_last_updated' => date('Y-m-d H:i:s', strtotime('-5 years')),
            'mtime'              => $originalMtime,
            'solddate'           => null,
        ]);

        $mtimeBefore = $this->db->query(
            'SELECT mtime FROM cars WHERE id = ?',
            [$carId]
        )->first()->mtime;

        $this->runBackfillScopedToCar($carId);

        $mtimeAfter = $this->db->query(
            'SELECT mtime FROM cars WHERE id = ?',
            [$carId]
        )->first()->mtime;

        $this->assertSame(
            (string) $mtimeBefore,
            (string) $mtimeAfter,
            'BACKFILL_SQL must leave mtime byte-identical — the explicit `mtime = mtime` in the ' .
            'statement exists precisely to suppress the ON UPDATE CURRENT_TIMESTAMP clause'
        );
        $this->assertSame(
            $originalMtime,
            (string) $mtimeAfter,
            'mtime must equal the value set at fixture creation, not the backfill run time'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function columnInfo(string $table, string $column): ?object
    {
        return $this->db->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = ?
               AND COLUMN_NAME  = ?
             LIMIT 1",
            [$table, $column]
        )->first();
    }

    /**
     * Executes ConvertCarTimestampsToDatetime::BACKFILL_SQL verbatim, scoped
     * to a single test-owned car via an appended `AND id = ?` — reuses the
     * constant exactly as written rather than retyping the UPDATE statement,
     * per the plan's requirement that the test exercise the real SQL.
     */
    private function runBackfillScopedToCar(int $carId): void
    {
        $sql = \ConvertCarTimestampsToDatetime::BACKFILL_SQL . ' AND id = ?';

        $this->db->query('SET @disable_triggers = 1');
        $this->db->query($sql, [$carId]);
        $this->db->query('SET @disable_triggers = NULL');
    }

    // -------------------------------------------------------------------------
    // The partial-date repair (PARTIAL_DATE_REPAIR_SQL_TEMPLATE)
    // -------------------------------------------------------------------------

    /**
     * The repair promotes an unknown day or month to `01`, keeping the year.
     *
     * Executes the migration's own statement against a scratch table shaped
     * like `cars_hist`, so the real SQL is exercised rather than a paraphrase
     * of it. A scratch table is used rather than a `cars_hist` fixture because
     * these values cannot be inserted into the live audit table under the
     * project's `sql_mode` without relaxing it, and because a stray audit row
     * is not test-local state that tearDown can reliably reclaim.
     *
     * @param string $stored   The partial date as legacy data holds it
     * @param string $expected What the repair must produce
     */
    #[Group('integration')]
    #[Group('migration')]
    #[DataProvider('partialDateRepairProvider')]
    public function testPartialDateRepair_promotesUnknownComponentsToOne(
        string $stored,
        string $expected
    ): void {
        $actual = $this->runPartialDateRepairOnScratchTable($stored);

        $this->assertSame(
            $expected,
            $actual,
            "The partial-date repair must turn '{$stored}' into '{$expected}' — promoting an "
            . 'unknown component to 01 while preserving every component that is known'
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function partialDateRepairProvider(): array
    {
        return [
            'unknown day'           => ['1999-06-00', '1999-06-01'],
            'unknown month and day' => ['2001-00-00', '2001-01-01'],
            'earliest real row'     => ['1968-04-00', '1968-04-01'],
            'december boundary'     => ['1977-12-00', '1977-12-01'],
        ];
    }

    /**
     * A zero-YEAR row must be left alone, not assigned a fabricated year.
     *
     * `MAKEDATE(0, 1)` does not error — it returns `2000-01-01`, because MySQL
     * reads a bare `0` as a two-digit year. Without the `AND YEAR(col) > 0`
     * guard the repair would silently invent a date 2000 years off, well-formed
     * and permanently wrong, for a row that recorded nothing at all. Leaving it
     * unmatched lets the subsequent ALTER reject it loudly instead.
     *
     * No such row exists in production today; this pins the guard so a future
     * one is not silently fabricated.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function testPartialDateRepair_leavesZeroYearRowUntouched(): void
    {
        $actual = $this->runPartialDateRepairOnScratchTable('0000-00-00');

        $this->assertSame(
            '0000-00-00',
            $actual,
            'A zero-year row must NOT be repaired: MAKEDATE(0,1) yields 2000-01-01, so '
            . 'repairing it would invent a year rather than promote an unknown component. '
            . 'It must survive unchanged so the ALTER can reject it loudly.'
        );
    }

    /**
     * A fully-valid date must not be touched by the repair.
     *
     * Guards the predicate itself: if it ever widened to match clean rows, the
     * repair would rewrite real purchase dates to the first of the month.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function testPartialDateRepair_leavesCompleteDateUntouched(): void
    {
        $actual = $this->runPartialDateRepairOnScratchTable('1985-12-25');

        $this->assertSame(
            '1985-12-25',
            $actual,
            'A complete date must be excluded by the repair predicate — matching it would '
            . 'rewrite real purchase dates to the first of the month'
        );
    }

    /**
     * Runs the migration's real repair statement over a one-row scratch table
     * and returns the resulting value.
     *
     * `sql_mode` is relaxed only to *insert* the fixture — legacy values like
     * `1999-06-00` cannot otherwise be written — and restored before the repair
     * runs, so the statement under test executes under the session's real mode.
     */
    private function runPartialDateRepairOnScratchTable(string $stored): string
    {
        $table = 'test_partial_date_repair';
        $originalSqlMode = $this->db->query('SELECT @@session.sql_mode AS m')->first()->m;

        try {
            $this->db->query("DROP TABLE IF EXISTS {$table}");
            $this->db->query("CREATE TABLE {$table} (id INT PRIMARY KEY, purchasedate DATE NULL)");

            $this->db->query("SET SESSION sql_mode = ''");
            $this->db->query("INSERT INTO {$table} (id, purchasedate) VALUES (1, ?)", [$stored]);
            $this->db->query('SET SESSION sql_mode = ?', [$originalSqlMode]);

            $sql = sprintf(
                \ConvertCarTimestampsToDatetime::PARTIAL_DATE_REPAIR_SQL_TEMPLATE,
                'purchasedate'
            );
            $this->db->query(str_replace('cars_hist', $table, $sql));

            return (string) $this->db->query(
                "SELECT CAST(purchasedate AS CHAR) AS d FROM {$table} WHERE id = 1"
            )->first()->d;
        } finally {
            $this->db->query('SET SESSION sql_mode = ?', [$originalSqlMode]);
            $this->db->query("DROP TABLE IF EXISTS {$table}");
        }
    }
}
