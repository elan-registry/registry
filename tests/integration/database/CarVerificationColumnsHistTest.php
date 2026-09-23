<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for the car verification and bounce-state columns and
 * their capture in `cars_hist`.
 *
 * Migration 20260902104755_add_car_verification_columns (#1155) added three
 * columns to `cars` (mirrored onto `cars_hist`) and extended the cars_insert,
 * cars_update, and cars_delete triggers to capture them:
 *
 * - `owner_last_updated` DATETIME NULL
 * - `vericode_sent_at`   DATETIME NULL
 * - `email_bounced`      TINYINT(1) NOT NULL DEFAULT 0
 *
 * Migration 20260907141816_add_car_bounce_state_columns (#1887) added two
 * more the same way, exactly mirroring #1155's migration:
 *
 * - `email_bounced_address` VARCHAR(155) NULL
 * - `email_suppressed`      TINYINT(1) NOT NULL DEFAULT 0
 *
 * This is real MySQL trigger behavior and cannot be verified with a mocked
 * DB — only a live database proves the trigger bodies actually capture these
 * columns on every INSERT, UPDATE, and DELETE.
 *
 * Per the migrations' cars_update trigger bodies, all five columns follow the
 * same convention as most other columns (OLD.*), NOT the chassis_override
 * exception (NEW.*) — see AddCarVerificationColumns::createTriggers().
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarVerificationColumnsHistTest extends IntegrationTestCase
{
    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        foreach (
            ['owner_last_updated', 'vericode_sent_at', 'email_bounced', 'email_bounced_address', 'email_suppressed']
            as $column
        ) {
            $this->assertColumnExists('cars', $column);
            $this->assertColumnExists('cars_hist', $column);
        }

        $this->testUserId = $this->createTestUser();
        $this->loginAsTestUser($this->testUserId);
    }

    /**
     * Skips the test (rather than failing with a DB error) if the given
     * column is not yet present — mirrors ChassisOverridePersistenceTest's
     * pattern for a migration that may not have run yet.
     */
    private function assertColumnExists(string $table, string $column): void
    {
        $check = $this->db->query(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = ?
               AND COLUMN_NAME  = ?
             LIMIT 1",
            [$table, $column]
        );

        if (!$check || $check->count() === 0) {
            $this->markTestSkipped(
                "{$table}.{$column} not yet available — run `composer migrate`"
            );
        }
    }

    /**
     * One row per trigger-captured column: [column, value written to cars,
     * cast applied to the cars_hist value before comparing]. The value read
     * back from cars_hist must equal the value written.
     *
     * @return array<string, array{string, int|string, 'int'|'string'}>
     */
    public static function histColumnProvider(): array
    {
        return [
            'owner_last_updated'    => ['owner_last_updated', '2026-08-15 10:30:00', 'string'],
            'vericode_sent_at'      => ['vericode_sent_at', '2026-08-20 09:00:00', 'string'],
            'email_bounced'         => ['email_bounced', 1, 'int'],
            'email_bounced_address' => ['email_bounced_address', 'bounced@example.com', 'string'],
            'email_suppressed'      => ['email_suppressed', 1, 'int'],
        ];
    }

    /**
     * Reads $column from $histRow and casts it for a strict comparison.
     *
     * @param 'int'|'string' $cast
     */
    private function histValue(object $histRow, string $column, string $cast): int|string
    {
        return $cast === 'int' ? (int) $histRow->{$column} : (string) $histRow->{$column};
    }

    /**
     * Schema assertion: cars.email_bounced_address is VARCHAR(155) NULL, no
     * default; cars.email_suppressed is a boolean-like column NOT NULL
     * DEFAULT 0. Same assertions apply to cars_hist.
     */
    #[Group('fast')]
    public function testColumnTypesAndDefaultsMatchSpec(): void
    {
        foreach (['cars', 'cars_hist'] as $table) {
            $addressColumn = $this->db->query(
                "SELECT IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'email_bounced_address'",
                [$table]
            )->first();

            $this->assertIsObject($addressColumn, "{$table}.email_bounced_address must exist");
            $this->assertSame('YES', $addressColumn->IS_NULLABLE, "{$table}.email_bounced_address must be nullable");
            $this->assertSame(155, (int) $addressColumn->CHARACTER_MAXIMUM_LENGTH, "{$table}.email_bounced_address must be varchar(155)");

            $suppressedColumn = $this->db->query(
                "SELECT IS_NULLABLE, COLUMN_DEFAULT
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'email_suppressed'",
                [$table]
            )->first();

            $this->assertIsObject($suppressedColumn, "{$table}.email_suppressed must exist");
            $this->assertSame('NO', $suppressedColumn->IS_NULLABLE, "{$table}.email_suppressed must be NOT NULL");
            $this->assertSame('0', (string) $suppressedColumn->COLUMN_DEFAULT, "{$table}.email_suppressed must default to 0");
        }
    }

    /**
     * INSERT: a new car row with the column populated must produce a
     * corresponding cars_hist INSERT row capturing that same value.
     *
     * Deliberately inserts via raw SQL rather than createTestCar(): that
     * helper purges any pre-existing cars_hist rows for the new car ID
     * immediately after inserting, as a safeguard against AUTO_INCREMENT
     * reuse — but that purge would also delete the very INSERT-trigger row
     * this test needs to inspect.
     *
     * @param 'int'|'string' $cast
     */
    #[Group('fast')]
    #[DataProvider('histColumnProvider')]
    public function testInsertTriggerCapturesColumn(string $column, int|string $value, string $cast): void
    {
        $inserted = $this->db->insert('cars', [
            'user_id' => $this->testUserId,
            'year'    => 1973,
            'model'   => 'Elan S4',
            'series'  => 'S4',
            'variant' => 'SE',
            'type'    => 'FHC',
            'chassis' => 'VC' . substr(uniqid(), -10),
            'color'   => 'Red',
            'ctime'   => date('Y-m-d H:i:s'),
            $column   => $value,
        ]);
        $this->assertTrue($inserted, 'Failed to insert test car: ' . $this->db->errorString());

        $carId = (int) $this->db->lastId();
        $this->assertGreaterThan(0, $carId, 'Failed to get inserted car ID');
        $this->trackCarId($carId);

        $histRow = $this->db->query(
            "SELECT *
             FROM cars_hist
             WHERE car_id = ? AND operation = 'INSERT'
             ORDER BY timestamp DESC, id DESC
             LIMIT 1",
            [$carId]
        )->first();

        $this->assertIsObject(
            $histRow,
            'Expected an INSERT row in cars_hist — check that the cars_insert trigger is present'
        );
        $this->assertSame(
            $value,
            $this->histValue($histRow, $column, $cast),
            "cars_hist INSERT row must capture {$column}"
        );
    }

    /**
     * UPDATE: the cars_update trigger uses OLD.* (not NEW.*) for these five
     * columns — the deliberate NEW.* exception is chassis_override only.
     *
     * Each write goes through its dedicated CarRepository method (mirroring
     * real application call sites in CarVerificationManager), so each UPDATE
     * produces its own cars_hist row whose value for the just-changed
     * column(s) must be the PRE-update value, not the new one. Kept as one
     * sequential method rather than parameterised: each block reads the
     * newest UPDATE row, which is the one its own write just produced
     * (ordered by `timestamp DESC, id DESC` — `timestamp` has whole-second
     * resolution, so several blocks' rows can share one value).
     */
    #[Group('fast')]
    public function testUpdateTriggerCapturesPreUpdateOldValues(): void
    {
        $originalOwnerLastUpdated = '2026-01-01 00:00:00';
        $originalVericodeSentAt   = '2026-01-02 00:00:00';
        $originalBouncedAddress   = 'original-' . uniqid() . '@example.com';

        $carId = $this->createTestCar($this->testUserId, [
            'owner_last_updated'    => $originalOwnerLastUpdated,
            'vericode_sent_at'      => $originalVericodeSentAt,
            'email_bounced'         => 0,
            'email_bounced_address' => $originalBouncedAddress,
            'email_suppressed'      => 0,
        ]);

        $repo = new CarRepository($this->db);

        // --- owner_last_updated -------------------------------------------
        // Via updateCar() directly, not a dedicated single-column setter
        // (unlike the columns below): CarRepository's own
        // owner_last_updated setter was removed as dead code (#1930) — no
        // production caller ever wrote this column standalone, since
        // Car::update() and CarVerificationManager fold it into their own
        // multi-column updateCar() calls to avoid a second cars_hist row for
        // one logical edit. This is still a single-column UPDATE statement
        // for this test's purposes, so the trigger assertion below is
        // unaffected.
        $this->assertTrue(
            $repo->updateCar($carId, ['owner_last_updated' => '2026-09-01 12:00:00']),
            'updateCar() must succeed'
        );

        $histAfterOwnerUpdate = $this->db->query(
            "SELECT owner_last_updated
             FROM cars_hist
             WHERE car_id = ? AND operation = 'UPDATE'
             ORDER BY timestamp DESC, id DESC
             LIMIT 1",
            [$carId]
        )->first();

        $this->assertIsObject($histAfterOwnerUpdate, 'Expected an UPDATE row in cars_hist');
        $this->assertSame(
            $originalOwnerLastUpdated,
            (string) $histAfterOwnerUpdate->owner_last_updated,
            'cars_hist UPDATE row must capture the pre-update (OLD) owner_last_updated value'
        );

        // --- vericode_sent_at ------------------------------------------------
        $this->assertTrue(
            $repo->updateVerificationSentAt($carId, '2026-09-02 08:00:00'),
            'updateVerificationSentAt() must succeed'
        );

        $histAfterVericodeUpdate = $this->db->query(
            "SELECT vericode_sent_at
             FROM cars_hist
             WHERE car_id = ? AND operation = 'UPDATE'
             ORDER BY timestamp DESC, id DESC
             LIMIT 1",
            [$carId]
        )->first();

        $this->assertIsObject($histAfterVericodeUpdate, 'Expected an UPDATE row in cars_hist');
        $this->assertSame(
            $originalVericodeSentAt,
            (string) $histAfterVericodeUpdate->vericode_sent_at,
            'cars_hist UPDATE row must capture the pre-update (OLD) vericode_sent_at value'
        );

        // --- email_bounced + email_bounced_address ---------------------------
        // One block, not two: updateEmailBounced() writes both columns in a
        // single UPDATE statement, so a second call would see the first
        // call's address as OLD rather than the original fixture value.
        $this->assertTrue(
            $repo->updateEmailBounced($carId, true, 'new-' . uniqid() . '@example.com'),
            'updateEmailBounced() must succeed'
        );

        $histAfterBouncedUpdate = $this->db->query(
            "SELECT email_bounced, email_bounced_address
             FROM cars_hist
             WHERE car_id = ? AND operation = 'UPDATE'
             ORDER BY timestamp DESC, id DESC
             LIMIT 1",
            [$carId]
        )->first();

        $this->assertIsObject($histAfterBouncedUpdate, 'Expected an UPDATE row in cars_hist');
        $this->assertSame(
            0,
            (int) $histAfterBouncedUpdate->email_bounced,
            'cars_hist UPDATE row must capture the pre-update (OLD) email_bounced value (0, not the new 1)'
        );
        $this->assertSame(
            $originalBouncedAddress,
            (string) $histAfterBouncedUpdate->email_bounced_address,
            'cars_hist UPDATE row must capture the pre-update (OLD) email_bounced_address value'
        );

        // --- email_suppressed ------------------------------------------------
        $this->assertTrue(
            $repo->updateEmailSuppressed($carId, true),
            'updateEmailSuppressed() must succeed'
        );

        $histAfterSuppressedUpdate = $this->db->query(
            "SELECT email_suppressed
             FROM cars_hist
             WHERE car_id = ? AND operation = 'UPDATE'
             ORDER BY timestamp DESC, id DESC
             LIMIT 1",
            [$carId]
        )->first();

        $this->assertIsObject($histAfterSuppressedUpdate, 'Expected an UPDATE row in cars_hist');
        $this->assertSame(
            0,
            (int) $histAfterSuppressedUpdate->email_suppressed,
            'cars_hist UPDATE row must capture the pre-update (OLD) email_suppressed value (0, not the new 1)'
        );
    }

    /**
     * Car::update() with $isOwnerInitiated = true must fold owner_last_updated
     * into the SAME $filteredFields array as the rest of the changed car
     * fields, producing exactly ONE `UPDATE cars SET ...` statement — not a
     * separate call for owner_last_updated alone. Two statements would
     * produce two cars_hist UPDATE rows for a single logical edit, doubling
     * the audit trail (the bug this branch fixes).
     *
     * Proof: since the cars_update trigger captures OLD.* for both `color`
     * and `owner_last_updated`, a single UPDATE statement that changes both
     * must produce exactly one cars_hist row whose `color` AND
     * `owner_last_updated` are BOTH the pre-update values. Two separate
     * UPDATE statements could not produce this — each would only capture the
     * OLD value of the column it individually changed.
     */
    #[Group('fast')]
    public function testCarUpdateWithOwnerInitiatedFlagProducesSingleAuditRow(): void
    {
        $originalColor             = 'Original Test Color';
        $originalOwnerLastUpdated  = '2026-01-01 00:00:00';

        $carId = $this->createTestCar($this->testUserId, [
            'color'              => $originalColor,
            'owner_last_updated' => $originalOwnerLastUpdated,
        ]);

        $histCountBefore = (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = 'UPDATE'",
            [$carId]
        )->first()->cnt;

        $car    = new Car($carId);
        $result = $car->update(['id' => $carId, 'color' => 'New Test Color'], true);

        $this->assertTrue($result, 'Car::update() with $isOwnerInitiated = true must return true');

        $histRowsAfter = $this->db->query(
            "SELECT color, owner_last_updated
             FROM cars_hist
             WHERE car_id = ? AND operation = 'UPDATE'
             ORDER BY timestamp DESC, id DESC",
            [$carId]
        )->results();

        $histCountAfter = count($histRowsAfter);

        $this->assertSame(
            $histCountBefore + 1,
            $histCountAfter,
            'Car::update($fields, true) must produce exactly ONE new cars_hist UPDATE row '
            . '(one UPDATE statement), not two — check that owner_last_updated is folded '
            . 'into the same $filteredFields array as the rest of the changed fields in Car::update()'
        );

        $newHistRow = $histRowsAfter[0];

        $this->assertSame(
            $originalColor,
            (string) $newHistRow->color,
            'The single cars_hist UPDATE row must capture the pre-update (OLD) color value'
        );
        $this->assertSame(
            $originalOwnerLastUpdated,
            (string) $newHistRow->owner_last_updated,
            'The SAME cars_hist UPDATE row must ALSO capture the pre-update (OLD) '
            . 'owner_last_updated value — proving both columns were captured by the same '
            . 'trigger firing on the same UPDATE statement (i.e. genuinely one UPDATE, not two)'
        );
    }

    /**
     * DELETE: deleting a car must produce a cars_hist DELETE row capturing
     * the column's final value (OLD.*, same convention as every other column
     * in the cars_delete trigger).
     *
     * @param 'int'|'string' $cast
     */
    #[Group('fast')]
    #[DataProvider('histColumnProvider')]
    public function testDeleteTriggerCapturesFinalColumnValue(string $column, int|string $value, string $cast): void
    {
        $carId = $this->createTestCar($this->testUserId, [$column => $value]);

        $car    = new Car($carId);
        $result = $car->delete("Test deletion for {$column} audit", $this->testUserId);

        $this->assertTrue($result, 'Car::delete() must return true on success');

        $histRow = $this->db->query(
            "SELECT *
             FROM cars_hist
             WHERE car_id = ? AND operation = 'DELETE'
             ORDER BY timestamp DESC, id DESC
             LIMIT 1",
            [$carId]
        )->first();

        $this->assertIsObject(
            $histRow,
            'Expected a DELETE row in cars_hist — check that the cars_delete trigger is present'
        );
        $this->assertSame(
            $value,
            $this->histValue($histRow, $column, $cast),
            "cars_hist DELETE row must capture the final {$column} value"
        );
    }

    /**
     * The migration's backfill (`UPDATE cars SET owner_last_updated = mtime,
     * mtime = mtime WHERE owner_last_updated IS NULL`) runs before the trigger
     * rebuild step, while the pre-migration cars_update trigger is still
     * installed. Without the @disable_triggers guard it wraps the statement
     * in, that trigger would fire once per row and insert a spurious 'UPDATE'
     * cars_hist row for what is pure internal bookkeeping, not a real edit —
     * this proves the guard suppresses it, mirroring
     * CarsYearSmallintMigrationTest::test_disableTriggersGuard_suppressesUpdateHistory()
     * for a different migration's guarded UPDATE.
     *
     * The property under test is the @disable_triggers guard itself, not the
     * backfill's `IS NULL` predicate. Migration 20260905172137 made
     * `cars.owner_last_updated` NOT NULL DEFAULT CURRENT_TIMESTAMP, so a NULL
     * fixture is no longer constructible and the original one-time backfill can
     * never match a row again. The guarded UPDATE is therefore driven off a
     * known-stale sentinel value instead — same statement shape, same trigger
     * exposure, and it keeps running on every migrated database rather than
     * skipping into permanent silence.
     */
    #[Group('fast')]
    public function testMigrationBackfillGuardSuppressesUpdateHistory(): void
    {
        $staleSentinel = '2000-01-01 00:00:00';
        $carId = $this->createTestCar($this->testUserId, ['owner_last_updated' => $staleSentinel]);

        $histCountBefore = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = 'UPDATE'",
            [$carId]
        )->first()->cnt;

        $this->db->query('SET @disable_triggers = 1');
        $this->db->query(
            'UPDATE cars SET owner_last_updated = mtime, mtime = mtime WHERE id = ? AND owner_last_updated = ?',
            [$carId, $staleSentinel]
        );
        $this->db->query('SET @disable_triggers = NULL');

        $histCountAfter = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = 'UPDATE'",
            [$carId]
        )->first()->cnt;

        $this->assertSame(
            (int) $histCountBefore,
            (int) $histCountAfter,
            'The migration backfill\'s @disable_triggers guard must prevent the cars_update '
            . 'trigger from inserting a spurious cars_hist row — if this regresses, every '
            . 'pre-existing car gets a bogus \'UPDATE\' entry the next time this backfill runs'
        );

        $carsRow = $this->db->query(
            'SELECT owner_last_updated FROM cars WHERE id = ?',
            [$carId]
        )->first();

        $this->assertNotSame(
            $staleSentinel,
            (string) $carsRow->owner_last_updated,
            'The guarded UPDATE must still perform the backfill even though the trigger is suppressed'
        );
    }
}
