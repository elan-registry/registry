<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2101: verification eligibility reads the owner's profile, not the car.
 *
 * Each case runs findVerificationEligible() against real rows. Every flag test
 * has an unflagged control car, so a test that passes because of some other
 * exclusion rule (stale date, sold, no email) cannot go unseen.
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarVerificationEligibilityProfileFlagsTest extends IntegrationTestCase
{
    private const OWNER_EMAIL = 'eligibility-owner@example.com';

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
     * A car that is stale, unsold, with an email and no recent send. With an
     * unflagged owner profile it is eligible.
     */
    private function createEligibleCar(int $ownerId, string $email = self::OWNER_EMAIL): int
    {
        $stale = date('Y-m-d H:i:s', strtotime('-3 years'));

        return $this->createTestCar($ownerId, [
            'email'              => $email,
            'last_verified'      => null,
            'owner_last_updated' => $stale,
            'mtime'              => $stale,
            'solddate'           => null,
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function flagProvider(): array
    {
        return [
            'opted out' => ['opted_out'],
            'blocked'   => ['blocked'],
            'bounced'   => ['bounced'],
        ];
    }

    private function applyFlag(int $ownerId, string $flag): void
    {
        match ($flag) {
            'opted_out' => $this->setOwnerOptedOut($ownerId),
            'blocked'   => $this->setOwnerBlocked($ownerId, self::OWNER_EMAIL),
            'bounced'   => $this->setOwnerBounced($ownerId, self::OWNER_EMAIL),
            default     => $this->fail("Test setup: unknown flag {$flag}"),
        };
    }

    public function testUnflaggedOwnerCarIsEligible(): void
    {
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createEligibleCar($ownerId);

        $this->assertContains($carId, $this->allVerificationEligibleCarIds(), 'Control: the fixture car must be eligible');
    }

    #[DataProvider('flagProvider')]
    public function testEachProfileFlagExcludesTheOwnersCar(string $flag): void
    {
        $flaggedOwner = $this->createTestUser(['email' => self::OWNER_EMAIL], true);
        $flaggedCar = $this->createEligibleCar($flaggedOwner);
        $controlOwner = $this->createTestUser([], true);
        $controlCar = $this->createEligibleCar($controlOwner, 'eligibility-control@example.com');

        $this->applyFlag($flaggedOwner, $flag);

        $eligible = $this->allVerificationEligibleCarIds();
        $this->assertNotContains($flaggedCar, $eligible, "A {$flag} owner's car must be excluded");
        $this->assertContains($controlCar, $eligible, 'Control: the unflagged owner car stays eligible');
    }

    #[DataProvider('flagProvider')]
    public function testACarRegisteredAfterTheFlagWasSetIsExcluded(string $flag): void
    {
        $ownerId = $this->createTestUser(['email' => self::OWNER_EMAIL], true);
        $this->applyFlag($ownerId, $flag);

        $newCar = $this->createEligibleCar($ownerId);

        $this->assertNotContains(
            $newCar,
            $this->allVerificationEligibleCarIds(),
            'A car added after the flag was set must be excluded too (#2319)'
        );

        $this->clearOwnerEmailFlags($ownerId);
        $this->assertContains(
            $newCar,
            $this->allVerificationEligibleCarIds(),
            'Control: the same car is eligible once the flag is cleared'
        );
    }

    public function testOwnerWithNoProfileRowExcludesTheCar(): void
    {
        $ownerId = $this->createTestUser();
        $carId = $this->createEligibleCar($ownerId);

        $this->assertNotContains($carId, $this->allVerificationEligibleCarIds(), 'No profiles row means do not mail');
    }

    public function testOwnerWithNoProfileRowShowsAsNoProfileInTheAllQueue(): void
    {
        $ownerId = $this->createTestUser();
        $carId = $this->createEligibleCar($ownerId);

        $rows = array_values(array_filter(
            $this->repo->findVerificationQueue('all', null, PHP_INT_MAX),
            static fn (object $row): bool => (int) $row->id === $carId
        ));

        $this->assertCount(1, $rows, 'The car must be in the all queue so that an admin can see it');
        $this->assertSame(0, (int) $rows[0]->has_profile);
        $this->assertSame(0, (int) $rows[0]->email_opted_out);
        $this->assertSame(0, (int) $rows[0]->email_blocked_active);
        $this->assertSame(0, (int) $rows[0]->email_bounced_active);
    }

    /** @return array<string, array{0: string}> */
    public static function flagColumnProvider(): array
    {
        return [
            'opted out' => ['email_opted_out'],
            'blocked'   => ['email_blocked'],
            'bounced'   => ['email_bounced'],
        ];
    }

    #[DataProvider('flagColumnProvider')]
    public function testFlagWithNullAddressExcludesTheCar(string $flagColumn): void
    {
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createEligibleCar($ownerId);
        $this->setFlagColumnOnly($ownerId, $flagColumn);

        $flags = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('1', (string) $flags[$flagColumn], 'Test setup: the flag must be set');
        foreach (['email_blocked_address', 'email_bounced_address'] as $addressColumn) {
            $this->assertNull($flags[$addressColumn], 'Test setup: the address must be NULL');
        }

        $this->assertNotContains($carId, $this->allVerificationEligibleCarIds(), 'A flag with a NULL address still excludes');
    }

    #[DataProvider('flagColumnProvider')]
    public function testFlagWithEmptyAddressExcludesTheCar(string $flagColumn): void
    {
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createEligibleCar($ownerId);
        $this->setFlagColumnOnly($ownerId, $flagColumn);
        $result = $this->db->query(
            "UPDATE profiles SET email_blocked_address = '', email_bounced_address = '' WHERE user_id = ?",
            [$ownerId]
        );
        $this->assertFalse($result->error(), 'Test setup: ' . $result->errorString());

        $flags = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('1', (string) $flags[$flagColumn], 'Test setup: the flag must be set');
        $this->assertSame('', $flags['email_blocked_address']);
        $this->assertSame('', $flags['email_bounced_address']);

        $this->assertNotContains($carId, $this->allVerificationEligibleCarIds(), 'A flag with an empty address still excludes');
    }

    /**
     * Set one flag column to 1 and write nothing else (no address, no date).
     */
    private function setFlagColumnOnly(int $ownerId, string $flagColumn): void
    {
        $this->assertContains($flagColumn, ['email_opted_out', 'email_blocked', 'email_bounced'], 'Test setup: unknown flag column');
        $result = $this->db->query("UPDATE profiles SET {$flagColumn} = 1 WHERE user_id = ?", [$ownerId]);
        $this->assertFalse($result->error(), 'Test setup: ' . $result->errorString());
    }

    public function testResumeByABlockedAndOptedOutOwnerLeavesTheBlockAndKeepsTheOwnerExcluded(): void
    {
        $ownerId = $this->createTestUser(['email' => self::OWNER_EMAIL], true);
        $carId = $this->createEligibleCar($ownerId);
        $this->setOwnerBlocked($ownerId, self::OWNER_EMAIL, '2026-02-03 04:05:06', 'spam');
        $this->setOwnerOptedOut($ownerId, true, '2026-02-04 04:05:06');
        $blockedBefore = $this->blockedColumns($ownerId);

        $changed = $this->manager->clearSuppressedForOwnerByOwner($ownerId);

        $this->assertTrue($changed, 'Resume must clear the opt-out');
        $flags = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('0', (string) $flags['email_opted_out']);
        $this->assertNull($flags['email_opted_out_at']);
        $this->assertSame(
            ['1', self::OWNER_EMAIL, '2026-02-03 04:05:06', 'spam'],
            $blockedBefore,
            'Test setup: the block fixture must hold the literals above'
        );
        $this->assertSame($blockedBefore, $this->blockedColumns($ownerId), 'Resume must not touch the block, its address, date or cause');
        $this->assertNotContains($carId, $this->allVerificationEligibleCarIds(), 'The block still excludes the owner after Resume');
    }

    /**
     * @return list<?string> email_blocked, address, time and cause as strings
     */
    private function blockedColumns(int $ownerId): array
    {
        $flags = $this->getOwnerEmailFlags($ownerId);

        return [
            (string) $flags['email_blocked'],
            $flags['email_blocked_address'],
            $flags['email_blocked_at'],
            $flags['email_blocked_cause'],
        ];
    }

    /**
     * A block or a bounce stored for another address does not apply when the
     * owner uses a new address. The flag stays in the row, so only the match
     * decides (#2356).
     */
    #[DataProvider('addressBoundFlagProvider')]
    public function testAFlagStoredForAnOldAddressDoesNotExcludeASyncedCar(string $flag): void
    {
        $ownerId = $this->createTestUser(['email' => self::OWNER_EMAIL], true);
        $carId = $this->createEligibleCar($ownerId);
        $this->applyFlag($ownerId, $flag);
        $this->assertNotContains($carId, $this->allVerificationEligibleCarIds(), 'Test setup: the matching flag excludes');

        $newAddress = 'changed-' . uniqid() . '@example.com';
        $this->setUserEmailRaw($ownerId, $newAddress);
        $this->setCarEmailRaw($carId, $newAddress);

        $this->assertContains(
            $carId,
            $this->allVerificationEligibleCarIds(),
            "A {$flag} flag for the old address must not exclude a car that mails the new address"
        );
        $this->assertSame(
            '1',
            (string) $this->getOwnerEmailFlags($ownerId)['email_' . $flag],
            'The flag stays in the row. Only the match ignores it.'
        );
    }

    /**
     * The batch mails users.email. cars.email is only a copy. When
     * users.email moves on and the car still holds the flagged address, the
     * flag no longer applies. A flag on the new users.email excludes the car
     * (#2356).
     */
    #[DataProvider('addressBoundFlagProvider')]
    public function testOnlyAFlagOnUsersEmailExcludesTheCarWhateverTheCarEmail(string $flag): void
    {
        $ownerId = $this->createTestUser(['email' => self::OWNER_EMAIL], true);
        // The car keeps the flagged address as its copy.
        $carId = $this->createEligibleCar($ownerId);
        $this->applyFlag($ownerId, $flag);
        $this->assertNotContains($carId, $this->allVerificationEligibleCarIds(), 'Test setup: the matching flag excludes');

        $newAddress = 'changed-' . uniqid() . '@example.com';
        $this->setUserEmailRaw($ownerId, $newAddress);

        $this->assertContains(
            $carId,
            $this->allVerificationEligibleCarIds(),
            "A {$flag} flag that matches only the stale cars.email must not exclude the car"
        );
        $this->assertNull(
            $this->queueRow($flag === 'bounced' ? 'bounced' : 'suppressed', $carId),
            'The car does not show under the pill of a flag that does not apply'
        );

        $flag === 'bounced'
            ? $this->setOwnerBounced($ownerId, $newAddress)
            : $this->setOwnerBlocked($ownerId, $newAddress);

        $this->assertNotContains(
            $carId,
            $this->allVerificationEligibleCarIds(),
            "A {$flag} flag on users.email must exclude the car, whatever cars.email holds"
        );
        $this->assertNotNull(
            $this->queueRow($flag === 'bounced' ? 'bounced' : 'suppressed', $carId),
            'The car shows under the pill of its flag'
        );
    }

    /**
     * A car with a live link is Pending. A flag that matches only its stale
     * cars.email keeps it Pending. A flag on users.email moves it to the pill
     * of its flag, and the counts move with the rows (#2356).
     */
    #[DataProvider('addressBoundFlagProvider')]
    public function testOnlyAFlagOnUsersEmailMovesALiveLinkCarOutOfPending(string $flag): void
    {
        $pill = $flag === 'bounced' ? 'bounced' : 'suppressed';
        $before = $this->repo->countVerificationSummary(null);

        $ownerId = $this->createTestUser(['email' => self::OWNER_EMAIL], true);
        $carId = $this->createEligibleCar($ownerId);
        $this->db->query(
            'UPDATE cars SET vericode = ?, vericode_sent_at = ? WHERE id = ?',
            ['hash-live-link', date('Y-m-d H:i:s', strtotime('-1 day')), $carId]
        );
        $this->assertNotNull($this->queueRow('pending', $carId), 'Test setup: the live-link car is Pending');

        $this->applyFlag($ownerId, $flag);
        $newAddress = 'changed-' . uniqid() . '@example.com';
        $this->setUserEmailRaw($ownerId, $newAddress);

        $this->assertNotNull($this->queueRow('pending', $carId), 'A flag on the stale cars.email keeps the car Pending');
        $this->assertSame(
            $before['pending'] + 1,
            $this->repo->countVerificationSummary(null)['pending'],
            'The Pending count includes the car'
        );

        $flag === 'bounced'
            ? $this->setOwnerBounced($ownerId, $newAddress)
            : $this->setOwnerBlocked($ownerId, $newAddress);

        $after = $this->repo->countVerificationSummary(null);
        $this->assertNull($this->queueRow('pending', $carId), 'The car does not show as Pending');
        $this->assertSame($before['pending'], $after['pending'], 'The Pending count does not include the car');
        $this->assertSame($before[$pill] + 1, $after[$pill], "The {$pill} count includes the car");
        $this->assertNotNull($this->queueRow($pill, $carId), "The car shows under the {$pill} pill");
        $row = $this->queueRow('all', $carId);
        $this->assertNotNull($row, 'The car is in the all queue');
        $this->assertSame(1, (int) $row->{'email_' . $flag . '_active'}, "The all row marks the {$flag} flag as active");
    }

    /** @return array<string, array{string}> */
    public static function emptyUsersEmailProvider(): array
    {
        return [
            'empty'         => [''],
            'spaces only'   => ['   '],
            // MySQL TRIM() keeps a tab. The send trims it, so the car must not use a batch slot.
            'space and tab' => [" \t "],
        ];
    }

    /** The send mails users.email, so an owner with an empty users.email is not eligible, whatever the car holds. */
    #[DataProvider('emptyUsersEmailProvider')]
    public function testAnEmptyUsersEmailExcludesTheCarEvenWithACarEmail(string $emptyEmail): void
    {
        $ownerId = $this->createTestUser(['email' => self::OWNER_EMAIL], true);
        $carId = $this->createEligibleCar($ownerId);
        $this->assertContains($carId, $this->allVerificationEligibleCarIds(), 'Test setup: the car is eligible');

        $this->setUserEmailRaw($ownerId, $emptyEmail);

        $this->assertNotContains($carId, $this->allVerificationEligibleCarIds());
    }

    /** An empty cars.email does not exclude the car. The send never reads it. */
    public function testAnEmptyCarEmailDoesNotExcludeTheCar(): void
    {
        $ownerId = $this->createTestUser(['email' => self::OWNER_EMAIL], true);
        $carId = $this->createEligibleCar($ownerId, '');

        $this->assertContains($carId, $this->allVerificationEligibleCarIds());
    }

    /** The flag applies again when the address goes back to the flagged one (A to B to A). */
    #[DataProvider('addressBoundFlagProvider')]
    public function testTheFlagExcludesTheCarAgainWhenTheAddressReturnsToTheFlaggedOne(string $flag): void
    {
        $ownerId = $this->createTestUser(['email' => self::OWNER_EMAIL], true);
        $carId = $this->createEligibleCar($ownerId);
        $this->applyFlag($ownerId, $flag);

        $other = 'other-' . uniqid() . '@example.com';
        $this->setUserEmailRaw($ownerId, $other);
        $this->setCarEmailRaw($carId, $other);
        $this->assertContains($carId, $this->allVerificationEligibleCarIds(), 'Test setup: B is eligible');
        $this->setUserEmailRaw($ownerId, '  ' . strtoupper(self::OWNER_EMAIL) . "\t");

        $this->assertNotContains(
            $carId,
            $this->allVerificationEligibleCarIds(),
            'The flag for A applies again, whatever the case and the surrounding whitespace of A'
        );
    }

    /** An opt-out excludes the car after any address change, and a mismatched block does not add or remove anything. */
    public function testOptOutStillExcludesTheCarAfterTheAddressChanges(): void
    {
        $ownerId = $this->createTestUser(['email' => self::OWNER_EMAIL], true);
        $carId = $this->createEligibleCar($ownerId);
        $this->setOwnerOptedOut($ownerId);
        $this->setOwnerBlocked($ownerId, self::OWNER_EMAIL);

        $this->setUserEmailRaw($ownerId, 'new-' . uniqid() . '@example.com');

        $this->assertNotContains($carId, $this->allVerificationEligibleCarIds(), 'The opt-out applies whatever the address');
    }

    /** @return array<string, array{0: string}> */
    public static function addressBoundFlagProvider(): array
    {
        return [
            'blocked' => ['blocked'],
            'bounced' => ['bounced'],
        ];
    }

    /**
     * Queue rows (the `all` queue): the `_active` columns follow the match,
     * the opt-out column does not. The owner and the car both move to the
     * new address. The suppressed and bounced pills use the
     * same predicate.
     */
    public function testQueueActiveColumnsFollowTheAddressMatch(): void
    {
        $ownerId = $this->createTestUser(['email' => self::OWNER_EMAIL], true);
        $carId = $this->createEligibleCar($ownerId);
        $this->setOwnerBlocked($ownerId, self::OWNER_EMAIL);
        $this->setOwnerBounced($ownerId, self::OWNER_EMAIL);
        $this->setOwnerOptedOut($ownerId);

        $row = $this->queueRow('all', $carId);
        $this->assertNotNull($row, 'Test setup: the car is in the all queue');
        $this->assertSame([1, 1, 1], [(int) $row->email_blocked_active, (int) $row->email_bounced_active, (int) $row->email_opted_out]);
        $this->assertNotNull($this->queueRow('bounced', $carId), 'The bounced pill holds the car while the bounce applies');
        $this->assertNotNull($this->queueRow('suppressed', $carId), 'The suppressed pill holds the car while the block applies');

        $moved = 'moved-' . uniqid() . '@example.com';
        $this->setUserEmailRaw($ownerId, $moved);
        $this->setCarEmailRaw($carId, $moved);

        $row = $this->queueRow('all', $carId);
        $this->assertNotNull($row, 'The opted-out car stays in the all queue');
        $this->assertSame(
            [0, 0, 1],
            [(int) $row->email_blocked_active, (int) $row->email_bounced_active, (int) $row->email_opted_out],
            'The flags for the old address are inactive. The opt-out stays.'
        );
        $this->assertNull($this->queueRow('bounced', $carId), 'The bounced pill drops the car when the address no longer matches');
        $this->assertNotNull($this->queueRow('suppressed', $carId), 'The opt-out keeps the car in the suppressed pill');

        $this->setOwnerOptedOut($ownerId, false);
        $this->assertNull(
            $this->queueRow('suppressed', $carId),
            'Without the opt-out, a block for the old address leaves the suppressed pill'
        );
        $this->assertNotNull($this->queueRow('eligible', $carId), 'The car is eligible again');
    }

    /** A flag with a NULL address is active in the queue columns (fail closed). */
    public function testQueueActiveColumnsTreatANullAddressAsActive(): void
    {
        $ownerId = $this->createTestUser(['email' => self::OWNER_EMAIL], true);
        $carId = $this->createEligibleCar($ownerId);
        $this->setFlagColumnOnly($ownerId, 'email_blocked');
        $this->setFlagColumnOnly($ownerId, 'email_bounced');
        $this->setUserEmailRaw($ownerId, 'moved-' . uniqid() . '@example.com');

        $row = $this->queueRow('all', $carId);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->email_blocked_active);
        $this->assertSame(1, (int) $row->email_bounced_active);
    }

    /** Write cars.email directly, as a failed car sync or an older row leaves it. */
    private function setCarEmailRaw(int $carId, string $email): void
    {
        $result = $this->db->query('UPDATE cars SET email = ? WHERE id = ?', [$email, $carId]);
        $this->assertFalse($result->error(), 'Test setup: cars.email update failed: ' . $result->errorString());
    }

    private function queueRow(string $status, int $carId): ?object
    {
        foreach ($this->repo->findVerificationQueue($status, null, PHP_INT_MAX) as $row) {
            if ((int) $row->id === $carId) {
                return $row;
            }
        }

        return null;
    }

    public function testOwnerOptOutOnABlockedOwnerSurvivesClearingTheBlock(): void
    {
        $ownerId = $this->createTestUser(['email' => self::OWNER_EMAIL], true);
        $carId = $this->createEligibleCar($ownerId);
        $this->setOwnerBlocked($ownerId, self::OWNER_EMAIL);

        $changed = $this->manager->setSuppressedForOwner($ownerId);
        $this->assertTrue($changed, 'The opt-out must be recorded although email_blocked is set');

        $this->repo->clearProfileBlocked($ownerId);

        $flags = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('0', (string) $flags['email_blocked'], 'Test setup: the block must be cleared');
        $this->assertSame('1', (string) $flags['email_opted_out'], 'The opt-out must stay after the block is cleared');
        $this->assertNotNull($flags['email_opted_out_at']);
        $this->assertNotContains($carId, $this->allVerificationEligibleCarIds(), 'Verification still excludes the owner');
    }

    public function testOwnerOptOutOnABouncedOwnerSurvivesClearingTheBounce(): void
    {
        $ownerId = $this->createTestUser(['email' => self::OWNER_EMAIL], true);
        $carId = $this->createEligibleCar($ownerId);
        $this->setOwnerBounced($ownerId, self::OWNER_EMAIL);

        $this->assertTrue($this->manager->setSuppressedForOwner($ownerId));
        $this->repo->clearProfileBounced($ownerId);

        $flags = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('0', (string) $flags['email_bounced']);
        $this->assertSame('1', (string) $flags['email_opted_out']);
        $this->assertNotContains($carId, $this->allVerificationEligibleCarIds());
    }
}
