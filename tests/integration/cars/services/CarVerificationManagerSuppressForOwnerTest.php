<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1883: CarVerificationManager::setSuppressedForOwner() (one-click opt-out) against real SQL.
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarVerificationManagerSuppressForOwnerTest extends IntegrationTestCase
{
    private CarRepository $repo;
    private CarVerificationManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repo = new CarRepository($this->db);
        $this->manager = new CarVerificationManager($this->repo);
    }

    private function emailSuppressed(int $carId): int
    {
        $row = $this->db->query('SELECT email_suppressed FROM cars WHERE id = ?', [$carId])->first();
        $this->assertNotNull($row, "Test setup: car {$carId} disappeared");
        return (int) $row->email_suppressed;
    }

    /**
     * @return int|null 0 or 1 as stored, or null when the owner has no profiles row
     */
    private function profileEmailSuppressed(int $ownerId): ?int
    {
        $result = $this->db->query('SELECT email_suppressed FROM profiles WHERE user_id = ?', [$ownerId]);
        if ($this->db->count() === 0) {
            return null;
        }
        return (int) $result->first()->email_suppressed;
    }

    #[Group('fast')]
    public function testAllOfOwnersCarsAreSuppressed(): void
    {
        $ownerId = $this->createTestUser([], true);
        $carIds = [
            $this->createTestCar($ownerId, ['email' => 'owner1-car1@example.com', 'email_suppressed' => 0]),
            $this->createTestCar($ownerId, ['email' => 'owner1-car2@example.com', 'email_suppressed' => 0]),
            $this->createTestCar($ownerId, ['email' => 'owner1-car3@example.com', 'email_suppressed' => 0]),
        ];

        $this->assertSame(0, $this->profileEmailSuppressed($ownerId), 'Test sanity: owner must start unsuppressed');

        $changed = $this->manager->setSuppressedForOwner($ownerId);

        $this->assertCount(3, $changed, 'All 3 of the owner\'s cars must be reported as changed');

        foreach ($carIds as $carId) {
            $this->assertSame(
                1,
                $this->emailSuppressed($carId),
                "Car {$carId} must have email_suppressed = 1 after setSuppressedForOwner()"
            );
        }

        $this->assertSame(
            1,
            $this->profileEmailSuppressed($ownerId),
            'The owner-level profiles.email_suppressed flag must also be set (#1883)'
        );
    }

    /** #1929: opting out confirms nothing about the car. */
    #[Group('fast')]
    public function testOptOutDoesNotChangeOwnerLastUpdated(): void
    {
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createTestCar($ownerId, ['email' => 'optout-fresh@example.com', 'email_suppressed' => 0]);

        $old = date('Y-m-d H:i:s', strtotime('-2 years'));
        $this->seedOwnerLastUpdated($carId, $old);
        $before = $this->getOwnerLastUpdated($carId);
        $this->assertSame($old, $before, 'Precondition: the seeded value must be stored as written');

        $this->manager->setSuppressedForOwner($ownerId);

        $this->assertSame(1, $this->emailSuppressed($carId), 'Precondition: the opt-out must have suppressed the car');
        $after = $this->getOwnerLastUpdated($carId);
        $this->assertSame($before, $after, 'setSuppressedForOwner() must not change owner_last_updated');
    }

    #[Group('fast')]
    public function testDifferentOwnersCarIsUntouched(): void
    {
        $ownerId = $this->createTestUser([], true);
        $otherOwnerId = $this->createTestUser([], true);

        $ownedCarId = $this->createTestCar($ownerId, ['email' => 'owner2-car@example.com', 'email_suppressed' => 0]);
        $otherCarId = $this->createTestCar($otherOwnerId, ['email' => 'other-owner-car@example.com', 'email_suppressed' => 0]);

        $this->manager->setSuppressedForOwner($ownerId);

        $this->assertSame(1, $this->emailSuppressed($ownedCarId), 'The target owner\'s car must be suppressed');
        $this->assertSame(0, $this->emailSuppressed($otherCarId), 'A different owner\'s car must remain untouched');

        $this->assertSame(1, $this->profileEmailSuppressed($ownerId), 'The target owner\'s profile flag must be set');
        $this->assertSame(
            0,
            $this->profileEmailSuppressed($otherOwnerId),
            'A different owner\'s profile flag must remain untouched'
        );
    }

    #[Group('fast')]
    public function testReRunningOnSameOwnerIsNoOpAndReturnsEmptyArray(): void
    {
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createTestCar($ownerId, ['email' => 'owner3-car@example.com', 'email_suppressed' => 0]);

        $firstRun = $this->manager->setSuppressedForOwner($ownerId);
        $this->assertCount(1, $firstRun, 'First run must suppress the one car');
        $this->assertSame(1, $this->emailSuppressed($carId));

        $secondRun = $this->manager->setSuppressedForOwner($ownerId);

        $this->assertSame([], $secondRun, 'A re-run on an already-suppressed owner must be a no-op returning []');
        $this->assertSame(1, $this->emailSuppressed($carId), 'The car must remain suppressed, not toggled');
        $this->assertSame(
            1,
            $this->profileEmailSuppressed($ownerId),
            'The profile flag must remain 1 on a re-run — the already-suppressed read must skip the write, '
            . 'not throw on MySQL reporting 0 affected rows'
        );
    }

    /**
     * Cars may already be suppressed by the Brevo path; the profile flag is written independently.
     */
    #[Group('fast')]
    public function testProfileFlagIsSetEvenWhenEveryCarWasAlreadySuppressed(): void
    {
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createTestCar($ownerId, ['email' => 'owner5-car@example.com', 'email_suppressed' => 1]);

        $this->assertSame(0, $this->profileEmailSuppressed($ownerId), 'Test sanity: profile starts unsuppressed');

        $changed = $this->manager->setSuppressedForOwner($ownerId);

        $this->assertSame([], $changed, 'No car needed changing, so no car is reported as changed');
        $this->assertSame(1, $this->emailSuppressed($carId), 'The car stays suppressed');
        $this->assertSame(
            1,
            $this->profileEmailSuppressed($ownerId),
            'The profile flag must still be brought up to date even though no car changed'
        );
    }

    /**
     * #1883: every car, sold included, so the audit rows match the car count the
     * confirmation page shows.
     */
    #[Group('fast')]
    public function testSoldCarIsSuppressedAlongsideUnsoldCars(): void
    {
        $ownerId = $this->createTestUser([], true);
        $unsoldCarId = $this->createTestCar($ownerId, ['email' => 'owner7-unsold@example.com', 'email_suppressed' => 0]);
        $soldCarId = $this->createTestCar($ownerId, [
            'email' => 'owner7-sold@example.com',
            'email_suppressed' => 0,
            'solddate' => date('Y-m-d', strtotime('-30 days')),
        ]);

        $changed = $this->manager->setSuppressedForOwner($ownerId);

        $changedIds = array_map(static fn (object $car): int => (int) $car->id, $changed);
        sort($changedIds);
        $expectedIds = [$unsoldCarId, $soldCarId];
        sort($expectedIds);
        $this->assertSame($expectedIds, $changedIds, 'Both the unsold and the sold car must be reported as changed');

        $this->assertSame(1, $this->emailSuppressed($unsoldCarId), 'The unsold car must be suppressed');
        $this->assertSame(1, $this->emailSuppressed($soldCarId), 'The sold car must also be suppressed');
        $this->assertSame(
            1,
            $this->profileEmailSuppressed($ownerId),
            'The owner-level flag must be set regardless of car sale status'
        );
    }

    #[Group('fast')]
    public function testOwnerWithOnlySoldCarStillGetsItSuppressed(): void
    {
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createTestCar($ownerId, [
            'email' => 'owner8-sold-only@example.com',
            'email_suppressed' => 0,
            'solddate' => date('Y-m-d', strtotime('-30 days')),
        ]);

        $changed = $this->manager->setSuppressedForOwner($ownerId);

        $this->assertCount(1, $changed, 'The owner\'s one (sold) car must still be reported as changed');
        $this->assertSame(1, $this->emailSuppressed($carId), 'The sold car must be suppressed');
        $this->assertSame(1, $this->profileEmailSuppressed($ownerId));
    }

    /**
     * Must fail before touching a car, or the owner-level decision is silently lost.
     * The schema allows a user without a profiles row.
     */
    #[Group('fast')]
    public function testOwnerWithNoProfilesRowThrowsAndSuppressesNoCar(): void
    {
        $ownerId = $this->createTestUser();
        $carId = $this->createTestCar($ownerId, ['email' => 'owner6-car@example.com', 'email_suppressed' => 0]);

        $this->assertNull($this->profileEmailSuppressed($ownerId), 'Test setup: the owner must have no profiles row');

        $this->expectException(CarDatabaseException::class);

        try {
            $this->manager->setSuppressedForOwner($ownerId);
        } finally {
            $this->assertSame(
                0,
                $this->emailSuppressed($carId),
                'The abort must happen before the fan-out — no car may be suppressed'
            );
        }
    }

    /**
     * Real connection, except update('cars', ...) reports failure.
     * Intercepts update() because CarRepository::updateCar() does not use query().
     */
    private function dbFailingUpdateCar(): DatabaseInterface
    {
        $real = $this->db;
        return new class ($real) implements DatabaseInterface {
            public function __construct(private DatabaseInterface $real)
            {
            }

            public function query(string $sql, array $params = []): self
            {
                $this->real->query($sql, $params);
                return $this;
            }

            public function get(string $table, array $where): self|false
            {
                $result = $this->real->get($table, $where);
                return $result === false ? false : $this;
            }

            public function insert(string $table, array $fields = [], bool $update = false): bool
            {
                return $this->real->insert($table, $fields, $update);
            }

            public function update(string $table, array|int $id, array $fields): bool
            {
                if ($table === 'cars') {
                    // Simulate a failed UPDATE without touching the real row.
                    return false;
                }
                return $this->real->update($table, $id, $fields);
            }

            public function delete(string $table, array|int $where): self|false
            {
                $result = $this->real->delete($table, $where);
                return $result === false ? false : $this;
            }

            public function error(): bool
            {
                return $this->real->error();
            }

            public function errorString(): string
            {
                return $this->real->errorString() ?: 'Simulated database failure for setSuppressedForOwner() test';
            }

            public function errorInfo(): array
            {
                return $this->real->errorInfo();
            }

            public function count(): int
            {
                return $this->real->count();
            }

            public function first(bool $assoc = false): array|object
            {
                return $this->real->first($assoc);
            }

            public function results(bool $assoc = false): array
            {
                return $this->real->results($assoc);
            }

            public function lastId(): int
            {
                return $this->real->lastId();
            }

            public function beginTransaction(): bool
            {
                return $this->real->beginTransaction();
            }

            public function commit(): bool
            {
                return $this->real->commit();
            }

            public function rollBack(): bool
            {
                return $this->real->rollBack();
            }

            public function inTransaction(): bool
            {
                return $this->real->inTransaction();
            }
        };
    }

    #[Group('fast')]
    public function testDatabaseFailurePropagatesCarDatabaseException(): void
    {
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createTestCar($ownerId, ['email' => 'owner4-car@example.com', 'email_suppressed' => 0]);

        $failingRepo = new CarRepository($this->dbFailingUpdateCar());
        $failingManager = new CarVerificationManager($failingRepo);

        $this->expectException(CarDatabaseException::class);

        try {
            $failingManager->setSuppressedForOwner($ownerId);
        } finally {
            $this->assertSame(
                0,
                $this->emailSuppressed($carId),
                'A simulated database failure must leave the car unsuppressed'
            );
            // The profile write is not intercepted and commits first. The method has
            // no transaction of its own; the caller rolls back.
            $this->assertSame(
                1,
                $this->profileEmailSuppressed($ownerId),
                'The profile flag was written before the car failure; the caller\'s transaction rolls it back'
            );
        }
    }
}
