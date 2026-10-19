<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EmailEventMatch;
use ElanRegistry\Car\EmailEventMatcher;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the decisions of EmailEventMatcher (#2359).
 *
 * The repository is a mock that returns canned rows. The real SQL (indexes,
 * collation, candidate rows) is in EmailEventMatcherIntegrationTest.
 */
#[Group('fast')]
#[AllowMockObjectsWithoutExpectations]
final class EmailEventMatcherTest extends TestCase
{
    private const MESSAGE_ID = '<202610140000.1@smtp-relay.example>';

    /** @var CarRepository&\PHPUnit\Framework\MockObject\MockObject */
    private CarRepository $repo;

    private EmailEventMatcher $matcher;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(CarRepository::class);
        $this->repo->method('sendinblueMessagesTableExists')->willReturn(true);
        $this->matcher = new EmailEventMatcher($this->repo);
    }

    private static function sentRow(int $userId, ?int $carId, string $email, string $at = '2026-10-14 09:00:00'): object
    {
        return (object) [
            'user_id' => (string) $userId,
            'car_id' => $carId === null ? null : (string) $carId,
            'email' => $email,
            'brevo_message_id' => self::MESSAGE_ID,
            'occurred_at' => $at,
        ];
    }

    private static function pluginRow(string $recipient, string $at = '2026-10-14 09:00:05'): object
    {
        return (object) [
            'message_id' => self::MESSAGE_ID,
            'recipient_email' => $recipient,
            'accepted_at' => $at,
        ];
    }

    // --- Not ours --------------------------------------------------------

    public function test_matchByMessageId_noSentRowAndNoPluginRow_returnsNotOurs(): void
    {
        $this->repo->method('findSentRowsByMessageId')->willReturn([]);
        $this->repo->method('findSendinblueMessageById')->willReturn(null);
        $this->repo->expects($this->never())->method('findUserIdsByAddress');

        $match = $this->matcher->matchByMessageId(self::MESSAGE_ID, 'bob@x.com');

        $this->assertTrue($match->isNotOurs());
    }

    public function test_matchByMessageId_noPluginTableAndNoSentRow_returnsNotOursWithoutReadingThePluginTable(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->method('sendinblueMessagesTableExists')->willReturn(false);
        $repo->method('findSentRowsByMessageId')->willReturn([]);
        $repo->expects($this->never())->method('findSendinblueMessageById');

        $match = (new EmailEventMatcher($repo))->matchByMessageId(self::MESSAGE_ID, 'bob@x.com');

        $this->assertTrue($match->isNotOurs());
    }

    public function test_matchByMessageId_ourMessageAndAnAddressThatNormalizesToEmpty_returnsNoUser(): void
    {
        $this->repo->method('findSentRowsByMessageId')->willReturn([self::sentRow(5, 9, 'bob@x.com')]);
        $this->repo->method('findSendinblueMessageById')->willReturn(null);

        $match = $this->matcher->matchByMessageId(self::MESSAGE_ID, " \t ");

        $this->assertTrue($match->isNoUser());
    }

    // --- Step (a) --------------------------------------------------------

    public function test_matchByMessageId_sentRowWithTheEventAddress_returnsItsUserCarAndTimeWithoutStepB(): void
    {
        $this->repo->method('findSentRowsByMessageId')->willReturn([self::sentRow(5, 9, 'bob@x.com', '2026-10-14 08:00:00')]);
        $this->repo->method('findSendinblueMessageById')->willReturn(self::pluginRow('bob@x.com', '2026-10-14 08:00:09'));
        $this->repo->expects($this->never())->method('findUserIdsByAddress');

        $match = $this->matcher->matchByMessageId(self::MESSAGE_ID, 'bob@x.com');

        $this->assertTrue($match->isMatched());
        $this->assertSame(5, $match->userId());
        $this->assertSame(9, $match->carId());
        $this->assertSame('2026-10-14 08:00:00', $match->messageTime(), 'Step (a) takes the sent row time, not the plugin time');
    }

    public function test_matchByMessageId_sentRowAddressDiffersOnlyInCaseAndOuterWhitespace_stillMatchesStepA(): void
    {
        $this->repo->method('findSentRowsByMessageId')->willReturn([self::sentRow(5, 9, '  Bob@X.com ')]);
        $this->repo->method('findSendinblueMessageById')->willReturn(null);

        $match = $this->matcher->matchByMessageId(self::MESSAGE_ID, "BOB@x.COM\n");

        $this->assertTrue($match->isMatched());
        $this->assertSame(5, $match->userId());
    }

    public function test_matchByMessageId_sentRowWithNoCar_returnsNullCarId(): void
    {
        $this->repo->method('findSentRowsByMessageId')->willReturn([self::sentRow(5, null, 'bob@x.com')]);
        $this->repo->method('findSendinblueMessageById')->willReturn(null);

        $match = $this->matcher->matchByMessageId(self::MESSAGE_ID, 'bob@x.com');

        $this->assertNull($match->carId());
    }

    public function test_matchByMessageId_twoSentRowsWithTheSameAddress_returnsNoUserAndNeverFallsThrough(): void
    {
        $this->repo->method('findSentRowsByMessageId')->willReturn([
            self::sentRow(5, 9, 'bob@x.com'),
            self::sentRow(6, 10, 'bob@x.com'),
        ]);
        $this->repo->method('findSendinblueMessageById')->willReturn(self::pluginRow('bob@x.com'));
        $this->repo->expects($this->never())->method('findUserIdsByAddress');

        $match = $this->matcher->matchByMessageId(self::MESSAGE_ID, 'bob@x.com');

        $this->assertTrue($match->isNoUser());
    }

    // --- Fall-through from (a) to (b) -------------------------------------

    public function test_matchByMessageId_sentRowAddressDiffers_fallsThroughToTheOnlyUserOfThePluginAddress(): void
    {
        // A multi-recipient send: the sent row is for a@x.com, the event is for b@x.com.
        $this->repo->method('findSentRowsByMessageId')->willReturn([self::sentRow(5, 9, 'a@x.com')]);
        $this->repo->method('findSendinblueMessageById')->willReturn(self::pluginRow('b@x.com', '2026-10-14 09:00:07'));
        $this->repo->expects($this->once())->method('findUserIdsByAddress')->with('b@x.com')->willReturn([7]);

        $match = $this->matcher->matchByMessageId(self::MESSAGE_ID, 'b@x.com');

        $this->assertTrue($match->isMatched());
        $this->assertSame(7, $match->userId(), 'The user of the sent row (5) must not get the flag');
        $this->assertNull($match->carId());
        $this->assertSame('2026-10-14 09:00:07', $match->messageTime(), 'Step (b) takes accepted_at');
    }

    public function test_matchByMessageId_sentRowAddressDiffersAndNoPluginRow_returnsNoUser(): void
    {
        $this->repo->method('findSentRowsByMessageId')->willReturn([self::sentRow(5, 9, 'a@x.com')]);
        $this->repo->method('findSendinblueMessageById')->willReturn(null);
        $this->repo->expects($this->never())->method('findUserIdsByAddress');

        $match = $this->matcher->matchByMessageId(self::MESSAGE_ID, 'b@x.com');

        $this->assertTrue($match->isNoUser(), 'The sent row user must not get a flag for another address');
    }

    // --- Step (b) --------------------------------------------------------

    public function test_matchByMessageId_pluginRowOnly_returnsTheUserWithNoCar(): void
    {
        $this->repo->method('findSentRowsByMessageId')->willReturn([]);
        $this->repo->method('findSendinblueMessageById')->willReturn(self::pluginRow('bob@x.com', '2026-10-14 10:11:12'));
        $this->repo->method('findUserIdsByAddress')->willReturn([3]);

        $match = $this->matcher->matchByMessageId(self::MESSAGE_ID, 'bob@x.com');

        $this->assertSame(3, $match->userId());
        $this->assertNull($match->carId());
        $this->assertSame('2026-10-14 10:11:12', $match->messageTime());
    }

    public function test_matchByMessageId_pluginRowAddressWithCaseAndOuterWhitespace_matchesAndAsksForTheNormalizedAddress(): void
    {
        $this->repo->method('findSentRowsByMessageId')->willReturn([]);
        $this->repo->method('findSendinblueMessageById')->willReturn(self::pluginRow(' Bob@X.com '));
        $this->repo->expects($this->once())->method('findUserIdsByAddress')->with('bob@x.com')->willReturn([3]);

        $match = $this->matcher->matchByMessageId(self::MESSAGE_ID, "\tBOB@x.com");

        $this->assertSame(3, $match->userId());
    }

    public function test_matchByMessageId_addressSharedByTwoUsers_returnsNoUser(): void
    {
        $this->repo->method('findSentRowsByMessageId')->willReturn([]);
        $this->repo->method('findSendinblueMessageById')->willReturn(self::pluginRow('bob@x.com'));
        $this->repo->method('findUserIdsByAddress')->willReturn([3, 4]);

        $match = $this->matcher->matchByMessageId(self::MESSAGE_ID, 'bob@x.com');

        $this->assertTrue($match->isNoUser());
    }

    public function test_matchByMessageId_addressWithNoUser_returnsNoUser(): void
    {
        $this->repo->method('findSentRowsByMessageId')->willReturn([]);
        $this->repo->method('findSendinblueMessageById')->willReturn(self::pluginRow('bob@x.com'));
        $this->repo->method('findUserIdsByAddress')->willReturn([]);

        $this->assertTrue($this->matcher->matchByMessageId(self::MESSAGE_ID, 'bob@x.com')->isNoUser());
    }

    public function test_matchByMessageId_eventAddressIsASubstringOfThePluginRecipient_returnsNoUserWithoutAUserLookup(): void
    {
        $this->repo->method('findSentRowsByMessageId')->willReturn([]);
        $this->repo->method('findSendinblueMessageById')->willReturn(self::pluginRow('jimbob@x.com'));
        $this->repo->expects($this->never())->method('findUserIdsByAddress');

        $match = $this->matcher->matchByMessageId(self::MESSAGE_ID, 'bob@x.com');

        $this->assertTrue($match->isNoUser(), 'bob@x.com must not match a row for jimbob@x.com');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function multiRecipientProvider(): array
    {
        return [
            'comma' => ['bob@x.com,al@x.com'],
            'comma and space' => ['bob@x.com, al@x.com'],
            'semicolon' => ['bob@x.com;al@x.com'],
            'space' => ['bob@x.com al@x.com'],
            'display name form' => ['Bob <bob@x.com>'],
        ];
    }

    #[DataProvider('multiRecipientProvider')]
    public function test_matchByMessageId_pluginRowWithMoreThanOneRecipient_returnsNoUser(string $recipient): void
    {
        $this->repo->method('findSentRowsByMessageId')->willReturn([]);
        $this->repo->method('findSendinblueMessageById')->willReturn(self::pluginRow($recipient));
        $this->repo->expects($this->never())->method('findUserIdsByAddress');

        $match = $this->matcher->matchByMessageId(self::MESSAGE_ID, 'bob@x.com');

        $this->assertTrue($match->isNoUser());
    }

    public function test_matchByMessageId_accentVariantOfThePluginRecipient_returnsNoUser(): void
    {
        $this->repo->method('findSentRowsByMessageId')->willReturn([]);
        $this->repo->method('findSendinblueMessageById')->willReturn(self::pluginRow('bób@x.com'));
        $this->repo->expects($this->never())->method('findUserIdsByAddress');

        $this->assertTrue($this->matcher->matchByMessageId(self::MESSAGE_ID, 'bob@x.com')->isNoUser());
    }

    public function test_matchByMessageId_asksForThePluginRowByTheEventMessageIdOnly(): void
    {
        $this->repo->expects($this->once())->method('findSentRowsByMessageId')->with(self::MESSAGE_ID)->willReturn([]);
        $this->repo->expects($this->once())->method('findSendinblueMessageById')->with(self::MESSAGE_ID)->willReturn(null);

        $this->matcher->matchByMessageId(self::MESSAGE_ID, 'bob@x.com');
    }

    public function test_matchByMessageId_checksThePluginTableOncePerInstance(): void
    {
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('sendinblueMessagesTableExists')->willReturn(true);
        $repo->method('findSentRowsByMessageId')->willReturn([]);
        $repo->method('findSendinblueMessageById')->willReturn(null);
        $matcher = new EmailEventMatcher($repo);

        $matcher->matchByMessageId(self::MESSAGE_ID, 'bob@x.com');
        $matcher->matchByMessageId('other-id', 'bob@x.com');
    }

    public function test_matchByMessageId_repositoryFailure_propagatesTheException(): void
    {
        $this->repo->method('findSentRowsByMessageId')->willThrowException(new CarDatabaseException('boom'));

        $this->expectException(CarDatabaseException::class);
        $this->matcher->matchByMessageId(self::MESSAGE_ID, 'bob@x.com');
    }

    // --- matchUserByAddress ----------------------------------------------

    /**
     * @return array<string, array{list<int>, int|null}>
     */
    public static function userIdsProvider(): array
    {
        return [
            'one user' => [[12], 12],
            'two users' => [[12, 13], null],
            'no user' => [[], null],
        ];
    }

    /**
     * @param list<int> $ids
     */
    #[DataProvider('userIdsProvider')]
    public function test_matchUserByAddress_returnsTheUserOnlyWhenExactlyOneHoldsTheAddress(array $ids, ?int $expected): void
    {
        $this->repo->method('findUserIdsByAddress')->willReturn($ids);

        $this->assertSame($expected, $this->matcher->matchUserByAddress('bob@x.com'));
    }

    // --- Wrong-typed values (value object) -------------------------------

    /**
     * @return array<string, array{int, int|null, string}>
     */
    public static function badMatchProvider(): array
    {
        return [
            'zero user' => [0, null, '2026-01-01 00:00:00'],
            'negative user' => [-1, null, '2026-01-01 00:00:00'],
            'zero car' => [1, 0, '2026-01-01 00:00:00'],
            'negative car' => [1, -5, '2026-01-01 00:00:00'],
            'empty time' => [1, null, ''],
            'blank time' => [1, null, "  \t"],
        ];
    }

    #[DataProvider('badMatchProvider')]
    public function test_matched_outOfRangeValue_throws(int $userId, ?int $carId, string $time): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmailEventMatch::matched($userId, $carId, $time);
    }

    public function test_userId_onANotOursResult_throws(): void
    {
        $this->expectException(LogicException::class);
        EmailEventMatch::notOurs()->userId();
    }
}
