<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\Group;

/**
 * CarVerificationManager::clearSuppressedForOwnerByOwner() (owner
 * self-service "Resume verification emails") against real SQL.
 *
 * Resume clears `profiles.email_opted_out` and `email_opted_out_at` and
 * nothing else (#2101). It leaves `email_blocked` and `email_bounced`, and it
 * writes nothing to a car.
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

    /** @return array<string, mixed> The profile flag columns, as stored */
    private function flags(int $ownerId): array
    {
        return $this->getOwnerEmailFlags($ownerId);
    }

    /** @return array<string, mixed> The full cars row as stored */
    private function carRow(int $carId): array
    {
        $row = $this->repo->findById($carId);
        $this->assertNotNull($row, "Test setup: car {$carId} disappeared");

        return (array) $row;
    }

    private function staleDate(): string
    {
        return date('Y-m-d H:i:s', strtotime(self::STALE_DATE));
    }

    /**
     * A stale car, so that only the owner's flags keep it out of findVerificationEligible().
     */
    private function createEligibleCar(int $ownerId): int
    {
        return $this->createTestCar($ownerId, [
            'email' => 'eligible-' . uniqid() . '@example.com',
            'owner_last_updated' => $this->staleDate(),
            'last_verified' => $this->staleDate(),
        ]);
    }

    #[Group('fast')]
    public function testResumeClearsTheOptOutAndWritesNothingToAnyOwnedCar(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->setOwnerOptedOut($ownerId, true, '2026-01-02 03:04:05');
        $unsoldCarId = $this->createTestCar($ownerId, ['email' => 'owner-unsold@example.com']);
        $soldCarId = $this->createTestCar($ownerId, [
            'email' => 'owner-sold@example.com',
            'solddate' => date('Y-m-d', strtotime('-30 days')),
        ]);
        $unsoldBefore = $this->carRow($unsoldCarId);
        $soldBefore = $this->carRow($soldCarId);
        $this->assertSame('1', (string) $this->flags($ownerId)['email_opted_out'], 'Test sanity: owner starts opted out');

        $changed = $this->manager->clearSuppressedForOwnerByOwner($ownerId);

        $this->assertTrue($changed, 'The opt-out changed from 1 to 0');
        $flags = $this->flags($ownerId);
        $this->assertSame('0', (string) $flags['email_opted_out']);
        $this->assertNull($flags['email_opted_out_at']);
        $this->assertSame($unsoldBefore, $this->carRow($unsoldCarId), 'The unsold car row must be unchanged');
        $this->assertSame($soldBefore, $this->carRow($soldCarId), 'The sold car row must be unchanged');
    }

    #[Group('fast')]
    public function testResumeWritesOneOwnerSourceLogRowWithOwnerAsActingUser(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->setOwnerOptedOut($ownerId);

        $this->manager->clearSuppressedForOwnerByOwner($ownerId);

        $changes = $this->repo->recentFlagChanges($ownerId, 100);
        $this->assertCount(1, $changes);
        $this->assertSame('email_opted_out', $changes[0]['flag']);
        $this->assertSame(1, $changes[0]['old']);
        $this->assertSame(0, $changes[0]['new']);
        $this->assertSame('owner', $changes[0]['source']);
        $this->assertSame($ownerId, $changes[0]['acting_user_id']);

        // A second Resume changes nothing and writes no second row.
        $this->assertFalse($this->manager->clearSuppressedForOwnerByOwner($ownerId));
        $this->assertCount(1, $this->repo->recentFlagChanges($ownerId, 100));
    }

    #[Group('fast')]
    public function testDifferentOwnersOptOutIsUntouched(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->setOwnerOptedOut($ownerId);
        $otherOwnerId = $this->createTestUser([], true);
        $this->setOwnerOptedOut($otherOwnerId, true, '2026-05-06 07:08:09');
        $otherBefore = $this->flags($otherOwnerId);

        $this->manager->clearSuppressedForOwnerByOwner($ownerId);

        $this->assertSame('0', (string) $this->flags($ownerId)['email_opted_out']);
        $this->assertSame($otherBefore, $this->flags($otherOwnerId), 'A different owner\'s profile must be unchanged');
    }

    #[Group('fast')]
    public function testOwnerWithBouncedAndOptedOutEndsWithOnlyTheOptOutCleared(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->setOwnerOptedOut($ownerId);
        $this->setOwnerBounced($ownerId, 'bounced-and-opted-out@example.com', '2026-03-04 05:06:07');

        $this->manager->clearSuppressedForOwnerByOwner($ownerId);

        $flags = $this->flags($ownerId);
        $this->assertSame('0', (string) $flags['email_opted_out'], 'The opt-out must be cleared');
        $this->assertSame('1', (string) $flags['email_bounced'], 'The bounce flag must be left as it was');
        $this->assertSame('bounced-and-opted-out@example.com', $flags['email_bounced_address']);
        $this->assertSame('2026-03-04 05:06:07', $flags['email_bounced_at']);
    }

    /**
     * An owner who complained and then opted out: Resume clears the opt-out
     * and leaves the block, its address, time and cause. Verification still
     * excludes the owner, so mail does not reach a blocked address.
     */
    #[Group('fast')]
    public function testResumeByBlockedAndOptedOutOwnerKeepsTheBlockAndVerificationStillExcludesIt(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->setOwnerBlocked($ownerId, 'complained@example.com', '2026-02-03 04:05:06', 'spam');
        $this->setOwnerOptedOut($ownerId);
        $carId = $this->createEligibleCar($ownerId);
        $blockBefore = array_intersect_key($this->flags($ownerId), array_flip([
            'email_blocked', 'email_blocked_address', 'email_blocked_at', 'email_blocked_cause',
        ]));
        $this->assertNotContains($carId, $this->allVerificationEligibleCarIds(), 'Test sanity: excluded before Resume');

        $this->manager->clearSuppressedForOwnerByOwner($ownerId);

        $flags = $this->flags($ownerId);
        $this->assertSame('0', (string) $flags['email_opted_out'], 'Resume clears the opt-out');
        $this->assertSame(
            $blockBefore,
            array_intersect_key($flags, $blockBefore),
            'Resume must leave the block, its address, time and cause unchanged'
        );
        $this->assertNotContains(
            $carId,
            $this->allVerificationEligibleCarIds(),
            'The owner is still blocked, so verification must still exclude the car'
        );
    }

    /**
     * Real connection, except an `UPDATE profiles` statement is not run and
     * reports failure. Every other statement, including the row lock, runs.
     */
    private function dbFailingProfileUpdate(): DatabaseInterface
    {
        $real = $this->db;
        return new class ($real) implements DatabaseInterface {
            private bool $failed = false;

            public function __construct(private DatabaseInterface $real)
            {
            }

            public function query(string $sql, array $params = []): self
            {
                $this->failed = str_starts_with(ltrim($sql), 'UPDATE profiles');
                if (!$this->failed) {
                    $this->real->query($sql, $params);
                }
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
                return $this->real->update($table, $id, $fields);
            }

            public function delete(string $table, array|int $where): self|false
            {
                $result = $this->real->delete($table, $where);
                return $result === false ? false : $this;
            }

            public function error(): bool
            {
                return $this->failed || $this->real->error();
            }

            public function errorString(): string
            {
                return $this->failed
                    ? 'Simulated database failure for the profile UPDATE'
                    : $this->real->errorString();
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
    public function testSimulatedDatabaseFailurePropagatesCarDatabaseExceptionAndKeepsTheOptOut(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->setOwnerOptedOut($ownerId);
        $this->createTestCar($ownerId, ['email' => 'failure-case@example.com']);

        $failingManager = new CarVerificationManager(new CarRepository($this->dbFailingProfileUpdate()));

        try {
            $failingManager->clearSuppressedForOwnerByOwner($ownerId);
            $this->fail('Expected CarDatabaseException when the profile UPDATE fails');
        } catch (CarDatabaseException $e) {
            $this->assertStringContainsString('setProfileOptedOut', $e->getMessage());
        }

        $this->assertSame(
            '1',
            (string) $this->flags($ownerId)['email_opted_out'],
            'A failed profile UPDATE must leave the owner opted out'
        );
        $this->assertSame([], $this->repo->recentFlagChanges($ownerId, 100), 'A failed write logs no flag change');
    }

    /** #1895 AC 9: same eligibility result as the admin clearSuppressedForOwner(). */
    #[Group('fast')]
    public function testEligibilityParityWithAdminClearSuppressedForOwner(): void
    {
        $ownerOneId = $this->createTestUser([], true);
        $this->setOwnerOptedOut($ownerOneId);
        $ownerTwoId = $this->createTestUser([], true);
        $this->setOwnerOptedOut($ownerTwoId);

        $carOneId = $this->createEligibleCar($ownerOneId);
        $carTwoId = $this->createEligibleCar($ownerTwoId);

        $before = $this->allVerificationEligibleCarIds();
        $this->assertNotContains($carOneId, $before);
        $this->assertNotContains($carTwoId, $before);

        $this->manager->clearSuppressedForOwnerByOwner($ownerOneId);
        $this->manager->clearSuppressedForOwner($ownerTwoId, $ownerTwoId);

        $after = $this->allVerificationEligibleCarIds();

        $this->assertContains($carOneId, $after, 'Owner-cleared car must be eligible, same as the admin-cleared one');
        $this->assertContains($carTwoId, $after, 'Admin-cleared car must be eligible');
    }

    /**
     * #1895 double-submit race: B holds a snapshot, A clears the flag and commits.
     * B's locking read must see A's 0 and skip the profile write, not throw.
     */
    #[Group('fast')]
    public function testConcurrentResumeAfterOtherRequestCommittedSucceeds(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->setOwnerOptedOut($ownerId);

        $otherRequest = $this->secondConnection();

        $this->repo->beginTransaction();
        try {
            $this->assertSame(1, $this->repo->findProfileOptedOut($ownerId), 'Test setup: B reads 1 first');

            $stmt = $otherRequest->prepare('UPDATE profiles SET email_opted_out = 0 WHERE user_id = ?');
            $stmt->execute([$ownerId]);
            $this->assertSame(1, $stmt->rowCount(), 'Test setup: request A must clear and commit the profile flag');

            $this->assertSame(
                1,
                $this->repo->findProfileOptedOut($ownerId),
                'Test setup: B\'s plain read must still return its snapshot value, or this test proves nothing'
            );

            $this->assertFalse(
                $this->manager->clearSuppressedForOwnerByOwner($ownerId),
                'B must see the committed 0 through its locking read and change nothing'
            );
            $this->repo->commit();
        } catch (\Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }

        $this->assertSame('0', (string) $this->flags($ownerId)['email_opted_out']);
    }

    private function secondConnection(): \PDO
    {
        $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST'));
        $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306;
        $name = (string) ($_ENV['DB_NAME'] ?? getenv('DB_NAME'));

        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);
        }

        return new \PDO(
            "mysql:host={$host};port={$port};dbname={$name}",
            (string) ($_ENV['DB_USER'] ?? getenv('DB_USER')),
            (string) ($_ENV['DB_PASS'] ?? getenv('DB_PASS')),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }
}
