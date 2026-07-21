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

        // Create unique test car for this test
        try {
            $this->testCarId = $this->createTestCar(1, [
                'chassis' => 'DEL' . uniqid()
            ]);
        } catch (RuntimeException $e) {
            $this->markTestSkipped('Could not create test car: ' . $e->getMessage());
        }
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
     * Test that deleting an already-deleted car throws CarNotFoundException.
     *
     * This exercises the path added in issue #1311: when the first deletion
     * succeeds, the car row is gone.  A second delete attempt on the same ID
     * must throw CarNotFoundException rather than silently returning true.
     */
    #[Group('fast')]
    public function testDeleteAlreadyDeletedCarThrowsCarNotFoundException(): void
    {
        // First deletion — must succeed
        $car = new Car($this->testCarId);
        $car->delete('First deletion', Token::generate());

        // tearDown will attempt to clean up $this->testCarId; if the car is
        // already gone the cleanup silently ignores the missing row.

        // Second deletion on the same ID — car no longer exists
        $this->expectException(CarNotFoundException::class);
        $car2 = new Car($this->testCarId);
        $car2->delete('Second deletion', Token::generate());
    }

}
