<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * Integration tests for Car database operations
 *
 * Tests actual database operations with real connections, transactions,
 * triggers, and audit trails. These tests verify that the database layer
 * works correctly with the Car class.
 *
 * Uses real database fixtures (user ID 1, car IDs 1-2).
 *
 * @group integration
 */
final class CarDatabaseOperationsTest extends IntegrationTestCase
{
    private $testCarId;
    private $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Set up authenticated user context for tests
        global $user;
        $user = new User();
        $user->find(1);  // Load user ID 1

        // Manually set the private $_isLoggedIn property to true using reflection
        $reflection = new ReflectionClass($user);
        $isLoggedInProperty = $reflection->getProperty('_isLoggedIn');
        $isLoggedInProperty->setAccessible(true);
        $isLoggedInProperty->setValue($user, true);

        $GLOBALS['user'] = $user;

        // Ensure car_user relationship exists for test car
        // Car ID 1 needs a relationship to user ID 1 for some tests
        if (!$this->db->query('SELECT * FROM car_user WHERE car_id = 1 AND userid = 1')->count()) {
            $this->db->insert('car_user', [
                'car_id' => 1,
                'userid' => 1
            ]);
        }

        $this->testCarId = 1;
        $this->testUserId = 1;
    }

    protected function tearDown(): void
    {
        // Clean up any test-specific data if needed
        // Keep main test fixtures intact for other tests
    }

    /**
     * Test car creation persists to database
     *
     * @group integration
     */
    public function testCarCreationPersistsToDatabases(): void
    {
        $carData = [
            'token' => Token::generate(),
            'user_id' => $this->testUserId,
            'year' => '1973',
            'model' => 'Elan S4',
            'series' => 'S4',
            'variant' => 'SE',
            'type' => 'FHC',
            'chassis' => 'TST' . substr(uniqid(), -9),  // Keep within 15 char limit
            'color' => 'Integration Red',
            'engine' => 'ENG' . substr(uniqid(), -9)  // Keep within 15 char limit
        ];

        $car = new Car();
        $result = $car->create($carData);

        $this->assertTrue($result);
        $createdId = $car->data()->id;

        // Verify data was persisted
        $query = $this->db->query('SELECT * FROM cars WHERE id = ?', [$createdId]);
        $this->assertGreaterThan(0, $query->count());

        $persistedCar = $query->first();
        $this->assertEquals('Integration Red', $persistedCar->color);
        $this->assertEquals($carData['chassis'], $persistedCar->chassis);
    }

    /**
     * Test car update persists to database
     *
     * @group integration
     */
    public function testCarUpdatePersiststoDatabase(): void
    {
        $car = new Car($this->testCarId);
        $originalYear = $car->data()->year;

        $updateData = [
            'id' => $this->testCarId,
            'token' => Token::generate(),
            'year' => '1973',
            'color' => 'Integration Blue'
        ];

        $result = $car->update($updateData);

        $this->assertTrue($result);

        // Verify data was persisted
        $query = $this->db->query('SELECT * FROM cars WHERE id = ?', [$this->testCarId]);
        $updatedCar = $query->first();

        $this->assertEquals('1973', $updatedCar->year);
        $this->assertEquals('Integration Blue', $updatedCar->color);
    }

    /**
     * Test car deletion removes from database
     *
     * @group integration
     */
    public function testCarDeletionRemovesFromDatabase(): void
    {
        // Create a car specifically for deletion testing
        $carData = [
            'token' => Token::generate(),
            'user_id' => $this->testUserId,
            'year' => '1973',
            'model' => 'Elan',
            'series' => 'S4',
            'variant' => 'SE',
            'type' => 'FHC',
            'chassis' => 'DEL' . substr(uniqid(), -9),  // Keep within 15 char limit
            'color' => 'Red'
        ];

        $car = new Car();
        $car->create($carData);
        $carId = $car->data()->id;

        // Verify car exists
        $query = $this->db->query('SELECT * FROM cars WHERE id = ?', [$carId]);
        $this->assertGreaterThan(0, $query->count());

        // Delete the car
        $token = Token::generate();
        $result = $car->delete('Integration test deletion', $token);

        $this->assertTrue($result);

        // Verify car was deleted
        $query = $this->db->query('SELECT * FROM cars WHERE id = ?', [$carId]);
        $this->assertEquals(0, $query->count());
    }

    /**
     * Test car deletion creates audit trail
     *
     * @group integration
     */
    public function testCarDeletionCreatesAuditTrail(): void
    {
        // Create a car for deletion with audit verification
        $carData = [
            'token' => Token::generate(),
            'user_id' => $this->testUserId,
            'year' => '1971',
            'model' => 'Elan',
            'series' => 'S4',
            'variant' => 'SE',
            'type' => 'FHC',
            'chassis' => 'AUD' . substr(uniqid(), -9),  // Keep within 15 char limit
            'color' => 'Red'
        ];

        $car = new Car();
        $car->create($carData);
        $carId = $car->data()->id;

        // Delete with reason for audit trail
        $token = Token::generate();
        $result = $car->delete('Integration test audit trail', $token);

        $this->assertTrue($result);

        // Verify audit trail entry was created
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'DELETE'",
            [$carId]
        );
        $this->assertGreaterThan(0, $historyQuery->count());

        $historyEntry = $historyQuery->first();
        $this->assertStringContainsString('Integration test audit trail', $historyEntry->comments);
    }

    /**
     * Test car transfer updates relationships
     *
     * @group integration
     */
    public function testCarTransferUpdatesRelationships(): void
    {
        $car = new Car($this->testCarId);
        $targetUserId = 10;  // Use existing user ID (FredHansen)

        // Verify original relationship
        $origRelation = $this->db->query(
            'SELECT * FROM car_user WHERE car_id = ? AND userid = ?',
            [$this->testCarId, $this->testUserId]
        );
        $this->assertGreaterThan(0, $origRelation->count());

        // Transfer car
        $result = $car->transfer($targetUserId, 'Integration test transfer', 'NEWOWNER');

        $this->assertTrue($result);

        // Verify relationship was updated
        $newRelation = $this->db->query(
            'SELECT * FROM car_user WHERE car_id = ? AND userid = ?',
            [$this->testCarId, $targetUserId]
        );
        $this->assertGreaterThan(0, $newRelation->count());
    }

    /**
     * Test car transfer creates history record
     *
     * @group integration
     */
    public function testCarTransferCreatesHistoryRecord(): void
    {
        $car = new Car($this->testCarId);

        // Transfer car to existing user ID 10 (FredHansen)
        $result = $car->transfer(10, 'Integration test transfer history', 'NEWOWNER');

        $this->assertTrue($result);

        // Verify history record exists
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'NEWOWNER'",
            [$this->testCarId]
        );
        $this->assertGreaterThan(0, $historyQuery->count());
    }

    /**
     * Test car merge transfers history records
     *
     * @group integration
     */
    public function testCarMergeTransfersHistoryRecords(): void
    {
        // Create source car for merge
        $sourceCarData = [
            'token' => Token::generate(),
            'user_id' => $this->testUserId,
            'year' => '1969',
            'model' => 'Elan',
            'series' => 'S4',
            'variant' => 'SE',
            'type' => 'FHC',
            'chassis' => 'SRC' . substr(uniqid(), -9),  // Keep within 15 char limit
            'color' => 'Red'
        ];

        $sourceCar = new Car();
        $sourceCar->create($sourceCarData);
        $sourceCarId = $sourceCar->data()->id;

        // Create target car for merge
        $targetCarData = [
            'token' => Token::generate(),
            'user_id' => $this->testUserId,
            'year' => '1970',
            'model' => 'Elan',
            'series' => 'S4',
            'variant' => 'SE',
            'type' => 'FHC',
            'chassis' => 'TGT' . substr(uniqid(), -9),  // Keep within 15 char limit
            'color' => 'Blue'
        ];

        $targetCar = new Car();
        $targetCar->create($targetCarData);
        $targetCarId = $targetCar->data()->id;

        // Perform merge
        $result = $targetCar->merge($sourceCarId, 'Integration test merge');

        $this->assertTrue($result);

        // Verify source car was deleted
        $sourceQuery = $this->db->query('SELECT * FROM cars WHERE id = ?', [$sourceCarId]);
        $this->assertEquals(0, $sourceQuery->count());

        // Verify merge audit trail exists
        $mergeQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'MERGE'",
            [$targetCarId]
        );
        $this->assertGreaterThan(0, $mergeQuery->count());
    }

    /**
     * Test database trigger creates update history on car update
     *
     * @group integration
     */
    public function testDatabaseTriggerCreatesUpdateHistory(): void
    {
        $car = new Car($this->testCarId);
        $originalColor = $car->data()->color;

        // Count existing history entries
        $beforeQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'UPDATE'",
            [$this->testCarId]
        );
        $beforeCount = $beforeQuery->count();

        // Update car
        $updateData = [
            'id' => $this->testCarId,
            'token' => Token::generate(),
            'color' => 'Trigger Test Color'
        ];

        $result = $car->update($updateData);

        $this->assertTrue($result);

        // Verify trigger created history entry
        $afterQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'UPDATE'",
            [$this->testCarId]
        );
        $afterCount = $afterQuery->count();

        // Should have created new history record
        $this->assertGreaterThan($beforeCount, $afterCount);
    }

    /**
     * Test car-user relationship integrity
     *
     * @group integration
     */
    public function testCarUserRelationshipIntegrity(): void
    {
        // Verify car has valid user relationship
        $relationQuery = $this->db->query(
            'SELECT cu.*, u.id FROM car_user cu JOIN users u ON cu.userid = u.id WHERE cu.car_id = ?',
            [$this->testCarId]
        );

        $this->assertGreaterThan(0, $relationQuery->count());

        $relation = $relationQuery->first();
        $this->assertNotNull($relation->userid);
        $this->assertNotNull($relation->car_id);
        $this->assertEquals($this->testCarId, $relation->car_id);
    }

    /**
     * Test verification code is set and retrieved
     *
     * @group integration
     */
    public function testVerificationCodeSetAndRetrieved(): void
    {
        $car = new Car($this->testCarId);
        $verificationCode = 'INT-VERIFY-' . uniqid();

        $result = $car->setVerificationCode($verificationCode);

        $this->assertTrue($result);

        // Retrieve from database
        $query = $this->db->query(
            'SELECT verification_code FROM cars WHERE id = ?',
            [$this->testCarId]
        );
        $result = $query->first();

        $this->assertEquals($verificationCode, $result->verification_code);
    }

    /**
     * Test mark sold updates database
     *
     * @group integration
     */
    public function testMarkSoldUpdatesDatabase(): void
    {
        // Create a test car for sold marking
        $carData = [
            'token' => Token::generate(),
            'user_id' => $this->testUserId,
            'year' => '1968',
            'model' => 'Elan',
            'series' => 'S4',
            'variant' => 'SE',
            'type' => 'FHC',
            'chassis' => 'SLD' . substr(uniqid(), -9),  // Keep within 15 char limit
            'color' => 'Red'
        ];

        $car = new Car();
        $car->create($carData);
        $carId = $car->data()->id;

        // Mark as sold
        $soldDate = '2023-06-15';
        $result = $car->markSold($soldDate);

        $this->assertTrue($result);

        // Verify in database
        $query = $this->db->query('SELECT solddate FROM cars WHERE id = ?', [$carId]);
        $carData = $query->first();

        $this->assertStringStartsWith($soldDate, $carData->solddate);
    }

    /**
     * Test concurrent car operations maintain integrity
     *
     * @group integration
     */
    public function testConcurrentCarOperationsMaintainIntegrity(): void
    {
        // Load same car twice
        $car1 = new Car($this->testCarId);
        $car2 = new Car($this->testCarId);

        // Both should have same data
        $this->assertEquals($car1->data()->id, $car2->data()->id);
        $this->assertEquals($car1->data()->chassis, $car2->data()->chassis);

        // Update with one instance
        $updateData = [
            'id' => $this->testCarId,
            'token' => Token::generate(),
            'color' => 'Concurrent Test'
        ];

        $result = $car1->update($updateData);

        $this->assertTrue($result);

        // Reload second instance
        $car2->find($this->testCarId);

        // Should reflect update
        $this->assertEquals('Concurrent Test', $car2->data()->color);
    }
}
