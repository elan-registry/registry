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

    /**
     * Address shapes for #2594. `{u}` becomes a unique token, so each case
     * keeps its shape and the users table sees a new address every run.
     *
     * @return array<string, array{string, bool}> [address template, stays eligible]
     */
    public static function userEmailShapeProvider(): array
    {
        return [
            'string None'            => ['None{u}', false],
            'no at sign'             => ['owner{u}.example.com', false],
            'at replaced by dash'    => ['owner{u}-at-example.com', false],
            'no dot in domain'       => ['owner{u}@localhost', false],
            'space in local part'    => ['a{u} b@example.com', false],
            'two at signs'           => ['a{u}@@example.com', false],
            'plain well formed'      => ['owner{u}@example.com', true],
            'padded and mixed case'  => ["  Owner{u}@Example.COM\t", true],
            'plus tag, long domain'  => ['first.last+tag{u}@sub.example.co.uk', true],
        ];
    }

    /**
     * #2594: a malformed users.email fails at send time but still takes a
     * batch slot, so the car must not be selected. The well-formed cases are
     * the control: they prove the pattern does not reject real addresses.
     */
    #[Group('fast')]
    #[DataProvider('userEmailShapeProvider')]
    public function testFindVerificationEligibleSelectsCarOnlyWhenOwnerEmailIsWellFormed(
        string $template,
        bool $expectedEligible
    ): void {
        $address = str_replace('{u}', substr(uniqid(), -8), $template);
        $ownerId = $this->createTestUser(['email' => $address], true);
        $carId = $this->createTestCar($ownerId, $this->staleCarFields('car-copy-' . uniqid() . '@example.com'));

        $stored = $this->db->query('SELECT email FROM users WHERE id = ?', [$ownerId])->first();
        $this->assertSame($address, $stored->email, 'Test setup: users.email must hold the raw address');

        $eligible = $this->eligibleIds();
        if ($expectedEligible) {
            $this->assertContains($carId, $eligible, "A car whose owner email is '{$address}' must stay eligible");
        } else {
            $this->assertNotContains($carId, $eligible, "A car whose owner email is '{$address}' must not be eligible");
        }
    }

    /**
     * #2594 acceptance: a batch of 5 with one malformed car sends to 5 other
     * cars. The malformed car has the oldest last_verified, so it sorts first
     * and without the filter it takes one of the 5 slots. A setup assertion
     * fails if another eligible car sorts ahead of the 6 test cars.
     */
    #[Group('fast')]
    public function testFindVerificationEligibleBatchOfFiveSkipsMalformedCarAndFillsFromOthers(): void
    {
        $malformedOwnerId = $this->createTestUser(['email' => 'None'], true);
        $malformedCarId = $this->createTestCar(
            $malformedOwnerId,
            array_merge($this->staleCarFields('batch-malformed@example.com'), ['last_verified' => '1990-01-01 00:00:00'])
        );

        $goodCarIds = [];
        for ($day = 2; $day <= 6; $day++) {
            $goodCarIds[] = $this->createTestCar(
                $this->testUserId,
                array_merge(
                    $this->staleCarFields('batch-good-' . $day . '@example.com'),
                    ['last_verified' => sprintf('1990-01-%02d 00:00:00', $day)]
                )
            );
        }

        // The batch sorts by last_verified ASC with NULL first. Another eligible
        // car that sorts ahead of 1990-01-07 would take a slot, and the
        // malformed car would then miss the batch even without the fix.
        $testCarIds = array_merge([$malformedCarId], $goodCarIds);
        $ahead = array_filter(
            $this->repo->findVerificationEligible(1000, 0),
            static fn ($row) => !in_array((int) $row->id, $testCarIds, true)
                && ($row->last_verified === null || $row->last_verified < '1990-01-07')
        );
        $this->assertSame([], array_values($ahead), 'Setup: other eligible cars sort ahead of the test cars');

        $batchIds = array_map(
            static fn ($row) => (int) $row->id,
            $this->repo->findVerificationEligible(5, 0)
        );

        $this->assertCount(5, $batchIds, 'The batch must be full');
        $this->assertNotContains($malformedCarId, $batchIds, 'The malformed-address car must not take a slot');
        $this->assertEqualsCanonicalizing(
            $goodCarIds,
            $batchIds,
            'The 5 slots must go to the 5 cars with a well-formed owner email'
        );
    }

    /**
     * #2599: cars with the same last_verified go in car id order, so a batch
     * and the pages of the Eligible list never repeat or skip a car. The
     * tied cars have the oldest date, so they come first.
     */
    #[Group('fast')]
    public function testFindVerificationEligibleOrdersTiedLastVerifiedByCarId(): void
    {
        $tiedIds = [];
        for ($i = 0; $i < 4; $i++) {
            $tiedIds[] = $this->createTestCar(
                $this->testUserId,
                array_merge($this->staleCarFields("tie-{$i}@example.com"), ['last_verified' => '1989-06-15 00:00:00'])
            );
        }
        $ahead = array_filter(
            $this->repo->findVerificationEligible(1000, 0),
            static fn ($row) => !in_array((int) $row->id, $tiedIds, true)
                && ($row->last_verified === null || $row->last_verified <= '1989-06-15 00:00:00')
        );
        $this->assertSame([], array_values($ahead), 'Setup: no other eligible car sorts ahead of the tied cars');

        $ids = static fn (array $rows): array => array_map(static fn ($row) => (int) $row->id, $rows);

        $this->assertSame($tiedIds, $ids($this->repo->findVerificationEligible(4, 0)));
        $this->assertSame(array_slice($tiedIds, 0, 2), $ids($this->repo->findVerificationEligible(2, 0)));
        $this->assertSame(array_slice($tiedIds, 2, 2), $ids($this->repo->findVerificationEligible(2, 2)));
    }
}
