<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/../../app/admin/includes/fix-script-core.php';

use ElanRegistry\DatabaseInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeDatabase;

/**
 * #1961: drift-detection query functions of 26-Reconcile-Owner-Fields.php.
 * The page is securePage()-gated, so the functions are loaded with
 * IntegrationTestCase::loadOwnerFieldDriftFunctions().
 */
#[Group('integration')]
#[Group('database')]
final class ReconcileOwnerFieldsAnalyzeTest extends IntegrationTestCase
{
    private const SCRIPT_PATH = __DIR__ . '/../../app/admin/scripts/maintenance/26-Reconcile-Owner-Fields.php';

    /** @var list<int> Profile IDs created directly by this test, deleted in tearDown(). */
    private array $createdProfileIds = [];

    /** @var list<int> fix_script_runs.id values inserted by this test, deleted in tearDown(). */
    private array $insertedRunIds = [];

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

            foreach ($this->insertedRunIds as $runId) {
                try {
                    $this->db->delete('fix_script_runs', ['id', '=', $runId]);
                } catch (\Throwable $e) {
                    fwrite(STDERR, "NOTE: tearDown() cleanup failed for fix_script_runs id {$runId}: {$e->getMessage()}\n");
                }
            }
            $this->insertedRunIds = [];
        }

        parent::tearDown();
    }

    /**
     * createTestUser() does not create a profiles row; owner fields such as
     * city and website need one.
     *
     * @param array<string, mixed> $overrides
     */
    private function createTestProfile(int $userId, array $overrides = []): void
    {
        $defaults = [
            'user_id' => $userId,
            'bio'     => '',
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 45.5231,
            'lon'     => -122.6765,
            'website' => 'https://example.com',
        ];

        $this->db->insert('profiles', array_merge($defaults, $overrides));

        $row = $this->db->query('SELECT id FROM profiles WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId])->first();
        if (!$row) {
            throw new \RuntimeException("createTestProfile: insert failed for user_id={$userId}");
        }
        $this->createdProfileIds[] = (int) $row->id;
    }

    /** A car that matches its owner exactly reports no drift. */
    public function testCarMatchingOwnerExactlyReportsNoDrift(): void
    {
        $userId = $this->createTestUser([
            'fname' => 'Matching',
            'lname' => 'Owner',
            'email' => 'matching-owner@example.com',
        ]);
        $this->createTestProfile($userId, [
            'city'    => 'Eugene',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 44.0521,
            'lon'     => -123.0868,
            'website' => 'https://matching.example.com',
        ]);
        $carId = $this->createTestCar($userId, [
            'fname'   => 'Matching',
            'lname'   => 'Owner',
            'email'   => 'matching-owner@example.com',
            'city'    => 'Eugene',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 44.0521,
            'lon'     => -123.0868,
            'website' => 'https://matching.example.com',
        ]);

        // Check this test's own IDs, not global counts: other tests in the
        // shared schema can change the global aggregates (#2005).
        $this->assertNotContains(
            $carId,
            array_column($this->findAllDriftedCarDetails(), 'carId'),
            'An exactly-matching car must not be listed as repairable drift'
        );

        $this->assertNotContains(
            $userId,
            $this->allOwnerIdsWithDrift(),
            'A drift-free owner must not appear in the drift-repair work list'
        );

        $this->assertFalse(
            $this->isCarOrphaned($carId),
            'A drift-free, non-orphaned car must not be counted as orphaned'
        );
    }

    /**
     * A car with one drifted field (email) and one drifted numeric field
     * (lat) is counted precisely, and the pruning behavior of
     * findDriftedCarDetails() (only the fields that actually differ) is
     * verified.
     */
    public function testSingleFieldDriftIsCountedAndDetailIsPruned(): void
    {
        $userId = $this->createTestUser([
            'fname' => 'Drift',
            'lname' => 'Test',
            'email' => 'current-email@example.com',
        ]);
        $this->createTestProfile($userId, [
            'city'    => 'Salem',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 44.9429,
            'lon'     => -123.0351,
            'website' => 'https://salem.example.com',
        ]);
        $carId = $this->createTestCar($userId, [
            'fname'   => 'Drift',
            'lname'   => 'Test',
            'email'   => 'stale-email@example.com', // drifted
            'city'    => 'Salem',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 40.0000, // drifted
            'lon'     => -123.0351,
            'website' => 'https://salem.example.com',
        ]);

        // ID-scoped checks, not global count deltas (#2005).

        $ownerIds = findOwnerIdsWithDrift(dbi());
        $this->assertContains($userId, $ownerIds, 'The drifted owner must appear in the work list');

        $details = $this->findAllDriftedCarDetails();
        $matching = array_values(array_filter($details, static fn ($row) => $row['carId'] === $carId));
        $this->assertCount(1, $matching, 'Exactly one detail row must exist for the drifted car');

        $row = $matching[0];
        $this->assertSame($userId, $row['ownerId']);
        $this->assertSame('Drift Test', $row['ownerName']);

        $this->assertSame(
            ['email', 'lat'],
            array_keys($row['fields']),
            'findDriftedCarDetails() must prune to only the fields that actually differ'
        );
        $this->assertSame('stale-email@example.com', $row['fields']['email']['car']);
        $this->assertSame('current-email@example.com', $row['fields']['email']['owner']);
        $this->assertSame('40', $row['fields']['lat']['car']);
        $this->assertSame('44.9429', $row['fields']['lat']['owner']);
    }

    /** An orphan car is counted, but its missing owner is never worklisted. */
    public function testOrphanedUserIdIsCountedButExcludedFromDriftWorklist(): void
    {
        // Far outside the auto-increment range of any test run.
        $orphanUserId = 999_999_999;

        $ownerIdsBefore = findOwnerIdsWithDrift(dbi());
        $this->assertNotContains($orphanUserId, $ownerIdsBefore, 'Precondition: the orphan sentinel ID must not already be a real user');

        $carId = $this->createTestCar($this->createTestUser(), ['user_id' => $orphanUserId]);

        // ID-scoped, not a global count delta (#2005).
        $this->assertTrue(
            $this->isCarOrphaned($carId),
            'findOrphanedOwnerCarCount()\'s predicate must classify this car as orphaned'
        );

        $ownerIdsAfter = findOwnerIdsWithDrift(dbi());
        $this->assertNotContains(
            $orphanUserId,
            $ownerIdsAfter,
            'An orphaned user_id must never appear in the drift-repair work list — it cannot be compared to a nonexistent owner'
        );

        $this->assertGreaterThan(0, $carId);
    }

    /** An owner with one drifted car of two is counted and worklisted once. */
    public function testMultiCarOwnerWithOneDriftedCarDedupsToOneOwner(): void
    {
        $userId = $this->createTestUser([
            'fname' => 'Multi',
            'lname' => 'Car',
            'email' => 'multi-car@example.com',
        ]);
        $this->createTestProfile($userId, [
            'city'    => 'Bend',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 44.0582,
            'lon'     => -121.3153,
            'website' => 'https://bend.example.com',
        ]);

        // Matching car: no drift.
        $this->createTestCar($userId, [
            'fname'   => 'Multi',
            'lname'   => 'Car',
            'email'   => 'multi-car@example.com',
            'city'    => 'Bend',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 44.0582,
            'lon'     => -121.3153,
            'website' => 'https://bend.example.com',
        ]);

        // Drifted car: email differs.
        $driftedCarId = $this->createTestCar($userId, [
            'fname'   => 'Multi',
            'lname'   => 'Car',
            'email'   => 'old-email@example.com',
            'city'    => 'Bend',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 44.0582,
            'lon'     => -121.3153,
            'website' => 'https://bend.example.com',
        ]);

        // ID-scoped checks, not global count deltas (#2005).

        $ownerIds = findOwnerIdsWithDrift(dbi());
        $matches = array_values(array_filter($ownerIds, static fn ($id) => $id === $userId));
        $this->assertCount(1, $matches, 'findOwnerIdsWithDrift() must return the owner exactly once, not once per drifted car');

        $details = $this->findAllDriftedCarDetails();
        $carIds = array_column($details, 'carId');
        $this->assertContains($driftedCarId, $carIds);
        $this->assertSame(1, count(array_filter($carIds, static fn ($id) => $id === $driftedCarId)), 'The drifted car must appear exactly once in the detail list');
    }

    /**
     * The zero-drift Analyze branch records completion with this shared
     * helper. The gated AJAX handler itself has no harness.
     */
    public function testZeroDriftCompletionIsRecordedViaSharedHelper(): void
    {
        $userId = $this->createTestUser();

        $beforeMax = $this->maxRunId();

        admin_script_record_completion(self::SCRIPT_PATH, $userId);

        $rows = $this->db->query(
            'SELECT id, script_name, completed_at FROM fix_script_runs WHERE id > ? ORDER BY id DESC',
            [$beforeMax]
        )->results();

        $this->assertNotEmpty($rows, 'admin_script_record_completion() must insert a fix_script_runs row');

        $row = $rows[0];
        $this->insertedRunIds[] = (int) $row->id;

        $this->assertSame(
            '26-Reconcile-Owner-Fields.php',
            $row->script_name,
            'fix_script_runs.script_name must be the basename of 26-Reconcile-Owner-Fields.php'
        );

        $completedAt = strtotime((string) $row->completed_at);
        $this->assertNotFalse($completedAt, 'completed_at must be a parseable timestamp');
        $this->assertGreaterThan(
            time() - 60,
            $completedAt,
            'completed_at must be a fresh timestamp recorded by this test run, not a stale row'
        );
    }

    /**
     * A car holding its own website, different from its owner's profile
     * website, is a conflict: syncing would destroy the car's value rather
     * than refresh it.
     */
    public function testCarWebsiteDifferingFromOwnersIsDetectedAsAConflict(): void
    {
        $userId = $this->createTestUser([
            'fname' => 'Conflict',
            'lname' => 'Owner',
            'email' => 'conflict-owner@example.com',
        ]);
        $this->createTestProfile($userId, ['website' => 'www.lotus-elan.net']);
        $carId = $this->createTestCar($userId, [
            'fname'   => 'Conflict',
            'lname'   => 'Owner',
            'email'   => 'conflict-owner@example.com',
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 45.5231,
            'lon'     => -122.6765,
            // Both values are real sites, and neither is obviously the stale one.
            'website' => 'https://www.myoldies.net',
        ]);

        $this->assertContains(
            $userId,
            findOwnerIdsWithWebsiteConflict(dbi()),
            'An owner whose car holds a different website must be flagged for manual review'
        );

        $conflicts = findWebsiteConflictCars(dbi());
        $matching = array_values(array_filter($conflicts, static fn ($row) => $row['carId'] === $carId));
        $this->assertCount(1, $matching, 'The conflicted car must be reported exactly once');
        $this->assertSame('https://www.myoldies.net', $matching[0]['carWebsite']);
        $this->assertSame('www.lotus-elan.net', $matching[0]['ownerWebsite']);
        $this->assertSame($userId, $matching[0]['ownerId']);
    }

    /**
     * An empty owner website against a real car website is not a conflict:
     * the owner has no competing value, so it syncs normally.
     */
    public function testEmptyOwnerWebsiteAgainstRealCarWebsiteIsNotAConflict(): void
    {
        $userId = $this->createTestUser([
            'fname' => 'Blank',
            'lname' => 'Profile',
            'email' => 'blank-profile@example.com',
        ]);
        $this->createTestProfile($userId, ['website' => '']);
        $carId = $this->createTestCar($userId, ['website' => 'https://carsite.example.com']);

        $conflictCarIds = array_column(findWebsiteConflictCars(dbi()), 'carId');
        $this->assertNotContains($carId, $conflictCarIds, 'An empty owner website has nothing to protect the car from');

        $this->assertNotContains(
            $userId,
            findOwnerIdsWithWebsiteConflict(dbi()),
            'An owner must not be held back when their own website is empty'
        );

        // ...and it is still ordinary drift, so it does get repaired.
        $this->assertContains($userId, findOwnerIdsWithDrift(dbi()));
    }

    /** An empty car website is not a conflict: there is nothing to lose. */
    public function testEmptyCarWebsiteIsNotAConflict(): void
    {
        $userId = $this->createTestUser([
            'fname' => 'Empty',
            'lname' => 'CarSite',
            'email' => 'empty-carsite@example.com',
        ]);
        $this->createTestProfile($userId, ['website' => 'https://owner.example.com']);
        $carId = $this->createTestCar($userId, ['website' => '']);

        $conflictCarIds = array_column(findWebsiteConflictCars(dbi()), 'carId');
        $this->assertNotContains($carId, $conflictCarIds, 'A car with no website of its own has nothing to protect');

        $this->assertNotContains(
            $userId,
            findOwnerIdsWithWebsiteConflict(dbi()),
            'An owner must not be held back over a car that has no website to lose'
        );

        // ...and it is still ordinary drift, so it does get repaired.
        $this->assertContains($userId, findOwnerIdsWithDrift(dbi()));
    }

    /**
     * NULL car website vs NULL owner website is not drift. Owner::find()
     * normalizes NULL to '', so a one-sided coalesce made a permanent false
     * positive (found live during #1961 review).
     */
    public function testNullCarWebsiteAgainstNullOwnerWebsiteIsNotDrift(): void
    {
        $userId = $this->createTestUser([
            'fname' => 'Null',
            'lname' => 'BothSides',
            'email' => 'null-both-sides@example.com',
        ]);
        $this->createTestProfile($userId, [
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => null,
            'lon'     => null,
            'website' => null,
        ]);
        // createTestCar() leaves the other owner fields NULL; set them so that
        // only website is under test.
        $carId = $this->createTestCar($userId, [
            'fname'   => 'Null',
            'lname'   => 'BothSides',
            'email'   => 'null-both-sides@example.com',
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => null,
            'lon'     => null,
            'website' => null,
        ]);

        $this->assertNotContains(
            $userId,
            findOwnerIdsWithDrift(dbi()),
            'A NULL car website matching a NULL owner website must not be reported as drift, and every other field matches too'
        );

        $details = $this->findAllDriftedCarDetails();
        $matching = array_values(array_filter($details, static fn ($row) => $row['carId'] === $carId));
        $this->assertCount(0, $matching, 'This car must not appear in the drifted-car detail list at all');
    }

    /**
     * NULL vs '' website is not drift, in either direction. Other fields are
     * left drifted, so the assertion targets the website field only.
     */
    public function testNullCarWebsiteAgainstEmptyOwnerWebsiteIsNotDrift(): void
    {
        $userId = $this->createTestUser([
            'fname' => 'Null',
            'lname' => 'CarEmptyOwner',
            'email' => 'null-car-empty-owner@example.com',
        ]);
        $this->createTestProfile($userId, ['website' => '']);
        $carId = $this->createTestCar($userId, ['website' => null]);

        $details = $this->findAllDriftedCarDetails();
        $matching = array_values(array_filter($details, static fn ($row) => $row['carId'] === $carId));
        $this->assertCount(1, $matching, 'This car has ordinary drift on other fields and must still be reported');
        $this->assertArrayNotHasKey(
            'website',
            $matching[0]['fields'],
            'A NULL car website against an empty-string owner website must not be reported as a drifted field'
        );
    }

    /**
     * Cars on the `noowner` account are excluded from drift detection: its
     * placeholder data would overwrite the last real owner details. Its ID
     * is positive, so the `c.user_id > 0` filter does not catch it.
     */
    public function testNoOwnerAccountCarsAreExcludedFromDriftAndCountedSeparately(): void
    {
        $noOwnerId = findNoOwnerAccountId(dbi());
        if ($noOwnerId === null) {
            $this->markTestSkipped('This database has no `noowner` system account.');
        }

        $countBefore = findNoOwnerAccountCarCount(dbi(), $noOwnerId);

        // Real-looking data against the account's placeholders.
        $carId = $this->createTestCar($this->createTestUser(), [
            'user_id' => $noOwnerId,
            'email'   => 'last-known-real-owner@example.com',
            'fname'   => 'Real',
            'lname'   => 'Owner',
            'city'    => 'Bristol',
        ]);

        $this->assertSame(
            $countBefore + 1,
            findNoOwnerAccountCarCount(dbi(), $noOwnerId),
            'The system account\'s cars must be counted for the admin\'s information'
        );

        $this->assertNotContains(
            $noOwnerId,
            findOwnerIdsWithDrift(dbi()),
            'The `noowner` system account must never appear in the drift-repair work list'
        );

        // ID-scoped, not a global count delta (#2005).
        $this->assertNotContains(
            $carId,
            array_column($this->findAllDriftedCarDetails(), 'carId'),
            'A car on the system account must not be listed as repairable drift'
        );
    }

    /**
     * A failed drift query must throw, not read as zero drift. DB::query()
     * does not throw on failure; two earlier bugs (collation, LIMIT binding)
     * hid real drift that way.
     */
    public function testFailedDriftSummaryQueryThrowsRatherThanReportingZeroDrift(): void
    {
        $failingDb = $this->makeFailingDatabase();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/drift summary query failed/');

        findOwnerFieldDriftSummary($failingDb);
    }

    /**
     * Reports every query as failed the way \DB does: query() returns
     * itself, error() is true, and the result set is empty.
     */
    private function makeFailingDatabase(): DatabaseInterface
    {
        return new class extends FakeDatabase {
            /** SQL of the most recent query(), mirroring \DB's per-call error state. */
            public ?string $lastSql = null;

            /** Reads of the error state, recorded so the accessors are observably impure. */
            public int $errorReads = 0;

            public function query(string $sql, array $params = []): self
            {
                $this->lastSql = $sql;

                return $this;
            }

            public function error(): bool
            {
                $this->errorReads++;

                return $this->lastSql !== null;
            }

            public function errorString(): string
            {
                $this->errorReads++;

                return $this->lastSql === null
                    ? ''
                    : 'ERROR #HY000: simulated statement failure';
            }
        };
    }

    private function maxRunId(): int
    {
        $row = $this->db->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM fix_script_runs')->first();

        return is_object($row) ? (int) $row->max_id : 0;
    }

    /**
     * @return list<int>
     */
    private function allOwnerIdsWithDrift(): array
    {
        return findOwnerIdsWithDrift(dbi());
    }

    /**
     * Whether one car is orphaned, with the same predicate as
     * findOrphanedOwnerCarCount(), scoped to one car ID (#2005).
     */
    private function isCarOrphaned(int $carId): bool
    {
        $result = $this->db->query(
            'SELECT 1 FROM cars c LEFT JOIN users u ON u.id = c.user_id '
            . 'WHERE c.id = ? AND c.user_id > 0 AND u.id IS NULL',
            [$carId]
        );
        if ($result->error()) {
            throw new \RuntimeException('isCarOrphaned() query failed: ' . $result->errorString());
        }

        return is_object($result->first());
    }

    /**
     * Pages through findDriftedCarDetails(), so tests do not depend on the
     * 50-row page size.
     *
     * @return list<array{carId: int, ownerId: int, ownerName: string, fields: array<string, array{car: string|null, owner: string|null}>}>
     */
    private function findAllDriftedCarDetails(): array
    {
        $all = [];
        $offset = 0;
        $pageSize = 50;

        do {
            $page = findDriftedCarDetails(dbi(), $pageSize, $offset);
            $all = array_merge($all, $page);
            $offset += count($page);
        } while (count($page) === $pageSize);

        return $all;
    }

}
