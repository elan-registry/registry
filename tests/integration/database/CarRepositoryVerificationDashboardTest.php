<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationEmailComposer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration (real MySQL) tests for the verification dashboard's count and
 * queue-list methods (#1896):
 *   - CarRepository::countVerificationSummary()
 *   - CarRepository::findVerificationQueue()
 *   - CarRepository::findRecentVerificationActivity()
 *
 * Playwright coverage for the dashboard can only confirm "the page renders
 * what the method returned" — it cannot tell a broken query from a broken
 * page showing matching wrong numbers. This file seeds one real car per
 * dashboard state and asserts both the count AND the matching queue-list
 * method return the same set, against the real `cars`/`cars_hist`/`profiles`
 * tables.
 *
 * State definitions (see CarRepository::countVerificationSummary()'s own
 * docblock, which is the one definition both the count and the queue share):
 *   - eligible: findVerificationEligible()'s predicate.
 *   - pending: sent, link still live (within
 *     CarVerificationEmailComposer::LINK_TTL_DAYS), no response yet.
 *   - bounced: cars.email_bounced = 1.
 *   - suppressed: cars.email_suppressed = 1 OR profiles.email_suppressed = 1.
 *   - verified / sold: distinct cars with a cars_hist VERIFIED / VERIFIED SOLD
 *     row in the window, counted by car, not by history row.
 *   - all: eligible OR pending OR bounced OR suppressed (verified/sold are
 *     history, not queue state, so they are excluded from `all`).
 */
#[Group('integration')]
final class CarRepositoryVerificationDashboardTest extends IntegrationTestCase
{
    private CarRepository $repo;
    private int $userId;

    /** More than 1 year ago — stale for both last_verified and owner_last_updated. */
    private const STALE_DATE = '-3 years';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repo = new CarRepository($this->db);
        $this->userId = $this->createTestUser();
    }

    private function staleDate(): string
    {
        return date('Y-m-d H:i:s', strtotime(self::STALE_DATE));
    }

    /** Baseline fields for a car that is otherwise plain-eligible. */
    private function eligibleFields(string $email): array
    {
        return [
            'email'              => $email,
            'email_bounced'      => 0,
            'email_suppressed'   => 0,
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
            'vericode'           => null,
            'vericode_sent_at'   => null,
        ];
    }

    /**
     * Insert a minimal, valid cars_hist row with a given operation/timestamp
     * for an existing car. Mirrors CarRepositoryHistoryOperationTest's
     * insertHistRow() helper.
     */
    private function insertHistRow(int $carId, string $operation, string $timestamp): void
    {
        $inserted = $this->db->insert('cars_hist', [
            'operation' => $operation,
            'car_id'    => $carId,
            'model'     => 'Elan',
            'series'    => 'S4',
            'variant'   => 'SE',
            'type'      => 'FHC',
            'chassis'   => 'VDHISTTEST',
            'timestamp' => $timestamp,
        ]);
        $this->assertTrue($inserted, 'Test setup: failed to seed cars_hist row: ' . $this->db->errorString());
    }

    /** @return list<int> Car ids present in a queue-list result. */
    private function queueIds(array $rows): array
    {
        return array_map(static fn (object $row): int => (int) $row->id, $rows);
    }

    // --- eligible -------------------------------------------------------------

    #[Group('fast')]
    public function testEligibleCountAndQueueListAgreeOnTheSeededCar(): void
    {
        $eligibleCarId = $this->createTestCar($this->userId, $this->eligibleFields('dash-eligible@example.com'));
        // Control: a recently-verified car is NOT eligible — proves the count
        // isn't simply "every car", and the queue list excludes it too.
        $controlCarId = $this->createTestCar($this->userId, array_merge(
            $this->eligibleFields('dash-eligible-control@example.com'),
            ['last_verified' => date('Y-m-d H:i:s', strtotime('-1 day'))]
        ));

        $before = $this->repo->countVerificationSummary(null)['eligible'];
        // Isolate via a second car rather than asserting on the raw total,
        // which the shared integration schema can pollute: assert the count
        // increased by exactly 1 when a second eligible car is added.
        $secondEligibleCarId = $this->createTestCar(
            $this->userId,
            $this->eligibleFields('dash-eligible-2@example.com')
        );
        $after = $this->repo->countVerificationSummary(null)['eligible'];

        $this->assertSame(
            $before + 1,
            $after,
            'Adding one more eligible car must increase the eligible count by exactly 1'
        );

        $queueIds = $this->queueIds($this->repo->findVerificationQueue('eligible', null, 1000));
        $this->assertContains($eligibleCarId, $queueIds, 'Eligible car must appear in the eligible queue list');
        $this->assertContains(
            $secondEligibleCarId,
            $queueIds,
            'Second eligible car must appear in the eligible queue list'
        );
        $this->assertNotContains(
            $controlCarId,
            $queueIds,
            'A recently-verified (non-eligible) car must not appear in the eligible queue list'
        );
    }

    // --- pending: the LINK_TTL_DAYS boundary -----------------------------------

    #[Group('fast')]
    public function testPendingBoundaryJustInsideTtlIsPendingJustOutsideIsNot(): void
    {
        $ttlDays = CarVerificationEmailComposer::LINK_TTL_DAYS;

        $insideCarId = $this->createTestCar($this->userId, array_merge(
            $this->eligibleFields('dash-pending-inside@example.com'),
            [
                'vericode'         => 'hash-inside',
                'vericode_sent_at' => date('Y-m-d H:i:s', strtotime("-{$ttlDays} days +1 hour")),
            ]
        ));

        $outsideCarId = $this->createTestCar($this->userId, array_merge(
            $this->eligibleFields('dash-pending-outside@example.com'),
            [
                'vericode'         => 'hash-outside',
                'vericode_sent_at' => date('Y-m-d H:i:s', strtotime("-{$ttlDays} days -1 hour")),
            ]
        ));

        $pendingIds = $this->queueIds($this->repo->findVerificationQueue('pending', null, 1000));

        $this->assertContains(
            $insideCarId,
            $pendingIds,
            "A car sent just inside the {$ttlDays}-day link TTL must be Pending"
        );
        $this->assertNotContains(
            $outsideCarId,
            $pendingIds,
            "A car sent just outside the {$ttlDays}-day link TTL must NOT be Pending (link is dead)"
        );

        // The count must agree with the list: including the inside car raises
        // the count by exactly 1 relative to a baseline without it.
        $this->db->query('UPDATE cars SET vericode = NULL, vericode_sent_at = NULL WHERE id = ?', [$insideCarId]);
        $baseline = $this->repo->countVerificationSummary(null)['pending'];
        $this->db->query(
            'UPDATE cars SET vericode = ?, vericode_sent_at = ? WHERE id = ?',
            ['hash-inside', date('Y-m-d H:i:s', strtotime("-{$ttlDays} days +1 hour")), $insideCarId]
        );
        $withInside = $this->repo->countVerificationSummary(null)['pending'];

        $this->assertSame(
            $baseline + 1,
            $withInside,
            'countVerificationSummary()[pending] must agree with findVerificationQueue(\'pending\', ...) '
            . 'on whether the boundary car counts'
        );
    }

    // --- bounced ----------------------------------------------------------------

    #[Group('fast')]
    public function testBouncedCountAndQueueListAgreeOnTheSeededCar(): void
    {
        $bouncedCarId = $this->createTestCar($this->userId, array_merge(
            $this->eligibleFields('dash-bounced@example.com'),
            ['email_bounced' => 1, 'email_bounced_address' => 'dash-bounced@example.com']
        ));

        $before = $this->repo->countVerificationSummary(null)['bounced'];
        $secondBouncedCarId = $this->createTestCar($this->userId, array_merge(
            $this->eligibleFields('dash-bounced-2@example.com'),
            ['email_bounced' => 1, 'email_bounced_address' => 'dash-bounced-2@example.com']
        ));
        $after = $this->repo->countVerificationSummary(null)['bounced'];

        $this->assertSame($before + 1, $after, 'Adding one more bounced car must increase the bounced count by 1');

        $queueIds = $this->queueIds($this->repo->findVerificationQueue('bounced', null, 1000));
        $this->assertContains($bouncedCarId, $queueIds);
        $this->assertContains($secondBouncedCarId, $queueIds);
    }

    // --- suppressed (car flag OR profile flag) -----------------------------------

    #[Group('fast')]
    public function testSuppressedCountAndQueueListIncludeBothCarFlagAndProfileFlagCars(): void
    {
        $carFlagSuppressedId = $this->createTestCar($this->userId, array_merge(
            $this->eligibleFields('dash-suppressed-car-flag@example.com'),
            ['email_suppressed' => 1]
        ));

        $optedOutUserId = $this->createTestUser([], true);
        $this->db->query('UPDATE profiles SET email_suppressed = 1 WHERE user_id = ?', [$optedOutUserId]);
        $profileFlagSuppressedId = $this->createTestCar(
            $optedOutUserId,
            $this->eligibleFields('dash-suppressed-profile-flag@example.com')
        );

        $queueIds = $this->queueIds($this->repo->findVerificationQueue('suppressed', null, 1000));

        $this->assertContains(
            $carFlagSuppressedId,
            $queueIds,
            'A car with cars.email_suppressed = 1 must appear in the suppressed queue'
        );
        $this->assertContains(
            $profileFlagSuppressedId,
            $queueIds,
            'A car whose owner has profiles.email_suppressed = 1 (car flag clear) must appear in the '
            . 'suppressed queue too'
        );

        $before = $this->repo->countVerificationSummary(null)['suppressed'];
        $extraSuppressedId = $this->createTestCar($this->userId, array_merge(
            $this->eligibleFields('dash-suppressed-3@example.com'),
            ['email_suppressed' => 1]
        ));
        $after = $this->repo->countVerificationSummary(null)['suppressed'];

        $this->assertSame(
            $before + 1,
            $after,
            'Adding one more suppressed car must increase the suppressed count by exactly 1'
        );
        $this->assertContains($extraSuppressedId, $this->queueIds(
            $this->repo->findVerificationQueue('suppressed', null, 1000)
        ));
    }

    // --- verified / sold: distinct cars, not raw cars_hist rows ------------------

    #[Group('fast')]
    public function testVerifiedCountsDistinctCarsNotRawHistoryRows(): void
    {
        $carId = $this->createTestCar($this->userId, $this->eligibleFields('dash-verified-multi@example.com'));

        $before = $this->repo->countVerificationSummary(null)['verified'];

        // 3 VERIFIED rows for the SAME car — must still count as 1 car, not 3.
        $this->insertHistRow($carId, 'VERIFIED', date('Y-m-d H:i:s', strtotime('-3 days')));
        $this->insertHistRow($carId, 'VERIFIED', date('Y-m-d H:i:s', strtotime('-2 days')));
        $this->insertHistRow($carId, 'VERIFIED', date('Y-m-d H:i:s', strtotime('-1 days')));

        $after = $this->repo->countVerificationSummary(null)['verified'];

        $this->assertSame(
            $before + 1,
            $after,
            'A car with 3 VERIFIED cars_hist rows must increase the verified count by exactly 1, not 3 — '
            . 'verified counts distinct cars, not raw history rows'
        );

        $verifiedIds = $this->queueIds($this->repo->findVerificationQueue('verified', null, 1000));
        $occurrences = array_count_values($verifiedIds);
        $this->assertArrayHasKey($carId, $occurrences);
        $this->assertSame(
            1,
            $occurrences[$carId],
            'findVerificationQueue(\'verified\', ...) must list the car exactly once despite 3 VERIFIED rows'
        );
    }

    #[Group('fast')]
    public function testSoldCountsDistinctCarsNotRawHistoryRows(): void
    {
        $carId = $this->createTestCar($this->userId, $this->eligibleFields('dash-sold-multi@example.com'));

        $before = $this->repo->countVerificationSummary(null)['sold'];

        $this->insertHistRow($carId, 'VERIFIED SOLD', date('Y-m-d H:i:s', strtotime('-2 days')));
        $this->insertHistRow($carId, 'VERIFIED SOLD', date('Y-m-d H:i:s', strtotime('-1 days')));

        $after = $this->repo->countVerificationSummary(null)['sold'];

        $this->assertSame(
            $before + 1,
            $after,
            'A car with 2 VERIFIED SOLD cars_hist rows must increase the sold count by exactly 1, not 2'
        );

        $soldIds = $this->queueIds($this->repo->findVerificationQueue('sold', null, 1000));
        $occurrences = array_count_values($soldIds);
        $this->assertArrayHasKey($carId, $occurrences);
        $this->assertSame(1, $occurrences[$carId]);
    }

    #[Group('fast')]
    public function testVerifiedAndSoldWindowExcludesAnOlderHistoryRow(): void
    {
        $carId = $this->createTestCar($this->userId, $this->eligibleFields('dash-verified-window@example.com'));
        $this->insertHistRow($carId, 'VERIFIED', date('Y-m-d H:i:s', strtotime('-100 days')));

        $windowed = $this->repo->countVerificationSummary(7);
        $unwindowed = $this->repo->countVerificationSummary(null);

        $this->assertLessThanOrEqual(
            $unwindowed['verified'],
            $windowed['verified'],
            'A 7-day window must not report more verified cars than an unbounded (null) window'
        );

        $windowedIds = $this->queueIds($this->repo->findVerificationQueue('verified', 7, 1000));
        $unwindowedIds = $this->queueIds($this->repo->findVerificationQueue('verified', null, 1000));

        $this->assertNotContains(
            $carId,
            $windowedIds,
            'A VERIFIED row from 100 days ago must not appear under a 7-day window'
        );
        $this->assertContains(
            $carId,
            $unwindowedIds,
            'The same VERIFIED row must appear under an unbounded (null) window'
        );
    }

    // --- all: eligible + pending + bounced + suppressed, NOT verified/sold ------

    #[Group('fast')]
    public function testAllPillExcludesACarThatIsOnlyVerifiedNotInAnyQueueState(): void
    {
        // A car with ONLY a VERIFIED history row and otherwise fresh (just
        // verified, so not stale/eligible), not bounced, not suppressed, and
        // no live pending link — must be in neither findVerificationQueue('all')
        // nor the `all` count, because verified/sold are history, not queue state.
        $carId = $this->createTestCar($this->userId, [
            'email'              => 'dash-all-excl-verified@example.com',
            'email_bounced'      => 0,
            'email_suppressed'   => 0,
            'last_verified'      => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'owner_last_updated' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'mtime'              => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'solddate'           => null,
            'vericode'           => null,
            'vericode_sent_at'   => null,
        ]);
        $this->insertHistRow($carId, 'VERIFIED', date('Y-m-d H:i:s', strtotime('-1 hour')));

        $allIds = $this->queueIds($this->repo->findVerificationQueue('all', null, 1000));

        $this->assertNotContains(
            $carId,
            $allIds,
            'A car that is only Verified (fresh, not bounced/suppressed, no live pending link) must not '
            . 'appear under the all pill — Verified/Sold are history, not a queue state'
        );
    }

    #[Group('fast')]
    public function testAllPillCountEqualsUnionOfEligiblePendingBouncedSuppressedQueueIds(): void
    {
        $ttlDays = CarVerificationEmailComposer::LINK_TTL_DAYS;

        $eligibleCarId = $this->createTestCar($this->userId, $this->eligibleFields('dash-all-eligible@example.com'));
        $pendingCarId = $this->createTestCar($this->userId, array_merge(
            $this->eligibleFields('dash-all-pending@example.com'),
            [
                'vericode'         => 'hash-all-pending',
                'vericode_sent_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            ]
        ));
        $bouncedCarId = $this->createTestCar($this->userId, array_merge(
            $this->eligibleFields('dash-all-bounced@example.com'),
            ['email_bounced' => 1, 'email_bounced_address' => 'dash-all-bounced@example.com']
        ));
        $suppressedCarId = $this->createTestCar($this->userId, array_merge(
            $this->eligibleFields('dash-all-suppressed@example.com'),
            ['email_suppressed' => 1]
        ));

        $union = array_unique(array_merge(
            $this->queueIds($this->repo->findVerificationQueue('eligible', null, 1000)),
            $this->queueIds($this->repo->findVerificationQueue('pending', null, 1000)),
            $this->queueIds($this->repo->findVerificationQueue('bounced', null, 1000)),
            $this->queueIds($this->repo->findVerificationQueue('suppressed', null, 1000))
        ));

        $allIds = $this->queueIds($this->repo->findVerificationQueue('all', null, 1000));

        foreach ([$eligibleCarId, $pendingCarId, $bouncedCarId, $suppressedCarId] as $expectedCarId) {
            $this->assertContains($expectedCarId, $allIds, "Car {$expectedCarId} must appear under the all pill");
        }

        sort($union);
        sort($allIds);
        $this->assertSame(
            $union,
            $allIds,
            'findVerificationQueue(\'all\', ...) must return exactly the union of the eligible, pending, '
            . 'bounced and suppressed queue lists — no more, no fewer'
        );

        $allCount = $this->repo->countVerificationSummary(null)['all'];
        $this->assertSame(
            count($union),
            $allCount,
            'countVerificationSummary()[\'all\'] must equal the size of that same union'
        );

        // Silence an unused-variable warning for a constant used only in this
        // test's doc-adjacent setup (kept for clarity of the 1-day pending fixture).
        $this->assertGreaterThan(0, $ttlDays);
    }

    // --- findRecentVerificationActivity() ----------------------------------------

    #[Group('fast')]
    public function testRecentActivityListsVerifiedAndSoldRowsNewestFirstWithinWindow(): void
    {
        $verifiedCarId = $this->createTestCar(
            $this->userId,
            $this->eligibleFields('dash-activity-verified@example.com')
        );
        $soldCarId = $this->createTestCar($this->userId, $this->eligibleFields('dash-activity-sold@example.com'));

        $this->insertHistRow($verifiedCarId, 'VERIFIED', date('Y-m-d H:i:s', strtotime('-2 days')));
        $this->insertHistRow($soldCarId, 'VERIFIED SOLD', date('Y-m-d H:i:s', strtotime('-1 days')));
        // An unrelated operation on the same car must not appear.
        $this->insertHistRow($verifiedCarId, 'EMAIL BOUNCED', date('Y-m-d H:i:s', strtotime('-12 hours')));

        $activity = $this->repo->findRecentVerificationActivity(null, 1000);
        $carIds = array_map(static fn (object $row): int => (int) $row->car_id, $activity);
        $operations = array_map(static fn (object $row): string => $row->operation, $activity);

        $this->assertContains($verifiedCarId, $carIds);
        $this->assertContains($soldCarId, $carIds);
        $this->assertNotContains('EMAIL BOUNCED', $operations, 'Only VERIFIED/VERIFIED SOLD rows must appear');

        // Newest-first ordering: the sold row (1 day ago) must come before the
        // verified row (2 days ago) among these two fixtures' indices.
        $soldIndex = array_search($soldCarId, $carIds, true);
        $verifiedIndex = array_search($verifiedCarId, $carIds, true);
        $this->assertNotFalse($soldIndex);
        $this->assertNotFalse($verifiedIndex);
        $this->assertLessThan(
            $verifiedIndex,
            $soldIndex,
            'The more recent VERIFIED SOLD row must sort before the older VERIFIED row'
        );
    }

    #[Group('fast')]
    public function testRecentActivityWindowExcludesAnOlderRow(): void
    {
        $carId = $this->createTestCar($this->userId, $this->eligibleFields('dash-activity-window@example.com'));
        $this->insertHistRow($carId, 'VERIFIED', date('Y-m-d H:i:s', strtotime('-100 days')));

        $windowedCarIds = array_map(
            static fn (object $row): int => (int) $row->car_id,
            $this->repo->findRecentVerificationActivity(7, 1000)
        );
        $unwindowedCarIds = array_map(
            static fn (object $row): int => (int) $row->car_id,
            $this->repo->findRecentVerificationActivity(null, 1000)
        );

        $this->assertNotContains($carId, $windowedCarIds, 'A 100-day-old row must not appear under a 7-day window');
        $this->assertContains($carId, $unwindowedCarIds, 'The same row must appear under an unbounded window');
    }
}
