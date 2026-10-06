<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real-DB integration tests for
 * CarVerificationManager::clearSuppressedForOwnerByOwner() (#1895 — owner
 * self-service "Resume verification emails").
 *
 * Unit coverage (tests/unit/cars/services/CarVerificationManagerTest.php)
 * exercises the method against a mocked CarRepository; this file proves the
 * same behavior against a real database and database schema: every one of an
 * owner's cars (sold included) ends up unsuppressed, a different owner's car
 * is untouched, a genuine database failure propagates CarDatabaseException,
 * the bounce columns are never touched even on a car that has both flags
 * set, and clearing via this method produces the same
 * findVerificationEligible() membership as clearing via the admin
 * clearSuppressedForOwner() method (AC 9 parity).
 *
 * @see usersc/classes/Car/CarVerificationManager.php
 * @see https://github.com/elan-registry/registry/issues/1895
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarVerificationManagerSuppressForOwnerByOwnerTest extends IntegrationTestCase
{
    private CarRepository $repo;
    private CarVerificationManager $manager;

    /** More than 1 year ago — satisfies both the last_verified and owner_last_updated staleness checks. */
    private const STALE_DATE = '-3 years';

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

    private function emailBounced(int $carId): int
    {
        $row = $this->db->query('SELECT email_bounced FROM cars WHERE id = ?', [$carId])->first();
        $this->assertNotNull($row, "Test setup: car {$carId} disappeared");
        return (int) $row->email_bounced;
    }

    private function emailBouncedAddress(int $carId): ?string
    {
        $row = $this->db->query('SELECT email_bounced_address FROM cars WHERE id = ?', [$carId])->first();
        $this->assertNotNull($row, "Test setup: car {$carId} disappeared");
        return $row->email_bounced_address === null ? null : (string) $row->email_bounced_address;
    }

    /**
     * @return int|null 0 or 1 as stored, or null when the owner has no
     *                  profiles row.
     */
    private function profileEmailSuppressed(int $ownerId): ?int
    {
        $result = $this->db->query('SELECT email_suppressed FROM profiles WHERE user_id = ?', [$ownerId]);
        if ($this->db->count() === 0) {
            return null;
        }
        return (int) $result->first()->email_suppressed;
    }

    private function staleDate(): string
    {
        return date('Y-m-d H:i:s', strtotime(self::STALE_DATE));
    }

    /**
     * A car that is stale (so freshness never gates it), has no sale date,
     * has a real email, is not bounced and is suppressed — i.e. one that
     * only the suppression flags keep out of findVerificationEligible().
     */
    private function createEligibleSuppressedCar(int $ownerId, array $overrides = []): int
    {
        return $this->createTestCar($ownerId, array_merge([
            'email' => 'eligible-' . uniqid() . '@example.com',
            'email_suppressed' => 1,
            'email_bounced' => 0,
            'owner_last_updated' => $this->staleDate(),
            'last_verified' => $this->staleDate(),
        ], $overrides));
    }

    #[Group('fast')]
    public function testAllOwnedCarsIncludingSoldAreUnsuppressedInOneCall(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->db->query('UPDATE profiles SET email_suppressed = 1 WHERE user_id = ?', [$ownerId]);
        $this->assertSame(1, $this->profileEmailSuppressed($ownerId), 'Test sanity: owner must start suppressed');

        $unsoldCarId = $this->createTestCar($ownerId, [
            'email' => 'owner-unsold@example.com',
            'email_suppressed' => 1,
        ]);
        $soldCarId = $this->createTestCar($ownerId, [
            'email' => 'owner-sold@example.com',
            'email_suppressed' => 1,
            'solddate' => date('Y-m-d', strtotime('-30 days')),
        ]);

        $changed = $this->manager->clearSuppressedForOwnerByOwner($ownerId);

        $changedIds = array_map(static fn (object $car): int => (int) $car->id, $changed);
        sort($changedIds);
        $expectedIds = [$unsoldCarId, $soldCarId];
        sort($expectedIds);
        $this->assertSame($expectedIds, $changedIds, 'Both cars, sold included, must be reported as changed');

        $this->assertSame(0, $this->emailSuppressed($unsoldCarId));
        $this->assertSame(0, $this->emailSuppressed($soldCarId));
        $this->assertSame(0, $this->profileEmailSuppressed($ownerId), 'The owner-level flag must also be cleared');
    }

    #[Group('fast')]
    public function testDifferentOwnersSuppressedCarIsUntouched(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->db->query('UPDATE profiles SET email_suppressed = 1 WHERE user_id = ?', [$ownerId]);
        $otherOwnerId = $this->createTestUser([], true);
        $this->db->query('UPDATE profiles SET email_suppressed = 1 WHERE user_id = ?', [$otherOwnerId]);

        $ownedCarId = $this->createTestCar($ownerId, ['email' => 'owner-a@example.com', 'email_suppressed' => 1]);
        $otherCarId = $this->createTestCar($otherOwnerId, ['email' => 'owner-b@example.com', 'email_suppressed' => 1]);

        $this->manager->clearSuppressedForOwnerByOwner($ownerId);

        $this->assertSame(0, $this->emailSuppressed($ownedCarId), 'The target owner\'s car must be unsuppressed');
        $this->assertSame(1, $this->emailSuppressed($otherCarId), 'A different owner\'s car must remain suppressed');
        $this->assertSame(0, $this->profileEmailSuppressed($ownerId));
        $this->assertSame(
            1,
            $this->profileEmailSuppressed($otherOwnerId),
            'A different owner\'s profile flag must remain untouched'
        );
    }

    #[Group('fast')]
    public function testCarWithBothSuppressedAndBouncedEndsWithOnlySuppressionCleared(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->db->query('UPDATE profiles SET email_suppressed = 1 WHERE user_id = ?', [$ownerId]);

        $carId = $this->createTestCar($ownerId, [
            'email' => 'bounced-and-suppressed@example.com',
            'email_suppressed' => 1,
            'email_bounced' => 1,
            'email_bounced_address' => 'bounced-and-suppressed@example.com',
        ]);

        $this->manager->clearSuppressedForOwnerByOwner($ownerId);

        $this->assertSame(0, $this->emailSuppressed($carId), 'Suppression must be cleared');
        $this->assertSame(1, $this->emailBounced($carId), 'The bounce flag must be left exactly as it was');
        $this->assertSame(
            'bounced-and-suppressed@example.com',
            $this->emailBouncedAddress($carId),
            'The bounce address must be left exactly as it was'
        );
    }

    /**
     * A DatabaseInterface proxy backed by the real connection, except that
     * update('cars', ...) always reports failure — forcing
     * CarRepository::updateEmailSuppressed() to return false, which
     * CarVerificationManager::clearSuppressed()'s persist() helper turns
     * into a thrown CarDatabaseException, exactly as a genuine deadlock or
     * constraint violation would. Mirrors
     * CarVerificationManagerSuppressForOwnerTest::dbFailingUpdateCar().
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
                return $this->real->errorString() ?: 'Simulated database failure for clearSuppressedForOwnerByOwner() test';
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
    public function testSimulatedDatabaseFailurePropagatesCarDatabaseException(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->db->query('UPDATE profiles SET email_suppressed = 1 WHERE user_id = ?', [$ownerId]);
        $carId = $this->createTestCar($ownerId, ['email' => 'failure-case@example.com', 'email_suppressed' => 1]);

        $failingRepo = new CarRepository($this->dbFailingUpdateCar());
        $failingManager = new CarVerificationManager($failingRepo);

        $this->expectException(CarDatabaseException::class);

        try {
            $failingManager->clearSuppressedForOwnerByOwner($ownerId);
        } finally {
            $this->assertSame(
                1,
                $this->emailSuppressed($carId),
                'A simulated database failure must leave the car suppressed'
            );
        }
    }

    /**
     * AC 9: clearing via the owner self-service path must produce the exact
     * same findVerificationEligible() membership as clearing via the
     * existing admin clearSuppressedForOwner() path — no special-cased
     * "send immediately" or other divergent behavior hides behind the new
     * method.
     */
    #[Group('fast')]
    public function testEligibilityParityWithAdminClearSuppressedForOwner(): void
    {
        $ownerOneId = $this->createTestUser([], true);
        $this->db->query('UPDATE profiles SET email_suppressed = 1 WHERE user_id = ?', [$ownerOneId]);
        $ownerTwoId = $this->createTestUser([], true);
        $this->db->query('UPDATE profiles SET email_suppressed = 1 WHERE user_id = ?', [$ownerTwoId]);

        $carOneId = $this->createEligibleSuppressedCar($ownerOneId);
        $carTwoId = $this->createEligibleSuppressedCar($ownerTwoId);

        // Neither car is eligible before either clear.
        $before = $this->allVerificationEligibleCarIds();
        $this->assertNotContains($carOneId, $before);
        $this->assertNotContains($carTwoId, $before);

        $this->manager->clearSuppressedForOwnerByOwner($ownerOneId);
        $this->manager->clearSuppressedForOwner($ownerTwoId);

        $after = $this->allVerificationEligibleCarIds();

        $this->assertContains($carOneId, $after, 'Owner-cleared car must be eligible, same as the admin-cleared one');
        $this->assertContains($carTwoId, $after, 'Admin-cleared car must be eligible');
    }
}
