<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1155, #1887: the verification and bounce-state columns on `cars` and their
 * capture in `cars_hist` by the cars_insert/update/delete triggers. Trigger
 * behavior needs a live database. In cars_update these columns use OLD.*;
 * only chassis_override uses NEW.*.
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

    /** Skips, rather than fails, when the migration has not run. */
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
     * [column, value written to cars, cast for the cars_hist comparison]
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
     * Raw SQL, not createTestCar(): that helper purges cars_hist rows for the
     * new ID, which would delete the INSERT-trigger row under test.
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
     * Each UPDATE must write the PRE-update value to cars_hist (OLD.*).
     * One sequential method: each block reads the newest UPDATE row, ordered
     * by `timestamp DESC, id DESC` because `timestamp` has whole-second
     * resolution.
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
        // Via updateCar(): the standalone setter was removed as dead code (#1930).
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
        // One block: updateEmailBounced() writes both columns in one UPDATE.
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
     * An owner-initiated Car::update() must change owner_last_updated in the
     * SAME statement as the other fields, so one edit gives one cars_hist row.
     * One row with both OLD values proves a single statement.
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
     * DELETE: the cars_hist DELETE row captures the final value (OLD.*).
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
     * The @disable_triggers guard around the migration's backfill UPDATE must
     * suppress the spurious cars_hist 'UPDATE' row. owner_last_updated is now
     * NOT NULL, so the guarded UPDATE runs off a stale sentinel value instead.
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
