<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1961: the Execute step of 26-Reconcile-Owner-Fields.php. The page is
 * securePage()-gated, so the test reproduces its owner loop and totals.
 * Mid-sync ownership changes are covered in OwnerSyncOwnerFieldsToCarsTest.
 *
 * Count cars_hist by operation='OWNER_SYNC': the cars_update trigger also
 * writes an 'UPDATE' row on every matched UPDATE, including no-ops.
 *
 * @see app/admin/scripts/maintenance/26-Reconcile-Owner-Fields.php
 * @see usersc/classes/Owner.php Owner::syncOwnerFieldsToCars()
 */
#[Group('integration')]
#[Group('database')]
final class ReconcileOwnerFieldsExecuteTest extends IntegrationTestCase
{
    /** @var int[] Profile IDs to clean up in tearDown */
    private array $createdProfileIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->loadOwnerFieldDriftFunctions();
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            foreach ($this->createdProfileIds as $profileId) {
                try {
                    $this->db->query('DELETE FROM profiles WHERE id = ?', [$profileId]);
                } catch (\Throwable $e) {
                    fwrite(STDERR, "NOTE: tearDown() cleanup failed for profile id {$profileId}: {$e->getMessage()}\n");
                }
            }
            $this->createdProfileIds = [];
        }

        parent::tearDown();
    }

    /** Tracked for cleanup in tearDown(). */
    private function createTestProfile(int $userId, array $overrides = []): void
    {
        $defaults = [
            'user_id' => $userId,
            'bio'     => '',
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => null,
            'lon'     => null,
            'website' => '',
        ];

        $this->db->insert('profiles', array_merge($defaults, $overrides));

        $row = $this->db->query('SELECT id FROM profiles WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId])->first();
        if (!$row) {
            throw new \RuntimeException("createTestProfile: insert failed for user_id={$userId}");
        }
        $this->createdProfileIds[] = (int) $row->id;
    }

    /** The trigger also writes operation='UPDATE' rows, so scope to OWNER_SYNC. */
    private function countOwnerSyncHistoryRows(int $carId): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = 'OWNER_SYNC'",
            [$carId]
        );
        if ($result->error()) {
            throw new \RuntimeException('countOwnerSyncHistoryRows query failed: ' . $result->errorString());
        }
        return (int) $result->first()->cnt;
    }

    /**
     * The script's owner loop and totals. Catches only the two infrastructure
     * exception types the script absorbs, so a programming error fails the test.
     *
     * @param list<int> $ownerIds
     * @return array{updated:int, skipped:int, failed:int, ownersScanned:int, ownerErrors:int, perOwner: array<int, \ElanRegistry\OwnerSyncResult|null>}
     */
    private function runExecuteLoop(array $ownerIds): array
    {
        $totalUpdated = 0;
        $totalSkipped = 0;
        $totalFailed = 0;
        $ownersScanned = 0;
        $ownerErrors = 0;
        $perOwner = [];

        foreach ($ownerIds as $ownerId) {
            $ownersScanned++;
            try {
                $result = (new Owner($ownerId, $this->db))->syncOwnerFieldsToCars();
                $totalUpdated += $result->updatedCount();
                $totalSkipped += $result->skippedCount();
                $totalFailed += $result->failedCount();
                $perOwner[$ownerId] = $result;
            } catch (OwnerDatabaseException | CarDatabaseException $e) {
                // One owner's infrastructure failure must not abort the run.
                $ownerErrors++;
                $perOwner[$ownerId] = null;

                // Same rollback as the script, so a mid-transaction failure does not cascade.
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            }
        }

        return [
            'updated'       => $totalUpdated,
            'skipped'       => $totalSkipped,
            'failed'        => $totalFailed,
            'ownersScanned' => $ownersScanned,
            'ownerErrors'   => $ownerErrors,
            'perOwner'      => $perOwner,
        ];
    }

    /**
     * The loop with the website-conflict guard: the conflict set is resolved
     * once, and a conflicted owner is skipped entirely.
     *
     * @param list<int> $ownerIds
     * @return array{updated:int, skipped:int, failed:int, ownersScanned:int, ownerErrors:int, ownersSkippedForConflict:int, perOwner: array<int, \ElanRegistry\OwnerSyncResult|null>}
     */
    private function runExecuteLoopSkippingConflicts(array $ownerIds): array
    {
        $conflictsByOwner = [];
        foreach (findWebsiteConflictCars($this->db) as $conflict) {
            $conflictsByOwner[$conflict['ownerId']][] = $conflict;
        }

        $ownersSkippedForConflict = 0;
        $toSync = [];
        foreach ($ownerIds as $ownerId) {
            if (isset($conflictsByOwner[$ownerId])) {
                $ownersSkippedForConflict++;
                continue;
            }
            $toSync[] = $ownerId;
        }

        $totals = $this->runExecuteLoop($toSync);
        // Counts skipped owners too, as the script does.
        $totals['ownersScanned'] = count($ownerIds);
        $totals['ownersSkippedForConflict'] = $ownersSkippedForConflict;

        return $totals;
    }

    /**
     * A website conflict on one car holds back all of that owner's cars, and
     * only that owner. syncOwnerFieldsToCars() has no per-field opt-out.
     */
    public function testWebsiteConflictSkipsTheWholeOwnerButNotOtherOwners(): void
    {
        $conflictOwnerId = $this->createTestUser([
            'fname' => 'Conflict',
            'lname' => 'Owner',
            'email' => 'conflict-owner@example.com',
        ]);
        $this->createTestProfile($conflictOwnerId, [
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'website' => 'www.lotus-elan.net',
        ]);
        $conflictCarId = $this->createTestCar($conflictOwnerId, [
            'email'   => 'stale-conflict@example.com',
            'website' => 'https://www.myoldies.net',
        ]);
        $siblingCarId = $this->createTestCar($conflictOwnerId, [
            'email'   => 'stale-sibling@example.com',
            // No conflict of its own; held back because its owner has one.
            'website' => 'www.lotus-elan.net',
        ]);

        // A conflict is per owner, not a global stop.
        $cleanOwnerId = $this->createTestUser([
            'fname' => 'Clean',
            'lname' => 'Owner',
            'email' => 'clean-owner@example.com',
        ]);
        $this->createTestProfile($cleanOwnerId, [
            'city'    => 'Salem',
            'state'   => 'Oregon',
            'country' => 'United States',
            'website' => '',
        ]);
        $cleanCarId = $this->createTestCar($cleanOwnerId, [
            'email'   => 'stale-clean@example.com',
            'website' => '',
        ]);

        $conflictOwnerIds = findOwnerIdsWithWebsiteConflict($this->db);
        $this->assertContains($conflictOwnerId, $conflictOwnerIds);
        $this->assertNotContains($cleanOwnerId, $conflictOwnerIds);

        $workList = findOwnerIdsWithDrift($this->db);
        $this->assertContains($conflictOwnerId, $workList, 'The conflicted owner is still discovered as drifted...');
        $this->assertContains($cleanOwnerId, $workList);

        $totals = $this->runExecuteLoopSkippingConflicts(
            array_values(array_intersect($workList, [$conflictOwnerId, $cleanOwnerId]))
        );

        $this->assertSame(
            1,
            $totals['ownersSkippedForConflict'],
            '...but is skipped at Execute time rather than synced'
        );

        $conflictCar = $this->db->query('SELECT email, website FROM cars WHERE id = ?', [$conflictCarId])->first();
        $this->assertSame('https://www.myoldies.net', $conflictCar->website, 'The car\'s own website must survive the run');
        $this->assertSame('stale-conflict@example.com', $conflictCar->email, 'The conflicted car must not be synced at all');

        $siblingCar = $this->db->query('SELECT email FROM cars WHERE id = ?', [$siblingCarId])->first();
        $this->assertSame(
            'stale-sibling@example.com',
            $siblingCar->email,
            'The owner\'s OTHER car must be held back too — this is the whole-owner skip, and the cost of it'
        );

        $cleanCar = $this->db->query('SELECT email FROM cars WHERE id = ?', [$cleanCarId])->first();
        $this->assertSame(
            'clean-owner@example.com',
            $cleanCar->email,
            'An unrelated owner must still be repaired in the same run'
        );

        $this->assertSame(1, $totals['updated'], 'Only the clean owner\'s single car may be updated');
        $this->assertSame(0, $totals['failed']);
        $this->assertSame(
            0,
            $this->countOwnerSyncHistoryRows($conflictCarId),
            'A skipped owner\'s cars must gain no OWNER_SYNC history rows'
        );
        $this->assertSame(0, $this->countOwnerSyncHistoryRows($siblingCarId));
    }

    /**
     * One owner's failure must not abort the run. The middle owner's user row
     * is deleted (the real race), and the thrower is in the middle because a
     * last-position thrower would pass even if the loop aborted.
     */
    public function testOneOwnersFailureDoesNotStopLaterOwnersFromBeingRepaired(): void
    {
        $goodCarIds = [];
        $goodOwnerIds = [];

        foreach (['Good', 'Thrower', 'Later'] as $index => $label) {
            $ownerId = $this->createTestUser([
                'fname' => $label,
                'lname' => 'Owner',
                'email' => strtolower($label) . '-isolation@example.com',
            ]);
            $this->createTestProfile($ownerId, [
                'city'    => 'Portland',
                'state'   => 'Oregon',
                'country' => 'United States',
            ]);
            $carId = $this->createTestCar($ownerId, [
                'email' => 'stale-' . strtolower($label) . '@example.com',
                'city'  => 'StaleCity',
            ]);

            $goodOwnerIds[$index] = $ownerId;
            $goodCarIds[$index] = $carId;
        }

        [$firstOwnerId, $throwerOwnerId, $lastOwnerId] = $goodOwnerIds;
        [$firstCarId, $throwerCarId, $lastCarId] = $goodCarIds;

        $ownerIds = findOwnerIdsWithDrift($this->db);
        $this->assertContains($firstOwnerId, $ownerIds);
        $this->assertContains($throwerOwnerId, $ownerIds);
        $this->assertContains($lastOwnerId, $ownerIds);

        $ownerIdsToRun = [$firstOwnerId, $throwerOwnerId, $lastOwnerId];

        $this->db->query('DELETE FROM users WHERE id = ?', [$throwerOwnerId]);

        $totals = $this->runExecuteLoop($ownerIdsToRun);

        $this->assertSame(3, $totals['ownersScanned'], 'All three owners must be attempted');
        $this->assertSame(1, $totals['ownerErrors'], 'Exactly the one broken owner may be counted as an owner-level error');
        $this->assertNull($totals['perOwner'][$throwerOwnerId], 'The broken owner must have produced no sync result');

        $firstCar = $this->db->query('SELECT email, city FROM cars WHERE id = ?', [$firstCarId])->first();
        $this->assertSame('good-isolation@example.com', $firstCar->email, 'The owner before the failure must be repaired');
        $this->assertSame('Portland', $firstCar->city);

        $lastCar = $this->db->query('SELECT email, city FROM cars WHERE id = ?', [$lastCarId])->first();
        $this->assertSame(
            'later-isolation@example.com',
            $lastCar->email,
            'The owner AFTER the failure must still be repaired — this is what proves the loop continued rather than aborting'
        );
        $this->assertSame('Portland', $lastCar->city);

        $throwerCar = $this->db->query('SELECT email FROM cars WHERE id = ?', [$throwerCarId])->first();
        $this->assertSame('stale-thrower@example.com', $throwerCar->email, 'The broken owner\'s car must be left alone');

        $this->assertSame(2, $totals['updated'], 'Exactly the two healthy owners\' cars may be counted as updated');
        $this->assertSame(0, $totals['failed']);
        $this->assertSame(0, $totals['skipped']);
    }

    /** Totals equal the sum of each owner's OwnerSyncResult counts. */
    public function testTwoOwnersAggregatedCorrectly(): void
    {
        $owner1Id = $this->createTestUser([
            'fname' => 'Alice',
            'lname' => 'Anderson',
            'email' => 'alice@example.com',
        ]);
        $this->createTestProfile($owner1Id, [
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
        ]);
        $owner1Car1 = $this->createTestCar($owner1Id, [
            'email' => 'stale-alice@example.com',
            'city'  => 'StaleCity',
        ]);
        $owner1Car2 = $this->createTestCar($owner1Id, [
            'fname' => 'StaleFirstName',
        ]);

        $owner2Id = $this->createTestUser([
            'fname' => 'Bob',
            'lname' => 'Baker',
            'email' => 'bob@example.com',
        ]);
        $this->createTestProfile($owner2Id, [
            'city'    => 'Salem',
            'state'   => 'Oregon',
            'country' => 'United States',
        ]);
        $owner2Car1 = $this->createTestCar($owner2Id, [
            'city' => 'OldSalem',
        ]);

        $owner3Id = $this->createTestUser([
            'fname' => 'Carl',
            'lname' => 'Carter',
            'email' => 'carl@example.com',
        ]);
        $this->createTestProfile($owner3Id, [
            'city'    => 'Eugene',
            'state'   => 'Oregon',
            'country' => 'United States',
        ]);
        $owner3Car = $this->createTestCar($owner3Id, [
            'fname'   => 'Carl',
            'lname'   => 'Carter',
            'email'   => 'carl@example.com',
            'city'    => 'Eugene',
            'state'   => 'Oregon',
            'country' => 'United States',
            // createTestCar() leaves website NULL but the profile default is ''.
            'website' => '',
        ]);

        $ownerIds = findOwnerIdsWithDrift($this->db);

        $this->assertContains($owner1Id, $ownerIds, 'Owner 1 (drifted) must be in the work list');
        $this->assertContains($owner2Id, $ownerIds, 'Owner 2 (drifted) must be in the work list');
        $this->assertNotContains($owner3Id, $ownerIds, 'Owner 3 (not drifted) must not be in the work list');

        // Only this test's owners: the shared DB can hold other drifted owners.
        $ownerIdsToRun = array_values(array_intersect($ownerIds, [$owner1Id, $owner2Id]));
        $totals = $this->runExecuteLoop($ownerIdsToRun);

        $car1 = $this->db->query('SELECT email, city FROM cars WHERE id = ?', [$owner1Car1])->first();
        $this->assertSame('alice@example.com', $car1->email);
        $this->assertSame('Portland', $car1->city);

        $car2 = $this->db->query('SELECT fname FROM cars WHERE id = ?', [$owner1Car2])->first();
        $this->assertSame('Alice', $car2->fname);

        $car3 = $this->db->query('SELECT city FROM cars WHERE id = ?', [$owner2Car1])->first();
        $this->assertSame('Salem', $car3->city);

        $sumUpdated = 0;
        $sumSkipped = 0;
        $sumFailed = 0;
        foreach ($totals['perOwner'] as $result) {
            $this->assertNotNull($result, 'No owner-level exception expected in this scenario');
            $sumUpdated += $result->updatedCount();
            $sumSkipped += $result->skippedCount();
            $sumFailed += $result->failedCount();
        }

        $this->assertSame($sumUpdated, $totals['updated']);
        $this->assertSame($sumSkipped, $totals['skipped']);
        $this->assertSame($sumFailed, $totals['failed']);

        $this->assertSame(3, $totals['updated']);
        $this->assertSame(0, $totals['skipped']);
        $this->assertSame(0, $totals['failed']);
    }

    /** One OWNER_SYNC row per changed car; an unchanged car gets none. */
    public function testExactlyOneOwnerSyncRowPerActuallyChangedCar(): void
    {
        $ownerId = $this->createTestUser([
            'fname' => 'Drift',
            'lname' => 'Owner',
            'email' => 'drift-owner@example.com',
        ]);
        $this->createTestProfile($ownerId, [
            'city'    => 'Bend',
            'state'   => 'Oregon',
            'country' => 'United States',
        ]);

        $driftedCarId = $this->createTestCar($ownerId, [
            'email' => 'stale@example.com',
        ]);

        $owner = new Owner($ownerId, $this->db);
        $data = $owner->data();
        $this->assertNotNull($data);
        $matchingCarId = $this->createTestCar($ownerId, [
            'fname'   => $data->fname,
            'lname'   => $data->lname,
            'email'   => $data->email,
            'city'    => $data->city,
            'state'   => $data->state,
            'country' => $data->country,
            'lat'     => $data->lat,
            'lon'     => $data->lon,
            'website' => $data->website,
        ]);

        $this->assertSame(0, $this->countOwnerSyncHistoryRows($driftedCarId), 'Precondition: no OWNER_SYNC rows before the run');
        $this->assertSame(0, $this->countOwnerSyncHistoryRows($matchingCarId), 'Precondition: no OWNER_SYNC rows before the run');

        $ownerIds = findOwnerIdsWithDrift($this->db);
        $this->assertContains($ownerId, $ownerIds);

        $this->runExecuteLoop([$ownerId]);

        $this->assertSame(
            1,
            $this->countOwnerSyncHistoryRows($driftedCarId),
            'The actually-changed car must gain exactly one OWNER_SYNC row'
        );
        $this->assertSame(
            0,
            $this->countOwnerSyncHistoryRows($matchingCarId),
            'A car that already matched the owner must gain zero OWNER_SYNC rows'
        );
    }

    /** #1961: the run never touches owner_last_updated. */
    public function testOwnerLastUpdatedNeverTouched(): void
    {
        $ownerId = $this->createTestUser([
            'fname' => 'Invariant',
            'lname' => 'Owner',
            'email' => 'invariant-owner@example.com',
        ]);
        $this->createTestProfile($ownerId, [
            'city'    => 'Ashland',
            'state'   => 'Oregon',
            'country' => 'United States',
        ]);

        $staleOwnerLastUpdated = date('Y-m-d H:i:s', strtotime('-2 years'));
        $carId = $this->createTestCar($ownerId, [
            'email'              => 'stale-invariant@example.com',
            'owner_last_updated' => $staleOwnerLastUpdated,
        ]);

        $beforeValue = $this->getOwnerLastUpdated($carId);
        $this->assertSame($staleOwnerLastUpdated, $beforeValue, 'Precondition: owner_last_updated must start at the seeded stale value');

        $ownerIds = findOwnerIdsWithDrift($this->db);
        $this->assertContains($ownerId, $ownerIds);

        $totals = $this->runExecuteLoop([$ownerId]);
        $this->assertSame(1, $totals['updated'], 'Precondition: the seeded car must actually have been synced');

        $afterValue = $this->getOwnerLastUpdated($carId);
        $this->assertSame(
            $beforeValue,
            $afterValue,
            'owner_last_updated must be byte-for-byte unchanged after reconciliation — this is the invariant that keeps a synced car eligible for verification'
        );
    }

    /** A repeat run finds no drift and writes no new OWNER_SYNC rows. */
    public function testRepeatRunIsIdempotent(): void
    {
        $ownerId = $this->createTestUser([
            'fname' => 'Idempotent',
            'lname' => 'Owner',
            'email' => 'idempotent-owner@example.com',
        ]);
        $this->createTestProfile($ownerId, [
            'city'    => 'Corvallis',
            'state'   => 'Oregon',
            'country' => 'United States',
        ]);
        $carId = $this->createTestCar($ownerId, [
            'email' => 'stale-idempotent@example.com',
            'city'  => 'OldCorvallis',
        ]);

        $firstRunOwnerIds = findOwnerIdsWithDrift($this->db);
        $this->assertContains($ownerId, $firstRunOwnerIds);

        $firstTotals = $this->runExecuteLoop([$ownerId]);
        $this->assertSame(1, $firstTotals['updated']);
        $this->assertSame(1, $this->countOwnerSyncHistoryRows($carId), 'First run must write exactly one OWNER_SYNC row for the drifted car');

        $car = $this->db->query('SELECT email, city FROM cars WHERE id = ?', [$carId])->first();
        $this->assertSame('idempotent-owner@example.com', $car->email);
        $this->assertSame('Corvallis', $car->city);

        $secondRunOwnerIds = findOwnerIdsWithDrift($this->db);
        $this->assertNotContains(
            $ownerId,
            $secondRunOwnerIds,
            'After the first run leaves everything synced, this owner must not reappear in the drift work list'
        );

        $historyCountBeforeSecondRun = $this->countOwnerSyncHistoryRows($carId);

        $secondTotals = $this->runExecuteLoop([$ownerId]);

        $this->assertSame(0, $secondTotals['skipped'], 'A repeat run must not skip any car');
        $this->assertSame(0, $secondTotals['failed'], 'A repeat run must not fail any car');
        $this->assertSame(1, $secondTotals['updated'], 'A repeat run still reports the no-op UPDATE as a successful update (per syncOwnerFieldsToCars()\'s own no-op branch), just with no new history row');

        $result = $secondTotals['perOwner'][$ownerId];
        $this->assertNotNull($result);
        $this->assertTrue($result->isCompleteSuccess(), 'A repeat, no-op run must read as complete success');

        $this->assertSame(
            $historyCountBeforeSecondRun,
            $this->countOwnerSyncHistoryRows($carId),
            'A repeat, no-op run must add zero new OWNER_SYNC rows'
        );
    }

    /** The circuit breaker fires at exactly the threshold (#1992). */
    public function testConsecutiveErrorCircuitBreakerFiresAtExactThreshold(): void
    {
        for ($i = 0; $i < RECONCILE_MAX_CONSECUTIVE_OWNER_ERRORS - 1; $i++) {
            $this->assertFalse(
                reconcileShouldAbortForConsecutiveErrors($i),
                "Must not abort before the threshold is reached (at count {$i})"
            );
        }

        $this->assertFalse(
            reconcileShouldAbortForConsecutiveErrors(RECONCILE_MAX_CONSECUTIVE_OWNER_ERRORS - 1),
            'Must not abort one short of the threshold'
        );
        $this->assertTrue(
            reconcileShouldAbortForConsecutiveErrors(RECONCILE_MAX_CONSECUTIVE_OWNER_ERRORS),
            'Must abort exactly at the threshold'
        );
        $this->assertTrue(
            reconcileShouldAbortForConsecutiveErrors(RECONCILE_MAX_CONSECUTIVE_OWNER_ERRORS + 1),
            'Must still abort past the threshold — the loop should never reach this in practice (it aborts as soon as the threshold is hit), but the comparison itself must not falsely reset'
        );
    }

    /** A success resets the consecutive-failure count, so 4 + success + 4 must not trip. */
    public function testConsecutiveErrorCountResetsOnAnInterveningSuccess(): void
    {
        $consecutiveErrors = 0;
        $tripped = false;

        $outcomes = [false, false, false, false, true, false, false, false, false];

        foreach ($outcomes as $succeeded) {
            if ($succeeded) {
                $consecutiveErrors = 0;
                continue;
            }

            $consecutiveErrors++;
            if (reconcileShouldAbortForConsecutiveErrors($consecutiveErrors)) {
                $tripped = true;
                break;
            }
        }

        $this->assertFalse(
            $tripped,
            'A success partway through must reset the streak — 4+4 failures either side of one success must never reach the 5-in-a-row threshold'
        );
    }
}
