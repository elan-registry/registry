<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EffectiveEmailFlags;
use ElanRegistry\Car\MailKind;
use ElanRegistry\Car\SendDecision;
use ElanRegistry\Car\SendPolicy;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SendPolicy, SendDecision and MailKind (#2360).
 *
 * CarRepository is the only collaborator, and it is a read boundary: the
 * test feeds it flag states and checks the decision that comes back.
 * Expected values are literals, not values computed by the policy.
 */
#[Group('fast')]
#[AllowMockObjectsWithoutExpectations]
final class SendPolicyTest extends TestCase
{
    private const BOUNCED_AT = '2026-03-04 05:06:07';

    /** @var CarRepository&\PHPUnit\Framework\MockObject\MockObject */
    private CarRepository $repo;

    private SendPolicy $policy;

    protected function setUp(): void
    {
        $this->repo   = $this->createMock(CarRepository::class);
        $this->policy = new SendPolicy($this->repo);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * @param list<string> $flags Any of no_profile, email_bounced, email_opted_out, email_blocked
     */
    private function flags(array $flags, ?string $bouncedAt = self::BOUNCED_AT): EffectiveEmailFlags
    {
        if (in_array('no_profile', $flags, true)) {
            return EffectiveEmailFlags::none();
        }

        return new EffectiveEmailFlags(
            true,
            in_array('email_opted_out', $flags, true),
            null,
            in_array('email_blocked', $flags, true),
            null,
            null,
            null,
            in_array('email_bounced', $flags, true),
            null,
            $bouncedAt,
            'owner@example.com'
        );
    }

    /**
     * A decision with the match count that agrees with its flags: 0 with
     * no_match, 1 without it
     *
     * @param list<string> $flags
     */
    private static function decision(array $flags, ?string $bouncedAt = null): SendDecision
    {
        return new SendDecision($flags, $bouncedAt, in_array('no_match', $flags, true) ? 0 : 1);
    }

    /** @param array<int, EffectiveEmailFlags> $byUser */
    private function stubFlags(array $byUser): void
    {
        $this->repo->method('effectiveEmailFlags')->willReturnCallback(
            fn (int $userId): EffectiveEmailFlags => $byUser[$userId]
        );
    }

    // ------------------------------------------------------------------
    // blockingFlags(): the table
    // ------------------------------------------------------------------

    /** @return array<string, array{0: MailKind, 1: list<string>}> */
    public static function tableProvider(): array
    {
        $all = ['no_profile', 'email_bounced', 'email_opted_out', 'email_blocked'];

        return [
            'verification' => [MailKind::Verification, $all],
            'owner contact' => [MailKind::OwnerContact, $all],
            // An admin block stops registry mail, not a direct admin message.
            'admin contact' => [MailKind::AdminContact, ['no_profile', 'email_bounced', 'email_opted_out']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('tableProvider')]
    public function test_blockingFlags_eachKind_returnsTheTableRow(MailKind $kind, array $expected): void
    {
        $this->assertSame($expected, $this->policy->blockingFlags($kind));
    }

    public function test_mailKind_hasExactlyThreeKinds(): void
    {
        $this->assertSame(
            ['verification', 'owner_contact', 'admin_contact'],
            array_map(static fn (MailKind $k): string => $k->value, MailKind::cases())
        );
    }

    // ------------------------------------------------------------------
    // forUser(): every kind x every flag
    // ------------------------------------------------------------------

    /** @return array<string, array{0: MailKind, 1: string, 2: bool}> kind, flag, blocks */
    public static function kindFlagProvider(): array
    {
        $cases = [];
        foreach (MailKind::cases() as $kind) {
            foreach (['no_profile', 'email_bounced', 'email_opted_out', 'email_blocked'] as $flag) {
                // The only cell of the table that sends: a block, for an admin message.
                $blocks = !($kind === MailKind::AdminContact && $flag === 'email_blocked');
                $cases["{$kind->value} / {$flag}"] = [$kind, $flag, $blocks];
            }
        }

        return $cases;
    }

    #[DataProvider('kindFlagProvider')]
    public function test_forUser_singleFlag_blocksAccordingToTheTable(MailKind $kind, string $flag, bool $blocks): void
    {
        $this->stubFlags([7 => $this->flags([$flag])]);

        $decision = $this->policy->forUser($kind, 7);

        $this->assertSame(!$blocks, $decision->allowed);
        $this->assertSame($blocks ? [$flag] : [], $decision->flags);
    }

    /** @return array<string, array{0: MailKind}> */
    public static function kindProvider(): array
    {
        return array_combine(
            array_map(static fn (MailKind $k): string => $k->value, MailKind::cases()),
            array_map(static fn (MailKind $k): array => [$k], MailKind::cases())
        );
    }

    #[DataProvider('kindProvider')]
    public function test_forUser_cleanOwner_isAllowedForEveryKind(MailKind $kind): void
    {
        $this->stubFlags([7 => $this->flags([])]);

        $decision = $this->policy->forUser($kind, 7);

        $this->assertTrue($decision->allowed);
        $this->assertSame([], $decision->flags);
        $this->assertNull($decision->bouncedAt);
    }

    public function test_forUser_blockedOnlyOwner_isAllowedForAdminContactAndRefusedForTheRest(): void
    {
        $this->stubFlags([7 => $this->flags(['email_blocked'])]);

        $this->assertTrue($this->policy->forUser(MailKind::AdminContact, 7)->allowed);
        $this->assertFalse($this->policy->forUser(MailKind::OwnerContact, 7)->allowed);
        $this->assertFalse($this->policy->forUser(MailKind::Verification, 7)->allowed);
    }

    public function test_forUser_blockedAndOptedOut_adminContactKeepsOnlyTheOptOut(): void
    {
        $this->stubFlags([7 => $this->flags(['email_blocked', 'email_opted_out'])]);

        $this->assertSame(['email_opted_out'], $this->policy->forUser(MailKind::AdminContact, 7)->flags);
    }

    public function test_forUser_allFlags_listsThemInTheFixedOrder(): void
    {
        $this->stubFlags([7 => $this->flags(['email_blocked', 'email_opted_out', 'email_bounced'])]);

        $this->assertSame(
            ['email_bounced', 'email_opted_out', 'email_blocked'],
            $this->policy->forUser(MailKind::OwnerContact, 7)->flags
        );
    }

    public function test_forUser_noProfile_neverCarriesABounceDate(): void
    {
        $this->stubFlags([7 => $this->flags(['no_profile'])]);

        $decision = $this->policy->forUser(MailKind::Verification, 7);

        $this->assertSame(['no_profile'], $decision->flags);
        $this->assertNull($decision->bouncedAt);
    }

    public function test_forUser_bounced_carriesTheBounceDate(): void
    {
        $this->stubFlags([7 => $this->flags(['email_bounced'])]);

        $this->assertSame(self::BOUNCED_AT, $this->policy->forUser(MailKind::AdminContact, 7)->bouncedAt);
    }

    public function test_forUser_passesTheUserIdToTheRepository(): void
    {
        $this->repo->expects($this->once())
            ->method('effectiveEmailFlags')
            ->with(42)
            ->willReturn($this->flags([]));

        $this->policy->forUser(MailKind::OwnerContact, 42);
    }

    // ------------------------------------------------------------------
    // forAddress(): the "Multiple" path
    // ------------------------------------------------------------------

    #[DataProvider('kindProvider')]
    public function test_forAddress_zeroMatches_isRefusedWithNoMatchForEveryKind(MailKind $kind): void
    {
        $this->repo->method('findUserIdsByAddress')->with('nobody@example.com')->willReturn([]);
        $this->repo->expects($this->never())->method('effectiveEmailFlags');

        $decision = $this->policy->forAddress($kind, 'nobody@example.com');

        $this->assertFalse($decision->allowed);
        $this->assertSame(['no_match'], $decision->flags);
        $this->assertNull($decision->bouncedAt);
        $this->assertSame(0, $decision->matchCount);
    }

    public function test_forAddress_mergesTheFlagsOfAllMatchesWithoutDuplicates(): void
    {
        $this->repo->method('findUserIdsByAddress')->willReturn([1, 2, 3]);
        $this->stubFlags([
            1 => $this->flags(['email_opted_out']),
            2 => $this->flags(['no_profile']),
            3 => $this->flags(['email_opted_out', 'email_bounced']),
        ]);

        $decision = $this->policy->forAddress(MailKind::AdminContact, 'shared@example.com');

        $this->assertFalse($decision->allowed);
        $this->assertSame(['no_profile', 'email_bounced', 'email_opted_out'], $decision->flags);
        $this->assertSame(3, $decision->matchCount, 'The admin log line names the count of matching users.');
    }

    public function test_forAddress_oneBlockingMatchRefusesEvenWhenOthersAreClean(): void
    {
        $this->repo->method('findUserIdsByAddress')->willReturn([1, 2]);
        $this->stubFlags([
            1 => $this->flags([]),
            2 => $this->flags(['email_opted_out']),
        ]);

        $decision = $this->policy->forAddress(MailKind::AdminContact, 'shared@example.com');

        $this->assertFalse($decision->allowed);
        $this->assertSame(['email_opted_out'], $decision->flags);
    }

    public function test_forAddress_allCleanMatches_isAllowed(): void
    {
        $this->repo->method('findUserIdsByAddress')->willReturn([1, 2]);
        $this->stubFlags([1 => $this->flags([]), 2 => $this->flags([])]);

        $this->assertTrue($this->policy->forAddress(MailKind::AdminContact, 'shared@example.com')->allowed);
    }

    public function test_forAddress_blockedMatchIsAllowedForAdminContactButNotOwnerContact(): void
    {
        $this->repo->method('findUserIdsByAddress')->willReturn([1, 2]);
        $this->stubFlags([1 => $this->flags([]), 2 => $this->flags(['email_blocked'])]);

        $this->assertTrue($this->policy->forAddress(MailKind::AdminContact, 'shared@example.com')->allowed);
        $this->assertSame(
            ['email_blocked'],
            $this->policy->forAddress(MailKind::OwnerContact, 'shared@example.com')->flags
        );
    }

    /** @return array<string, array{0: list<?string>, 1: ?string}> bounce dates by match order, expected latest */
    public static function latestBounceProvider(): array
    {
        return [
            'latest is last'   => [['2026-01-01 00:00:00', '2026-05-06 07:08:09'], '2026-05-06 07:08:09'],
            'latest is first'  => [['2026-05-06 07:08:09', '2026-01-01 00:00:00'], '2026-05-06 07:08:09'],
            'latest is middle' => [['2026-01-01 00:00:00', '2026-09-09 09:09:09', '2026-02-02 00:00:00'], '2026-09-09 09:09:09'],
            'one has no date'  => [[null, '2026-01-01 00:00:00'], '2026-01-01 00:00:00'],
            'no date at all'   => [[null, null], null],
        ];
    }

    /** @param list<?string> $dates */
    #[DataProvider('latestBounceProvider')]
    public function test_forAddress_manyBouncedMatches_keepsTheLatestBounceDate(array $dates, ?string $expected): void
    {
        $ids = [];
        $byUser = [];
        foreach ($dates as $i => $date) {
            $ids[] = $i + 1;
            $byUser[$i + 1] = $this->flags(['email_bounced'], $date);
        }
        $this->repo->method('findUserIdsByAddress')->willReturn($ids);
        $this->stubFlags($byUser);

        $decision = $this->policy->forAddress(MailKind::AdminContact, 'shared@example.com');

        $this->assertSame(['email_bounced'], $decision->flags);
        $this->assertSame($expected, $decision->bouncedAt);
    }

    public function test_forAddress_bounceDateOfAMatchThatIsNotBouncedIsIgnored(): void
    {
        $this->repo->method('findUserIdsByAddress')->willReturn([1, 2]);
        $this->stubFlags([
            1 => $this->flags(['email_bounced'], '2026-01-01 00:00:00'),
            // Not bounced, so a stray date must not win. The reader nulls it, and so must the merge.
            2 => $this->flags(['email_opted_out'], '2030-01-01 00:00:00'),
        ]);

        $this->assertSame(
            '2026-01-01 00:00:00',
            $this->policy->forAddress(MailKind::AdminContact, 'shared@example.com')->bouncedAt
        );
    }

    public function test_forAddress_passesTheAddressToTheRepository(): void
    {
        $this->repo->expects($this->once())
            ->method('findUserIdsByAddress')
            ->with('who@example.com')
            ->willReturn([]);

        $this->policy->forAddress(MailKind::AdminContact, 'who@example.com');
    }

    // ------------------------------------------------------------------
    // verificationReason()
    // ------------------------------------------------------------------

    /** @return array<string, array{0: list<string>, 1: ?string}> */
    public static function reasonProvider(): array
    {
        return [
            'allowed'    => [[], null],
            'no profile' => [['no_profile'], 'Owner has no profile.'],
            'opted out'  => [['email_opted_out'], 'Owner has opted out of verification emails.'],
            'blocked'    => [['email_blocked'], 'Owner email is blocked.'],
            'bounced'    => [['email_bounced'], 'Owner email bounced.'],
            // Priority: no profile, opted out, blocked, bounced.
            'profile beats all'      => [['email_bounced', 'email_blocked', 'email_opted_out', 'no_profile'], 'Owner has no profile.'],
            'opted out beats blocked' => [['email_bounced', 'email_blocked', 'email_opted_out'], 'Owner has opted out of verification emails.'],
            'blocked beats bounced'  => [['email_bounced', 'email_blocked'], 'Owner email is blocked.'],
            'no match fallback'      => [['no_match'], 'No owner has this address now.'],
        ];
    }

    /** @param list<string> $flags */
    #[DataProvider('reasonProvider')]
    public function test_verificationReason_flags_returnsTheFirstReasonWordForWord(array $flags, ?string $expected): void
    {
        $this->assertSame($expected, $this->policy->verificationReason(self::decision($flags)));
    }

    // ------------------------------------------------------------------
    // adminMessage()
    // ------------------------------------------------------------------

    public function test_adminMessage_allowed_isEmptyString(): void
    {
        $this->assertSame('', $this->policy->adminMessage(new SendDecision([])));
    }

    /** @return array<string, array{0: list<string>, 1: ?string, 2: string}> */
    public static function messageProvider(): array
    {
        return [
            'no match'   => [['no_match'], null, 'Message not sent. No owner has this address now.'],
            'no profile' => [['no_profile'], null, 'Message not sent. This owner has no profile.'],
            'bounced'    => [
                ['email_bounced'],
                '2026-03-04 05:06:07',
                "Message not sent. This owner's address is invalid. It bounced on 2026-03-04.",
            ],
            'bounced, no date' => [['email_bounced'], null, "Message not sent. This owner's address is invalid."],
            'opted out'  => [['email_opted_out'], null, 'Message not sent. The owner opted out.'],
            'bounced and opted out' => [
                ['email_opted_out', 'email_bounced'],
                '2026-03-04 05:06:07',
                "Message not sent. This owner's address is invalid. It bounced on 2026-03-04. The owner opted out.",
            ],
            // The fixed order: no match, no profile, bounced, opted out. Input order does not matter.
            'all four in order' => [
                ['email_opted_out', 'email_bounced', 'no_profile', 'no_match'],
                '2026-03-04 05:06:07',
                "Message not sent. No owner has this address now. This owner has no profile. "
                . "This owner's address is invalid. It bounced on 2026-03-04. The owner opted out.",
            ],
        ];
    }

    /** @param list<string> $flags */
    #[DataProvider('messageProvider')]
    public function test_adminMessage_flags_returnsTheSentencesInFixedOrder(array $flags, ?string $date, string $expected): void
    {
        $this->assertSame($expected, $this->policy->adminMessage(self::decision($flags, $date)));
    }

    public function test_adminMessage_blockedFlagAlone_addsNoSentenceOfItsOwn(): void
    {
        // email_blocked blocks owner contact, which has no admin message. The text stays the bare stem.
        $this->assertSame('Message not sent.', $this->policy->adminMessage(new SendDecision(['email_blocked'])));
    }

    /** @return array<string, array{0: string}> */
    public static function badDateProvider(): array
    {
        return [
            'html'             => ['<script>alert(1)</script>'],
            'html after date'  => ['2026-03-04 05:06:07<b>x</b>'],
            'quote'            => ["2026-03-04 05:06:07' onload='x"],
            'zero date'        => ['0000-00-00 00:00:00'],
            'impossible day'   => ['2026-02-30 00:00:00'],
            'date only'        => ['2026-03-04'],
            'empty'            => [''],
            'text'             => ['yesterday'],
        ];
    }

    #[DataProvider('badDateProvider')]
    public function test_adminMessage_malformedBounceDate_fallsBackToTheNoDateSentence(string $date): void
    {
        $message = $this->policy->adminMessage(new SendDecision(['email_bounced'], $date));

        $this->assertSame("Message not sent. This owner's address is invalid.", $message);
        $this->assertStringNotContainsString('<', $message);
    }

    public function test_adminMessage_validDate_isReformattedToYmd(): void
    {
        $message = $this->policy->adminMessage(new SendDecision(['email_bounced'], '2026-12-31 23:59:59'));

        $this->assertStringContainsString('It bounced on 2026-12-31.', $message);
        $this->assertStringNotContainsString('23:59', $message);
    }

    // ------------------------------------------------------------------
    // SendDecision
    // ------------------------------------------------------------------

    public function test_sendDecision_unknownFlag_throwsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown flag name: email_unsubscribed');

        new SendDecision(['email_bounced', 'email_unsubscribed']);
    }

    /** @return array<string, array{0: array<mixed>}> */
    public static function wrongTypedFlagsProvider(): array
    {
        return [
            'int'   => [[123]],
            'null'  => [[null]],
            'bool'  => [[true]],
            'float' => [[1.5]],
            'array' => [[[1]]],
            'object' => [[new \stdClass()]],
        ];
    }

    /** @param array<mixed> $flags */
    #[DataProvider('wrongTypedFlagsProvider')]
    public function test_sendDecision_wrongTypedFlagElement_throwsInvalidArgumentException(array $flags): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SendDecision($flags);
    }

    public function test_sendDecision_duplicatesAreRemovedAndOrderIsFixed(): void
    {
        $decision = new SendDecision(['email_blocked', 'no_match', 'email_blocked', 'email_bounced'], self::BOUNCED_AT, 0);

        $this->assertSame(['no_match', 'email_bounced', 'email_blocked'], $decision->flags);
        $this->assertFalse($decision->allowed);
    }

    /** @return array<string, array{0: list<string>, 1: int}> */
    public static function badMatchCountProvider(): array
    {
        return [
            'negative count'         => [[], -1],
            'negative with no_match' => [['no_match'], -1],
            'zero without no_match'  => [['email_bounced'], 0],
            'zero and no flags'      => [[], 0],
            'no_match with one user' => [['no_match'], 1],
            'no_match with two users' => [['no_match', 'email_opted_out'], 2],
        ];
    }

    /** @param list<string> $flags */
    #[DataProvider('badMatchCountProvider')]
    public function test_sendDecision_matchCountDisagreesWithNoMatch_throwsInvalidArgumentException(array $flags, int $matchCount): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not agree with the no_match flag');

        new SendDecision($flags, null, $matchCount);
    }

    /** @return array<string, array{0: list<string>, 1: int}> */
    public static function goodMatchCountProvider(): array
    {
        return [
            'no_match with zero'      => [['no_match'], 0],
            'one user, allowed'       => [[], 1],
            'one user, blocked'       => [['email_blocked'], 1],
            'three users, opted out'  => [['email_opted_out'], 3],
        ];
    }

    /** @param list<string> $flags */
    #[DataProvider('goodMatchCountProvider')]
    public function test_sendDecision_matchCountAgreesWithNoMatch_isAccepted(array $flags, int $matchCount): void
    {
        $decision = new SendDecision($flags, null, $matchCount);

        $this->assertSame($matchCount, $decision->matchCount);
        $this->assertSame($flags, $decision->flags);
    }

    public function test_sendDecision_noFlags_isAllowed(): void
    {
        $decision = new SendDecision([]);

        $this->assertTrue($decision->allowed);
        $this->assertSame([], $decision->flags);
    }

    public function test_sendDecision_bounceDateIsKeptOnlyWithTheBouncedFlag(): void
    {
        $this->assertSame(self::BOUNCED_AT, (new SendDecision(['email_bounced'], self::BOUNCED_AT))->bouncedAt);
        $this->assertNull((new SendDecision(['email_opted_out'], self::BOUNCED_AT))->bouncedAt);
        $this->assertNull((new SendDecision([], self::BOUNCED_AT))->bouncedAt);
    }

    public function test_sendDecision_has_reportsOnlyPresentFlags(): void
    {
        $decision = new SendDecision(['email_opted_out']);

        $this->assertTrue($decision->has(SendDecision::FLAG_OPTED_OUT));
        $this->assertFalse($decision->has(SendDecision::FLAG_BLOCKED));
    }

    // ------------------------------------------------------------------
    // Wrong-typed arguments
    // ------------------------------------------------------------------

    public function test_forUser_stringUserId_throwsTypeError(): void
    {
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore argument.type */
        $this->policy->forUser(MailKind::OwnerContact, '7');
    }

    public function test_forUser_stringKind_throwsTypeError(): void
    {
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore argument.type */
        $this->policy->forUser('owner_contact', 7);
    }

    public function test_forAddress_intAddress_throwsTypeError(): void
    {
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore argument.type */
        $this->policy->forAddress(MailKind::AdminContact, 7);
    }

    public function test_forAddress_nullAddress_throwsTypeError(): void
    {
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore argument.type */
        $this->policy->forAddress(MailKind::AdminContact, null);
    }

    public function test_blockingFlags_stringKind_throwsTypeError(): void
    {
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore argument.type */
        $_ = $this->policy->blockingFlags('verification');
    }

    public function test_verificationReason_arrayInsteadOfDecision_throwsTypeError(): void
    {
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore argument.type */
        $_ = $this->policy->verificationReason(['no_profile']);
    }

    public function test_adminMessage_nullInsteadOfDecision_throwsTypeError(): void
    {
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore argument.type */
        $this->policy->adminMessage(null);
    }

    public function test_mailKind_tryFromUnknownString_returnsNullAndFromThrows(): void
    {
        $this->assertNull(MailKind::tryFrom('newsletter'));
        $this->expectException(\ValueError::class);
        MailKind::from('newsletter');
    }
}
