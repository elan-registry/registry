<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationEmailComposer;
use PHPUnit\Framework\Attributes\DataProvider;
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
 *   - bounced: the owner's profiles.email_bounced = 1.
 *   - blocked: the owner's profiles.email_blocked = 1, for the owner's current address.
 *   - opted_out: the owner's profiles.email_opted_out = 1, whatever the address.
 *   - invalid_email: a live owner with no profiles row, or a malformed address.
 *     Never eligible, and listed under Invalid email.
 *   - verified / sold: distinct cars with a cars_hist VERIFIED / VERIFIED SOLD
 *     row in the window, counted by car, not by history row.
 *   - next_queue has no queue list here: its rows come from findVerificationEligible().
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
        $this->userId = $this->createTestUser([], true);
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
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
            'vericode'           => null,
            'vericode_sent_at'   => null,
        ];
    }

    /**
     * Create an owner who has a profile with every flag clear, then set the flags
     * that the caller names. Flags belong to the owner, so each flagged state
     * needs its own owner.
     *
     * @param string $flag 'bounced', 'blocked', 'opted_out' or 'none'
     */
    private function createOwner(string $flag = 'none'): int
    {
        // A flag applies only while its address matches users.email (#2356),
        // so the flag address is the owner's own email.
        $email = 'dash-owner-' . uniqid() . '@example.com';
        $ownerId = $this->createTestUser(['email' => $email], true);
        if ($flag === 'bounced') {
            $this->setOwnerBounced($ownerId, $email);
        } elseif ($flag === 'blocked') {
            $this->setOwnerBlocked($ownerId, $email);
        } elseif ($flag === 'opted_out') {
            $this->setOwnerOptedOut($ownerId);
        } else {
            $this->assertSame('none', $flag, 'Test setup: unknown owner flag');
        }

        return $ownerId;
    }

    /**
     * Insert a minimal, valid cars_hist row with a given operation/timestamp
     * for an existing car.
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

    // --- pending: the other 4 exclusion rules (verificationPendingWhereSql()) --

    /**
     * The TTL boundary above is one of five Pending exclusions. These four
     * each have a car with a LIVE link (sent inside the TTL, so the TTL
     * clause alone would include it), each excluded for a different reason:
     * already answered, transferred, sold, and already bounced/blocked/opted out.
     * A regression in any one clause leaves that car stuck in Pending,
     * double-counted under its real pill too.
     */
    #[Group('fast')]
    public function testPendingExcludesAnsweredTransferredSoldAndBouncedBlockedOrOptedOutCars(): void
    {
        $ttlDays = CarVerificationEmailComposer::LINK_TTL_DAYS;
        $liveLinkSentAt = date('Y-m-d H:i:s', strtotime("-1 day"));

        $answeredCarId = $this->createTestCar($this->userId, array_merge(
            $this->eligibleFields('dash-pending-answered@example.com'),
            [
                'vericode'         => 'hash-answered',
                'vericode_sent_at' => $liveLinkSentAt,
                'last_verified'    => date('Y-m-d H:i:s', strtotime('+1 hour', strtotime($liveLinkSentAt))),
            ]
        ));

        // A transfer clears vericode but keeps vericode_sent_at (see
        // CarAdministrationService::transfer()) — vericode IS NULL is what
        // excludes this car, not vericode_sent_at being stale.
        $transferredCarId = $this->createTestCar($this->userId, array_merge(
            $this->eligibleFields('dash-pending-transferred@example.com'),
            [
                'vericode'         => null,
                'vericode_sent_at' => $liveLinkSentAt,
            ]
        ));

        $soldCarId = $this->createTestCar($this->userId, array_merge(
            $this->eligibleFields('dash-pending-sold@example.com'),
            [
                'vericode'         => 'hash-sold',
                'vericode_sent_at' => $liveLinkSentAt,
                'solddate'         => date('Y-m-d'),
            ]
        ));

        $bouncedOwnerId = $this->createOwner('bounced');
        $bouncedCarId = $this->createTestCar($bouncedOwnerId, array_merge(
            $this->eligibleFields('dash-pending-bounced@example.com'),
            [
                'vericode'         => 'hash-bounced',
                'vericode_sent_at' => $liveLinkSentAt,
            ]
        ));

        $optedOutPendingCarId = $this->createTestCar($this->createOwner('opted_out'), array_merge(
            $this->eligibleFields('dash-pending-opted-out@example.com'),
            [
                'vericode'         => 'hash-opted-out',
                'vericode_sent_at' => $liveLinkSentAt,
            ]
        ));

        $blockedCarId = $this->createTestCar($this->createOwner('blocked'), array_merge(
            $this->eligibleFields('dash-pending-blocked@example.com'),
            [
                'vericode'         => 'hash-blocked',
                'vericode_sent_at' => $liveLinkSentAt,
            ]
        ));

        $pendingIds = $this->queueIds($this->repo->findVerificationQueue('pending', null, 1000));

        $this->assertNotContains(
            $answeredCarId,
            $pendingIds,
            'A car whose owner already verified after the link was sent must not be Pending'
        );
        $this->assertNotContains(
            $transferredCarId,
            $pendingIds,
            'A transferred car (vericode cleared) must not be Pending even with a live vericode_sent_at'
        );
        $this->assertNotContains($soldCarId, $pendingIds, 'A sold car must not be Pending');
        $this->assertNotContains($bouncedCarId, $pendingIds, 'A bounced car with a live link must not be Pending');
        $this->assertNotContains(
            $optedOutPendingCarId,
            $pendingIds,
            'A car of an opted-out owner with a live link must not be Pending'
        );
        $this->assertNotContains(
            $blockedCarId,
            $pendingIds,
            'A car of a blocked owner with a live link must not be Pending'
        );

        // Each exclusion must also hold under the Pending count, not only the
        // list: isolate via a before/after delta the same way the TTL test
        // above does, using the bounced car. Clearing the owner's bounce leaves a
        // live, unanswered link with every other Pending condition met, so
        // the car becomes Pending — proving the count applies the bounced
        // exclusion the same way the list does, not that it ignores it.
        $this->clearOwnerEmailFlags($bouncedOwnerId);
        $withoutBounced = $this->repo->countVerificationSummary(null)['pending'];
        $bouncedEmail = $this->db->query('SELECT email FROM users WHERE id = ?', [$bouncedOwnerId])->first()->email;
        $this->setOwnerBounced($bouncedOwnerId, $bouncedEmail);
        $withBounced = $this->repo->countVerificationSummary(null)['pending'];

        $this->assertSame(
            $withBounced + 1,
            $withoutBounced,
            'Clearing the owner bounce on a car with an otherwise-live link must raise the Pending count by '
            . 'exactly 1: the count applies the bounced exclusion the same way the list does'
        );
    }

    // --- bounced ----------------------------------------------------------------

    #[Group('fast')]
    public function testBouncedCountAndQueueListAgreeOnTheSeededCar(): void
    {
        $bouncedCarId = $this->createTestCar(
            $this->createOwner('bounced'),
            $this->eligibleFields('dash-bounced@example.com')
        );
        // Control: a car of an owner with no flag is not in the bounced queue.
        $cleanCarId = $this->createTestCar($this->userId, $this->eligibleFields('dash-bounced-control@example.com'));

        $before = $this->repo->countVerificationSummary(null)['bounced'];
        $secondBouncedCarId = $this->createTestCar(
            $this->createOwner('bounced'),
            $this->eligibleFields('dash-bounced-2@example.com')
        );
        $after = $this->repo->countVerificationSummary(null)['bounced'];

        $this->assertSame($before + 1, $after, 'Adding one more bounced car must increase the bounced count by 1');

        $queueIds = $this->queueIds($this->repo->findVerificationQueue('bounced', null, 1000));
        $this->assertContains($bouncedCarId, $queueIds);
        $this->assertContains($secondBouncedCarId, $queueIds);
        $this->assertNotContains($cleanCarId, $queueIds, 'A car of an owner with no flag must not be bounced');
        $this->assertNotContains(
            $bouncedCarId,
            $this->queueIds($this->repo->findVerificationQueue('eligible', null, 1000)),
            'A car of a bounced owner must not be eligible'
        );
    }

    // --- blocked and opted out (separate pills and cards) -------------------------

    #[Group('fast')]
    public function testBlockedAndOptedOutCountsAndQueuesAreSeparate(): void
    {
        $blockedCarId = $this->createTestCar(
            $this->createOwner('blocked'),
            $this->eligibleFields('dash-blocked@example.com')
        );
        $optedOutCarId = $this->createTestCar(
            $this->createOwner('opted_out'),
            $this->eligibleFields('dash-opted-out@example.com')
        );
        $cleanCarId = $this->createTestCar($this->userId, $this->eligibleFields('dash-flag-clean@example.com'));
        $bouncedCarId = $this->createTestCar(
            $this->createOwner('bounced'),
            $this->eligibleFields('dash-flag-bounced@example.com')
        );

        $blockedIds = $this->queueIds($this->repo->findVerificationQueue('blocked', null, 1000));
        $optedOutIds = $this->queueIds($this->repo->findVerificationQueue('opted_out', null, 1000));

        $this->assertContains($blockedCarId, $blockedIds, 'A car of a blocked owner is in the Blocked queue');
        $this->assertNotContains($optedOutCarId, $blockedIds, 'An opted-out owner is not Blocked');
        $this->assertContains($optedOutCarId, $optedOutIds, 'A car of an opted-out owner is in the Opted out queue');
        $this->assertNotContains($blockedCarId, $optedOutIds, 'A blocked owner is not Opted out');
        foreach ([$cleanCarId, $bouncedCarId] as $otherCarId) {
            $this->assertNotContains($otherCarId, $blockedIds);
            $this->assertNotContains($otherCarId, $optedOutIds);
        }

        $before = $this->repo->countVerificationSummary(null);
        $this->createTestCar($this->createOwner('blocked'), $this->eligibleFields('dash-blocked-2@example.com'));
        $this->createTestCar($this->createOwner('opted_out'), $this->eligibleFields('dash-opted-out-2@example.com'));
        $after = $this->repo->countVerificationSummary(null);

        $this->assertSame($before['blocked'] + 1, $after['blocked'], 'One more blocked car adds exactly 1 to Blocked');
        $this->assertSame($before['opted_out'] + 1, $after['opted_out'], 'One more opted-out car adds exactly 1 to Opted out');
        $this->assertSame($before['blocked_owners'] + 1, $after['blocked_owners']);
        $this->assertSame($before['opted_out_owners'] + 1, $after['opted_out_owners']);
        $this->assertSame($before['bounced'], $after['bounced'], 'Control: Bounced does not move');
    }

    #[Group('fast')]
    public function testOwnerBlockedAndOptedOutIsListedOnceInEachPill(): void
    {
        $ownerId = $this->createOwner('blocked');
        $this->setOwnerOptedOut($ownerId);
        $carId = $this->createTestCar($ownerId, $this->eligibleFields('dash-flag-both@example.com'));

        $blocked = array_count_values($this->queueIds($this->repo->findVerificationQueue('blocked', null, 1000)));
        $optedOut = array_count_values($this->queueIds($this->repo->findVerificationQueue('opted_out', null, 1000)));

        $this->assertSame(1, $blocked[$carId] ?? 0, 'The car is listed once under Blocked');
        $this->assertSame(1, $optedOut[$carId] ?? 0, 'The car is listed once under Opted out');

        // Control: with both flags cleared the car leaves both queues.
        $this->clearOwnerEmailFlags($ownerId);
        foreach (['blocked', 'opted_out'] as $status) {
            $this->assertNotContains(
                $carId,
                $this->queueIds($this->repo->findVerificationQueue($status, null, 1000)),
                "Control: a car of an owner with no flag is not in {$status}"
            );
        }
    }

    /** @return array<string, array{string}> */
    public static function flagProvider(): array
    {
        return [
            'bounced'   => ['bounced'],
            'blocked'   => ['blocked'],
            'opted out' => ['opted_out'],
        ];
    }

    /**
     * An owner with two flagged cars counts once on the card and twice on the
     * pill. A second owner with one car adds one to each.
     */
    #[Group('fast')]
    #[DataProvider('flagProvider')]
    public function testOwnerWithTwoCarsCountsOncePerCardAndTwicePerPill(string $flag): void
    {
        $before = $this->repo->countVerificationSummary(null);

        $twoCarOwner = $this->createOwner($flag);
        $this->createTestCar($twoCarOwner, $this->eligibleFields("dash-two-{$flag}-a@example.com"));
        $this->createTestCar($twoCarOwner, $this->eligibleFields("dash-two-{$flag}-b@example.com"));

        $afterOne = $this->repo->countVerificationSummary(null);
        $this->assertSame($before[$flag] + 2, $afterOne[$flag], "The {$flag} pill counts both cars");
        $this->assertSame($before[$flag . '_owners'] + 1, $afterOne[$flag . '_owners'], "The {$flag} card counts the owner once");

        $oneCarOwner = $this->createOwner($flag);
        $this->createTestCar($oneCarOwner, $this->eligibleFields("dash-one-{$flag}@example.com"));

        $afterTwo = $this->repo->countVerificationSummary(null);
        $this->assertSame($before[$flag] + 3, $afterTwo[$flag]);
        $this->assertSame($before[$flag . '_owners'] + 2, $afterTwo[$flag . '_owners'], 'A second owner adds one more');
    }

    /**
     * An admin changes the owner's address. A block and a bounce for the old
     * address leave the card, the pill and the queue. The opt-out stays.
     */
    #[Group('fast')]
    public function testAdminEmailChangeLiftsBlockAndBounceButNotOptOut(): void
    {
        $blockedOwner = $this->createOwner('blocked');
        $blockedCar = $this->createTestCar($blockedOwner, $this->eligibleFields('dash-chg-blocked@example.com'));
        $bouncedOwner = $this->createOwner('bounced');
        $bouncedCar = $this->createTestCar($bouncedOwner, $this->eligibleFields('dash-chg-bounced@example.com'));
        $optedOutOwner = $this->createOwner('opted_out');
        $optedOutCar = $this->createTestCar($optedOutOwner, $this->eligibleFields('dash-chg-opted@example.com'));

        $before = $this->repo->countVerificationSummary(null);
        $this->assertContains($blockedCar, $this->queueIds($this->repo->findVerificationQueue('blocked', null, 1000)), 'Test setup');
        $this->assertContains($bouncedCar, $this->queueIds($this->repo->findVerificationQueue('bounced', null, 1000)), 'Test setup');

        foreach ([$blockedOwner, $bouncedOwner, $optedOutOwner] as $ownerId) {
            $this->setUserEmailRaw($ownerId, 'admin-changed-' . uniqid() . '@example.com');
        }

        $after = $this->repo->countVerificationSummary(null);
        $this->assertSame($before['blocked'] - 1, $after['blocked'], 'The Blocked pill drops the car');
        $this->assertSame($before['blocked_owners'] - 1, $after['blocked_owners'], 'The Blocked card drops the owner');
        $this->assertSame($before['bounced'] - 1, $after['bounced'], 'The Bounced pill drops the car');
        $this->assertSame($before['bounced_owners'] - 1, $after['bounced_owners'], 'The Bounced card drops the owner');
        $this->assertSame($before['opted_out'], $after['opted_out'], 'The opt-out survives the address change');
        $this->assertSame($before['opted_out_owners'], $after['opted_out_owners']);

        $this->assertNotContains($blockedCar, $this->queueIds($this->repo->findVerificationQueue('blocked', null, 1000)));
        $this->assertNotContains($bouncedCar, $this->queueIds($this->repo->findVerificationQueue('bounced', null, 1000)));
        $this->assertContains($optedOutCar, $this->queueIds($this->repo->findVerificationQueue('opted_out', null, 1000)));
    }

    /** @return array<string, array{string, ?string}> */
    public static function missingAddressProvider(): array
    {
        return [
            'blocked NULL address'  => ['blocked', null],
            'blocked empty address' => ['blocked', ''],
            'bounced NULL address'  => ['bounced', null],
            'bounced empty address' => ['bounced', ''],
        ];
    }

    /** A flag with no stored address still applies (fail closed), also after an address change. */
    #[Group('fast')]
    #[DataProvider('missingAddressProvider')]
    public function testFlagWithNullOrEmptyAddressFailsClosed(string $flag, ?string $address): void
    {
        $ownerId = $this->createOwner();
        $carId = $this->createTestCar($ownerId, $this->eligibleFields("dash-failclosed-{$flag}@example.com"));
        $before = $this->repo->countVerificationSummary(null);

        // $flag is one of two literals from the provider, so the column names are safe to interpolate.
        $result = $this->db->query(
            "UPDATE profiles SET email_{$flag} = 1, email_{$flag}_address = ? WHERE user_id = ?",
            [$address, $ownerId]
        );
        $this->assertFalse($result->error(), 'Test setup: profile update failed: ' . $result->errorString());
        $this->setUserEmailRaw($ownerId, 'failclosed-' . uniqid() . '@example.com');

        $after = $this->repo->countVerificationSummary(null);
        $this->assertSame($before[$flag] + 1, $after[$flag], "The {$flag} pill counts the car");
        $this->assertSame($before[$flag . '_owners'] + 1, $after[$flag . '_owners'], "The {$flag} card counts the owner");
        $this->assertContains($carId, $this->queueIds($this->repo->findVerificationQueue($flag, null, 1000)));
    }

    // --- no profile ----------------------------------------------------------------

    #[Group('fast')]
    public function testCarOfOwnerWithNoProfileIsNotEligibleButIsInInvalidEmailQueueAndCount(): void
    {
        $noProfileOwnerId = $this->createTestUser();
        $noProfileCarId = $this->createTestCar(
            $noProfileOwnerId,
            $this->eligibleFields('dash-no-profile@example.com')
        );
        $withProfileCarId = $this->createTestCar($this->userId, $this->eligibleFields('dash-has-profile@example.com'));

        $eligibleIds = $this->queueIds($this->repo->findVerificationQueue('eligible', null, 1000));
        $allIds = $this->queueIds($this->repo->findVerificationQueue('invalid_email', null, 100000));

        $this->assertContains($withProfileCarId, $eligibleIds, 'Control: the car with a profile is eligible');
        $this->assertNotContains($withProfileCarId, $allIds, 'Control: the car with a profile is not Invalid email');
        $this->assertNotContains($noProfileCarId, $eligibleIds, 'A car whose owner has no profile is never eligible');
        $this->assertContains($noProfileCarId, $allIds, 'The no-profile car must be visible under Invalid email');

        $queueRow = null;
        foreach ($this->repo->findVerificationQueue('invalid_email', null, 100000) as $row) {
            if ((int) $row->id === $noProfileCarId) {
                $queueRow = $row;
            }
        }
        $this->assertNotNull($queueRow, 'The Invalid email queue must return a row for the no-profile car');
        $this->assertSame(0, (int) $queueRow->has_profile, 'The row must show that there is no profile');

        $before = $this->repo->countVerificationSummary(null)['invalid_email'];
        $this->db->query('DELETE FROM cars WHERE id = ?', [$noProfileCarId]);
        $after = $this->repo->countVerificationSummary(null)['invalid_email'];
        $this->assertSame($before - 1, $after, 'The Invalid email count includes the no-profile car');
    }

    // --- malformed owner address (#2594) --------------------------------------------

    /** The Invalid email row of a car, or null when the car is not listed. */
    private function allQueueRow(int $carId): ?object
    {
        foreach ($this->repo->findVerificationQueue('invalid_email', null, 100000) as $row) {
            if ((int) $row->id === $carId) {
                return $row;
            }
        }
        return null;
    }

    #[Group('fast')]
    public function testMalformedOwnerAddressCarIsInInvalidEmailQueueAndCountButNotEligible(): void
    {
        $before = $this->repo->countVerificationSummary(null);

        $badOwnerId = $this->createTestUser(['email' => 'None'], true);
        $badCarId = $this->createTestCar($badOwnerId, $this->eligibleFields('dash-malformed@example.com'));
        $goodCarId = $this->createTestCar($this->userId, $this->eligibleFields('dash-malformed-control@example.com'));

        $after = $this->repo->countVerificationSummary(null);

        $badRow = $this->allQueueRow($badCarId);
        $this->assertNotNull($badRow, 'The malformed-address car must be listed under Invalid email');
        $this->assertSame(1, (int) $badRow->email_address_malformed, 'The row must be marked as malformed');

        $this->assertNull($this->allQueueRow($goodCarId), 'Control: the well-formed car is not listed under Invalid email');

        $this->assertNotContains(
            $badCarId,
            $this->queueIds($this->repo->findVerificationQueue('eligible', null, 100000)),
            'A malformed-address car is not eligible'
        );
        // Two cars added; only the well-formed one is eligible, only the malformed one is Invalid email.
        $this->assertSame($before['eligible'] + 1, $after['eligible'], 'Only the well-formed car is counted eligible');
        $this->assertSame($before['invalid_email'] + 1, $after['invalid_email'], 'The Invalid email count includes the malformed car');
    }

    /**
     * A malformed address matters only for a car that is a verification
     * candidate. A sold car, a noowner car and a white-space-only address are left out.
     *
     * @return array<string, array{string}>
     */
    public static function notMalformedCandidateProvider(): array
    {
        return [
            'sold car'                   => ['sold'],
            'noowner account'            => ['noowner'],
            'white-space only address'   => ['blank'],
        ];
    }

    #[Group('fast')]
    #[DataProvider('notMalformedCandidateProvider')]
    public function testMalformedAddressConditionIgnoresSoldNoownerAndBlankAddressCars(string $case): void
    {
        $fields = $this->eligibleFields('dash-not-candidate@example.com');
        $ownerId = $this->createTestUser(['email' => 'None'], true);
        if ($case === 'sold') {
            $fields['solddate'] = date('Y-m-d');
        } elseif ($case === 'noowner') {
            $row = $this->db->query('SELECT id, email FROM users WHERE username = ?', ['noowner'])->first();
            $this->assertNotEmpty($row, 'noowner system account missing');
            $ownerId = (int) $row->id;
            // Control: the account address is malformed, so only the noowner rule can leave the car out.
            $this->assertDoesNotMatchRegularExpression('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $row->email);
        } else {
            $this->setUserEmailRaw($ownerId, " \t ");
        }
        $carId = $this->createTestCar($ownerId, $fields);

        // $before counts with the car present. The count must not change when the car is deleted.
        $before = $this->repo->countVerificationSummary(null)['invalid_email'];
        $this->assertNull($this->allQueueRow($carId), 'This car must not be listed under Invalid email');
        $this->db->query('DELETE FROM cars WHERE id = ?', [$carId]);
        $this->assertSame(
            $before,
            $this->repo->countVerificationSummary(null)['invalid_email'],
            'The Invalid email count ignores this car'
        );
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

    // --- invalid_email: no profile or malformed address, NOT verified/sold -------------

    #[Group('fast')]
    public function testInvalidEmailQueueExcludesACarThatIsOnlyVerified(): void
    {
        // A fresh, just-verified car with a clean owner: no profile problem, no
        // malformed address. It must be in no queue state.
        $carId = $this->createTestCar($this->userId, [
            'email'              => 'dash-all-excl-verified@example.com',
            'last_verified'      => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'owner_last_updated' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'mtime'              => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'solddate'           => null,
            'vericode'           => null,
            'vericode_sent_at'   => null,
        ]);
        $this->insertHistRow($carId, 'VERIFIED', date('Y-m-d H:i:s', strtotime('-1 hour')));

        $this->assertNotContains(
            $carId,
            $this->queueIds($this->repo->findVerificationQueue('invalid_email', null, 100000)),
            'A Verified car with a well-formed address and a profile is not Invalid email'
        );
    }

    #[Group('fast')]
    public function testInvalidEmailQueueIsUnionOfNoProfileAndMalformedAndMatchesTheCount(): void
    {
        $beforeCount = $this->repo->countVerificationSummary(null)['invalid_email'];
        $beforeIds = $this->queueIds($this->repo->findVerificationQueue('invalid_email', null, 100000));

        // No profile row, well-formed address.
        $noProfileCarId = $this->createTestCar(
            $this->createTestUser(['email' => 'dash-ie-noprofile@example.com']),
            $this->eligibleFields('dash-ie-noprofile@example.com')
        );
        // Profile row, malformed address.
        $malformedCarId = $this->createTestCar(
            $this->createTestUser(['email' => 'None'], true),
            $this->eligibleFields('dash-ie-malformed@example.com')
        );
        // Both: no profile row and a malformed address. Counts once.
        $bothCarId = $this->createTestCar(
            $this->createTestUser(['email' => 'None']),
            $this->eligibleFields('dash-ie-both@example.com')
        );
        // Control: profile and well-formed address.
        $cleanCarId = $this->createTestCar($this->userId, $this->eligibleFields('dash-ie-clean@example.com'));

        $afterIds = $this->queueIds($this->repo->findVerificationQueue('invalid_email', null, 100000));
        $added = array_values(array_diff($afterIds, $beforeIds));
        sort($added);
        $expected = [$noProfileCarId, $malformedCarId, $bothCarId];
        sort($expected);

        $this->assertNotEmpty($added, 'Test setup: the seeded cars must reach the Invalid email list');
        $this->assertSame($expected, $added, 'Exactly the three invalid cars are added, not the clean control');
        $this->assertSame(
            1,
            array_count_values($afterIds)[$bothCarId] ?? 0,
            'A car with both conditions is listed once'
        );
        $this->assertNotContains($cleanCarId, $afterIds);

        $afterCount = $this->repo->countVerificationSummary(null)['invalid_email'];
        $this->assertSame($beforeCount + 3, $afterCount, 'The count adds the both-case once');
        $this->assertSame(count($afterIds), $afterCount, 'The Invalid email count equals the list size');
    }

    #[Group('fast')]
    public function testSummaryHasNoAllKeyAndEachPillCountEqualsItsListSize(): void
    {
        // Seed one car per state so each count is non-trivial.
        $this->createTestCar($this->userId, $this->eligibleFields('dash-sum-eligible@example.com'));
        $this->createTestCar($this->userId, array_merge(
            $this->eligibleFields('dash-sum-pending@example.com'),
            ['vericode' => 'hash-sum-pending', 'vericode_sent_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]
        ));
        $this->createTestCar($this->createOwner('bounced'), $this->eligibleFields('dash-sum-bounced@example.com'));
        $this->createTestCar($this->createOwner('blocked'), $this->eligibleFields('dash-sum-blocked@example.com'));
        $this->createTestCar($this->createOwner('opted_out'), $this->eligibleFields('dash-sum-opted@example.com'));
        $this->createTestCar($this->createTestUser(['email' => 'None'], true), $this->eligibleFields('dash-sum-bad@example.com'));

        $summary = $this->repo->countVerificationSummary(null);
        $this->assertArrayNotHasKey('all', $summary);

        $checked = 0;
        foreach (CarRepository::QUEUE_STATUSES as $status) {
            if ($status === 'next_queue') {
                continue;
            }
            $rows = $this->repo->findVerificationQueue($status, null, 100000);
            $this->assertSame($summary[$status], count($rows), "The {$status} count equals the {$status} list size");
            $checked++;
        }
        $this->assertSame(count(CarRepository::QUEUE_STATUSES) - 1, $checked);
    }

    // --- send order (#2599) ---------------------------------------------------------

    /**
     * @param list<int> $ids
     * @param list<int> $keep
     * @return list<int> $ids filtered to $keep, order preserved
     */
    private function only(array $ids, array $keep): array
    {
        return array_values(array_filter($ids, static fn (int $id): bool => in_array($id, $keep, true)));
    }

    #[Group('fast')]
    public function testEligibleQueueAndBatchShareSendOrderWithIdTiebreak(): void
    {
        $nullIds = [];
        // 25 cars, so the batch size of 25 below is full even on an empty test database.
        for ($i = 0; $i < 25; $i++) {
            $nullIds[] = $this->createTestCar($this->userId, $this->eligibleFields("dash-order-null-{$i}@example.com"));
        }
        // Dated more than a year ago, so it is still eligible. It sorts after
        // every NULL (never verified) car.
        $datedId = $this->createTestCar($this->userId, array_merge(
            $this->eligibleFields('dash-order-dated@example.com'),
            ['last_verified' => date('Y-m-d H:i:s', strtotime('-2 years'))]
        ));

        $total = $this->repo->countVerificationSummary(null)['eligible'];
        $eligibleIds = $this->queueIds($this->repo->findVerificationQueue('eligible', null, $total + 100));

        $this->assertSame(
            [...$nullIds, $datedId],
            $this->only($eligibleIds, [...$nullIds, $datedId]),
            'Never-verified cars come first, by id; the dated car comes last'
        );

        // Adjacent pairs of the full list are non-decreasing in (NULL first, last_verified, id).
        $rows = $this->repo->findVerificationQueue('eligible', null, $total + 100);
        $this->assertGreaterThan(1, count($rows));
        for ($i = 1; $i < count($rows); $i++) {
            $a = $rows[$i - 1];
            $b = $rows[$i];
            $keyA = [$a->last_verified === null ? 0 : 1, (string) $a->last_verified, (int) $a->id];
            $keyB = [$b->last_verified === null ? 0 : 1, (string) $b->last_verified, (int) $b->id];
            $this->assertLessThanOrEqual(0, $keyA <=> $keyB, "Row {$i} is out of send order");
        }

        // The batch query returns the first N rows of the Eligible list.
        foreach ([1, 5, 25] as $n) {
            $batch = $this->queueIds($this->repo->findVerificationEligible($n, 0));
            $this->assertCount($n, $batch);
            $this->assertSame(array_slice($eligibleIds, 0, $n), $batch, "Batch of {$n} is the first {$n} Eligible rows");
        }
        $this->assertSame(
            array_slice($eligibleIds, 3, 3),
            $this->queueIds($this->repo->findVerificationEligible(3, 3)),
            'OFFSET 3 skips the first three Eligible rows'
        );
    }

    #[Group('fast')]
    public function testEligibleQueueHonoursShowLimit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createTestCar($this->userId, $this->eligibleFields("dash-limit-{$i}@example.com"));
        }

        $this->assertCount(2, $this->repo->findVerificationQueue('eligible', null, 2));
        $this->assertCount(1, $this->repo->findVerificationQueue('eligible', null, 1));
        $this->assertSame([], $this->repo->findVerificationQueue('eligible', null, 0));
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
        $this->insertHistRow($verifiedCarId, 'UPDATE', date('Y-m-d H:i:s', strtotime('-12 hours')));

        $activity = $this->repo->findRecentVerificationActivity(null, 1000);
        $carIds = array_map(static fn (object $row): int => (int) $row->car_id, $activity);
        $operations = array_map(static fn (object $row): string => $row->operation, $activity);

        $this->assertContains($verifiedCarId, $carIds);
        $this->assertContains($soldCarId, $carIds);
        $this->assertNotContains('UPDATE', $operations, 'Only VERIFIED/VERIFIED SOLD rows must appear');

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
