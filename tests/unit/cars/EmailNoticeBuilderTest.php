<?php

declare(strict_types=1);

namespace Tests\Unit\Cars;

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EmailNoticeBuilder;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for EmailNoticeBuilder::buildForOwner(), mocking CarRepository.
 *
 * The owner's profile is the system of record (#2101). The builder reads one
 * repository method, findVerificationStateByOwner(), whose rows carry the
 * profile columns next to each car. It no longer reads suppression events or
 * cars_hist rows. The rules these tests pin:
 * - `email_blocked` names `email_blocked_address`, cause brevo_complaint
 *   (whatever `email_blocked_cause` holds), dated `email_blocked_at`.
 * - `email_bounced` names `email_bounced_address`, dated `email_bounced_at`.
 * - `email_opted_out` names each distinct car `email` (compared without
 *   case), cause owner_optout, dated `email_opted_out_at`.
 * - On one address, a block wins over an opt-out, because Resume does not
 *   clear a block.
 * - A flag with no usable address is skipped and logged. A NULL `_at` gives a
 *   NULL date.
 */
#[Group('fast')]
final class EmailNoticeBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];
    }

    /**
     * @param array<object> $rows
     */
    private function repoReturning(array $rows): CarRepository
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('findVerificationStateByOwner')->willReturn($rows);

        return $repo;
    }

    /**
     * One row of findVerificationStateByOwner(): a car's own columns plus the
     * owner's profile columns. Every flag is clear unless $profile sets it.
     *
     * @param array<string, mixed> $profile Profile column overrides
     */
    private static function row(int $carId, ?string $carEmail, array $profile = []): object
    {
        return (object) array_merge([
            'id' => $carId,
            'email' => $carEmail,
            'has_profile' => 1,
            'email_opted_out' => 0,
            'email_opted_out_at' => null,
            'email_blocked' => 0,
            'email_blocked_address' => null,
            'email_blocked_at' => null,
            'email_blocked_cause' => null,
            'email_bounced' => 0,
            'email_bounced_address' => null,
            'email_bounced_at' => null,
        ], $profile);
    }

    // --- Nothing to show -----------------------------------------------

    public function testReturnsNullWhenOwnerHasNoFlag(): void
    {
        $builder = new EmailNoticeBuilder($this->repoReturning([self::row(1, 'clean@example.com')]));

        $this->assertNull($builder->buildForOwner(1));
    }

    public function testReturnsNullWhenOwnerHasNoCarsAtAll(): void
    {
        $builder = new EmailNoticeBuilder($this->repoReturning([]));

        $this->assertNull($builder->buildForOwner(1));
    }

    /** An owner with no profiles row has NULL flags (LEFT JOIN), which is not a flag. */
    public function testReturnsNullWhenOwnerHasNoProfileRow(): void
    {
        $row = self::row(1, 'owner@example.com', [
            'has_profile' => 0,
            'email_opted_out' => null,
            'email_blocked' => null,
            'email_bounced' => null,
        ]);
        $builder = new EmailNoticeBuilder($this->repoReturning([$row]));

        $this->assertNull($builder->buildForOwner(1));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notAFlagValueProvider(): array
    {
        return [
            'int zero' => [0],
            'string zero' => ['0'],
            'NULL' => [null],
            'wrong-typed string' => ['abc'],
            'bool true' => [true],
            'float one' => [1.0],
            'array' => [[1]],
        ];
    }

    #[DataProvider('notAFlagValueProvider')]
    public function testOnlyAnIntOrDigitStringCountsAsASetFlag(mixed $value): void
    {
        $row = self::row(1, 'owner@example.com', [
            'email_opted_out' => $value,
            'email_blocked' => $value,
            'email_blocked_address' => 'owner@example.com',
            'email_bounced' => $value,
            'email_bounced_address' => 'owner@example.com',
        ]);
        $builder = new EmailNoticeBuilder($this->repoReturning([$row]));

        $this->assertNull($builder->buildForOwner(1));
    }

    public function testStringTypedFlagColumnsAreHandledLikeIntTypedOnes(): void
    {
        $row = self::row(1, 'owner@example.com', [
            'email_blocked' => '1',
            'email_blocked_address' => 'owner@example.com',
            'email_bounced' => '1',
            'email_bounced_address' => 'owner@example.com',
        ]);
        $builder = new EmailNoticeBuilder($this->repoReturning([$row]));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertTrue($result['hasSuppressed']);
        $this->assertTrue($result['hasBounced']);
    }

    // --- Opt-out -------------------------------------------------------

    public function testOptOutNamesEachDistinctCarEmailWithTheOptOutDate(): void
    {
        $profile = ['email_opted_out' => 1, 'email_opted_out_at' => '2026-08-01 09:30:00'];
        $builder = new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'work@example.com', $profile),
            self::row(2, 'home@example.com', $profile),
        ]));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame(
            [
                ['address' => 'home@example.com', 'suppressed' => ['cause' => 'owner_optout', 'date' => '2026-08-01'], 'bounced' => null],
                ['address' => 'work@example.com', 'suppressed' => ['cause' => 'owner_optout', 'date' => '2026-08-01'], 'bounced' => null],
            ],
            $result['addresses']
        );
        $this->assertTrue($result['hasSuppressed']);
        $this->assertFalse($result['hasBounced']);
        $this->assertSame(0, $result['overflowCount']);
    }

    public function testOptOutDedupesCarEmailsWithoutRegardToCase(): void
    {
        $profile = ['email_opted_out' => 1, 'email_opted_out_at' => '2026-08-01 09:30:00'];
        $builder = new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'Owner@Example.com', $profile),
            self::row(2, 'owner@example.com', $profile),
        ]));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['addresses']);
    }

    public function testOptOutWithNullTimeGivesANullDateNotAnInventedOne(): void
    {
        $builder = new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'owner@example.com', ['email_opted_out' => 1, 'email_opted_out_at' => null]),
        ]));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertNull($result['addresses'][0]['suppressed']['date']);
    }

    /** A sold car still carries mail for its owner, so it is not filtered out here. */
    public function testSoldCarStillNamesItsAddress(): void
    {
        $row = self::row(1, 'owner@example.com', ['email_opted_out' => 1]);
        $row->solddate = '2020-01-01';
        $builder = new EmailNoticeBuilder($this->repoReturning([$row]));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame('owner@example.com', $result['addresses'][0]['address']);
    }

    public function testOptOutWithNoCarEmailReturnsNullAndLogsWithoutAnAddress(): void
    {
        $builder = new EmailNoticeBuilder($this->repoReturning([
            self::row(1, null, ['email_opted_out' => 1]),
            self::row(2, '   ', ['email_opted_out' => 1]),
        ]));

        $this->assertNull($builder->buildForOwner(9));

        global $mockLogEntries;
        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_ERROR, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('owner 9 is opted out but has no usable email address', $mockLogEntries[0]['message']);
    }

    // --- Block ---------------------------------------------------------

    /**
     * @return array<string, array{?string}>
     */
    public static function blockedCauseProvider(): array
    {
        return [
            'spam' => ['spam'],
            'unsubscribed' => ['unsubscribed'],
            'blocklist' => ['blocklist'],
            'unknown cause' => [null],
        ];
    }

    /**
     * Every block shows as a Brevo complaint until #2356 and #2361 separate
     * the states, whatever cause the profile stores.
     */
    #[DataProvider('blockedCauseProvider')]
    public function testBlockedProfileNamesTheBlockedAddressAsABrevoComplaint(?string $cause): void
    {
        $builder = new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'car@example.com', [
                'email_blocked' => 1,
                'email_blocked_address' => 'blocked@example.com',
                'email_blocked_at' => '2026-07-15 08:00:00',
                'email_blocked_cause' => $cause,
            ]),
        ]));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame(
            [['address' => 'blocked@example.com', 'suppressed' => ['cause' => 'brevo_complaint', 'date' => '2026-07-15'], 'bounced' => null]],
            $result['addresses'],
            'The car email is not named. The block names its own address'
        );
        $this->assertTrue($result['hasSuppressed']);
        $this->assertFalse($result['hasBounced']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableAddressProvider(): array
    {
        return [
            'NULL' => [null],
            'empty string' => [''],
            'spaces' => ['   '],
            'wrong type' => [['x@example.com']],
        ];
    }

    #[DataProvider('unusableAddressProvider')]
    public function testBlockWithNoUsableAddressIsSkippedAndLoggedWithoutAnAddress(mixed $address): void
    {
        $builder = new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'car@example.com', ['email_blocked' => 1, 'email_blocked_address' => $address]),
        ]));

        $this->assertNull($builder->buildForOwner(7));

        global $mockLogEntries;
        $this->assertCount(1, $mockLogEntries);
        $this->assertStringContainsString('owner 7 is blocked but has no usable email address', $mockLogEntries[0]['message']);
        $this->assertStringNotContainsString('@', $mockLogEntries[0]['message']);
    }

    // --- Bounce --------------------------------------------------------

    public function testBouncedProfileNamesTheBouncedAddressWithItsDate(): void
    {
        $builder = new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'car@example.com', [
                'email_bounced' => 1,
                'email_bounced_address' => 'bounced@example.com',
                'email_bounced_at' => '2026-06-30 23:59:59',
            ]),
        ]));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame(
            [['address' => 'bounced@example.com', 'suppressed' => null, 'bounced' => ['date' => '2026-06-30']]],
            $result['addresses']
        );
        $this->assertFalse($result['hasSuppressed']);
        $this->assertTrue($result['hasBounced']);
    }

    public function testBouncedProfileWithNullTimeGivesANullDate(): void
    {
        $builder = new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'car@example.com', [
                'email_bounced' => 1,
                'email_bounced_address' => 'bounced@example.com',
                'email_bounced_at' => null,
            ]),
        ]));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame(['date' => null], $result['addresses'][0]['bounced']);
    }

    #[DataProvider('unusableAddressProvider')]
    public function testBounceWithNoUsableAddressIsSkippedAndLogged(mixed $address): void
    {
        $builder = new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'car@example.com', ['email_bounced' => 1, 'email_bounced_address' => $address]),
        ]));

        $this->assertNull($builder->buildForOwner(7));

        global $mockLogEntries;
        $this->assertCount(1, $mockLogEntries);
        $this->assertStringContainsString('owner 7 is bounced but has no usable email address', $mockLogEntries[0]['message']);
    }

    /** A malformed or zero date in the profile gives no date. It does not crash. */
    public function testMalformedDateColumnGivesANullDate(): void
    {
        $builder = new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'car@example.com', [
                'email_bounced' => 1,
                'email_bounced_address' => 'bounced@example.com',
                'email_bounced_at' => '0000-00-00 00:00:00',
            ]),
        ]));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertNull($result['addresses'][0]['bounced']['date']);
    }

    // --- Several flags -------------------------------------------------

    public function testBlockAndBounceOnDifferentAddressesProduceTwoEntriesSortedByAddress(): void
    {
        $builder = new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'car@example.com', [
                'email_blocked' => 1,
                'email_blocked_address' => 'zeta@example.com',
                'email_blocked_at' => '2026-07-01 00:00:00',
                'email_bounced' => 1,
                'email_bounced_address' => 'alpha@example.com',
                'email_bounced_at' => '2026-06-01 00:00:00',
            ]),
        ]));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame(['alpha@example.com', 'zeta@example.com'], array_column($result['addresses'], 'address'));
        $this->assertNull($result['addresses'][0]['suppressed']);
        $this->assertNotNull($result['addresses'][0]['bounced']);
        $this->assertNotNull($result['addresses'][1]['suppressed']);
        $this->assertNull($result['addresses'][1]['bounced']);
    }

    public function testBlockAndBounceOnTheSameAddressMergeIntoOneEntryWithoutRegardToCase(): void
    {
        $builder = new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'car@example.com', [
                'email_blocked' => 1,
                'email_blocked_address' => 'Owner@Example.com',
                'email_blocked_at' => '2026-07-01 00:00:00',
                'email_bounced' => 1,
                'email_bounced_address' => 'owner@example.com',
                'email_bounced_at' => '2026-06-01 00:00:00',
            ]),
        ]));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['addresses']);
        $this->assertSame('brevo_complaint', $result['addresses'][0]['suppressed']['cause']);
        $this->assertSame(['date' => '2026-06-01'], $result['addresses'][0]['bounced']);
    }

    /** Resume clears only the opt-out, so the notice must keep showing the block. */
    public function testBlockWinsOverOptOutOnTheSameAddress(): void
    {
        $builder = new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'owner@example.com', [
                'email_opted_out' => 1,
                'email_opted_out_at' => '2026-08-01 00:00:00',
                'email_blocked' => 1,
                'email_blocked_address' => 'owner@example.com',
                'email_blocked_at' => '2026-07-01 00:00:00',
            ]),
        ]));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['addresses']);
        $this->assertSame(
            ['cause' => 'brevo_complaint', 'date' => '2026-07-01'],
            $result['addresses'][0]['suppressed']
        );
    }

    /** The opt-out names the car address. The block names its own address. They stay apart. */
    public function testOptOutOnACarAddressAndBlockOnADifferentAddressStayIndependent(): void
    {
        $builder = new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'car@example.com', [
                'email_opted_out' => 1,
                'email_opted_out_at' => '2026-08-01 00:00:00',
                'email_blocked' => 1,
                'email_blocked_address' => 'old@example.com',
                'email_blocked_at' => '2026-07-01 00:00:00',
            ]),
        ]));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame(
            ['car@example.com' => 'owner_optout', 'old@example.com' => 'brevo_complaint'],
            array_column(array_map(
                static fn (array $e): array => ['address' => $e['address'], 'cause' => $e['suppressed']['cause']],
                $result['addresses']
            ), 'cause', 'address')
        );
    }

    // --- Cap and overflow ----------------------------------------------

    public function testMoreThanThreeAddressesAreCappedWithCorrectOverflowCount(): void
    {
        $profile = ['email_opted_out' => 1];
        $rows = [];
        foreach (['e', 'c', 'a', 'd', 'b'] as $i => $letter) {
            $rows[] = self::row($i + 1, "{$letter}@example.com", $profile);
        }
        $builder = new EmailNoticeBuilder($this->repoReturning($rows));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame(
            ['a@example.com', 'b@example.com', 'c@example.com'],
            array_column($result['addresses'], 'address')
        );
        $this->assertSame(2, $result['overflowCount']);
    }

    public function testOverflowCountCountsDistinctAddressesNotCars(): void
    {
        $profile = ['email_opted_out' => 1];
        $rows = [];
        // Eight cars, four distinct addresses.
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $n) {
            $rows[] = self::row($n, 'owner' . (($n % 4) + 1) . '@example.com', $profile);
        }
        $builder = new EmailNoticeBuilder($this->repoReturning($rows));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertCount(3, $result['addresses']);
        $this->assertSame(1, $result['overflowCount']);
    }

    /** `hasBounced` covers the full set, not only the shown addresses. */
    public function testHasBouncedIsTrueEvenWhenTheOnlyBouncedAddressOverflows(): void
    {
        $profile = [
            'email_opted_out' => 1,
            'email_bounced' => 1,
            'email_bounced_address' => 'zzz@example.com',
        ];
        $builder = new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'a@example.com', $profile),
            self::row(2, 'b@example.com', $profile),
            self::row(3, 'c@example.com', $profile),
        ]));

        $result = $builder->buildForOwner(1);

        $this->assertNotNull($result);
        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], array_column($result['addresses'], 'address'));
        $this->assertSame(1, $result['overflowCount']);
        $this->assertTrue($result['hasBounced']);
    }

    // --- contentHash ---------------------------------------------------

    public function testContentHashChangesWhenANewFlagIsAddedToAnAlreadyFlaggedAddress(): void
    {
        $blocked = ['email_blocked' => 1, 'email_blocked_address' => 'owner@example.com', 'email_blocked_at' => '2026-07-01 00:00:00'];
        $builderA = new EmailNoticeBuilder($this->repoReturning([self::row(1, 'owner@example.com', $blocked)]));
        $builderB = new EmailNoticeBuilder($this->repoReturning([self::row(1, 'owner@example.com', $blocked + [
            'email_bounced' => 1,
            'email_bounced_address' => 'owner@example.com',
        ])]));

        $resultA = $builderA->buildForOwner(1);
        $resultB = $builderB->buildForOwner(1);

        $this->assertNotNull($resultA);
        $this->assertNotNull($resultB);
        $this->assertNotSame($resultA['contentHash'], $resultB['contentHash']);
    }

    public function testContentHashChangesWhenTheDateChanges(): void
    {
        $make = fn (string $at): ?array => (new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'owner@example.com', [
                'email_bounced' => 1,
                'email_bounced_address' => 'owner@example.com',
                'email_bounced_at' => $at,
            ]),
        ])))->buildForOwner(1);

        $first = $make('2026-06-01 00:00:00');
        $second = $make('2026-06-02 00:00:00');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first['contentHash'], $second['contentHash'], 'A dismissed notice must show again when the date changes');
    }

    public function testContentHashChangesWhenAddressSetChanges(): void
    {
        $profile = ['email_opted_out' => 1];
        $one = (new EmailNoticeBuilder($this->repoReturning([self::row(1, 'a@example.com', $profile)])))->buildForOwner(1);
        $two = (new EmailNoticeBuilder($this->repoReturning([
            self::row(1, 'a@example.com', $profile),
            self::row(2, 'b@example.com', $profile),
        ])))->buildForOwner(1);

        $this->assertNotNull($one);
        $this->assertNotNull($two);
        $this->assertNotSame($one['contentHash'], $two['contentHash']);
    }

    public function testContentHashIsStableForIdenticalInput(): void
    {
        $rows = [self::row(1, 'a@example.com', ['email_opted_out' => 1, 'email_opted_out_at' => '2026-08-01 00:00:00'])];

        $first = (new EmailNoticeBuilder($this->repoReturning($rows)))->buildForOwner(1);
        $second = (new EmailNoticeBuilder($this->repoReturning($rows)))->buildForOwner(1);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first['contentHash'], $second['contentHash']);
    }

    // --- Removed behavior ----------------------------------------------

    /**
     * The notice reads the profile only. It must not read suppression events
     * or history rows (#2101).
     */
    public function testBuilderReadsNoEventTableAndNoHistory(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->method('findVerificationStateByOwner')->willReturn([
            self::row(1, 'owner@example.com', ['email_opted_out' => 1, 'email_blocked' => 1, 'email_blocked_address' => 'o@example.com']),
        ]);
        $repo->expects($this->never())->method('findLatestEmailEventsByCarIds');
        $repo->expects($this->never())->method('getHistory');
        $repo->expects($this->once())->method('findVerificationStateByOwner')->with(5);

        (new EmailNoticeBuilder($repo))->buildForOwner(5);
    }

    public function testHistoryOperationConstantsAndCauseResolverAreGone(): void
    {
        $class = new ReflectionClass(EmailNoticeBuilder::class);

        $this->assertFalse($class->hasMethod('resolveSuppressionCause'));
        $this->assertSame(
            [],
            array_filter(array_keys($class->getConstants()), static fn (string $name): bool => str_starts_with($name, 'OPERATION_')),
            'The cars_hist operation names are no longer written, so no constant names them'
        );
    }

    // --- CarDatabaseException propagation -----------------------------------

    public function testCarDatabaseExceptionFromFindVerificationStateByOwnerPropagates(): void
    {
        $repo = $this->createStub(CarRepository::class);
        $repo->method('findVerificationStateByOwner')->willThrowException(new CarDatabaseException('boom'));
        $builder = new EmailNoticeBuilder($repo);

        $this->expectException(CarDatabaseException::class);
        $builder->buildForOwner(1);
    }
}
