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
     * #2358: a car delete keeps the events with the user. The `sent` row of the car
     * loses its car (`car_id` NULL) and keeps its `user_id`. Other rows stay as they are.
     */
    #[Group('fast')]
    public function testDeleteCarKeepsItsEmailEventsWithTheUser(): void
    {
        $otherCarId = $this->createTestCar($this->testUserId, ['chassis' => 'DELB' . uniqid()]);
        $insert = 'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
                   VALUES (?, ?, ?, ?, NULL, ?, NOW())';
        foreach ([
            [$this->testCarId, 'sent', 'del-test-sent-1'],
            [$otherCarId, 'sent', 'del-test-sent-2'],
            [null, 'delivered', 'del-test-owner-level'],
        ] as [$carId, $event, $messageId]) {
            $this->db->query($insert, [$this->testUserId, $carId, 'delete-test@example.com', $event, $messageId]);
            $this->assertFalse($this->db->error(), 'Test setup: failed to seed er_email_events row: ' . $this->db->errorString());
        }

        $car = new Car($this->testCarId);
        $car->delete('Test deletion', $this->testUserId);

        $rows = [];
        foreach ($this->db->query(
            'SELECT brevo_message_id, user_id, car_id FROM er_email_events WHERE user_id = ? ORDER BY brevo_message_id',
            [$this->testUserId]
        )->results() as $row) {
            $rows[$row->brevo_message_id] = $row;
        }
        $this->assertCount(3, $rows, 'A car delete must delete no event row');
        $this->assertNull($rows['del-test-sent-1']->car_id, 'The deleted car\'s row keeps no car');
        $this->assertSame($this->testUserId, (int) $rows['del-test-sent-1']->user_id, 'user_id must stay');
        $this->assertSame($otherCarId, (int) $rows['del-test-sent-2']->car_id, 'The owner\'s other car keeps its row');
        $this->assertNull($rows['del-test-owner-level']->car_id);
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
