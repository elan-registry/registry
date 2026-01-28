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

        // Set up authenticated user context for merge operations
        global $user;
        $user = new User();
        $user->find(1);  // Load user ID 1

        // Manually set the private $_isLoggedIn property to true using reflection
        $reflection = new ReflectionClass($user);
        $isLoggedInProperty = $reflection->getProperty('_isLoggedIn');
        $isLoggedInProperty->setAccessible(true);
        $isLoggedInProperty->setValue($user, true);

        $GLOBALS['user'] = $user;

        $this->db = DB::getInstance();

        // Create unique test cars for this test
        try {
            $this->testCarId = $this->createTestCar(1, [
                'chassis' => 'MG' . uniqid()
            ]);
            $this->testMergeCarId = $this->createTestCar(1, [
                'chassis' => 'MG' . uniqid()
            ]);
        } catch (RuntimeException $e) {
            $this->markTestSkipped('Could not create test cars: ' . $e->getMessage());
        }

        // Ensure car_user relationships exist
        $this->db->insert('car_user', [
            'car_id' => $this->testCarId,
            'userid' => 1
        ]);

        $this->db->insert('car_user', [
            'car_id' => $this->testMergeCarId,
            'userid' => 1
        ]);
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
        $result = $car->merge($this->testMergeCarId, 'Test merge success');

        $this->assertTrue($result);
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
        $result = $car->merge($this->testMergeCarId, 'Test merge history transfer');

        $this->assertTrue($result);

        // Verify history records were transferred to surviving car
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'MERGE'",
            [$this->testCarId]
        );
        $this->assertGreaterThan(0, $historyQuery->count());
    }

    /**
     * Test car merge deletes old car
     *
     * @group fast
     */
    public function testMergeDeletesOldCar(): void
    {
        $oldCarId = $this->testMergeCarId;

        $car = new Car($this->testCarId);
        $result = $car->merge($oldCarId, 'Test merge deletes old car');

        $this->assertTrue($result);

        // Verify old car no longer exists
        $query = $this->db->query('SELECT * FROM cars WHERE id = ?', [$oldCarId]);
        $this->assertEquals(0, $query->count());
    }

    /**
     * Test car merge creates audit trail
     *
     * @group fast
     */
    public function testMergeCreatesAuditTrail(): void
    {
        $car = new Car($this->testCarId);
        $result = $car->merge($this->testMergeCarId, 'Test merge audit trail');

        $this->assertTrue($result);

        // Verify audit trail record exists with MERGE operation
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'MERGE'",
            [$this->testCarId]
        );
        $this->assertGreaterThan(0, $historyQuery->count());
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
        $oldCarId = $this->testMergeCarId;

        $car = new Car($this->testCarId);
        $result = $car->merge($oldCarId, 'Test merge removes relationships');

        $this->assertTrue($result);

        // Verify old car's relationships were removed
        $query = $this->db->query('SELECT * FROM car_user WHERE car_id = ?', [$oldCarId]);
        $this->assertEquals(0, $query->count());
    }

    /**
     * Test car merge requires authenticated user
     *
     * @group fast
     */
    public function testMergeRequiresAuthenticatedUser(): void
    {
        $this->expectException(CarPermissionException::class);

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
