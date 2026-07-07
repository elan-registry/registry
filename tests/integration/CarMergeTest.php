<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarPermissionException;

use PHPUnit\Framework\Attributes\Group;

/**
 * Test cases for Car merge functionality
 *
 * Tests cover car merging operations with history transfer, deletion,
 * transaction handling, and validation.
 */
#[Group('integration')]
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

        // Bypass login() to set the private $_isLoggedIn flag directly via reflection.
        // setAccessible() is intentionally omitted — it is a no-op since PHP 8.1.
        $reflection = new ReflectionClass($user);
        $isLoggedInProperty = $reflection->getProperty('_isLoggedIn');
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
        parent::tearDown();
    }

    /**
     * Test successful car merge with valid source car
     */
    #[Group('fast')]
    public function testMergeCarSuccessWithValidOldCar(): void
    {
        $car = new Car($this->testCarId);
        $result = $car->merge($this->testMergeCarId, 'Test merge success');

        $this->assertTrue($result);
    }

    /**
     * Test car merge fails when target car does not exist
     */
    #[Group('fast')]
    public function testMergeCarFailsWhenTargetNotExists(): void
    {
        $this->expectException(Exception::class);

        $car = new Car(99999);
        $car->merge($this->testMergeCarId, 'Test merge');
    }

    /**
     * Test car merge fails when source car does not exist
     */
    #[Group('fast')]
    public function testMergeCarFailsWhenSourceNotExists(): void
    {
        $this->expectException(Exception::class);

        $car = new Car($this->testCarId);
        $car->merge(99999, 'Test merge');
    }

    /**
     * Test car merge fails when merging car with itself
     */
    #[Group('fast')]
    public function testMergeCarFailsWhenMergingSelf(): void
    {
        $this->expectException(Exception::class);

        $car = new Car($this->testCarId);
        $car->merge($this->testCarId, 'Test merge');
    }

    /**
     * Test car merge transfers history records
     */
    #[Group('fast')]
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
     */
    #[Group('fast')]
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
     */
    #[Group('fast')]
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
     */
    #[Group('fast')]
    public function testMergeTransactionRollbackOnFailure(): void
    {
        $this->expectException(Exception::class);

        $car = new Car($this->testCarId);

        try {
            // Attempt to merge non-existent car
            $car->merge(99999, 'Test merge');
        } catch (Exception $e) {
            // After failed merge, original car should still exist
            $carReloaded = new Car((int) $car->data()->id);
            $this->assertTrue($carReloaded->exists());
            throw $e;
        }
    }

    /**
     * Test car merge removes old car relationships
     */
    #[Group('fast')]
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
     */
    #[Group('fast')]
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

    /**
     * Test that CarRepository::rollback() reverts all three merge steps when the
     * admin page aborts a car merge midway through.
     *
     * WHY THIS TEST EXISTS: The admin car-merge page performs three DB steps inside a
     * transaction — (1) transferHistory, (2) deleteCarUser, (3) deleteCar.  If a
     * failure occurs after steps 1 and 2 but before step 3, rollback() must undo all
     * completed steps so the database is left in a consistent state.  This test
     * simulates that scenario by executing steps 1 and 2, then calling rollback()
     * instead of proceeding to step 3, and asserting full state recovery.
     */
    #[Group('fast')]
    public function testCarRepositoryTransactionRollbackPreservesCarAndOwnerAssignment(): void
    {
        if ($this->testCarId === null) {
            $this->markTestSkipped('No test cars available');
        }

        // Seed a cars_hist row so transferHistory() has a real UPDATE to roll back.
        // createTestCar() purges any stale hist rows, so the table starts empty.
        $carRow = $this->db->query(
            'SELECT * FROM cars WHERE id = ?',
            [$this->testCarId]
        )->first();

        $histSeeded = $this->db->insert('cars_hist', [
            'car_id'    => $this->testCarId,
            'operation' => 'TEST',
            'model'     => $carRow->model,
            'series'    => $carRow->series,
            'variant'   => $carRow->variant,
            'year'      => $carRow->year,
            'type'      => $carRow->type,
            'chassis'   => $carRow->chassis,
        ]);
        $this->assertTrue($histSeeded, 'Precondition: should be able to seed a cars_hist row');

        // Snapshot counts before the transaction
        $carExistsBefore = $this->db->query(
            'SELECT id FROM cars WHERE id = ?',
            [$this->testCarId]
        )->count();

        $carUserCountBefore = $this->db->query(
            'SELECT * FROM car_user WHERE car_id = ?',
            [$this->testCarId]
        )->count();

        $histCountBefore = $this->db->query(
            'SELECT * FROM cars_hist WHERE car_id = ?',
            [$this->testCarId]
        )->count();

        $this->assertGreaterThan(0, $carExistsBefore, 'Precondition: test car must exist');
        $this->assertGreaterThan(0, $carUserCountBefore, 'Precondition: car_user row must exist');
        $this->assertGreaterThan(0, $histCountBefore, 'Precondition: cars_hist row must exist');

        // Simulate a mid-merge abort: steps 1 and 2 run, but step 3 (deleteCar) never fires
        $repo = new CarRepository($this->db);
        $repo->beginTransaction();
        $this->assertTrue(
            $repo->transferHistory($this->testCarId, $this->testMergeCarId),
            'Precondition: transferHistory must succeed within transaction'
        );
        // Mid-transaction: hist rows must now point to the merge target (visible within same connection)
        $histMid = $this->db->query(
            'SELECT * FROM cars_hist WHERE car_id = ?',
            [$this->testMergeCarId]
        )->count();
        $this->assertGreaterThan(0, $histMid, 'mid-transaction: transferHistory must have moved hist rows to merge target');

        $this->assertTrue(
            $repo->deleteCarUser($this->testCarId),
            'Precondition: deleteCarUser must succeed within transaction'
        );
        // Mid-transaction: car_user rows must be gone (visible within same connection)
        $carUserMid = $this->db->query(
            'SELECT * FROM car_user WHERE car_id = ?',
            [$this->testCarId]
        )->count();
        $this->assertEquals(0, $carUserMid, 'mid-transaction: deleteCarUser must have removed car_user rows');

        $repo->rollback();

        // Assertions: every in-transaction change must be fully reverted

        // 1. The cars row must still exist (was never touched, but confirms no side-effects)
        $carExistsAfter = $this->db->query(
            'SELECT id FROM cars WHERE id = ?',
            [$this->testCarId]
        )->count();
        $this->assertEquals(
            $carExistsBefore,
            $carExistsAfter,
            'cars row must survive rollback'
        );

        // 2. The car_user assignment must be restored (deleteCarUser was rolled back)
        $carUserCountAfter = $this->db->query(
            'SELECT * FROM car_user WHERE car_id = ?',
            [$this->testCarId]
        )->count();
        $this->assertEquals(
            $carUserCountBefore,
            $carUserCountAfter,
            'car_user rows must be restored after rollback'
        );

        // 3. The cars_hist rows must still belong to testCarId (transferHistory UPDATE was rolled back)
        $histCountAfter = $this->db->query(
            'SELECT * FROM cars_hist WHERE car_id = ?',
            [$this->testCarId]
        )->count();
        $this->assertEquals(
            $histCountBefore,
            $histCountAfter,
            'cars_hist rows must remain on testCarId after rollback'
        );
    }
}
