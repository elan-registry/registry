<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * Test cases for Car merge functionality
 *
 * Tests cover car merging operations with history transfer, deletion,
 * transaction handling, and validation.
 *
 * @group integration
 */
final class CarMergeTest extends IntegrationTestCase
{
    private $testCarId;
    private $testMergeCarId;
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->testCarId = 1;
        $this->testMergeCarId = 2;
        $this->db = DB::getInstance();
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }

    /**
     * Test successful car merge with valid source car
     *
     * @group fast
     */
    public function testMergeCarSuccessWithValidOldCar(): void
    {
        $car = new Car($this->testCarId);
        $oldCar = new Car($this->testMergeCarId);

        $oldCarId = $oldCar->data()->id;
        $this->assertTrue($oldCar->exists());

        $result = $car->merge($oldCarId, 'Test merge');

        $this->assertTrue($result);
        // Verify old car was deleted
        $deletedCar = new Car($oldCarId);
        $this->assertFalse($deletedCar->exists());
    }

    /**
     * Test car merge fails when target car does not exist
     *
     * @group fast
     */
    public function testMergeCarFailsWhenTargetNotExists(): void
    {
        $this->expectException(Exception::class);

        $car = new Car(99999);
        $car->merge($this->testMergeCarId, 'Test merge');
    }

    /**
     * Test car merge fails when source car does not exist
     *
     * @group fast
     */
    public function testMergeCarFailsWhenSourceNotExists(): void
    {
        $this->expectException(Exception::class);

        $car = new Car($this->testCarId);
        $car->merge(99999, 'Test merge');
    }

    /**
     * Test car merge fails when merging car with itself
     *
     * @group fast
     */
    public function testMergeCarFailsWhenMergingSelf(): void
    {
        $this->expectException(Exception::class);

        $car = new Car($this->testCarId);
        $car->merge($this->testCarId, 'Test merge');
    }

    /**
     * Test car merge transfers history records
     *
     * @group fast
     */
    public function testMergeTransfersHistoryRecords(): void
    {
        $car = new Car($this->testCarId);
        // Ensure old car exists for this test
        $oldCar = new Car($this->testMergeCarId);
        $oldCarId = $this->testMergeCarId;

        // Create a history record for the old car to verify transfer
        $historyFields = [
            'car_id' => $oldCarId,
            'operation' => 'CREATE',
            'comments' => 'Test history',
            'ctime' => date('Y-m-d H:i:s'),
            'mtime' => date('Y-m-d H:i:s')
        ];
        $this->db->insert('cars_hist', $historyFields);

        $result = $car->merge($oldCarId, 'Test merge with history');

        $this->assertTrue($result);

        // Verify history records were transferred to new car
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND comments = 'Test history'",
            [$car->data()->id]
        );
        $this->assertTrue($historyQuery->count() > 0);
    }

    /**
     * Test car merge deletes old car
     *
     * @group fast
     */
    public function testMergeDeletesOldCar(): void
    {
        $car = new Car($this->testCarId);
        $oldCarId = $this->testMergeCarId;

        // Verify old car exists
        $oldCar = new Car($oldCarId);
        $this->assertTrue($oldCar->exists());

        $result = $car->merge($oldCarId, 'Test merge deletion');

        $this->assertTrue($result);

        // Verify old car was deleted
        $deletedCar = new Car($oldCarId);
        $this->assertFalse($deletedCar->exists());
    }

    /**
     * Test car merge creates audit trail
     *
     * @group fast
     */
    public function testMergeCreatesAuditTrail(): void
    {
        $car = new Car($this->testCarId);
        $carId = $car->data()->id;
        $oldCarId = $this->testMergeCarId;

        $result = $car->merge($oldCarId, 'Test merge audit');

        $this->assertTrue($result);

        // Check that merge history record was created
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'MERGE'",
            [$carId]
        );
        $this->assertTrue($historyQuery->count() > 0);
    }

    /**
     * Test car merge transaction rollback on failure
     *
     * @group fast
     */
    public function testMergeTransactionRollbackOnFailure(): void
    {
        $this->expectException(Exception::class);

        $car = new Car($this->testCarId);

        try {
            // Attempt to merge non-existent car
            $car->merge(99999, 'Test merge');
        } catch (Exception $e) {
            // After failed merge, original car should still exist
            $carReloaded = new Car($car->data()->id);
            $this->assertTrue($carReloaded->exists());
            throw $e;
        }
    }

    /**
     * Test car merge removes old car relationships
     *
     * @group fast
     */
    public function testMergeRemovesOldCarRelationships(): void
    {
        $car = new Car($this->testCarId);
        $oldCarId = $this->testMergeCarId;

        // Verify old car has relationships
        $relationQuery = $this->db->query(
            "SELECT * FROM car_user WHERE car_id = ?",
            [$oldCarId]
        );
        $this->assertTrue($relationQuery->count() > 0);

        $result = $car->merge($oldCarId, 'Test merge relationships');

        $this->assertTrue($result);

        // Verify relationships were removed
        $relationQuery = $this->db->query(
            "SELECT * FROM car_user WHERE car_id = ?",
            [$oldCarId]
        );
        $this->assertEquals(0, $relationQuery->count());
    }

    /**
     * Test car merge requires authenticated user
     *
     * @group fast
     */
    public function testMergeRequiresAuthenticatedUser(): void
    {
        $this->expectException(CarMergeException::class);

        $car = new Car($this->testCarId);

        // Mock: Unset global user to simulate no authentication
        global $user;
        $originalUser = $user ?? null;
        unset($GLOBALS['user']);

        try {
            $car->merge($this->testMergeCarId, 'Test merge');
        } finally {
            // Restore user
            if ($originalUser) {
                $GLOBALS['user'] = $originalUser;
            }
        }
    }
}
