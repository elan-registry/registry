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
        $flaggedOwner = $this->createTestUser([], true);
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
        $ownerId = $this->createTestUser([], true);
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
        $this->assertSame(0, (int) $rows[0]->email_blocked);
        $this->assertSame(0, (int) $rows[0]->email_bounced);
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
        $ownerId = $this->createTestUser([], true);
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

    public function testOwnerOptOutOnABlockedOwnerSurvivesClearingTheBlock(): void
    {
        $ownerId = $this->createTestUser([], true);
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
        $ownerId = $this->createTestUser([], true);
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
