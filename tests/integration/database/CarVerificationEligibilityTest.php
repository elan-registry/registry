<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Runs CarRepository::findVerificationEligible() against real rows (#1155).
 * Unit tests only string-match the SQL. Tests assert on the presence of their
 * own rows, not on counts, because the shared DB holds other rows.
 *
 * owner_last_updated is NOT NULL (#1953) and mtime is not used for freshness:
 * MySQL bumps mtime on any UPDATE, including an unrelated profile sync.
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarVerificationEligibilityTest extends IntegrationTestCase
{
    private int $testUserId;
    private CarRepository $repo;

    /** More than 1 year ago — satisfies both the last_verified and owner_last_updated staleness checks. */
    private const STALE_DATE = '-3 years';

    /** Within the last 1 year — fails the staleness checks. */
    private const RECENT_DATE = '-1 day';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        foreach (['owner_last_updated', 'last_verified'] as $column) {
            $this->assertColumnExists('cars', $column);
        }

        // A car owner always has a profiles row. The flags (#2101) live on it,
        // and an owner with no profile is never eligible.
        $this->testUserId = $this->createTestUser([], true);
        $this->loginAsTestUser($this->testUserId);
        $this->repo = new CarRepository($this->db);
    }

    /**
     * Skips, rather than fails, when a migration has not run yet.
     */
    private function assertColumnExists(string $table, string $column): void
    {
        $check = $this->db->query(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = ?
               AND COLUMN_NAME  = ?
             LIMIT 1",
            [$table, $column]
        );

        if (!$check || $check->count() === 0) {
            $this->markTestSkipped(
                "{$table}.{$column} not yet available — run `composer migrate`"
            );
        }
    }

    /** @return int[] */
    private function eligibleIds(): array
    {
        $results = $this->repo->findVerificationEligible(1000, 0);
        return array_map(static fn ($row) => (int) $row->id, $results);
    }

    private function staleDate(): string
    {
        return date('Y-m-d H:i:s', strtotime(self::STALE_DATE));
    }

    private function recentDate(): string
    {
        return date('Y-m-d H:i:s', strtotime(self::RECENT_DATE));
    }

    #[Group('fast')]
    public function testSoldCarIsExcluded(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'sold-owner@example.com',
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => date('Y-m-d', strtotime(self::STALE_DATE)),
        ]);

        $this->assertNotContains(
            $carId,
            $this->eligibleIds(),
            'A car with a non-null solddate must be excluded from verification eligibility'
        );
    }

    /**
     * Fields for a stale, otherwise eligible car.
     *
     * @return array<string, mixed>
     */
    private function staleCarFields(string $email): array
    {
        return [
            'email'              => $email,
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ];
    }

    #[Group('fast')]
    public function testCarOfBouncedOwnerIsExcluded(): void
    {
        // A flag applies only while its address matches users.email (#2356).
        $bouncedOwnerId = $this->createTestUser(['email' => 'bounced-owner@example.com'], true);
        $this->setOwnerBounced($bouncedOwnerId, 'bounced-owner@example.com');
        $carId = $this->createTestCar($bouncedOwnerId, $this->staleCarFields('bounced-owner@example.com'));
        $controlCarId = $this->createTestCar($this->testUserId, $this->staleCarFields('bounced-control@example.com'));

        $eligible = $this->eligibleIds();
        $this->assertNotContains(
            $carId,
            $eligible,
            'A car whose owner has profiles.email_bounced = 1 must be excluded from verification eligibility'
        );
        $this->assertContains($controlCarId, $eligible, 'Control: a car of an owner with no flag stays eligible');
    }

    /**
     * Opt-out (consent), block (complaint) and bounce (deliverability) each
     * exclude a car alone. The unflagged control proves the cause.
     */
    #[Group('fast')]
    public function testEachOwnerFlagAloneExcludesTheCarButUnflaggedSiblingIsEligible(): void
    {
        $optedOutOwnerId = $this->createTestUser([], true);
        $this->setOwnerOptedOut($optedOutOwnerId);
        // The block applies only while its address matches users.email (#2356).
        $blockedOwnerId = $this->createTestUser(['email' => 'blocked-owner@example.com'], true);
        $this->setOwnerBlocked($blockedOwnerId, 'blocked-owner@example.com');

        $optedOutCarId = $this->createTestCar($optedOutOwnerId, $this->staleCarFields('opted-out-owner@example.com'));
        $blockedCarId = $this->createTestCar($blockedOwnerId, $this->staleCarFields('blocked-owner@example.com'));
        $unflaggedCarId = $this->createTestCar($this->testUserId, $this->staleCarFields('unflagged-owner@example.com'));

        $eligible = $this->eligibleIds();

        $this->assertNotContains($optedOutCarId, $eligible, 'An opted-out owner\'s car must be excluded');
        $this->assertNotContains($blockedCarId, $eligible, 'A blocked owner\'s car must be excluded');
        $this->assertContains(
            $unflaggedCarId,
            $eligible,
            'Control: an otherwise-identical car of an unflagged owner must remain eligible — '
            . 'otherwise the exclusions above prove nothing about the flags specifically'
        );
    }

    /**
     * #2319: the flag belongs to the owner, so a car registered after the
     * flag was set is excluded too.
     */
    #[Group('fast')]
    public function testCarAddedAfterOwnerFlagWasSetIsExcluded(): void
    {
        $ownerId = $this->createTestUser([], true);
        $this->setOwnerOptedOut($ownerId);

        $laterCarId = $this->createTestCar($ownerId, $this->staleCarFields('later-car@example.com'));

        $this->assertNotContains(
            $laterCarId,
            $this->eligibleIds(),
            'A car registered after the owner opted out must be excluded from verification eligibility'
        );
    }

    /**
     * An owner with no profiles row cannot be shown to allow mail, so the
     * car is excluded (it appears in the admin queue as "no profile").
     */
    #[Group('fast')]
    public function testCarOfOwnerWithNoProfileIsExcluded(): void
    {
        $noProfileOwnerId = $this->createTestUser();
        $noProfileCarId = $this->createTestCar($noProfileOwnerId, $this->staleCarFields('no-profile@example.com'));
        $controlCarId = $this->createTestCar($this->testUserId, $this->staleCarFields('has-profile@example.com'));

        $eligible = $this->eligibleIds();

        $this->assertNotContains($noProfileCarId, $eligible, 'A car whose owner has no profiles row is excluded');
        $this->assertContains($controlCarId, $eligible, 'Control: the same car with a profile is eligible');
    }

    /**
     * A flag excludes the car whatever its address column holds: a NULL or an
     * empty address is still a flag.
     */
    #[Group('fast')]
    public function testFlagWithNullOrEmptyAddressStillExcludesTheCar(): void
    {
        $blockedNullOwnerId = $this->createTestUser([], true);
        $this->setOwnerBlocked($blockedNullOwnerId, '');
        $this->db->query('UPDATE profiles SET email_blocked_address = NULL WHERE user_id = ?', [$blockedNullOwnerId]);
        $bouncedEmptyOwnerId = $this->createTestUser([], true);
        $this->setOwnerBounced($bouncedEmptyOwnerId, '');

        $nullCarId = $this->createTestCar($blockedNullOwnerId, $this->staleCarFields('null-address@example.com'));
        $emptyCarId = $this->createTestCar($bouncedEmptyOwnerId, $this->staleCarFields('empty-address@example.com'));

        $flags = $this->getOwnerEmailFlags($blockedNullOwnerId);
        $this->assertNull($flags['email_blocked_address'], 'Test setup: the blocked address must be NULL');
        $this->assertSame('', $this->getOwnerEmailFlags($bouncedEmptyOwnerId)['email_bounced_address']);

        $eligible = $this->eligibleIds();
        $this->assertNotContains($nullCarId, $eligible, 'A block with a NULL address must still exclude the car');
        $this->assertNotContains($emptyCarId, $eligible, 'A bounce with an empty address must still exclude the car');
    }

    /**
     * The send mails the owner's users.email. cars.email is only a copy, so
     * an empty or NULL copy must not exclude the car.
     *
     * @return array<string, array{?string}>
     */
    public static function emptyCarEmailProvider(): array
    {
        return ['empty' => [''], 'NULL' => [null]];
    }

    #[Group('fast')]
    #[DataProvider('emptyCarEmailProvider')]
    public function testEmptyCarEmailDoesNotExcludeTheCar(?string $carEmail): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => $carEmail,
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ]);

        $this->assertContains(
            $carId,
            $this->eligibleIds(),
            'cars.email is only a copy. The send mails users.email.'
        );
    }

    #[Group('fast')]
    public function testEmptyUsersEmailExcludesTheCar(): void
    {
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createTestCar($ownerId, [
            'email'              => 'car-copy-' . uniqid() . '@example.com',
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ]);
        $this->setUserEmailRaw($ownerId, '');

        $this->assertNotContains(
            $carId,
            $this->eligibleIds(),
            'An owner with no users.email cannot get the mail, whatever cars.email holds'
        );
    }

    /**
     * A never-verified car (last_verified IS NULL) that is otherwise stale must
     * be eligible: NULL must not exclude the row.
     */
    #[Group('fast')]
    public function testNeverVerifiedCarIsEligible(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'never-verified-owner@example.com',
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ]);

        $this->assertContains(
            $carId,
            $this->eligibleIds(),
            'A car with last_verified IS NULL and a stale owner_last_updated must be eligible for verification'
        );
    }

    #[Group('fast')]
    public function testRecentlyVerifiedCarIsExcluded(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'recently-verified-owner@example.com',
            'last_verified'      => $this->recentDate(),
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ]);

        $this->assertNotContains(
            $carId,
            $this->eligibleIds(),
            'A car verified within the last 1 year must be excluded from verification eligibility'
        );
    }

    /**
     * The verification email reads `image` from these rows to show the car's
     * photo. The send path uses findVerificationEligible(), and the landing
     * page uses findById(). Both must return the stored value unchanged.
     */
    #[Group('fast')]
    public function testEligibleRowsAndFindByIdReturnTheStoredImageValue(): void
    {
        $image = json_encode(['eligible-photo.jpg', 'second-photo.jpg']);
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'image-owner@example.com',
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
            'image'              => $image,
        ]);

        $eligibleRow = null;
        foreach ($this->repo->findVerificationEligible(1000, 0) as $row) {
            if ((int) $row->id === $carId) {
                $eligibleRow = $row;
                break;
            }
        }
        $this->assertNotNull($eligibleRow, 'Test setup: the car must be eligible');
        $this->assertSame($image, $eligibleRow->image);

        $foundRow = $this->repo->findById($carId);
        $this->assertNotNull($foundRow);
        $this->assertSame($image, $foundRow->image);
    }

    /** The fully-eligible case: stale last_verified AND stale owner_last_updated. */
    #[Group('fast')]
    public function testStaleCarIsEligible(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'stale-owner@example.com',
            'last_verified'      => $this->staleDate(),
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ]);

        $this->assertContains(
            $carId,
            $this->eligibleIds(),
            'A car with both last_verified and owner_last_updated more than 1 year old must be eligible'
        );
    }

    /**
     * A recent owner_last_updated alone makes a never-verified car ineligible.
     */
    #[Group('fast')]
    public function testRecentOwnerUpdateExcludesEvenWhenNeverVerified(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'recent-owner-update@example.com',
            'last_verified'      => null,
            'owner_last_updated' => $this->recentDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ]);

        $this->assertNotContains(
            $carId,
            $this->eligibleIds(),
            'A car with last_verified IS NULL but a recent owner_last_updated must be excluded'
        );
    }

    /**
     * #1953: the NOT NULL constraint must reject a NULL owner_last_updated, so
     * an mtime bump cannot reset the verification clock. Skips before the
     * migration, when the column is still nullable.
     */
    #[Group('fast')]
    public function testColumnIsNotNullSoNullOwnerLastUpdatedFixtureIsRejected(): void
    {
        $row = $this->db->query(
            "SELECT IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cars'
               AND COLUMN_NAME  = 'owner_last_updated'
             LIMIT 1"
        )->first();

        if (!$row || $row->IS_NULLABLE !== 'NO') {
            $this->markTestSkipped(
                'Migration 20260905172137 has not been applied — cars.owner_last_updated is ' .
                'still nullable. Run: composer migrate'
            );
        }

        $chassis = 'NULLREJ' . substr((string) uniqid(), -8);

        $fields = [
            'user_id'            => $this->testUserId,
            'chassis'            => $chassis,
            'email'              => 'null-owner-updated-rejected@example.com',
            'last_verified'      => null,
            'solddate'           => null,
        ];

        // Control: without it, an insert failure for an unrelated reason would
        // also make the assertion below pass.
        $controlId = $this->createTestCar($this->testUserId, [
            'email'              => 'null-owner-updated-control@example.com',
            'last_verified'      => null,
            'owner_last_updated' => date('Y-m-d H:i:s'),
            'solddate'           => null,
        ]);

        $this->assertGreaterThan(
            0,
            $controlId,
            'Control insert must succeed — otherwise the NULL rejection below proves nothing '
            . 'about the NOT NULL constraint specifically'
        );

        $insertResult = $this->db->insert('cars', array_merge($fields, [
            'owner_last_updated' => null,
        ]));

        $this->assertFalse(
            $insertResult,
            'Inserting a NULL owner_last_updated must fail — the column is NOT NULL by schema'
        );

        // No row must exist: the constraint rejected the write.
        $orphan = $this->db->query('SELECT id FROM cars WHERE chassis = ?', [$chassis]);
        $this->assertSame(
            0,
            $orphan->count(),
            'A rejected NULL owner_last_updated insert must leave no row behind'
        );
    }

    /**
     * #1991: a car owned by the seeded `noowner` account is never eligible.
     * Looks noowner up rather than creating it: the username is unique.
     * Warning: if car teardown fails, a stray car stays on the shared noowner
     * account; tearDown() only logs it.
     */
    #[Group('fast')]
    public function testNoOwnerAccountCarIsExcluded(): void
    {
        $noOwnerRow = $this->db->query("SELECT id FROM users WHERE username = ?", ['noowner'])->first();
        $this->assertNotEmpty(
            $noOwnerRow,
            'noowner system account missing — run composer migrate (RegisterNoownerAccount)'
        );
        $noOwnerId = (int) $noOwnerRow->id;

        $sharedFields = [
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ];

        $noOwnerCarId = $this->createTestCar($noOwnerId, array_merge($sharedFields, [
            'email' => 'noowner-owned@example.com',
        ]));

        $controlCarId = $this->createTestCar($this->testUserId, array_merge($sharedFields, [
            'email' => 'noowner-control-normal-owner@example.com',
        ]));

        $eligible = $this->eligibleIds();

        $this->assertNotContains(
            $noOwnerCarId,
            $eligible,
            'A car owned by the noowner system account must be excluded from verification eligibility'
        );
        $this->assertContains(
            $controlCarId,
            $eligible,
            'Control: an identical car owned by a normal user must remain eligible — '
            . 'otherwise the noowner exclusion above proves nothing about ownership specifically'
        );
    }

    /**
     * #1991: a car with user_id IS NULL is excluded. No FK on cars.user_id, so
     * the row is created normally and then set to NULL.
     */
    #[Group('fast')]
    public function testNullUserIdCarIsExcluded(): void
    {
        $sharedFields = [
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ];

        $carId = $this->createTestCar($this->testUserId, array_merge($sharedFields, [
            'email' => 'null-user-id@example.com',
        ]));

        $controlCarId = $this->createTestCar($this->testUserId, array_merge($sharedFields, [
            'email' => 'null-user-id-control-normal-owner@example.com',
        ]));

        $this->db->query('UPDATE cars SET user_id = NULL WHERE id = ?', [$carId]);
        $updatedRow = $this->db->query('SELECT user_id FROM cars WHERE id = ?', [$carId])->first();
        $this->assertNotEmpty($updatedRow, 'Test setup: car ' . $carId . ' disappeared after UPDATE');
        $this->assertNull(
            $updatedRow->user_id,
            'Test setup: cars.user_id was not actually nulled for car ' . $carId
        );

        $eligible = $this->eligibleIds();

        $this->assertNotContains(
            $carId,
            $eligible,
            'A car with a NULL user_id must be excluded from verification eligibility'
        );
        $this->assertContains(
            $controlCarId,
            $eligible,
            'Control: an identical car with a normal (non-NULL) user_id must remain eligible — '
            . 'otherwise the NULL-owner exclusion above proves nothing about ownership specifically'
        );
    }

    /**
     * #1991: a car whose user_id points at a deleted users row is excluded.
     * Reachable in production: there is no FK, and after_user_deletion.php can
     * return early after the user DELETE has committed. Only an INNER JOIN
     * rejects this state.
     */
    #[Group('fast')]
    public function testCarOwnedByDeletedUserIsExcluded(): void
    {
        $doomedUserId = $this->createTestUser();

        $sharedFields = [
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ];

        $orphanCarId = $this->createTestCar($doomedUserId, array_merge($sharedFields, [
            'email' => 'orphaned-owner@example.com',
        ]));

        $controlCarId = $this->createTestCar($this->testUserId, array_merge($sharedFields, [
            'email' => 'orphaned-owner-control-normal-owner@example.com',
        ]));

        // Raw DELETE with no reassignment, as an aborted deletion hook leaves it.
        $this->deleteTestUser($doomedUserId);
        $orphanedRow = $this->db->query('SELECT user_id FROM cars WHERE id = ?', [$orphanCarId])->first();
        $this->assertNotEmpty($orphanedRow, 'Test setup: car ' . $orphanCarId . ' disappeared after user deletion');
        $this->assertSame(
            $doomedUserId,
            (int) $orphanedRow->user_id,
            'Test setup: cars.user_id must still reference the deleted user (no FK cascades it) '
                . 'for car ' . $orphanCarId
        );

        $eligible = $this->eligibleIds();

        $this->assertNotContains(
            $orphanCarId,
            $eligible,
            'A car whose user_id points at a deleted (nonexistent) users row must be excluded from '
            . 'verification eligibility — otherwise a mail would go to an erased owner\'s last-known address'
        );
        $this->assertContains(
            $controlCarId,
            $eligible,
            'Control: an identical car with a live owner must remain eligible — otherwise the '
            . 'orphaned-owner exclusion above proves nothing about ownership specifically'
        );
    }

    /**
     * Attempt cap: a car at 2 attempts inside the 12-month window is excluded.
     * Earlier coverage only string-matched the SQL and could not see an
     * OR/AND grouping error.
     */
    #[Group('fast')]
    public function testAttemptCapReachedWithinYearExcludesCar(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'                       => 'attempt-cap-reached@example.com',
            'last_verified'               => null,
            'owner_last_updated'          => $this->staleDate(),
            'mtime'                       => $this->staleDate(),
            'solddate'                    => null,
            'verification_attempts'       => 2,
            'verification_attempts_since' => $this->recentDate(),
        ]);

        $this->assertNotContains(
            $carId,
            $this->eligibleIds(),
            'A car with verification_attempts = 2 and a verification_attempts_since well within the '
            . 'last year must be excluded — the yearly attempt cap exists specifically to protect '
            . 'sender reputation and must not be silently bypassed'
        );
    }

    /**
     * Attempts = 2 with verification_attempts_since past one year is eligible.
     * 366 days avoids leap-year and timing edge cases.
     */
    #[Group('fast')]
    public function testAttemptCapResetsAfterOneYearMakesCarEligibleAgain(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'                       => 'attempt-cap-reset@example.com',
            'last_verified'               => null,
            'owner_last_updated'          => $this->staleDate(),
            'mtime'                       => $this->staleDate(),
            'solddate'                    => null,
            'verification_attempts'       => 2,
            'verification_attempts_since' => date('Y-m-d H:i:s', strtotime('-366 days')),
        ]);

        $this->assertContains(
            $carId,
            $this->eligibleIds(),
            'A car whose verification_attempts_since is more than a year old must be eligible again '
            . 'regardless of its verification_attempts count — the window, not the counter alone, '
            . 'gates re-eligibility'
        );
    }

    /**
     * Two real incrementVerificationAttempts() calls make the car ineligible:
     * the cap clause and the increment/reset logic must agree on real data.
     */
    #[Group('fast')]
    public function testIncrementingAttemptsTwiceMakesAnEligibleCarIneligible(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'                       => 'attempt-cap-round-trip@example.com',
            'last_verified'               => null,
            'owner_last_updated'          => $this->staleDate(),
            'mtime'                       => $this->staleDate(),
            'solddate'                    => null,
            'verification_attempts'       => 0,
            'verification_attempts_since' => null,
        ]);

        $this->assertContains(
            $carId,
            $this->eligibleIds(),
            'Test setup: a freshly-created car with 0 attempts must start eligible'
        );

        $this->assertTrue(
            $this->repo->incrementVerificationAttempts($carId),
            'Test setup: first increment must succeed against a real row'
        );
        $this->assertTrue(
            $this->repo->incrementVerificationAttempts($carId),
            'Test setup: second increment must succeed against a real row'
        );

        $row = $this->db->query(
            'SELECT verification_attempts, verification_attempts_since FROM cars WHERE id = ?',
            [$carId]
        )->first();
        $this->assertSame(
            2,
            (int) $row->verification_attempts,
            'Test setup: two increments from a NULL verification_attempts_since must land on exactly 2, '
            . 'not roll the window over — incrementVerificationAttempts() only resets when the previous '
            . 'value was NULL or more than a year old, and this second call sees neither'
        );

        $this->assertNotContains(
            $carId,
            $this->eligibleIds(),
            'A car that has genuinely been sent to twice within the tracked window must leave the '
            . 'eligible set — this is the real write path a nightly cron run actually exercises, not a '
            . 'hand-set fixture'
        );
    }

    /**
     * FRD 60-day re-send cooldown. A send writes only vericode_sent_at, so
     * without this clause the cron re-sends to the same batch the next night.
     */
    #[Group('fast')]
    public function testCarSentToWithinSixtyDaysIsExcluded(): void
    {
        $this->assertColumnExists('cars', 'vericode_sent_at');

        $sharedFields = [
            'last_verified'               => $this->staleDate(),
            'owner_last_updated'          => $this->staleDate(),
            'mtime'                       => $this->staleDate(),
            'solddate'                    => null,
            // Below the cap, so only the cooldown can exclude the car.
            'verification_attempts'       => 1,
            'verification_attempts_since' => $this->recentDate(),
        ];

        $cooldownCarId = $this->createTestCar($this->testUserId, array_merge($sharedFields, [
            'email'            => 'cooldown-active@example.com',
            'vericode_sent_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
        ]));

        $controlCarId = $this->createTestCar($this->testUserId, array_merge($sharedFields, [
            'email'            => 'cooldown-never-sent@example.com',
            'vericode_sent_at' => null,
        ]));

        $eligible = $this->eligibleIds();

        $this->assertNotContains(
            $cooldownCarId,
            $eligible,
            'A car sent a verification email 10 days ago must be excluded — otherwise the nightly '
            . 'cron re-sends to the same batch on consecutive nights and the annual cadence collapses'
        );
        $this->assertContains(
            $controlCarId,
            $eligible,
            'Control: an identical never-sent car (vericode_sent_at IS NULL) must remain eligible — '
            . 'otherwise the cooldown exclusion proves nothing about vericode_sent_at specifically, '
            . 'and a NULL would be silently dropping the very cars the first batch should contain'
        );
    }

    /**
     * After the cooldown expires, the car is eligible again. 61 days avoids
     * timing edge cases.
     */
    #[Group('fast')]
    public function testCarSentToMoreThanSixtyDaysAgoIsEligibleAgain(): void
    {
        $this->assertColumnExists('cars', 'vericode_sent_at');

        $carId = $this->createTestCar($this->testUserId, [
            'email'                       => 'cooldown-expired@example.com',
            'last_verified'               => $this->staleDate(),
            'owner_last_updated'          => $this->staleDate(),
            'mtime'                       => $this->staleDate(),
            'solddate'                    => null,
            'vericode_sent_at'            => date('Y-m-d H:i:s', strtotime('-61 days')),
            'verification_attempts'       => 1,
            'verification_attempts_since' => $this->recentDate(),
        ]);

        $this->assertContains(
            $carId,
            $this->eligibleIds(),
            'A car whose last verification send was more than 60 days ago must be eligible again — '
            . 'silence is not a signal the way a bounce is, so the car gets one more attempt within '
            . 'its yearly cap rather than waiting out the full annual cycle'
        );
    }

    /**
     * FRD acceptance test: at most 2 sends in 12 months across three 60-day
     * silent cycles. verification_attempts_since is not aged: three cycles span
     * only ~6 months, so the cap window must stay open.
     */
    #[Group('fast')]
    public function testThreeSixtyDaySilentCyclesProduceExactlyTwoSends(): void
    {
        $this->assertColumnExists('cars', 'vericode_sent_at');

        $carId = $this->createTestCar($this->testUserId, [
            'email'                       => 'three-cycle-silent-owner@example.com',
            'last_verified'               => null,
            'owner_last_updated'          => $this->staleDate(),
            'mtime'                       => $this->staleDate(),
            'solddate'                    => null,
            'vericode_sent_at'            => null,
            'verification_attempts'       => 0,
            'verification_attempts_since' => null,
        ]);

        $sends = 0;
        for ($cycle = 1; $cycle <= 3; $cycle++) {
            if (in_array($carId, $this->eligibleIds(), true)) {
                $sends++;
                $this->assertTrue(
                    $this->repo->incrementVerificationAttempts($carId),
                    "Test setup: increment must succeed on cycle {$cycle}"
                );
                $this->db->query(
                    'UPDATE cars SET vericode_sent_at = NOW() WHERE id = ?',
                    [$carId]
                );
            }

            // Age only vericode_sent_at; aging verification_attempts_since would reset the cap window.
            $this->db->query(
                'UPDATE cars SET vericode_sent_at = ? WHERE id = ? AND vericode_sent_at IS NOT NULL',
                [date('Y-m-d H:i:s', strtotime('-61 days')), $carId]
            );
        }

        $this->assertSame(
            2,
            $sends,
            'A silent owner aged through three consecutive 60-day cycles must receive exactly 2 '
            . 'verification emails — the cooldown re-admits the car each cycle, and the rolling '
            . '12-month attempt cap is what stops the third send'
        );

        $row = $this->db->query(
            'SELECT verification_attempts FROM cars WHERE id = ?',
            [$carId]
        )->first();
        $this->assertSame(
            2,
            (int) $row->verification_attempts,
            'The stored counter must agree with the number of sends actually made'
        );
    }
}
