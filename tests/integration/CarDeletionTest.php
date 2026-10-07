<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Exceptions\CarNotFoundException;

use PHPUnit\Framework\Attributes\Group;

/** CSRF is checked in app/admin/index.php, not in Car::delete() (#1519, #1829). */
#[Group('integration')]
final class CarDeletionTest extends IntegrationTestCase
{
    private int $testCarId;
    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->testUserId = $this->createTestUser();

        $this->loginAsTestUser($this->testUserId);

        try {
            $this->testCarId = $this->createTestCar($this->testUserId, [
                'chassis' => 'DEL' . uniqid()
            ]);
        } catch (RuntimeException $e) {
            $this->markTestSkipped('Could not create test car: ' . $e->getMessage());
        }
    }

    #[Group('fast')]
    public function testDeleteCarSucceeds(): void
    {
        $car = new Car($this->testCarId);
        $this->assertTrue($car->exists());

        $result = $car->delete('Test deletion', $this->testUserId);

        $this->assertTrue($result);
        $this->assertFalse($car->exists());
    }

    #[Group('fast')]
    public function testDeleteCarFailsWhenCarNotExists(): void
    {
        $this->expectException(CarNotFoundException::class);

        $car = new Car(99999);
        $car->delete('Test deletion', $this->testUserId);
    }

    /**
     * #1887: er_email_events has no FK cascade on car_id, so delete() must remove
     * the rows in the same transaction.
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
     * #593, #956: the DELETE trigger writes the only audit row. A second row means
     * an application-level pre-delete insert came back.
     *
     * @see #593, #930, #931, #956
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
        $this->assertSame(
            1,
            $historyQuery->count(),
            'Expected exactly one DELETE row in cars_hist for the Car::delete() path '
                . '(also used by app/admin/index.php)'
        );
    }

    /** #1311: a second delete of the same ID throws, not returns true. */
    #[Group('fast')]
    public function testDeleteAlreadyDeletedCarThrowsCarNotFoundException(): void
    {
        $car = new Car($this->testCarId);
        $car->delete('First deletion', $this->testUserId);

        // tearDown ignores the missing row.

        $this->expectException(CarNotFoundException::class);
        $car2 = new Car($this->testCarId);
        $car2->delete('Second deletion', $this->testUserId);
    }

    /** delete() must not fall back to a global $user. */
    #[Group('fast')]
    public function testDeleteHonorsExplicitActingUserIdWithoutGlobalUser(): void
    {
        // Car::__construct() needs global $user (getSettings()), so construct first.
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
