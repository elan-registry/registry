<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EmailNoticeBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * EmailNoticeBuilder::buildForOwner() end to end with real rows; the unit
 * tests mock CarRepository and never run its queries.
 *
 * The notice reads the owner's profile flags (#2101), with `cars.email` only
 * for the addresses that an opt-out names.
 */
#[Group('integration')]
final class EmailNoticeBuilderIntegrationTest extends IntegrationTestCase
{
    private CarRepository $repo;
    private EmailNoticeBuilder $builder;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repo = new CarRepository($this->db);
        $this->builder = new EmailNoticeBuilder($this->repo);
        $this->userId = $this->createTestUser([], true);
    }

    #[Group('fast')]
    public function testOwnerOptOutNamesEveryDistinctCarAddressWithTheOptOutDate(): void
    {
        $emailA = 'optout-a-' . uniqid() . '@example.com';
        $emailB = 'optout-b-' . uniqid() . '@example.com';
        $this->createTestCar($this->userId, ['email' => $emailA]);
        $this->createTestCar($this->userId, ['email' => $emailB]);
        $this->createTestCar($this->userId, ['email' => strtoupper($emailA)]);
        $this->setOwnerOptedOut($this->userId, true, '2026-03-01 12:00:00');

        $result = $this->builder->buildForOwner($this->userId);

        $this->assertNotNull($result);
        $this->assertTrue($result['hasSuppressed']);
        $this->assertFalse($result['hasBounced']);
        $this->assertCount(2, $result['addresses'], 'A car address that differs only in case is one address');
        $addresses = array_map(static fn (array $a): string => strtolower($a['address']), $result['addresses']);
        $expected = [strtolower($emailA), strtolower($emailB)];
        sort($expected);
        $this->assertSame($expected, $addresses, 'Addresses are sorted');
        foreach ($result['addresses'] as $entry) {
            $this->assertNotNull($entry['suppressed']);
            $this->assertSame(EmailNoticeBuilder::CAUSE_OWNER_OPTOUT, $entry['suppressed']['cause']);
            $this->assertSame('2026-03-01', $entry['suppressed']['date']);
        }
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function blockCauseProvider(): array
    {
        return [
            'spam'         => ['spam'],
            'unsubscribed' => ['unsubscribed'],
            'blocklist'    => ['blocklist'],
            'unknown'      => [null],
        ];
    }

    /**
     * Every block cause shows the same Brevo-complaint text until #2356
     * replaces the notice. The address and date come from the profile, not
     * from a car.
     */
    #[DataProvider('blockCauseProvider')]
    #[Group('fast')]
    public function testBlockedOwnerShowsTheBlockedAddressAndDateFromTheProfile(?string $cause): void
    {
        $carEmail = 'car-' . uniqid() . '@example.com';
        $blockedAddress = 'blocked-' . uniqid() . '@example.com';
        $this->createTestCar($this->userId, ['email' => $carEmail]);
        $this->setOwnerBlocked($this->userId, $blockedAddress, '2026-03-10 09:30:00', $cause);

        $result = $this->builder->buildForOwner($this->userId);

        $this->assertNotNull($result);
        $this->assertTrue($result['hasSuppressed']);
        $this->assertCount(1, $result['addresses'], 'Only the blocked address is named, not the car address');
        $this->assertSame($blockedAddress, $result['addresses'][0]['address']);
        $this->assertNotNull($result['addresses'][0]['suppressed']);
        $this->assertSame(EmailNoticeBuilder::CAUSE_BREVO_COMPLAINT, $result['addresses'][0]['suppressed']['cause']);
        $this->assertSame('2026-03-10', $result['addresses'][0]['suppressed']['date']);
    }

    #[Group('fast')]
    public function testBouncedOwnerShowsTheBouncedAddressAndDate(): void
    {
        $bouncedAddress = 'bounced-' . uniqid() . '@example.com';
        $this->createTestCar($this->userId, ['email' => $bouncedAddress]);
        $this->setOwnerBounced($this->userId, $bouncedAddress, '2026-04-05 06:07:08');

        $result = $this->builder->buildForOwner($this->userId);

        $this->assertNotNull($result);
        $this->assertTrue($result['hasBounced']);
        $this->assertFalse($result['hasSuppressed']);
        $this->assertCount(1, $result['addresses']);
        $this->assertSame($bouncedAddress, $result['addresses'][0]['address']);
        $this->assertNull($result['addresses'][0]['suppressed']);
        $this->assertSame(['date' => '2026-04-05'], $result['addresses'][0]['bounced']);
    }

    /** One address both blocked and opted out: the block wins, because Resume does not clear it. */
    #[Group('fast')]
    public function testSameAddressBlockedAndOptedOutShowsTheBlock(): void
    {
        $email = 'both-' . uniqid() . '@example.com';
        $this->createTestCar($this->userId, ['email' => $email]);
        $this->setOwnerOptedOut($this->userId, true, '2026-03-01 12:00:00');
        $this->setOwnerBlocked($this->userId, $email, '2026-03-10 09:30:00', 'spam');

        $result = $this->builder->buildForOwner($this->userId);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['addresses']);
        $this->assertNotNull($result['addresses'][0]['suppressed']);
        $this->assertSame(EmailNoticeBuilder::CAUSE_BREVO_COMPLAINT, $result['addresses'][0]['suppressed']['cause']);
    }

    #[Group('fast')]
    public function testFourAddressOverflowEndToEnd(): void
    {
        // Four distinct cars, each with its own address: the owner opt-out
        // names all four, capped to MAX_ADDRESSES (3), with overflowCount
        // reporting the 4th.
        for ($i = 1; $i <= 4; $i++) {
            $this->createTestCar($this->userId, ['email' => "overflow-{$i}-" . uniqid() . '@example.com']);
        }
        $this->setOwnerOptedOut($this->userId);

        $result = $this->builder->buildForOwner($this->userId);

        $this->assertNotNull($result);
        $this->assertCount(EmailNoticeBuilder::MAX_ADDRESSES, $result['addresses']);
        $this->assertSame(1, $result['overflowCount']);
        $this->assertTrue($result['hasSuppressed']);
    }

    #[Group('fast')]
    public function testBlockedFlagWithNoAddressGivesNoNotice(): void
    {
        $this->createTestCar($this->userId, ['email' => 'noaddr-' . uniqid() . '@example.com']);
        $this->setOwnerBlocked($this->userId, 'placeholder@example.com');
        $this->db->query('UPDATE profiles SET email_blocked_address = NULL WHERE user_id = ?', [$this->userId]);

        $this->assertNull(
            $this->builder->buildForOwner($this->userId),
            'A flag with no address has nothing to name, so no notice is built (the case is logged)'
        );
    }

    #[Group('fast')]
    public function testOwnerWithFlagButNoCarsGetsNoNotice(): void
    {
        $this->setOwnerOptedOut($this->userId);

        $this->assertNull($this->builder->buildForOwner($this->userId), 'The notice is about car-verification emails');
    }

    #[Group('fast')]
    public function testCleanOwnerWithCarsReturnsNull(): void
    {
        $this->createTestCar($this->userId, [
            'email' => 'clean-' . uniqid() . '@example.com',
        ]);

        $this->assertNull($this->builder->buildForOwner($this->userId));
    }

    #[Group('fast')]
    public function testFlagOfAnotherOwnerDoesNotShowInThisOwnersNotice(): void
    {
        $this->createTestCar($this->userId, ['email' => 'mine-' . uniqid() . '@example.com']);
        $otherOwnerId = $this->createTestUser([], true);
        $this->createTestCar($otherOwnerId, ['email' => 'other-' . uniqid() . '@example.com']);
        $this->setOwnerBlocked($otherOwnerId, 'other-blocked@example.com');

        $this->assertNull($this->builder->buildForOwner($this->userId));
    }
}
