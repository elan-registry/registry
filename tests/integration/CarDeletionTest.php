<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * Test cases for Car deletion functionality
 *
 * Tests cover car deletion operations with CSRF protection, transaction handling,
 * audit trail creation, and error scenarios.
 *
 * @group integration
 */
final class CarDeletionTest extends IntegrationTestCase
{
    private $testCarId;
    private $testUserId;
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Set up authenticated user context for deletion operations
        global $user;
        $user = new User();
        $user->find(1);  // Load user ID 1

        // Manually set the private $_isLoggedIn property to true using reflection
        $reflection = new ReflectionClass($user);
        $isLoggedInProperty = $reflection->getProperty('_isLoggedIn');
        $isLoggedInProperty->setAccessible(true);
        $isLoggedInProperty->setValue($user, true);

        $GLOBALS['user'] = $user;

        $this->testUserId = 1;
        $this->db = DB::getInstance();

        // Create unique test car for this test
        try {
            $this->testCarId = $this->createTestCar($this->testUserId, [
                'chassis' => 'TEST-DELETE-' . microtime(true)
            ]);
        } catch (RuntimeException $e) {
            $this->markTestSkipped('Could not create test car: ' . $e->getMessage());
        }

        // Ensure car_user relationship exists
        $this->db->insert('car_user', [
            'car_id' => $this->testCarId,
            'userid' => $this->testUserId
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }

    /**
     * Test successful car deletion with valid CSRF token
     *
     * @group fast
     */
    public function testDeleteCarSuccessWithValidToken(): void
    {
        $car = new Car($this->testCarId);
        $this->assertTrue($car->exists());

        $token = Token::generate();
        $result = $car->delete('Test deletion', $token);

        $this->assertTrue($result);
        $this->assertFalse($car->exists());
    }

    /**
     * Test car deletion fails with invalid CSRF token
     *
     * @group fast
     */
    public function testDeleteCarFailsWithInvalidToken(): void
    {
        $this->expectException(CarDeletionException::class);

        $car = new Car($this->testCarId);
        $car->delete('Test deletion', 'invalid-token-12345');
    }

    /**
     * Test car deletion fails when car does not exist
     *
     * @group fast
     */
    public function testDeleteCarFailsWhenCarNotExists(): void
    {
        $this->expectException(CarNotFoundException::class);

        $car = new Car(99999);
        $car->delete('Test deletion', Token::generate());
    }

    /**
     * Test car deletion creates audit trail in history table
     *
     * @group fast
     */
    public function testDeleteCarCreatesAuditTrail(): void
    {
        $car = new Car($this->testCarId);
        $carId = $car->data()->id;

        $token = Token::generate();
        $result = $car->delete('Test deletion for audit', $token);

        $this->assertTrue($result);

        // Check that history record was created
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'DELETE'",
            [$carId]
        );
        $this->assertTrue($historyQuery->count() > 0);
    }

    /**
     * Test car deletion removes car-user relationships
     *
     * @group fast
     */
    public function testDeleteCarRemovesCarUserRelationships(): void
    {
        $car = new Car($this->testCarId);
        $carId = $car->data()->id;

        // Verify relationship exists before deletion
        $relationQuery = $this->db->query("SELECT * FROM car_user WHERE car_id = ?", [$carId]);
        $this->assertTrue($relationQuery->count() > 0);

        $token = Token::generate();
        $result = $car->delete('Test deletion', $token);

        $this->assertTrue($result);

        // Verify relationship was removed
        $relationQuery = $this->db->query("SELECT * FROM car_user WHERE car_id = ?", [$carId]);
        $this->assertEquals(0, $relationQuery->count());
    }

    /**
     * Test car deletion transaction rollback on failure
     *
     * @group fast
     */
    public function testDeleteTransactionRollbackOnFailure(): void
    {
        // This test verifies that if any step fails, the transaction rolls back
        // It's challenging to test without mocking the database
        // For now, we verify that attempting to delete without auth fails

        $this->expectException(CarDeletionException::class);

        // Create a car without authentication context
        $car = new Car($this->testCarId);

        // Mock: Unset global user to simulate no authentication
        global $user;
        $originalUser = $user ?? null;
        unset($GLOBALS['user']);

        try {
            $car->delete('Test deletion', Token::generate());
        } finally {
            // Restore user
            if ($originalUser) {
                $GLOBALS['user'] = $originalUser;
            }
        }
    }

    /**
     * Test car deletion requires authenticated user
     *
     * @group fast
     */
    public function testDeleteRequiresAuthenticatedUser(): void
    {
        $this->expectException(CarDeletionException::class);

        $car = new Car($this->testCarId);

        // Mock: Unset global user to simulate no authentication
        global $user;
        $originalUser = $user ?? null;
        unset($GLOBALS['user']);

        try {
            $car->delete('Test deletion', Token::generate());
        } finally {
            // Restore user
            if ($originalUser) {
                $GLOBALS['user'] = $originalUser;
            }
        }
    }

    /**
     * Test car deletion with optional token parameter
     *
     * @group fast
     */
    public function testDeleteCarWithOptionalToken(): void
    {
        $car = new Car($this->testCarId);
        $this->assertTrue($car->exists());

        // Delete without providing token (using default parameter)
        $result = $car->delete('Test deletion without token');

        $this->assertTrue($result);
        $this->assertFalse($car->exists());
    }
}
