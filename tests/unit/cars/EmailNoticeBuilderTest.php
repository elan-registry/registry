<?php

declare(strict_types=1);

namespace Tests\Unit\Cars;

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EffectiveEmailFlags;
use ElanRegistry\Car\EmailNoticeBuilder;
use ElanRegistry\Exceptions\CarDatabaseException;
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
 * - A known block cause is passed on. An unknown cause becomes null (#2318).
 * - A flag with a NULL or empty address names the current `users.email`.
 * - A NULL or malformed `_at` gives a NULL date.
 * - The builder calls effectiveEmailFlags() and no other repository method.
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

    private function builderFor(EffectiveEmailFlags $flags): EmailNoticeBuilder
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('effectiveEmailFlags')->willReturn($flags);

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
            ['kind', 'address', 'date', 'cause', 'alsoOptedOut', 'optedOutDate', 'contentHash'],
            array_keys($result)
        );
        $this->assertSame('blocked', $result['kind']);
        $this->assertSame('blocked@example.com', $result['address']);
        $this->assertSame('2026-08-03', $result['date']);
        $this->assertSame('spam', $result['cause']);
        $this->assertFalse($result['alsoOptedOut']);
        $this->assertNull($result['optedOutDate']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['contentHash']);
    }

    public function testBouncedStateHasNoCauseAndTheBounceDate(): void
    {
        $flags = self::flags([
            'bounced' => true, 'bouncedAddress' => 'bounced@example.com', 'bouncedAt' => '2026-09-15 08:00:00',
        ]);

        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame('bounced', $result['kind']);
        $this->assertSame('bounced@example.com', $result['address']);
        $this->assertSame('2026-09-15', $result['date']);
        $this->assertNull($result['cause']);
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
        $this->assertNull($result['cause']);
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
     * @return array<string, array{?string, ?string}>
     */
    public static function causeProvider(): array
    {
        return [
            'spam'                 => ['spam', 'spam'],
            'unsubscribed'         => ['unsubscribed', 'unsubscribed'],
            'blocklist'            => ['blocklist', 'blocklist'],
            'NULL cause'           => [null, null],
            'empty cause'          => ['', null],
            'unknown cause'        => ['hard_bounce', null],
            'retired brevo cause'  => ['brevo_complaint', null],
            'wrong case'           => ['SPAM', null],
            'padded'               => [' spam', null],
        ];
    }

    #[DataProvider('causeProvider')]
    public function testCauseIsPassedOnOnlyWhenKnown(?string $stored, ?string $expected): void
    {
        $flags = self::flags(['blocked' => true, 'blockedAddress' => 'b@example.com', 'blockedCause' => $stored]);

        $result = $this->builderFor($flags)->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame($expected, $result['cause']);
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

    public function testNoAddressAnywhereGivesNullAddress(): void
    {
        $flags = self::flags(['blocked' => true, 'blockedAddress' => null, 'currentEmail' => null]);

        $result = $this->builderFor($flags)->buildForOwner(1);

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

    /** Every other public repository method throws, so a stray call fails the test. */
    public function testBuilderCallsOnlyEffectiveEmailFlags(): void
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('effectiveEmailFlags')->willReturn(self::flags([
            'blocked' => true, 'blockedAddress' => 'b@example.com', 'optedOut' => true,
        ]));

        $guarded = 0;
        foreach ((new ReflectionClass(CarRepository::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || $method->isFinal()
                || $method->getName() === 'effectiveEmailFlags'
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
