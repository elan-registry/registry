<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for chassis_override flag persistence (issue #915)
 *
 * Verifies that:
 * - New cars default chassis_override to 0
 * - Car::update() persists chassis_override = 1
 * - Car::update() clears chassis_override back to 0
 * - The cars_update DB trigger captures chassis_override into cars_hist
 *
 * Requires the chassis_override column to be present in both the `cars` and
 * `cars_hist` tables. Run fix script 07-Chassis-Override-Schema-Backfill.php
 * first if the column is missing.
 */
#[Group('integration')]
#[Group('chassis-override')]
final class ChassisOverridePersistenceTest extends IntegrationTestCase
{
    private int $testUserId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Verify the chassis_override column exists before running any test.
        // If the fix script has not been run the column will be absent and all
        // four tests should be skipped rather than failing with a DB error.
        $columnCheck = $this->db->query(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cars'
               AND COLUMN_NAME  = 'chassis_override'
             LIMIT 1"
        );

        if (!$columnCheck || $columnCheck->count() === 0) {
            $this->markTestSkipped(
                'chassis_override column not yet available — run fix script 07-Chassis-Override-Schema-Backfill.php'
            );
        }

        // Set up an authenticated user context (mirrors CarDatabaseOperationsTest pattern)
        global $user;
        $user = new User();
        $user->find($this->testUserId);

        $reflection        = new ReflectionClass($user);
        $isLoggedInProp    = $reflection->getProperty('_isLoggedIn');
        $isLoggedInProp->setAccessible(true);
        $isLoggedInProp->setValue($user, true);

        $GLOBALS['user'] = $user;
    }

    /**
     * Cars created without specifying chassis_override must default to 0 in the DB.
     */
    #[Group('integration')]
    #[Group('chassis-override')]
    public function testCreateCarDefaultsChassisOverrideToZero(): void
    {
        $carId = $this->createTestCar($this->testUserId);

        $row = $this->db->query(
            'SELECT chassis_override FROM cars WHERE id = ?',
            [$carId]
        )->first();

        $this->assertNotNull($row, 'Expected a cars row for the newly created test car');
        $this->assertEqualsWithDelta(
            0,
            (int) $row->chassis_override,
            0,
            'chassis_override must default to 0 when not specified at creation time'
        );
    }

    /**
     * Calling Car::update() with chassis_override = 1 must persist that value.
     */
    #[Group('integration')]
    #[Group('chassis-override')]
    public function testUpdateCarPersistsChassisOverrideOne(): void
    {
        $carId = $this->createTestCar($this->testUserId);

        $car = new Car($carId);
        $result = $car->update([
            'id'               => $carId,
            'user_id'          => $this->testUserId,
            'token'            => Token::generate(),
            'chassis_override' => 1,
        ]);

        $this->assertTrue($result, 'Car::update() must return true on success');

        $row = $this->db->query(
            'SELECT chassis_override FROM cars WHERE id = ?',
            [$carId]
        )->first();

        $this->assertNotNull($row, 'Expected a cars row after update');
        $this->assertSame(
            1,
            (int) $row->chassis_override,
            'chassis_override must be 1 after update with chassis_override = 1'
        );
    }

    /**
     * Calling Car::update() with chassis_override = 0 after it was 1 must clear the flag.
     *
     * Note: Car::update() strips fields with empty-string or null values via array_filter,
     * but the integer 0 passes that filter, so chassis_override = 0 is persisted correctly.
     */
    #[Group('integration')]
    #[Group('chassis-override')]
    public function testUpdateCarClearsChassisOverride(): void
    {
        // Create car with chassis_override already set to 1
        $carId = $this->createTestCar($this->testUserId, ['chassis_override' => 1]);

        // Verify the starting state is actually 1
        $before = $this->db->query(
            'SELECT chassis_override FROM cars WHERE id = ?',
            [$carId]
        )->first();
        $this->assertSame(1, (int) $before->chassis_override, 'Pre-condition: chassis_override must be 1 before clear');

        // Now clear it
        $car    = new Car($carId);
        $result = $car->update([
            'id'               => $carId,
            'user_id'          => $this->testUserId,
            'token'            => Token::generate(),
            'chassis_override' => 0,
        ]);

        $this->assertTrue($result, 'Car::update() must return true on success');

        $after = $this->db->query(
            'SELECT chassis_override FROM cars WHERE id = ?',
            [$carId]
        )->first();

        $this->assertNotNull($after, 'Expected a cars row after update');
        $this->assertSame(
            0,
            (int) $after->chassis_override,
            'chassis_override must be 0 after update with chassis_override = 0'
        );
    }

    /**
     * The cars_update trigger must capture chassis_override into cars_hist.
     *
     * Performs one UPDATE that sets chassis_override = 1, then reads the most
     * recent UPDATE row from cars_hist and asserts the captured value is 1.
     */
    #[Group('integration')]
    #[Group('chassis-override')]
    public function testCarsHistTriggerCapturesChassisOverride(): void
    {
        // Verify the chassis_override column also exists in cars_hist
        $histColCheck = $this->db->query(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cars_hist'
               AND COLUMN_NAME  = 'chassis_override'
             LIMIT 1"
        );

        if (!$histColCheck || $histColCheck->count() === 0) {
            $this->markTestSkipped(
                'chassis_override column not present in cars_hist — run fix script 07-Chassis-Override-Schema-Backfill.php'
            );
        }

        $carId = $this->createTestCar($this->testUserId);

        $car    = new Car($carId);
        $result = $car->update([
            'id'               => $carId,
            'user_id'          => $this->testUserId,
            'token'            => Token::generate(),
            'chassis_override' => 1,
        ]);

        $this->assertTrue($result, 'Car::update() must return true on success');

        $histRow = $this->db->query(
            "SELECT chassis_override
             FROM cars_hist
             WHERE car_id   = ?
               AND operation = 'UPDATE'
             ORDER BY timestamp DESC
             LIMIT 1",
            [$carId]
        )->first();

        $this->assertNotNull(
            $histRow,
            'Expected an UPDATE row in cars_hist after Car::update() — check that the cars_update trigger is present'
        );
        $this->assertSame(
            1,
            (int) $histRow->chassis_override,
            'cars_hist must capture chassis_override = 1 from the UPDATE trigger'
        );
    }
}
