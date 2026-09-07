<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for the bounce-state columns added by issue #1887's
 * migration `20260907141816_add_car_bounce_state_columns`.
 *
 * That migration added two columns to `cars` (mirrored onto `cars_hist`) and
 * extended the cars_insert, cars_update, and cars_delete triggers to capture
 * them, exactly mirroring #1155's `add_car_verification_columns` migration:
 *
 * - `email_bounced_address` VARCHAR(155) NULL
 * - `email_suppressed`      TINYINT(1) NOT NULL DEFAULT 0
 *
 * This is real MySQL trigger behavior and cannot be verified with a mocked
 * DB — only a live database proves the trigger bodies actually capture these
 * columns on every INSERT, UPDATE, and DELETE. Follows
 * CarVerificationColumnsHistTest.php's exact style/structure for the sibling
 * migration.
 */
#[Group('integration')]
#[Group('car-verification')]
final class BrevoBounceStateColumnsHistTest extends IntegrationTestCase
{
    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        foreach (['email_bounced_address', 'email_suppressed'] as $column) {
            $this->assertColumnExists('cars', $column);
            $this->assertColumnExists('cars_hist', $column);
        }

        $this->testUserId = $this->createTestUser();
        $this->loginAsTestUser($this->testUserId);
    }

    /**
     * Skips the test (rather than failing with a DB error) if the given
     * column is not yet present — mirrors CarVerificationColumnsHistTest's
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
     * Schema assertion: cars.email_bounced_address is VARCHAR(155) NULL, no
     * default; cars.email_suppressed is a boolean-like column NOT NULL
     * DEFAULT 0. Same assertions apply to cars_hist.
     */
    #[Group('fast')]
    public function testColumnTypesAndDefaultsMatchSpec(): void
    {
        foreach (['cars', 'cars_hist'] as $table) {
            $addressColumn = $this->db->query(
                "SELECT IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH, COLUMN_DEFAULT
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'email_bounced_address'",
                [$table]
            )->first();

            $this->assertIsObject($addressColumn, "{$table}.email_bounced_address must exist");
            $this->assertSame('YES', $addressColumn->IS_NULLABLE, "{$table}.email_bounced_address must be nullable");
            $this->assertSame(155, (int) $addressColumn->CHARACTER_MAXIMUM_LENGTH, "{$table}.email_bounced_address must be varchar(155)");

            $suppressedColumn = $this->db->query(
                "SELECT IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE
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
     * INSERT: a new car row with both bounce-state columns populated must
     * produce a corresponding cars_hist INSERT row capturing those same
     * values. Deliberately inserts via raw SQL rather than createTestCar()
     * for the same reason as CarVerificationColumnsHistTest — that helper
     * purges pre-existing cars_hist rows for the new car ID immediately
     * after inserting, which would also delete the INSERT-trigger row this
     * test needs to inspect.
     */
    #[Group('fast')]
    public function testInsertTriggerCapturesBounceStateColumns(): void
    {
        $bouncedAddress = 'bounced-' . uniqid() . '@example.com';
        $chassis = 'BS' . substr(uniqid(), -10);

        $inserted = $this->db->insert('cars', [
            'user_id'                => $this->testUserId,
            'year'                   => 1973,
            'model'                  => 'Elan S4',
            'series'                 => 'S4',
            'variant'                => 'SE',
            'type'                   => 'FHC',
            'chassis'                => $chassis,
            'color'                  => 'Red',
            'ctime'                  => date('Y-m-d H:i:s'),
            'email_bounced_address'  => $bouncedAddress,
            'email_suppressed'       => 1,
        ]);
        $this->assertTrue($inserted, 'Failed to insert test car: ' . $this->db->errorString());

        $carId = (int) $this->db->lastId();
        $this->assertGreaterThan(0, $carId, 'Failed to get inserted car ID');
        $this->trackCarId($carId);

        $histRow = $this->db->query(
            "SELECT email_bounced_address, email_suppressed
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
            $bouncedAddress,
            (string) $histRow->email_bounced_address,
            'cars_hist INSERT row must capture email_bounced_address'
        );
        $this->assertSame(
            1,
            (int) $histRow->email_suppressed,
            'cars_hist INSERT row must capture email_suppressed'
        );
    }

    /**
     * UPDATE: the cars_update trigger uses OLD.* for these two columns (same
     * convention as email_bounced and every other column except
     * chassis_override).
     */
    #[Group('fast')]
    public function testUpdateTriggerCapturesPreUpdateOldValues(): void
    {
        $originalAddress = 'original-' . uniqid() . '@example.com';

        $carId = $this->createTestCar($this->testUserId, [
            'email_bounced_address' => $originalAddress,
            'email_suppressed'      => 0,
        ]);

        $repo = new CarRepository($this->db);

        // --- email_bounced_address (via updateEmailBounced) -----------------
        $this->assertTrue(
            $repo->updateEmailBounced($carId, true, 'new-' . uniqid() . '@example.com'),
            'updateEmailBounced() must succeed'
        );

        $histAfterBounceUpdate = $this->db->query(
            "SELECT email_bounced_address
             FROM cars_hist
             WHERE car_id = ? AND operation = 'UPDATE'
             ORDER BY timestamp DESC
             LIMIT 1",
            [$carId]
        )->first();

        $this->assertIsObject($histAfterBounceUpdate, 'Expected an UPDATE row in cars_hist');
        $this->assertSame(
            $originalAddress,
            (string) $histAfterBounceUpdate->email_bounced_address,
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
             ORDER BY timestamp DESC
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
     * DELETE: deleting a car must produce a cars_hist DELETE row capturing
     * the two bounce-state columns' final values (OLD.*).
     */
    #[Group('fast')]
    public function testDeleteTriggerCapturesFinalBounceStateColumns(): void
    {
        $finalAddress = 'final-' . uniqid() . '@example.com';

        $carId = $this->createTestCar($this->testUserId, [
            'email_bounced_address' => $finalAddress,
            'email_suppressed'      => 1,
        ]);

        $car    = new Car($carId);
        $result = $car->delete('Test deletion for bounce-state columns audit', $this->testUserId);

        $this->assertTrue($result, 'Car::delete() must return true on success');

        $histRow = $this->db->query(
            "SELECT email_bounced_address, email_suppressed
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
            $finalAddress,
            (string) $histRow->email_bounced_address,
            'cars_hist DELETE row must capture the final email_bounced_address value'
        );
        $this->assertSame(
            1,
            (int) $histRow->email_suppressed,
            'cars_hist DELETE row must capture the final email_suppressed value'
        );
    }
}
