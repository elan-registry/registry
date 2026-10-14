<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2358: the two dashboard readers select a car's events by the car's owner.
 *
 * After a car's cycle start (its latest `sent` row), the car shows every
 * non-`sent` row of `user_id = cars.user_id` with `car_id` = the car. A row
 * for another car of the owner never shows. A row with `car_id` NULL is for
 * mail that matched no verification send. It shows only when it is a
 * terminal event (#2359, criterion 41). A row with the message ID of the
 * car's cycle-start `sent` row also shows, whatever its `car_id` (a row that
 * the #2358 migration collapsed). Real rows, real SQL.
 */
#[Group('integration')]
final class CarRepositoryEmailEventReadersTest extends IntegrationTestCase
{
    private CarRepository $repo;
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repo = new CarRepository($this->db);
        $this->ownerId = $this->createTestUser([], true);
    }

    private function newCar(int $userId): int
    {
        return $this->createTestCar($userId, ['email' => 'readers-' . uniqid() . '@example.com']);
    }

    /**
     * Insert an event row. A null $carId writes an owner-level row.
     */
    private function insertEvent(int $userId, ?int $carId, string $event, string $messageId, string $occurredAt): void
    {
        $this->db->query(
            'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, ?, NULL, ?, ?)',
            [$userId, $carId, 'readers@example.com', $event, $messageId, $occurredAt]
        );
        $this->assertFalse($this->db->error(), 'Failed to seed er_email_events row: ' . $this->db->errorString());
    }

    private function checksum(): string
    {
        return (string) $this->db->query('CHECKSUM TABLE er_email_events')->first()->Checksum;
    }

    /**
     * @param list<int> $carIds
     * @return array<string, array<int, object>> Both readers' results, by reader name
     */
    private function bothReaders(array $carIds): array
    {
        return [
            'findLatestEmailEventsByCarIds'              => $this->repo->findLatestEmailEventsByCarIds($carIds),
            'findLatestEmailEventPerCarWithPrecedence' => $this->repo->findLatestEmailEventPerCarWithPrecedence($carIds),
        ];
    }

    public function testAnOwnerLevelDeliveredRowLeavesTheChipAndAnOwnerLevelHardBounceChangesIt(): void
    {
        $carIds = [$this->newCar($this->ownerId), $this->newCar($this->ownerId), $this->newCar($this->ownerId)];
        // Each car has its own cycle start, at a different time.
        foreach ($carIds as $index => $carId) {
            $this->insertEvent($this->ownerId, $carId, 'sent', 'local:c' . $index, sprintf('2026-05-01 10:%02d:00', $index * 5));
        }
        foreach ($this->bothReaders($carIds) as $reader => $before) {
            foreach ($carIds as $carId) {
                $this->assertSame('sent', $before[$carId]->event, "{$reader}: before the webhook, each car shows its sent row");
            }
        }

        // A password reset after all three cycle starts: its rows have no car.
        $this->insertEvent($this->ownerId, null, 'delivered', 'brevo-1', '2026-05-01 10:20:00');

        foreach ($this->bothReaders($carIds) as $reader => $result) {
            foreach ($carIds as $carId) {
                $this->assertSame('sent', $result[$carId]->event, "{$reader}: a car-less delivered row must not change the chip of car {$carId}");
            }
        }

        $this->insertEvent($this->ownerId, null, 'hard_bounce', 'brevo-1', '2026-05-01 10:21:00');

        foreach ($this->bothReaders($carIds) as $reader => $result) {
            foreach ($carIds as $carId) {
                $this->assertSame('hard_bounce', $result[$carId]->event, "{$reader}: a car-less hard bounce must show on car {$carId}");
                $this->assertSame('brevo-1', $result[$carId]->brevo_message_id);
            }
        }
    }

    public function testARowBeforeACarsCycleStartDoesNotChangeThatCarsChip(): void
    {
        $oldCar = $this->newCar($this->ownerId);
        $newCar = $this->newCar($this->ownerId);
        $this->insertEvent($this->ownerId, $oldCar, 'sent', 'local:old', '2026-05-01 08:00:00');
        // A bounce that arrives between the two cycle starts: after the first car's start, before the second's.
        $this->insertEvent($this->ownerId, null, 'hard_bounce', 'brevo-bounce', '2026-05-01 09:00:00');
        $this->insertEvent($this->ownerId, $newCar, 'sent', 'local:new', '2026-05-01 10:00:00');

        foreach ($this->bothReaders([$oldCar, $newCar]) as $reader => $result) {
            $this->assertSame('hard_bounce', $result[$oldCar]->event, "{$reader}: the old car's cycle holds the bounce");
            $this->assertSame('sent', $result[$newCar]->event, "{$reader}: the bounce is before the new car's cycle start");
            $this->assertSame('local:new', $result[$newCar]->brevo_message_id);
        }
    }

    public function testATerminalOwnerLevelRowWinsOnEveryCarOfTheOwner(): void
    {
        $carIds = [$this->newCar($this->ownerId), $this->newCar($this->ownerId)];
        foreach ($carIds as $index => $carId) {
            $this->insertEvent($this->ownerId, $carId, 'sent', 'local:t' . $index, '2026-05-02 10:00:00');
        }
        $this->insertEvent($this->ownerId, null, 'hard_bounce', 'brevo-t1', '2026-05-02 10:05:00');
        $this->insertEvent($this->ownerId, null, 'delivered', 'brevo-t2', '2026-05-02 10:10:00');

        $result = $this->repo->findLatestEmailEventPerCarWithPrecedence($carIds);

        foreach ($carIds as $carId) {
            $this->assertSame('hard_bounce', $result[$carId]->event, 'A terminal event wins on every car, at any time');
        }
    }

    public function testACarWithNoCycleStartShowsOnlyItsOwnCarIdRows(): void
    {
        $car = $this->newCar($this->ownerId);
        $otherCar = $this->newCar($this->ownerId);
        $this->insertEvent($this->ownerId, $otherCar, 'sent', 'local:other', '2026-05-03 09:00:00');
        $this->insertEvent($this->ownerId, $car, 'soft_bounce', 'brevo-own', '2026-05-03 10:00:00');
        // Later, and for the owner. The car has no cycle start, so it does not show this row.
        $this->insertEvent($this->ownerId, null, 'delivered', 'brevo-owner-level', '2026-05-03 11:00:00');

        foreach ($this->bothReaders([$car]) as $reader => $result) {
            $this->assertSame('soft_bounce', $result[$car]->event, "{$reader}: only the car's own row shows");
            $this->assertSame('brevo-own', $result[$car]->brevo_message_id);
        }
    }

    public function testACarWithNoCycleStartAndNoOwnRowsIsAbsent(): void
    {
        $car = $this->newCar($this->ownerId);
        $this->insertEvent($this->ownerId, null, 'delivered', 'brevo-only-owner', '2026-05-03 11:00:00');

        foreach ($this->bothReaders([$car]) as $reader => $result) {
            $this->assertArrayNotHasKey($car, $result, "{$reader}: the dashboard shows 'not sent'");
        }
    }

    public function testAnotherCarsSentRowDoesNotStartThisCarsCycleOrShow(): void
    {
        $car = $this->newCar($this->ownerId);
        $otherCar = $this->newCar($this->ownerId);
        $this->insertEvent($this->ownerId, $car, 'sent', 'local:mine', '2026-05-04 09:00:00');
        $this->insertEvent($this->ownerId, $otherCar, 'sent', 'local:theirs', '2026-05-04 12:00:00');

        foreach ($this->bothReaders([$car]) as $reader => $result) {
            $this->assertSame('local:mine', $result[$car]->brevo_message_id, "{$reader}: the other car's sent row must not show");
        }
    }

    public function testATransferredCarShowsNotSentAndTheTransferChangesNoRow(): void
    {
        $newOwnerId = $this->createTestUser([], true);
        $adminId = $this->createTestUser();
        $this->loginAsTestUser($adminId);
        $car = $this->newCar($this->ownerId);
        $this->insertEvent($this->ownerId, $car, 'sent', 'local:prev', '2026-05-05 09:00:00');
        $this->insertEvent($this->ownerId, null, 'delivered', 'brevo-prev', '2026-05-05 09:30:00');
        $this->insertEvent($this->ownerId, $car, 'hard_bounce', 'brevo-prev-car', '2026-05-05 09:40:00');
        $this->assertSame('hard_bounce', $this->repo->findLatestEmailEventPerCarWithPrecedence([$car])[$car]->event, 'Test setup');
        $checksumBefore = $this->checksum();

        (new Car($car))->transfer($newOwnerId, 'Test transfer', 'NEWOWNER', $adminId);

        $this->assertSame($checksumBefore, $this->checksum(), 'A transfer must change no er_email_events row');
        foreach ($this->bothReaders([$car]) as $reader => $result) {
            $this->assertArrayNotHasKey($car, $result, "{$reader}: the new owner sees 'not sent', not the previous owner's events");
        }

        // The next send starts a cycle. The previous owner's rows with this car_id still do not count.
        $this->repo->insertEmailEvent($car, 'new-owner@example.com', 'sent', null, 'local:next', '2026-05-06 09:00:00');
        $this->insertEvent($this->ownerId, $car, 'hard_bounce', 'brevo-prev-late', '2026-05-06 09:30:00');
        $this->insertEvent($this->ownerId, null, 'spam', 'brevo-prev-late2', '2026-05-06 09:31:00');
        foreach ($this->bothReaders([$car]) as $reader => $result) {
            $this->assertSame('sent', $result[$car]->event, "{$reader}: the next send starts a cycle for the new owner");
            $this->assertSame('local:next', $result[$car]->brevo_message_id);
        }

        // An owner-level terminal row of the new owner counts after the new cycle start.
        $this->insertEvent($newOwnerId, null, 'hard_bounce', 'brevo-new', '2026-05-06 10:00:00');
        foreach ($this->bothReaders([$car]) as $reader => $result) {
            $this->assertSame('hard_bounce', $result[$car]->event, $reader);
            $this->assertSame('brevo-new', $result[$car]->brevo_message_id);
        }
    }

    public function testARowOfAnotherOwnerNeverShowsOnTheCar(): void
    {
        $stranger = $this->createTestUser([], true);
        $car = $this->newCar($this->ownerId);
        $this->insertEvent($this->ownerId, $car, 'sent', 'local:own', '2026-05-07 09:00:00');
        $this->insertEvent($stranger, null, 'hard_bounce', 'brevo-stranger', '2026-05-07 09:30:00');
        $this->insertEvent($stranger, $car, 'spam', 'brevo-stranger-car', '2026-05-07 09:31:00');

        foreach ($this->bothReaders([$car]) as $reader => $result) {
            $this->assertSame('sent', $result[$car]->event, "{$reader}: rows of another user must not show");
        }
    }

    public function testAnotherCarsEventsOfTheSameOwnerDoNotChangeThisCarsChip(): void
    {
        $carA = $this->newCar($this->ownerId);
        $carB = $this->newCar($this->ownerId);
        $this->insertEvent($this->ownerId, $carA, 'sent', 'local:car-a', '2026-05-08 10:00:00');
        $this->insertEvent($this->ownerId, $carA, 'soft_bounce', 'brevo-car-a', '2026-05-08 10:05:00');
        $this->insertEvent($this->ownerId, $carB, 'sent', 'local:car-b', '2026-05-08 11:00:00');
        $this->insertEvent($this->ownerId, $carB, 'delivered', 'brevo-car-b', '2026-05-08 11:05:00');

        foreach ($this->bothReaders([$carA, $carB]) as $reader => $result) {
            $this->assertSame('soft_bounce', $result[$carA]->event, "{$reader}: car B's delivered must not hide car A's bounce");
            $this->assertSame('brevo-car-a', $result[$carA]->brevo_message_id, $reader);
            $this->assertSame('delivered', $result[$carB]->event, $reader);
            $this->assertSame('brevo-car-b', $result[$carB]->brevo_message_id, $reader);
        }
    }

    /**
     * The #2358 migration collapsed duplicate rows and kept the `car_id` of
     * the lowest-id row. So a `delivered` for car B's message can carry car
     * A's ID. The message ID still ties it to car B's cycle.
     */
    public function testARowWithTheCycleMessageIdShowsOnThatCarWhateverItsCarId(): void
    {
        $carA = $this->newCar($this->ownerId);
        $carB = $this->newCar($this->ownerId);
        $this->insertEvent($this->ownerId, $carA, 'sent', 'brevo-m-x', '2026-05-08 10:00:00');
        $this->insertEvent($this->ownerId, $carB, 'sent', 'brevo-m-y', '2026-05-08 11:00:00');
        $this->insertEvent($this->ownerId, $carA, 'delivered', 'brevo-m-y', '2026-05-08 11:05:00');

        foreach ($this->bothReaders([$carA, $carB]) as $reader => $result) {
            $this->assertSame('delivered', $result[$carB]->event, "{$reader}: the delivered for car B's message must show on car B");
            $this->assertSame('brevo-m-y', $result[$carB]->brevo_message_id, $reader);
        }
    }
}
