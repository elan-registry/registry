<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Exceptions\CarNotFoundException;

use PHPUnit\Framework\Attributes\Group;

/**
 * Test cases for Car deletion functionality
 *
 * Tests cover car deletion operations, transaction handling, audit trail
 * creation, and error scenarios. CSRF protection is validated at the HTTP
 * layer (app/admin/index.php), not inside Car::delete() — see #1519, #1829.
 */
#[Group('integration')]
final class CarDeletionTest extends IntegrationTestCase
{
    private $testCarId;
    private $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->testUserId = $this->createTestUser();

        // Set up authenticated user context for deletion operations
        $this->loginAsTestUser($this->testUserId);

        // Create unique test car for this test
        try {
            $this->testCarId = $this->createTestCar($this->testUserId, [
                'chassis' => 'DEL' . uniqid()
            ]);
        } catch (RuntimeException $e) {
            $this->markTestSkipped('Could not create test car: ' . $e->getMessage());
        }
    }

    /**
     * Test successful car deletion
     */
    #[Group('fast')]
    public function testDeleteCarSucceeds(): void
    {
        $car = new Car($this->testCarId);
        $this->assertTrue($car->exists());

        $result = $car->delete('Test deletion', $this->testUserId);

        $this->assertTrue($result);
        $this->assertFalse($car->exists());
    }

    /**
     * Test car deletion fails when car does not exist
     */
    #[Group('fast')]
    public function testDeleteCarFailsWhenCarNotExists(): void
    {
        $this->expectException(CarNotFoundException::class);

        $car = new Car(99999);
        $car->delete('Test deletion', $this->testUserId);
    }

    /**
     * er_email_events (#1887) has no FK/cascade on car_id, so a deleted
     * car's Brevo event history would otherwise survive the delete
     * permanently — CarAdministrationService::delete() must clean it up in
     * the same transaction as the car row.
     */
    #[Group('fast')]
    public function testDeleteCarRemovesEmailEventHistory(): void
    {
        $this->db->query(
            'INSERT INTO er_email_events (car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, NULL, ?, NOW())',
            [$this->testCarId, 'delete-test@example.com', 'delivered', 'del-test-msg-1']
        );
        $this->assertFalse($this->db->error(), 'Test setup: failed to seed er_email_events row');

        $car = new Car($this->testCarId);
        $car->delete('Test deletion', $this->testUserId);

        $remaining = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE car_id = ?',
            [$this->testCarId]
        )->first();
        $this->assertSame(0, (int) $remaining->cnt, 'er_email_events rows for a deleted car must be removed');
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

        $result = $car->delete('Test deletion for audit', $this->testUserId);

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
        $car->delete('First deletion', $this->testUserId);

        // tearDown will attempt to clean up $this->testCarId; if the car is
        // already gone the cleanup silently ignores the missing row.

        // Second deletion on the same ID — car no longer exists
        $this->expectException(CarNotFoundException::class);
        $car2 = new Car($this->testCarId);
        $car2->delete('Second deletion', $this->testUserId);
    }

    /**
     * Test delete works with an explicit actingUserId even when global $user is unset.
     * Verifies that Car::delete() does not fall back to currentUserId() internally.
     */
    #[Group('fast')]
    public function testDeleteHonorsExplicitActingUserIdWithoutGlobalUser(): void
    {
        // Car::__construct() needs a global $user (via getSettings()), so construct before
        // unsetting it — only delete() itself must not fall back to a global $user internally.
        $car = new Car($this->testCarId);

        $savedUser = $GLOBALS['user'] ?? null;
        unset($GLOBALS['user']);

        try {
            $result = $car->delete('Explicit actingUserId test', $this->testUserId);
            $this->assertTrue($result);
        } finally {
            if ($savedUser !== null) {
                $GLOBALS['user'] = $savedUser;
            }
        }
    }

}
