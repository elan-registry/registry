<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Input;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for Car database operations
 *
 * Tests actual database operations with real connections, transactions,
 * triggers, and audit trails. These tests verify that the database layer
 * works correctly with the Car class.
 *
 * Uses real database fixtures (user ID 1, car IDs 1-2).
 */
#[Group('integration')]
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

        // Bypass login() to set the private $_isLoggedIn flag directly via reflection.
        // setAccessible() is intentionally omitted — it is a no-op since PHP 8.1.
        $reflection = new ReflectionClass($user);
        $isLoggedInProperty = $reflection->getProperty('_isLoggedIn');
        $isLoggedInProperty->setValue($user, true);

        $GLOBALS['user'] = $user;

        $this->testUserId = 1;

        // Create unique test car for this test
        try {
            $this->testCarId = $this->createTestCar($this->testUserId, [
                'chassis' => 'DB' . uniqid()
            ]);
        } catch (RuntimeException $e) {
            $this->markTestSkipped('Could not create test car: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Test car creation persists to database
     */
    #[Group('integration')]
    public function testCarCreationPersistsToDatabases(): void
    {
        $carData = [
            'token' => Token::generate(),
            'user_id' => $this->testUserId,
            'year' => '1973',
            'model' => 'Sprint|FHC|36',  // Updated to new pipe-delimited format
            'series' => 'Sprint',
            'variant' => 'FHC',
            'type' => '36',
            'chassis' => 'TST' . substr(uniqid(), -9),  // Keep within 15 char limit
            'color' => 'Integration Red',
            'engine' => 'ENG' . substr(uniqid(), -9)  // Keep within 15 char limit
        ];

        $car = new Car();
        $result = $car->create($carData);

        $this->assertTrue($result);
        $createdId = $car->data()->id;
        $this->trackCarId((int) $createdId);

        // Verify data was persisted
        $query = $this->db->query('SELECT * FROM cars WHERE id = ?', [$createdId]);
        $this->assertGreaterThan(0, $query->count());

        $persistedCar = $query->first();
        $this->assertEquals('Integration Red', $persistedCar->color);
        $this->assertEquals($carData['chassis'], $persistedCar->chassis);
    }

    /**
     * Test car update persists to database
     */
    #[Group('integration')]
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
     */
    #[Group('integration')]
    public function testCarDeletionRemovesFromDatabase(): void
    {
        $car = new Car($this->testCarId);
        $result = $car->delete('Integration test deletion', Token::generate());

        $this->assertTrue($result);

        // Verify car no longer exists in database
        $query = $this->db->query('SELECT * FROM cars WHERE id = ?', [$this->testCarId]);
        $this->assertEquals(0, $query->count());
    }

    /**
     * Test car deletion creates audit trail
     */
    #[Group('integration')]
    public function testCarDeletionCreatesAuditTrail(): void
    {
        $car = new Car($this->testCarId);
        $result = $car->delete('Integration test audit trail', Token::generate());

        $this->assertTrue($result);

        // Verify audit trail record exists
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'DELETE'",
            [$this->testCarId]
        );
        $this->assertGreaterThan(0, $historyQuery->count());
    }

    /**
     * Test car transfer updates relationships
     */
    #[Group('integration')]
    public function testCarTransferUpdatesRelationships(): void
    {
        $car = new Car($this->testCarId);
        $targetUserId = 10;  // Use existing user ID (FredHansen)

        // Verify original owner
        $origRelation = $this->db->query(
            'SELECT user_id FROM cars WHERE id = ?',
            [$this->testCarId]
        )->first();
        $this->assertSame($this->testUserId, (int) $origRelation->user_id);

        // Transfer car
        $result = $car->transfer($targetUserId, 'Integration test transfer', 'NEWOWNER');

        $this->assertTrue($result);

        // Verify owner was updated
        $newRelation = $this->db->query(
            'SELECT user_id FROM cars WHERE id = ?',
            [$this->testCarId]
        )->first();
        $this->assertSame($targetUserId, (int) $newRelation->user_id);
    }

    /**
     * Test car transfer creates history record
     */
    #[Group('integration')]
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
     */
    #[Group('integration')]
    public function testCarMergeTransfersHistoryRecords(): void
    {
        // Create a second test car to merge from
        $mergeCarId = $this->createTestCar($this->testUserId, [
            'chassis' => 'DB' . uniqid()
        ]);

        $car = new Car($this->testCarId);
        $result = $car->merge($mergeCarId, 'Integration test merge');

        $this->assertTrue($result);

        // Verify merge audit trail exists
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'MERGE'",
            [$this->testCarId]
        );
        $this->assertGreaterThan(0, $historyQuery->count());
    }

    /**
     * Test database trigger creates update history on car update
     */
    #[Group('integration')]
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
     * Test car ownership integrity
     */
    #[Group('integration')]
    public function testCarOwnershipIntegrity(): void
    {
        // Verify car has a valid owner assigned
        $row = $this->db->query(
            'SELECT id, user_id FROM cars WHERE id = ?',
            [$this->testCarId]
        )->first();

        $this->assertNotNull($row);
        $this->assertEquals($this->testUserId, $row->user_id);
    }

    /**
     * Test verification code is set and retrieved
     */
    #[Group('integration')]
    public function testVerificationCodeSetAndRetrieved(): void
    {
        $car = new Car($this->testCarId);
        $verificationCode = 'INT-VERIFY-' . uniqid();

        $result = $car->setVerificationCode($verificationCode);

        $this->assertTrue($result);

        // Retrieve from database
        // Note: Database column is 'vericode', not 'verification_code'
        $query = $this->db->query(
            'SELECT vericode FROM cars WHERE id = ?',
            [$this->testCarId]
        );
        $result = $query->first();

        $this->assertEquals($verificationCode, $result->vericode);
    }

    /**
     * Test mark sold updates database
     */
    #[Group('integration')]
    public function testMarkSoldUpdatesDatabase(): void
    {
        // Create a test car for sold marking
        $carData = [
            'token' => Token::generate(),
            'user_id' => $this->testUserId,
            'year' => '1968',
            'model' => 'S4|FHC|36',  // Updated to new pipe-delimited format
            'series' => 'S4',
            'variant' => 'FHC',
            'type' => '36',
            'chassis' => 'SLD' . substr(uniqid(), -9),  // Keep within 15 char limit
            'color' => 'Red'
        ];

        $car = new Car();
        $car->create($carData);
        $carId = $car->data()->id;
        $this->trackCarId((int) $carId);

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
     */
    #[Group('integration')]
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

    /**
     * Car text field updates must store plain text, not HTML-encoded values.
     *
     * Simulates the full action layer: Input::raw() retrieves the POST value,
     * the Car class stores it, and the DB row contains plain text — no entities.
     */
    #[Group('integration')]
    public function testCarUpdateStoresTextFieldsAsPlainText(): void
    {
        $specialChars = "O'Brien & Co <élan>";

        // Simulate what edit.php does: get raw POST values → update Car
        $_POST['color']    = $specialChars;
        $_POST['comments'] = $specialChars;
        try {
            $storedColor    = Input::raw('color');
            $storedComments = Input::raw('comments');

            $car = new Car($this->testCarId);
            $car->update([
                'id'       => $this->testCarId,
                'token'    => Token::generate(),
                'color'    => $storedColor,
                'comments' => $storedComments,
            ]);

            // Read directly from DB to verify no entity encoding in either field
            // (color and comments go through separate update functions in edit.php)
            $query = $this->db->query(
                'SELECT color, comments FROM cars WHERE id = ?',
                [$this->testCarId]
            );
            $row = $query->first();

            foreach (['color' => $row->color, 'comments' => $row->comments] as $col => $dbValue) {
                $this->assertSame(
                    $specialChars,
                    $dbValue,
                    "DB column '{$col}' must store plain text, not HTML-encoded value"
                );
                $this->assertStringNotContainsString('&amp;',  $dbValue, "DB column '{$col}' must not contain &amp;");
                $this->assertStringNotContainsString('&#039;', $dbValue, "DB column '{$col}' must not contain &#039;");
                $this->assertStringNotContainsString('&lt;',   $dbValue, "DB column '{$col}' must not contain &lt;");
            }
        } finally {
            unset($_POST['color'], $_POST['comments']);
        }
    }

    /**
     * Saving a car text field twice must not accumulate HTML encoding.
     *
     * Verifies the idempotency invariant: read from DB → save again → DB value unchanged.
     * This is the core regression for the double-encoding defect.
     */
    #[Group('integration')]
    public function testCarUpdateIsIdempotentOnResave(): void
    {
        $specialChars = "O'Brien & Co";

        // First save
        $car = new Car($this->testCarId);
        $car->update([
            'id'    => $this->testCarId,
            'token' => Token::generate(),
            'color' => $specialChars,
        ]);

        // Read back from DB
        $query1   = $this->db->query('SELECT color FROM cars WHERE id = ?', [$this->testCarId]);
        $afterFirstSave = $query1->first()->color;

        // Second save (re-save with the DB-read value — simulates edit-without-change)
        $car->update([
            'id'    => $this->testCarId,
            'token' => Token::generate(),
            'color' => $afterFirstSave,
        ]);

        $query2   = $this->db->query('SELECT color FROM cars WHERE id = ?', [$this->testCarId]);
        $afterSecondSave = $query2->first()->color;

        $this->assertSame(
            $afterFirstSave,
            $afterSecondSave,
            'DB value must be identical after a second save — no encoding accumulation'
        );
        $this->assertSame(
            $specialChars,
            $afterSecondSave,
            'DB must contain the original plain-text value after two saves'
        );
    }
}
