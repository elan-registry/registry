<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for the verification columns added in issue #1155.
 *
 * Migration 20260902104755_add_car_verification_columns added three columns
 * to `cars` (mirrored onto `cars_hist`) and extended the cars_insert,
 * cars_update, and cars_delete triggers to capture them:
 *
 * - `owner_last_updated` DATETIME NULL
 * - `vericode_sent_at`   DATETIME NULL
 * - `email_bounced`      TINYINT(1) NOT NULL DEFAULT 0
 *
 * This is real MySQL trigger behavior and cannot be verified with a mocked
 * DB — only a live database proves the trigger bodies actually capture these
 * columns on every INSERT, UPDATE, and DELETE.
 *
 * Per the migration's cars_update trigger body, these three columns follow
 * the same convention as most other columns (OLD.*), NOT the chassis_override
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

        foreach (['owner_last_updated', 'vericode_sent_at', 'email_bounced'] as $column) {
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
     * INSERT: a new car row with all three verification columns populated
     * must produce a corresponding cars_hist INSERT row capturing those
     * same three values.
     *
     * Deliberately inserts via raw SQL rather than createTestCar(): that
     * helper purges any pre-existing cars_hist rows for the new car ID
     * immediately after inserting, as a safeguard against AUTO_INCREMENT
     * reuse — but that purge would also delete the very INSERT-trigger row
     * this test needs to inspect.
     */
    #[Group('fast')]
    public function testInsertTriggerCapturesVerificationColumns(): void
    {
        $ownerLastUpdated = '2026-08-15 10:30:00';
        $vericodeSentAt   = '2026-08-20 09:00:00';
        $chassis          = 'VC' . substr(uniqid(), -10);

        $inserted = $this->db->insert('cars', [
            'user_id'            => $this->testUserId,
            'year'               => 1973,
            'model'              => 'Elan S4',
            'series'             => 'S4',
            'variant'            => 'SE',
            'type'               => 'FHC',
            'chassis'            => $chassis,
            'color'              => 'Red',
            'ctime'              => date('Y-m-d H:i:s'),
            'owner_last_updated' => $ownerLastUpdated,
            'vericode_sent_at'   => $vericodeSentAt,
            'email_bounced'      => 1,
        ]);
        $this->assertTrue($inserted, 'Failed to insert test car: ' . $this->db->errorString());

        $carId = (int) $this->db->lastId();
        $this->assertGreaterThan(0, $carId, 'Failed to get inserted car ID');
        $this->trackCarId($carId);

        $histRow = $this->db->query(
            "SELECT owner_last_updated, vericode_sent_at, email_bounced
             FROM cars_hist
             WHERE car_id = ? AND operation = 'INSERT'
             ORDER BY timestamp DESC
             LIMIT 1",
            [$carId]
        )->first();

        $this->assertIsObject(
            $histRow,
            'Expected an INSERT row in cars_hist — check that the cars_insert trigger is present'
        );
        $this->assertSame(
            $ownerLastUpdated,
            (string) $histRow->owner_last_updated,
            'cars_hist INSERT row must capture owner_last_updated'
        );
        $this->assertSame(
            $vericodeSentAt,
            (string) $histRow->vericode_sent_at,
            'cars_hist INSERT row must capture vericode_sent_at'
        );
        $this->assertSame(
            1,
            (int) $histRow->email_bounced,
            'cars_hist INSERT row must capture email_bounced'
        );
    }

    /**
     * UPDATE: the cars_update trigger uses OLD.* (not NEW.*) for these three
     * columns — the deliberate NEW.* exception is chassis_override only.
     *
     * Each column is updated independently via its dedicated CarRepository
     * method (mirroring real application call sites in CarVerificationManager),
     * so each UPDATE produces its own cars_hist row whose value for the
     * just-changed column must be the PRE-update value, not the new one.
     */
    #[Group('fast')]
    public function testUpdateTriggerCapturesPreUpdateOldValues(): void
    {
        $originalOwnerLastUpdated = '2026-01-01 00:00:00';
        $originalVericodeSentAt   = '2026-01-02 00:00:00';

        $carId = $this->createTestCar($this->testUserId, [
            'owner_last_updated' => $originalOwnerLastUpdated,
            'vericode_sent_at'   => $originalVericodeSentAt,
            'email_bounced'      => 0,
        ]);

        $repo = new CarRepository($this->db);

        // --- owner_last_updated -------------------------------------------
        $this->assertTrue(
            $repo->updateOwnerLastUpdated($carId, '2026-09-01 12:00:00'),
            'updateOwnerLastUpdated() must succeed'
        );

        $histAfterOwnerUpdate = $this->db->query(
            "SELECT owner_last_updated
             FROM cars_hist
             WHERE car_id = ? AND operation = 'UPDATE'
             ORDER BY timestamp DESC
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
             ORDER BY timestamp DESC
             LIMIT 1",
            [$carId]
        )->first();

        $this->assertIsObject($histAfterVericodeUpdate, 'Expected an UPDATE row in cars_hist');
        $this->assertSame(
            $originalVericodeSentAt,
            (string) $histAfterVericodeUpdate->vericode_sent_at,
            'cars_hist UPDATE row must capture the pre-update (OLD) vericode_sent_at value'
        );

        // --- email_bounced -------------------------------------------------
        $this->assertTrue(
            $repo->updateEmailBounced($carId, true),
            'updateEmailBounced() must succeed'
        );

        $histAfterBouncedUpdate = $this->db->query(
            "SELECT email_bounced
             FROM cars_hist
             WHERE car_id = ? AND operation = 'UPDATE'
             ORDER BY timestamp DESC
             LIMIT 1",
            [$carId]
        )->first();

        $this->assertIsObject($histAfterBouncedUpdate, 'Expected an UPDATE row in cars_hist');
        $this->assertSame(
            0,
            (int) $histAfterBouncedUpdate->email_bounced,
            'cars_hist UPDATE row must capture the pre-update (OLD) email_bounced value (0, not the new 1)'
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
     * the three verification columns' final values (OLD.*, same convention
     * as every other column in the cars_delete trigger).
     */
    #[Group('fast')]
    public function testDeleteTriggerCapturesFinalVerificationColumns(): void
    {
        $ownerLastUpdated = '2026-07-01 06:00:00';
        $vericodeSentAt   = '2026-07-02 07:00:00';

        $carId = $this->createTestCar($this->testUserId, [
            'owner_last_updated' => $ownerLastUpdated,
            'vericode_sent_at'   => $vericodeSentAt,
            'email_bounced'      => 1,
        ]);

        $car    = new Car($carId);
        $result = $car->delete('Test deletion for verification columns audit', $this->testUserId);

        $this->assertTrue($result, 'Car::delete() must return true on success');

        $histRow = $this->db->query(
            "SELECT owner_last_updated, vericode_sent_at, email_bounced
             FROM cars_hist
             WHERE car_id = ? AND operation = 'DELETE'
             ORDER BY timestamp DESC
             LIMIT 1",
            [$carId]
        )->first();

        $this->assertIsObject(
            $histRow,
            'Expected a DELETE row in cars_hist — check that the cars_delete trigger is present'
        );
        $this->assertSame(
            $ownerLastUpdated,
            (string) $histRow->owner_last_updated,
            'cars_hist DELETE row must capture the final owner_last_updated value'
        );
        $this->assertSame(
            $vericodeSentAt,
            (string) $histRow->vericode_sent_at,
            'cars_hist DELETE row must capture the final vericode_sent_at value'
        );
        $this->assertSame(
            1,
            (int) $histRow->email_bounced,
            'cars_hist DELETE row must capture the final email_bounced value'
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
