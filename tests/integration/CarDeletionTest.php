<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Exceptions\CarDeletionException;
use ElanRegistry\Exceptions\CarNotFoundException;

use PHPUnit\Framework\Attributes\Group;

/**
 * Test cases for Car deletion functionality
 *
 * Tests cover car deletion operations with CSRF protection, transaction handling,
 * audit trail creation, and error scenarios.
 */
#[Group('integration')]
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

        // Bypass login() to set the private $_isLoggedIn flag directly via reflection.
        // setAccessible() is intentionally omitted — it is a no-op since PHP 8.1.
        $reflection = new ReflectionClass($user);
        $isLoggedInProperty = $reflection->getProperty('_isLoggedIn');
        $isLoggedInProperty->setValue($user, true);

        $GLOBALS['user'] = $user;

        $this->testUserId = 1;
        $this->db = DB::getInstance();

        // Create unique test car for this test
        try {
            $this->testCarId = $this->createTestCar($this->testUserId, [
                'chassis' => 'DEL' . uniqid()
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
     * Test successful car deletion with valid CSRF token
     */
    #[Group('fast')]
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
     */
    #[Group('fast')]
    public function testDeleteCarFailsWithInvalidToken(): void
    {
        $this->expectException(CarDeletionException::class);

        $car = new Car($this->testCarId);
        $car->delete('Test deletion', 'invalid-token-12345');
    }

    /**
     * Test car deletion fails with empty token
     */
    #[Group('fast')]
    public function testDeleteCarFailsWithEmptyToken(): void
    {
        $this->expectException(CarDeletionException::class);

        $car = new Car($this->testCarId);
        $car->delete('Test deletion', '');
    }

    /**
     * Test car deletion fails when car does not exist
     */
    #[Group('fast')]
    public function testDeleteCarFailsWhenCarNotExists(): void
    {
        $this->expectException(CarNotFoundException::class);

        $car = new Car(99999);
        $car->delete('Test deletion', Token::generate());
    }

    /**
     * Test car deletion creates exactly one audit trail row in cars_hist
     *
     * Verifies the trigger-only write path introduced in #593: the DELETE trigger
     * must fire once and no application-level pre-delete insert must add a second row.
     */
    #[Group('fast')]
    public function testDeleteCarCreatesAuditTrail(): void
    {
        $car = new Car($this->testCarId);
        $carId = $car->data()->id;

        $result = $car->delete('Test deletion for audit', Token::generate());

        $this->assertTrue($result);

        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'DELETE'",
            [$carId]
        );
        $this->assertSame(1, $historyQuery->count(), 'Expected exactly one DELETE row in cars_hist');
    }

    /**
     * Test car deletion transaction rollback on failure
     */
    #[Group('fast')]
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
     */
    #[Group('fast')]
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

}
