<?php

declare(strict_types=1);

namespace Tests\Unit\Cars;

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EffectiveEmailFlags;
use ElanRegistry\Car\EmailNoticeBuilder;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for EmailNoticeBuilder::buildForOwner(), with a stubbed CarRepository.
 *
 * The builder returns one owner-level state from
 * CarRepository::effectiveEmailFlags() (#2356). The rules these tests pin:
 * - A block wins over a bounce. A bounce wins over an opt-out.
 * - With a block or a bounce, `alsoOptedOut` tells the partial to add the
 *   opt-out line.
 * - A known profile block cause is passed on. An unknown cause is dropped (#2318).
 * - Event causes join the profile cause, each once (#2500).
 * - A flag with a NULL or empty address names the current `users.email`.
 * - A NULL or malformed `_at` gives a NULL date.
 * - The builder calls effectiveEmailFlags(). Only for a block with an address,
 *   it also calls latestBlockClearAt() and ownerBlockEventCauses().
 * - The latest Clear Block (CarRepository::latestBlockClearAt()) bounds the event lookup.
 */
#[Group('fast')]
final class EmailNoticeBuilderTest extends TestCase
{
    private const CURRENT = 'current@example.com';

    /**
     * Build a real EffectiveEmailFlags (the class is final, so no mock).
     * Detail fields reach the object only for a flag that applies.
     *
     * @param array<string, mixed> $o Overrides
     */
    private static function flags(array $o = []): EffectiveEmailFlags
    {
        $o += [
            'hasProfile'     => true,
            'optedOut'       => false,
            'optedOutAt'     => null,
            'blocked'        => false,
            'blockedAddress' => null,
            'blockedAt'      => null,
            'blockedCause'   => null,
            'bounced'        => false,
            'bouncedAddress' => null,
            'bouncedAt'      => null,
            'currentEmail'   => self::CURRENT,
        ];

        return new EffectiveEmailFlags(
            $o['hasProfile'],
            $o['optedOut'],
            $o['optedOutAt'],
            $o['blocked'],
            $o['blockedAddress'],
            $o['blockedAt'],
            $o['blockedCause'],
            $o['bounced'],
            $o['bouncedAddress'],
            $o['bouncedAt'],
            $o['currentEmail']
        );
    }

    /**
     * The builder calls the two block lookups only for a block with an address.
     *
     * @param list<string> $eventCauses What ownerBlockEventCauses() returns
     * @param string|null $clearedAt What latestBlockClearAt() returns
     */
    private function builderFor(EffectiveEmailFlags $flags, array $eventCauses = [], ?string $clearedAt = null): EmailNoticeBuilder
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('effectiveEmailFlags')->willReturn($flags);
        $repo->method('ownerBlockEventCauses')->willReturn($eventCauses);
        $repo->method('latestBlockClearAt')->willReturn($clearedAt);

        return new EmailNoticeBuilder($repo);
    }

    // --- Nothing to show -----------------------------------------------

    public function testReturnsNullWhenNoFlagApplies(): void
    {
        $this->assertNull($this->builderFor(self::flags())->buildForOwner(1));
    }

    public function testReturnsNullForAnOwnerWithNoProfile(): void
    {
        $this->assertNull($this->builderFor(EffectiveEmailFlags::none())->buildForOwner(1));
    }

    /** A flag that does not apply (old address) never reaches the notice, whatever its details held. */
    public function testFlagsThatDoNotApplyGiveNoNotice(): void
    {
        $flags = self::flags([
            'blocked' => false, 'blockedAddress' => 'old@example.com', 'blockedCause' => 'spam',
            'bounced' => false, 'bouncedAddress' => 'old@example.com',
        ]);

        $this->assertNull($this->builderFor($flags)->buildForOwner(1));
    }

    // --- Shape ---------------------------------------------------------

    public function testBlockedStateHasTheExactOwnerLevelShape(): void
    {
        $flags = self::flags([
            'blocked' => true, 'blockedAddress' => 'blocked@example.com',
            'blockedAt' => '2026-08-03 10:00:00', 'blockedCause' => 'spam',
        ]);

        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame(
            ['kind', 'address', 'date', 'causes', 'alsoOptedOut', 'optedOutDate', 'contentHash'],
            array_keys($result)
        );
        $this->assertSame('blocked', $result['kind']);
        $this->assertSame('blocked@example.com', $result['address']);
        $this->assertSame('2026-08-03', $result['date']);
        $this->assertSame(['spam'], $result['causes']);
        $this->assertFalse($result['alsoOptedOut']);
        $this->assertNull($result['optedOutDate']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['contentHash']);
    }

    public function testBouncedStateHasNoCausesAndTheBounceDate(): void
    {
        $flags = self::flags([
            'bounced' => true, 'bouncedAddress' => 'bounced@example.com', 'bouncedAt' => '2026-09-15 08:00:00',
        ]);

        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame('bounced', $result['kind']);
        $this->assertSame('bounced@example.com', $result['address']);
        $this->assertSame('2026-09-15', $result['date']);
        $this->assertSame([], $result['causes']);
        $this->assertFalse($result['alsoOptedOut']);
    }

    public function testOptedOutStateHasNoAddressAndTheOptOutDate(): void
    {
        $flags = self::flags(['optedOut' => true, 'optedOutAt' => '2026-08-01 09:30:00']);

        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame('opted_out', $result['kind']);
        $this->assertNull($result['address']);
        $this->assertSame('2026-08-01', $result['date']);
        $this->assertSame([], $result['causes']);
        $this->assertFalse($result['alsoOptedOut']);
        $this->assertNull($result['optedOutDate']);
    }

    // --- Precedence and alsoOptedOut ----------------------------------

    public function testBlockedWinsOverBounced(): void
    {
        $flags = self::flags([
            'blocked' => true, 'blockedAddress' => 'blocked@example.com', 'blockedAt' => '2026-08-03 10:00:00',
            'bounced' => true, 'bouncedAddress' => 'bounced@example.com', 'bouncedAt' => '2026-09-15 08:00:00',
        ]);

        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame('blocked', $result['kind']);
        $this->assertSame('blocked@example.com', $result['address']);
        $this->assertSame('2026-08-03', $result['date']);
    }

    public function testBouncedWinsOverOptedOutAndSetsAlsoOptedOut(): void
    {
        $flags = self::flags([
            'bounced' => true, 'bouncedAddress' => 'bounced@example.com', 'bouncedAt' => '2026-09-15 08:00:00',
            'optedOut' => true, 'optedOutAt' => '2026-08-01 09:30:00',
        ]);

        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame('bounced', $result['kind']);
        $this->assertSame('2026-09-15', $result['date']);
        $this->assertTrue($result['alsoOptedOut']);
        $this->assertSame('2026-08-01', $result['optedOutDate']);
    }

    public function testBlockedWithOptedOutSetsAlsoOptedOutAndItsDate(): void
    {
        $flags = self::flags([
            'blocked' => true, 'blockedAddress' => 'blocked@example.com',
            'blockedAt' => '2026-08-03 10:00:00', 'blockedCause' => 'blocklist',
            'optedOut' => true, 'optedOutAt' => '2026-07-04 12:00:00',
        ]);

        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame('blocked', $result['kind']);
        $this->assertTrue($result['alsoOptedOut']);
        $this->assertSame('2026-07-04', $result['optedOutDate']);
        $this->assertSame('2026-08-03', $result['date']);
    }

    public function testAlsoOptedOutWithNullOptOutTimeGivesNullOptedOutDate(): void
    {
        $flags = self::flags([
            'blocked' => true, 'blockedAddress' => 'blocked@example.com',
            'optedOut' => true, 'optedOutAt' => null,
        ]);

        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertTrue($result['alsoOptedOut']);
        $this->assertNull($result['optedOutDate']);
    }

    // --- Cause mapping -------------------------------------------------

    /**
     * @return array<string, array{?string, list<string>}>
     */
    public static function causeProvider(): array
    {
        return [
            'spam'                 => ['spam', ['spam']],
            'unsubscribed'         => ['unsubscribed', ['unsubscribed']],
            'blocklist'            => ['blocklist', ['blocklist']],
            'NULL cause'           => [null, []],
            'empty cause'          => ['', []],
            'unknown cause'        => ['hard_bounce', []],
            'retired brevo cause'  => ['brevo_complaint', []],
            'wrong case'           => ['SPAM', []],
            'padded'               => [' spam', []],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('causeProvider')]
    public function testProfileCauseIsPassedOnOnlyWhenKnown(?string $stored, array $expected): void
    {
        $flags = self::flags(['blocked' => true, 'blockedAddress' => 'b@example.com', 'blockedCause' => $stored]);

        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame($expected, $result['causes']);
    }

    // --- Several causes (#2500) ----------------------------------------

    /**
     * @param list<string> $eventCauses
     * @return list<string>
     */
    private function blockCauses(?string $profileCause, array $eventCauses): array
    {
        $flags = self::flags(['blocked' => true, 'blockedAddress' => 'b@example.com', 'blockedCause' => $profileCause]);
        $result = $this->builderFor($flags, $eventCauses)->buildForOwner(1);
        $this->assertNotNull($result);

        return $result['causes'];
    }

    public function testOneProfileCauseWithNoEventRowsGivesOneCause(): void
    {
        $this->assertSame(['spam'], $this->blockCauses('spam', []));
    }

    public function testProfileCauseComesFirstThenTheOtherEventCause(): void
    {
        $this->assertSame(['unsubscribed', 'spam'], $this->blockCauses('unsubscribed', ['unsubscribed', 'spam']));
        $this->assertSame(['spam', 'unsubscribed'], $this->blockCauses('spam', ['unsubscribed', 'spam']));
    }

    public function testEventCausesFollowTheBlockedCausesOrder(): void
    {
        // The repository gives no set order, so the builder sorts the event causes.
        $this->assertSame(['spam', 'unsubscribed'], $this->blockCauses(null, ['unsubscribed', 'spam']));
        $this->assertSame(['spam', 'unsubscribed'], $this->blockCauses(null, ['spam', 'unsubscribed']));
    }

    public function testBlocklistProfileCauseDropsTheUnsubscribedEvent(): void
    {
        $this->assertSame(['blocklist'], $this->blockCauses('blocklist', ['unsubscribed']));
    }

    public function testBlocklistProfileCauseKeepsASpamEvent(): void
    {
        $this->assertSame(['blocklist', 'spam'], $this->blockCauses('blocklist', ['unsubscribed', 'spam']));
    }

    public function testSpamProfileCauseKeepsATaggedAdminBlockEvent(): void
    {
        // The repository gives `blocklist` for a tagged admin-block import row.
        $this->assertSame(['spam', 'blocklist'], $this->blockCauses('spam', ['blocklist']));
    }

    public function testNoProfileCauseUsesTheEventCause(): void
    {
        $this->assertSame(['spam'], $this->blockCauses(null, ['spam']));
    }

    public function testUnknownProfileCauseGivesEventCausesOnlyAndOneLogLine(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        $causes = $this->blockCauses('brevo_complaint', ['spam']);

        $this->assertSame(['spam'], $causes);
        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_VALIDATION_ERROR, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('brevo_complaint', $mockLogEntries[0]['message']);
    }

    /** @return array<string, array{?string}> */
    public static function noLogCauseProvider(): array
    {
        return ['known' => ['spam'], 'NULL' => [null], 'empty' => ['']];
    }

    #[DataProvider('noLogCauseProvider')]
    public function testKnownNullOrEmptyProfileCauseWritesNoLogLine(?string $stored): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        $this->blockCauses($stored, []);

        $this->assertCount(0, $mockLogEntries);
    }

    /**
     * What latestBlockClearAt() returns, which is also the expected `$after`.
     * The repository picks the later source (CarRepository tests cover that).
     *
     * @return array<string, array{?string}>
     */
    public static function clearTimeProvider(): array
    {
        return [
            'a clear is on record' => ['2026-10-01 08:00:00'],
            'no clear'             => [null],
        ];
    }

    /** The event lookup uses the value that the repository gives for the latest clear. */
    #[DataProvider('clearTimeProvider')]
    public function testTheClearBlockTimeBoundsTheEventLookup(?string $clearAt): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->method('effectiveEmailFlags')->willReturn(
            self::flags(['blocked' => true, 'blockedAddress' => 'b@example.com'])
        );
        $repo->expects($this->once())
            ->method('latestBlockClearAt')
            ->with(7)
            ->willReturn($clearAt);
        $repo->expects($this->once())
            ->method('ownerBlockEventCauses')
            ->with(7, 'b@example.com', $clearAt)
            ->willReturn(['spam']);

        $result = (new EmailNoticeBuilder($repo))->buildForOwner(7);

        $this->assertNotNull($result);
        $this->assertSame(['spam'], $result['causes']);
    }

    public function testBouncedAndOptedOutNeverReadBlockEvents(): void
    {
        foreach ([
            self::flags(['bounced' => true, 'bouncedAddress' => 'b@example.com']),
            self::flags(['optedOut' => true]),
        ] as $flags) {
            $repo = $this->createMock(CarRepository::class);
            $repo->method('effectiveEmailFlags')->willReturn($flags);
            $repo->expects($this->never())->method('ownerBlockEventCauses');
            $repo->expects($this->never())->method('latestBlockClearAt');

            $result = (new EmailNoticeBuilder($repo))->buildForOwner(1);

            $this->assertNotNull($result);
            $this->assertSame([], $result['causes']);
        }
    }

    // --- Address fallback ----------------------------------------------

    /**
     * @return array<string, array{?string}>
     */
    public static function emptyAddressProvider(): array
    {
        return [
            'NULL'        => [null],
            'empty'       => [''],
            'white space' => ["  \t\n"],
        ];
    }

    #[DataProvider('emptyAddressProvider')]
    public function testBlockWithNoAddressNamesTheCurrentEmail(?string $stored): void
    {
        $flags = self::flags(['blocked' => true, 'blockedAddress' => $stored]);

        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame(self::CURRENT, $result['address']);
    }

    #[DataProvider('emptyAddressProvider')]
    public function testBounceWithNoAddressNamesTheCurrentEmail(?string $stored): void
    {
        $flags = self::flags(['bounced' => true, 'bouncedAddress' => $stored]);

        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame(self::CURRENT, $result['address']);
    }

    /** A block with no stored address reads the events for the current address. */
    #[DataProvider('emptyAddressProvider')]
    public function testBlockWithNoAddressReadsTheEventsForTheCurrentEmail(?string $stored): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->method('effectiveEmailFlags')->willReturn(self::flags(['blocked' => true, 'blockedAddress' => $stored]));
        $repo->method('latestBlockClearAt')->willReturn('2026-10-01 08:00:00');
        $repo->expects($this->once())
            ->method('ownerBlockEventCauses')
            ->with(1, self::CURRENT, '2026-10-01 08:00:00')
            ->willReturn(['spam']);

        $result = (new EmailNoticeBuilder($repo))->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame(['spam'], $result['causes']);
    }

    public function testNoAddressAnywhereGivesNullAddress(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->method('effectiveEmailFlags')->willReturn(
            self::flags(['blocked' => true, 'blockedAddress' => null, 'currentEmail' => null])
        );
        $repo->expects($this->never())->method('ownerBlockEventCauses');
        $repo->expects($this->never())->method('latestBlockClearAt');

        $result = (new EmailNoticeBuilder($repo))->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertNull($result['address']);
    }

    public function testStoredAddressWinsOverTheCurrentEmail(): void
    {
        $flags = self::flags(['bounced' => true, 'bouncedAddress' => 'stored@example.com']);

        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame('stored@example.com', $result['address']);
    }

    // --- Dates ---------------------------------------------------------

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function dateProvider(): array
    {
        return [
            'valid datetime'       => ['2026-10-12 23:59:59', '2026-10-12'],
            'NULL'                 => [null, null],
            'zero date'            => ['0000-00-00 00:00:00', null],
            'malformed'            => ['yesterday', null],
            'date only'            => ['2026-10-12', null],
            'impossible day'       => ['2026-02-31 00:00:00', null],
        ];
    }

    #[DataProvider('dateProvider')]
    public function testDateIsYmdOrNull(?string $stored, ?string $expected): void
    {
        $flags = self::flags(['bounced' => true, 'bouncedAddress' => 'b@example.com', 'bouncedAt' => $stored]);

        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame($expected, $result['date']);
    }

    // --- Content hash --------------------------------------------------

    public function testContentHashIsStableForTheSameState(): void
    {
        $flags = self::flags(['bounced' => true, 'bouncedAddress' => 'b@example.com', 'bouncedAt' => '2026-09-15 08:00:00']);

        $first  = $this->builderFor($flags)->buildForOwner(1);
        $second = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first['contentHash'], $second['contentHash']);
    }

    public function testContentHashChangesWhenTheBounceDateChanges(): void
    {
        $old = self::flags(['bounced' => true, 'bouncedAddress' => 'b@example.com', 'bouncedAt' => '2026-09-15 08:00:00']);
        $new = self::flags(['bounced' => true, 'bouncedAddress' => 'b@example.com', 'bouncedAt' => '2026-10-01 08:00:00']);

        $oldResult = $this->builderFor($old)->buildForOwner(1);
        $newResult = $this->builderFor($new)->buildForOwner(1);

        $this->assertNotNull($oldResult);
        $this->assertNotNull($newResult);
        $this->assertNotSame($oldResult['contentHash'], $newResult['contentHash']);
    }

    public function testContentHashChangesWhenAnOptOutIsAdded(): void
    {
        $base = ['blocked' => true, 'blockedAddress' => 'b@example.com'];

        $without = $this->builderFor(self::flags($base))->buildForOwner(1);
        $with    = $this->builderFor(self::flags($base + ['optedOut' => true]))->buildForOwner(1);

        $this->assertNotNull($without);
        $this->assertNotNull($with);
        $this->assertNotSame($without['contentHash'], $with['contentHash']);
    }

    public function testContentHashChangesWhenTheCausesChange(): void
    {
        $flags = self::flags(['blocked' => true, 'blockedAddress' => 'b@example.com', 'blockedCause' => 'unsubscribed']);

        $one = $this->builderFor($flags, [])->buildForOwner(1);
        $two = $this->builderFor($flags, ['spam'])->buildForOwner(1);

        $this->assertNotNull($one);
        $this->assertNotNull($two);
        $this->assertNotSame($one['contentHash'], $two['contentHash']);
    }

    public function testContentHashChangesWhenTheKindChanges(): void
    {
        $blocked = $this->builderFor(self::flags(['blocked' => true, 'blockedAddress' => 'b@example.com']))->buildForOwner(1);
        $bounced = $this->builderFor(self::flags(['bounced' => true, 'bouncedAddress' => 'b@example.com']))->buildForOwner(1);

        $this->assertNotNull($blocked);
        $this->assertNotNull($bounced);
        $this->assertNotSame($blocked['contentHash'], $bounced['contentHash']);
    }

    // --- Raw values ----------------------------------------------------

    /** The builder returns raw values. The partial escapes them at render. */
    public function testAddressIsReturnedUnescaped(): void
    {
        $flags = self::flags(['blocked' => true, 'blockedAddress' => "o'brien&co@example.com"]);

        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame("o'brien&co@example.com", $result['address']);
    }

    // --- Contract ------------------------------------------------------

    public function testBuildForOwnerPassesTheOwnerIdToTheRepository(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())
            ->method('effectiveEmailFlags')
            ->with(42)
            ->willReturn(self::flags());

        (new EmailNoticeBuilder($repo))->buildForOwner(42);
    }

    /**
     * Every other public repository method throws, so a stray call fails the test.
     * A block with an address may also call latestBlockClearAt() and
     * ownerBlockEventCauses().
     *
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function guardedStateProvider(): array
    {
        $allowedForBlock = ['effectiveEmailFlags', 'latestBlockClearAt', 'ownerBlockEventCauses'];

        return [
            'blocked and opted out' => [
                ['blocked' => true, 'blockedAddress' => 'b@example.com', 'optedOut' => true],
                $allowedForBlock,
            ],
            'bounced and opted out' => [
                ['bounced' => true, 'bouncedAddress' => 'b@example.com', 'optedOut' => true],
                ['effectiveEmailFlags'],
            ],
            'opted out' => [['optedOut' => true], ['effectiveEmailFlags']],
        ];
    }

    /**
     * @param array<string, mixed> $flagOverrides
     * @param list<string> $allowed
     */
    #[DataProvider('guardedStateProvider')]
    public function testBuilderCallsOnlyTheAllowedRepositoryMethods(array $flagOverrides, array $allowed): void
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('effectiveEmailFlags')->willReturn(self::flags($flagOverrides));
        if (in_array('latestBlockClearAt', $allowed, true)) {
            $repo->method('latestBlockClearAt')->willReturn(null);
            $repo->method('ownerBlockEventCauses')->willReturn([]);
        }

        $guarded = 0;
        foreach ((new ReflectionClass(CarRepository::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || $method->isFinal()
                || in_array($method->getName(), $allowed, true)
            ) {
                continue;
            }
            $repo->method($method->getName())->willThrowException(
                new \LogicException('EmailNoticeBuilder must not call CarRepository::' . $method->getName())
            );
            $guarded++;
        }
        $this->assertGreaterThan(20, $guarded, 'The guard must cover the repository methods');

        $this->assertNotNull((new EmailNoticeBuilder($repo))->buildForOwner(1));
    }

    public function testRepositoryFailurePropagates(): void
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('effectiveEmailFlags')->willThrowException(new CarDatabaseException('boom'));

        $this->expectException(CarDatabaseException::class);
        (new EmailNoticeBuilder($repo))->buildForOwner(1);
    }

    /** @return array<string, array{string}> */
    public static function causeLookupMethodProvider(): array
    {
        return [
            'clear-time read fails' => ['latestBlockClearAt'],
            'event read fails'      => ['ownerBlockEventCauses'],
        ];
    }

    /** The flags already show the block, so a failed cause lookup must not hide the notice. */
    #[DataProvider('causeLookupMethodProvider')]
    public function testFailedCauseLookupKeepsTheNoticeWithTheProfileCause(string $failingMethod): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];
        $repo = $this->createStub(CarRepository::class);
        $repo->method('effectiveEmailFlags')->willReturn(
            self::flags(['blocked' => true, 'blockedAddress' => 'b@example.com', 'blockedCause' => 'unsubscribed'])
        );
        $failure = new CarDatabaseException('boom for b@example.com');
        $clearAt = $repo->method('latestBlockClearAt');
        $failingMethod === 'latestBlockClearAt' ? $clearAt->willThrowException($failure) : $clearAt->willReturn(null);
        $events = $repo->method('ownerBlockEventCauses');
        $failingMethod === 'ownerBlockEventCauses' ? $events->willThrowException($failure) : $events->willReturn(['spam']);

        $result = (new EmailNoticeBuilder($repo))->buildForOwner(7);

        $this->assertNotNull($result);
        $this->assertSame(EmailNoticeBuilder::KIND_BLOCKED, $result['kind']);
        $this->assertSame(['unsubscribed'], $result['causes']);
        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_DATABASE_ERROR, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('owner 7', $mockLogEntries[0]['message']);
        $this->assertStringNotContainsString('b@example.com', $mockLogEntries[0]['message'], 'No exception message in the log (#2420)');
    }

    /** The partial drops a cause with no text, so each cause must have one. */
    public function testEveryBlockedCauseHasANoticeText(): void
    {
        foreach (CarRepository::BLOCKED_CAUSES as $cause) {
            $this->assertArrayHasKey($cause, EmailNoticeBuilder::CAUSE_TEXT);
            $this->assertNotSame('', trim(EmailNoticeBuilder::CAUSE_TEXT[$cause]), "{$cause} needs a notice text");
        }
        $this->assertSame([], array_diff(array_keys(EmailNoticeBuilder::CAUSE_TEXT), CarRepository::BLOCKED_CAUSES));
    }

    public function testRemovedConstantsAndPerCarShapeAreGone(): void
    {
        $constants = (new ReflectionClass(EmailNoticeBuilder::class))->getConstants();

        foreach (['CAUSE_BREVO_COMPLAINT', 'CAUSE_OWNER_OPTOUT', 'MAX_ADDRESSES'] as $removed) {
            $this->assertArrayNotHasKey($removed, $constants, "{$removed} must stay removed");
        }
        $this->assertSame(
            ['KIND_BLOCKED' => 'blocked', 'KIND_BOUNCED' => 'bounced', 'KIND_OPTED_OUT' => 'opted_out'],
            array_filter($constants, 'is_string')
        );
    }

    public function testResultHasNoPerCarKeys(): void
    {
        $flags  = self::flags(['blocked' => true, 'blockedAddress' => 'b@example.com']);
        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        foreach (['addresses', 'hasSuppressed', 'hasBounced', 'overflowCount'] as $removed) {
            $this->assertArrayNotHasKey($removed, $result);
        }
    }
}
