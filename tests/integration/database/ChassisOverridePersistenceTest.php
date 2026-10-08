<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use PHPUnit\Framework\Attributes\Group;

/**
 * chassis_override persistence and cars_hist capture (#915). Needs the column
 * in `cars` and `cars_hist`; run `composer migrate` if it is missing.
 */
#[Group('integration')]
#[Group('chassis-override')]
final class ChassisOverridePersistenceTest extends IntegrationTestCase
{
    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Skip, rather than fail with a DB error, when the column is absent.
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
                'chassis_override column not yet available — run `composer migrate`'
            );
        }

        $this->testUserId = $this->createTestUser();

        $this->loginAsTestUser($this->testUserId);
    }

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
     * Integer 0 passes update()'s array_filter, which strips '' and null.
     */
    #[Group('integration')]
    #[Group('chassis-override')]
    public function testUpdateCarClearsChassisOverride(): void
    {
        $carId = $this->createTestCar($this->testUserId, ['chassis_override' => 1]);

        $before = $this->db->query(
            'SELECT chassis_override FROM cars WHERE id = ?',
            [$carId]
        )->first();
        $this->assertSame(1, (int) $before->chassis_override, 'Pre-condition: chassis_override must be 1 before clear');

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

    #[Group('integration')]
    #[Group('chassis-override')]
    public function testCarsHistTriggerCapturesChassisOverride(): void
    {
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
                'chassis_override column not present in cars_hist — run `composer migrate`'
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
