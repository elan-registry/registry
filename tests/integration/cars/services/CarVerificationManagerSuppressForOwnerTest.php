<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PassThroughDatabase;

/**
 * CarVerificationManager::setSuppressedForOwner() (one-click opt-out) against real SQL.
 *
 * The opt-out is one profile row (#2101). Nothing is written to a car.
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

    /**
     * @return array<string, mixed> The profile flag columns, as stored
     */
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

    /**
     * @return list<array{date: string, flag: string, old: int, new: int, source: string, acting_user_id: int}>
     */
    private function flagChanges(int $ownerId): array
    {
        return $this->repo->recentFlagChanges($ownerId, 100);
    }

    #[Group('fast')]
    public function testOptOutWritesOneProfileRowAndLeavesEveryOwnedCarUnchanged(): void
    {
        $ownerId = $this->createTestUser([], true);
        $carIds = [
            $this->createTestCar($ownerId, ['email' => 'owner1-car1@example.com']),
            $this->createTestCar($ownerId, ['email' => 'owner1-car2@example.com']),
            $this->createTestCar($ownerId, ['email' => 'owner1-car3@example.com']),
        ];
        $carsBefore = array_map(fn (int $id): array => $this->carRow($id), $carIds);

        $this->assertSame('0', (string) $this->flags($ownerId)['email_opted_out'], 'Test sanity: owner starts opted in');

        $changed = $this->manager->setSuppressedForOwner($ownerId);

        $this->assertTrue($changed, 'The opt-out changed from 0 to 1');
        $flags = $this->flags($ownerId);
        $this->assertSame('1', (string) $flags['email_opted_out']);
        $this->assertNotNull($flags['email_opted_out_at'], 'The opt-out time must be recorded');
        $this->assertSame(
            $carsBefore,
            array_map(fn (int $id): array => $this->carRow($id), $carIds),
            'Nothing fans out to cars: all 3 car rows must be unchanged'
        );
    }

    #[Group('fast')]
    public function testOptOutWritesOneOwnerSourceLogRowWithoutAnAddress(): void
    {
        $ownerId = $this->createTestUser(['email' => 'optout-log@example.com'], true);

        $this->manager->setSuppressedForOwner($ownerId);

        $changes = $this->flagChanges($ownerId);
        $this->assertCount(1, $changes, 'One flag change writes exactly one log row');
        $this->assertSame('email_opted_out', $changes[0]['flag']);
        $this->assertSame(0, $changes[0]['old']);
        $this->assertSame(1, $changes[0]['new']);
        $this->assertSame('owner', $changes[0]['source']);
        $this->assertSame($ownerId, $changes[0]['acting_user_id'], 'The owner is the acting user');
        $this->assertStringNotContainsString('@', json_encode($changes, JSON_THROW_ON_ERROR));
    }

    /** #1929: opting out confirms nothing about the car. */
    #[Group('fast')]
    public function testOptOutDoesNotChangeOwnerLastUpdated(): void
    {
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createTestCar($ownerId, ['email' => 'optout-fresh@example.com']);

        $old = date('Y-m-d H:i:s', strtotime('-2 years'));
        $this->seedOwnerLastUpdated($carId, $old);
        $before = $this->getOwnerLastUpdated($carId);
        $this->assertSame($old, $before, 'Precondition: the seeded value must be stored as written');

        $this->manager->setSuppressedForOwner($ownerId);

        $this->assertSame(
            '1',
            (string) $this->flags($ownerId)['email_opted_out'],
            'Precondition: the opt-out must have been recorded'
        );
        $this->assertSame($before, $this->getOwnerLastUpdated($carId), 'The opt-out must not change owner_last_updated');
    }

    #[Group('fast')]
    public function testDifferentOwnersProfileIsUntouched(): void
    {
        $ownerId = $this->createTestUser([], true);
        $otherOwnerId = $this->createTestUser([], true);
        $this->createTestCar($ownerId, ['email' => 'owner2-car@example.com']);
        $this->createTestCar($otherOwnerId, ['email' => 'other-owner-car@example.com']);
        $otherBefore = $this->flags($otherOwnerId);

        $this->manager->setSuppressedForOwner($ownerId);

        $this->assertSame('1', (string) $this->flags($ownerId)['email_opted_out'], 'The target owner is opted out');
        $this->assertSame($otherBefore, $this->flags($otherOwnerId), 'A different owner\'s profile must be unchanged');
        $this->assertSame([], $this->flagChanges($otherOwnerId), 'A different owner gets no log row');
    }

    #[Group('fast')]
    public function testReRunningOnSameOwnerIsNoOpKeepsFirstTimeAndWritesNoSecondLogRow(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->createTestCar($ownerId, ['email' => 'owner3-car@example.com']);

        $this->assertTrue($this->manager->setSuppressedForOwner($ownerId), 'First run changes the flag');
        $firstAt = $this->flags($ownerId)['email_opted_out_at'];
        $this->assertNotNull($firstAt);
        // Move the stored time into the past, so that a rewrite would show.
        $this->db->query(
            'UPDATE profiles SET email_opted_out_at = ? WHERE user_id = ?',
            ['2026-01-02 03:04:05', $ownerId]
        );

        $this->assertFalse(
            $this->manager->setSuppressedForOwner($ownerId),
            'A re-run on an already opted-out owner is a no-op that returns false'
        );

        $flags = $this->flags($ownerId);
        $this->assertSame('1', (string) $flags['email_opted_out'], 'The flag stays set, not toggled');
        $this->assertSame('2026-01-02 03:04:05', $flags['email_opted_out_at'], 'A repeat opt-out keeps the first time');
        $this->assertCount(1, $this->flagChanges($ownerId), 'A no-op writes no second log row');
    }

    /**
     * The opt-out is the owner's own request and must outlive a later
     * clear of the block or the bounce, so it is set also when those are set.
     */
    #[Group('fast')]
    public function testOptOutIsSetAlsoWhenBlockedAndBouncedAndLeavesThoseFlagsUnchanged(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->setOwnerBlocked($ownerId, 'owner5@example.com', '2026-02-03 04:05:06', 'spam');
        $this->setOwnerBounced($ownerId, 'owner5@example.com', '2026-03-04 05:06:07');
        $this->createTestCar($ownerId, ['email' => 'owner5-car@example.com']);

        $this->assertTrue($this->manager->setSuppressedForOwner($ownerId));

        $flags = $this->flags($ownerId);
        $this->assertSame('1', (string) $flags['email_opted_out']);
        $this->assertSame('1', (string) $flags['email_blocked']);
        $this->assertSame('owner5@example.com', $flags['email_blocked_address']);
        $this->assertSame('2026-02-03 04:05:06', $flags['email_blocked_at']);
        $this->assertSame('spam', $flags['email_blocked_cause']);
        $this->assertSame('1', (string) $flags['email_bounced']);
        $this->assertSame('2026-03-04 05:06:07', $flags['email_bounced_at']);

        // Clear the block and the bounce. The opt-out stays.
        $this->db->query(
            'UPDATE profiles SET email_blocked = 0, email_bounced = 0 WHERE user_id = ?',
            [$ownerId]
        );
        $this->assertSame('1', (string) $this->flags($ownerId)['email_opted_out']);
    }

    /** An owner whose only car is sold still gets the owner-level opt-out. */
    #[Group('fast')]
    public function testOwnerWithOnlySoldCarStillGetsTheOptOut(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->createTestCar($ownerId, [
            'email' => 'owner8-sold-only@example.com',
            'solddate' => date('Y-m-d', strtotime('-30 days')),
        ]);

        $this->assertTrue($this->manager->setSuppressedForOwner($ownerId));
        $this->assertSame('1', (string) $this->flags($ownerId)['email_opted_out']);
    }

    /**
     * Must fail before it writes anything, or the owner-level decision is
     * silently lost. The schema allows a user without a profiles row.
     */
    #[Group('fast')]
    public function testOwnerWithNoProfilesRowThrowsAndWritesNoLogRow(): void
    {
        $ownerId = $this->createTestUser();
        $carId = $this->createTestCar($ownerId, ['email' => 'owner6-car@example.com']);
        $carBefore = $this->carRow($carId);

        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$ownerId])->first()->cnt,
            'Test setup: the owner must have no profiles row'
        );

        try {
            $this->manager->setSuppressedForOwner($ownerId);
            $this->fail('Expected CarDatabaseException for an owner with no profiles row');
        } catch (CarDatabaseException $e) {
            $this->assertNotSame('', $e->getUserMessage(), 'The exception carries a user-safe message');
        }

        $this->assertSame($carBefore, $this->carRow($carId), 'The car must be unchanged');
        $this->assertSame([], $this->flagChanges($ownerId), 'No log row is written');
    }

    /**
     * Real connection, except an `UPDATE profiles` statement is not run and
     * reports failure. Every other statement, including the row lock, runs.
     */
    private function dbFailingProfileUpdate(): DatabaseInterface
    {
        return new class ($this->db) extends PassThroughDatabase {
            public function query(string $sql, array $params = []): static
            {
                return str_starts_with(ltrim($sql), 'UPDATE profiles')
                    ? $this->simulateFailure('Simulated database failure for the profile UPDATE')
                    : parent::query($sql, $params);
            }
        };
    }

    #[Group('fast')]
    public function testDatabaseFailureOnTheProfileUpdatePropagatesCarDatabaseExceptionAndKeepsFlagClear(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->createTestCar($ownerId, ['email' => 'owner4-car@example.com']);

        $failingManager = new CarVerificationManager(new CarRepository($this->dbFailingProfileUpdate()));

        try {
            $failingManager->setSuppressedForOwner($ownerId);
            $this->fail('Expected CarDatabaseException when the profile UPDATE fails');
        } catch (CarDatabaseException $e) {
            $this->assertStringContainsString('setProfileOptedOut', $e->getMessage());
        }

        $this->assertSame(
            '0',
            (string) $this->flags($ownerId)['email_opted_out'],
            'A failed profile UPDATE must leave the flag clear'
        );
        $this->assertSame([], $this->flagChanges($ownerId), 'A failed write must not log a flag change');
    }
}
