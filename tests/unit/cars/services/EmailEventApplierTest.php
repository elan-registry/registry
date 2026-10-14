<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventMatch;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EmailEventApplier — the locked event step shared by the
 * Brevo webhook, the reconciliation job and the suppression import (#2359).
 *
 * Mocks CarRepository and CarVerificationManager. The real transaction, lock
 * and duplicate rule are in the integration tests. logger() assertions use
 * the $mockLogEntries global installed by tests/bootstrap-unit.php.
 */
#[Group('fast')]
#[AllowMockObjectsWithoutExpectations]
final class EmailEventApplierTest extends TestCase
{
    private const ADDRESS = 'owner@example.com';
    private const MESSAGE_TIME = '2026-01-01 00:00:00';
    private const EVENT_TIME = '2026-01-01 00:05:00';

    /** @var CarRepository&\PHPUnit\Framework\MockObject\MockObject */
    private CarRepository $mockRepo;

    /** @var CarVerificationManager&\PHPUnit\Framework\MockObject\MockObject */
    private CarVerificationManager $mockManager;

    private EmailEventApplier $applier;

    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        $this->mockRepo = $this->createMock(CarRepository::class);
        $this->mockManager = $this->createMock(CarVerificationManager::class);
        $this->applier = new EmailEventApplier($this->mockRepo, $this->mockManager);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{email: string, join_date: ?string, email_address_since: ?string,
     *               email_bounce_cleared_at: ?string, has_profile: bool}
     */
    private static function guardState(array $overrides = []): array
    {
        /** @var array{email: string, join_date: ?string, email_address_since: ?string, email_bounce_cleared_at: ?string, has_profile: bool} */
        return array_merge([
            'email'                   => self::ADDRESS,
            'join_date'               => '2020-01-01 00:00:00',
            'email_address_since'     => '2020-01-01 00:00:00',
            'email_bounce_cleared_at' => null,
            'has_profile'             => true,
        ], $overrides);
    }

    private function apply(
        string $event,
        int $userId = 42,
        string $mode = EmailEventApplier::MODE_EVENT,
        string $source = 'webhook',
        ?string $reason = null,
        ?int $carId = null,
        string $address = self::ADDRESS,
        ?string $blockedCause = null,
    ): bool {
        return $this->applier->apply(
            EmailEventMatch::matched($userId, $carId, self::MESSAGE_TIME),
            $address,
            $event,
            $reason,
            'msg-1',
            self::EVENT_TIME,
            $source,
            $mode,
            $blockedCause
        );
    }

    /** @param array<string, mixed> $overrides */
    private function givenState(array $overrides = [], int $inserted = 1): void
    {
        $this->mockRepo->method('lockEventGuardState')->willReturn(self::guardState($overrides));
        $this->mockRepo->method('insertOwnerEmailEvent')->willReturn($inserted);
    }

    // --- Flag mapping ----------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function hardBounceClassEventProvider(): array
    {
        return [
            'hard_bounce' => ['hard_bounce'],
            'blocked' => ['blocked'],
            'invalid' => ['invalid'],
            'invalid_email' => ['invalid_email'],
        ];
    }

    #[DataProvider('hardBounceClassEventProvider')]
    public function testHardBounceClassEventRecordsTheRowWithTheCarAndSetsBounced(string $event): void
    {
        $this->mockRepo->method('lockEventGuardState')->with(42)->willReturn(self::guardState());
        $this->mockRepo->expects($this->once())
            ->method('insertOwnerEmailEvent')
            ->with(42, self::ADDRESS, $event, null, 'msg-1', self::EVENT_TIME, 7)
            ->willReturn(1);
        $this->mockManager->expects($this->once())
            ->method('setBouncedFromEvent')
            ->with(42, self::ADDRESS, self::EVENT_TIME, 'webhook')
            ->willReturn(true);
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $this->assertTrue($this->apply($event, carId: 7));
        $this->assertNoLogEntries();
    }

    public function testSoftBounceBelowThresholdDoesNotEscalate(): void
    {
        $this->givenState();
        $this->mockRepo->expects($this->once())
            ->method('countSoftBouncesSinceLastDelivered')
            ->with(3, self::ADDRESS)
            ->willReturn(2);
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');

        $this->apply('soft_bounce', 3);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function softBounceEscalatingCountProvider(): array
    {
        return [
            'at threshold' => [3],
            'above threshold' => [5],
        ];
    }

    #[DataProvider('softBounceEscalatingCountProvider')]
    public function testSoftBounceAtOrAboveThresholdEscalatesToBounced(int $cycles): void
    {
        $this->givenState();
        $this->mockRepo->method('countSoftBouncesSinceLastDelivered')->willReturn($cycles);
        $this->mockManager->expects($this->once())
            ->method('setBouncedFromEvent')
            ->with(3, self::ADDRESS, self::EVENT_TIME, 'webhook');

        $this->apply('soft_bounce', 3);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function suppressionEventProvider(): array
    {
        return [
            'spam' => ['spam'],
            'unsubscribed' => ['unsubscribed'],
        ];
    }

    #[DataProvider('suppressionEventProvider')]
    public function testSuppressionEventSetsBlockedWithItsOwnCause(string $event): void
    {
        $this->givenState();
        $this->mockManager->expects($this->once())
            ->method('setSuppressedFromEvent')
            ->with(7, self::ADDRESS, $event, self::EVENT_TIME, 'webhook');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');

        $this->apply($event, 7);
        $this->assertNoLogEntries();
    }

    /**
     * Every caller passes only known names. The webhook logs an unknown
     * name and drops it, so the applier records any other name silently.
     *
     * @return array<string, array{string}>
     */
    public static function inertEventProvider(): array
    {
        return [
            'delivered' => ['delivered'],
            'unique_opened' => ['unique_opened'],
            'unknown name' => ['deferred'],
        ];
    }

    #[DataProvider('inertEventProvider')]
    public function testInertEventRecordsRowWithoutFlagChangeOrLog(string $event): void
    {
        $this->givenState();
        $this->mockRepo->expects($this->once())->method('insertOwnerEmailEvent');
        $this->mockRepo->expects($this->never())->method('countSoftBouncesSinceLastDelivered');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $this->assertTrue($this->apply($event, 9));
        $this->assertNoLogEntries();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function guardColumnProvider(): array
    {
        return [
            'join_date' => ['join_date'],
            'email_address_since' => ['email_address_since'],
            'email_bounce_cleared_at' => ['email_bounce_cleared_at'],
        ];
    }

    /** A zero date turns a guard off, so the log must show which owner and which guard. */
    #[DataProvider('guardColumnProvider')]
    public function testZeroGuardDateSetsNoBoundAndLogsTheUserIdAndColumnButNoAddress(string $column): void
    {
        $this->givenState([$column => '0000-00-00 00:00:00']);
        $this->mockRepo->expects($this->once())->method('insertOwnerEmailEvent');
        $this->mockManager->expects($this->once())->method('setBouncedFromEvent');

        $this->assertTrue($this->apply('hard_bounce', 23));

        global $mockLogEntries;
        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $mockLogEntries[0]['category']);
        $this->assertSame(23, $mockLogEntries[0]['user_id']);
        $this->assertStringContainsString('owner 23', $mockLogEntries[0]['message']);
        $this->assertStringContainsString($column, $mockLogEntries[0]['message']);
        $this->assertStringNotContainsString(self::ADDRESS, $mockLogEntries[0]['message']);
    }

    // --- Steps 2, 3 and 5 ------------------------------------------------

    /** A dropped event must leave a trace, but the trace holds no address. */
    public function testNoUsersRowRecordsNothingAndLogsTheUserId(): void
    {
        $this->mockRepo->method('lockEventGuardState')->willReturn(null);
        $this->mockRepo->expects($this->never())->method('insertOwnerEmailEvent');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockRepo->expects($this->once())->method('commit');

        $this->assertFalse($this->apply('hard_bounce', 61));

        $this->assertDroppedEventLogged(61, 'no users row');
    }

    /**
     * @return array<string, array{string, array<string, string>, string}>
     */
    public static function failedGuardProvider(): array
    {
        return [
            'message before join_date' => ['hard_bounce', ['join_date' => '2026-01-01 00:00:01'], 'join_date'],
            'message before email_address_since' => [
                'delivered', ['email_address_since' => '2026-02-01 00:00:00'], 'email_address_since',
            ],
            'bounce before email_bounce_cleared_at' => [
                'hard_bounce', ['email_bounce_cleared_at' => '2026-01-01 00:00:01'], 'email_bounce_cleared_at',
            ],
            'soft bounce before email_bounce_cleared_at' => [
                'soft_bounce', ['email_bounce_cleared_at' => '2026-01-02 00:00:00'], 'email_bounce_cleared_at',
            ],
        ];
    }

    /** @param array<string, string> $state */
    #[DataProvider('failedGuardProvider')]
    public function testAFailedDateGuardRecordsNothingSetsNoFlagAndLogsTheGuard(string $event, array $state, string $guard): void
    {
        $this->mockRepo->method('lockEventGuardState')->willReturn(self::guardState($state));
        $this->mockRepo->expects($this->never())->method('insertOwnerEmailEvent');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockRepo->expects($this->once())->method('commit');

        $this->assertFalse($this->apply($event, 62));

        $this->assertDroppedEventLogged(62, "the message is before {$guard}");
    }

    private function assertDroppedEventLogged(int $userId, string $why): void
    {
        global $mockLogEntries;
        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $mockLogEntries[0]['category']);
        $this->assertSame($userId, $mockLogEntries[0]['user_id']);
        $this->assertStringContainsString("owner {$userId} not recorded: {$why}.", $mockLogEntries[0]['message']);
        $this->assertStringNotContainsString('@', $mockLogEntries[0]['message']);
    }

    /** A spam complaint is not a bounce, so Clear Bounced does not stop it (R5-SR8). */
    public function testSpamBeforeClearBouncedStillRecordsAndBlocks(): void
    {
        $this->givenState(['email_bounce_cleared_at' => '2026-01-01 00:00:01']);
        $this->mockManager->expects($this->once())->method('setSuppressedFromEvent');

        $this->assertTrue($this->apply('spam'));
    }

    public function testAMessageAtTheGuardTimeExactlyPasses(): void
    {
        $this->givenState(['email_address_since' => self::MESSAGE_TIME, 'email_bounce_cleared_at' => self::MESSAGE_TIME]);
        $this->mockManager->expects($this->once())->method('setBouncedFromEvent');

        $this->apply('hard_bounce');
    }

    public function testNoProfilesRowKeepsTheRowLogsTheUserIdAndSetsNoFlag(): void
    {
        $this->givenState(['has_profile' => false, 'email_address_since' => null]);
        $this->mockRepo->expects($this->once())->method('insertOwnerEmailEvent');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');

        $this->assertTrue($this->apply('hard_bounce', 77));

        global $mockLogEntries;
        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(77, $mockLogEntries[0]['user_id']);
        $this->assertStringContainsString('owner 77', $mockLogEntries[0]['message']);
        $this->assertStringNotContainsString(self::ADDRESS, $mockLogEntries[0]['message']);
    }

    // --- Step 6: new row, current address, mode ---------------------------

    public function testEventModeDuplicateSetsNoFlag(): void
    {
        $this->givenState(inserted: 0);
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');

        $this->assertFalse($this->apply('hard_bounce'));
    }

    public function testStateModeDuplicateStillSetsTheFlag(): void
    {
        $this->givenState(inserted: 0);
        $this->mockManager->expects($this->once())
            ->method('setSuppressedFromEvent')
            ->with(42, self::ADDRESS, 'spam', self::EVENT_TIME, 'import');

        $this->assertFalse($this->apply('spam', mode: EmailEventApplier::MODE_STATE, source: 'import'));
    }

    public function testAnEventForAnOldAddressRecordsTheRowAndSetsNoFlag(): void
    {
        $this->givenState(['email' => 'new@example.com']);
        $this->mockRepo->expects($this->once())->method('insertOwnerEmailEvent');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');

        $this->assertTrue($this->apply('hard_bounce'));
    }

    public function testTheCurrentAddressCompareIgnoresCaseAndOuterWhiteSpace(): void
    {
        $this->givenState(['email' => ' Owner@Example.COM ']);
        $this->mockManager->expects($this->once())->method('setBouncedFromEvent');

        $this->apply('hard_bounce');
    }

    // --- MODE_STATE guards and block cause ---------------------------------

    /** Brevo's blocklist is current state, so an address change does not stop the import (criterion 50). */
    public function testStateModeSkipsTheJoinAndAddressGuards(): void
    {
        $this->givenState(['join_date' => '2026-06-01 00:00:00', 'email_address_since' => '2026-06-01 00:00:00']);
        $this->mockManager->expects($this->once())
            ->method('setSuppressedFromEvent')
            ->with(42, self::ADDRESS, 'spam', self::EVENT_TIME, 'import');

        $this->assertTrue($this->apply('spam', mode: EmailEventApplier::MODE_STATE, source: 'import'));
    }

    public function testStateModeHardBounceWithBlockedAtBeforeClearBouncedSetsNothing(): void
    {
        $this->givenState(['email_bounce_cleared_at' => '2026-01-01 00:06:00']);
        $this->mockRepo->expects($this->never())->method('insertOwnerEmailEvent');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');

        $this->assertFalse($this->apply('hard_bounce', mode: EmailEventApplier::MODE_STATE, source: 'import'));
        $this->assertDroppedEventLogged(42, 'the event is before email_bounce_cleared_at');
    }

    /** In state mode the bounce guard reads `$occurredAt` (blockedAt), not the message time. */
    public function testStateModeHardBounceWithBlockedAtAfterClearBouncedSetsTheFlag(): void
    {
        $this->givenState(['email_bounce_cleared_at' => '2026-01-01 00:01:00']);
        $this->mockManager->expects($this->once())->method('setBouncedFromEvent');

        $this->apply('hard_bounce', mode: EmailEventApplier::MODE_STATE, source: 'import');
    }

    public function testABlockCauseReplacesTheCauseOfTheEventName(): void
    {
        $this->givenState();
        $this->mockRepo->expects($this->once())
            ->method('insertOwnerEmailEvent')
            ->with(42, self::ADDRESS, 'unsubscribed')
            ->willReturn(1);
        $this->mockManager->expects($this->once())
            ->method('setSuppressedFromEvent')
            ->with(42, self::ADDRESS, 'blocklist', self::EVENT_TIME, 'import');

        $this->apply('unsubscribed', mode: EmailEventApplier::MODE_STATE, source: 'import', blockedCause: 'blocklist');
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function badBlockCauseProvider(): array
    {
        return [
            'event mode' => ['unsubscribed', EmailEventApplier::MODE_EVENT, 'blocklist'],
            'not a suppression event' => ['hard_bounce', EmailEventApplier::MODE_STATE, 'blocklist'],
            'unknown cause' => ['unsubscribed', EmailEventApplier::MODE_STATE, 'bogus'],
        ];
    }

    #[DataProvider('badBlockCauseProvider')]
    public function testABlockCauseOutsideItsUseThrowsBeforeTheTransaction(string $event, string $mode, string $cause): void
    {
        $this->mockRepo->expects($this->never())->method('beginTransaction');

        $this->expectException(\InvalidArgumentException::class);

        $this->apply($event, mode: $mode, source: 'import', blockedCause: $cause);
    }

    // --- Step 7: Clear Block bound ----------------------------------------

    /**
     * @return array<string, array{string, bool}>
     */
    public static function clearBlockProvider(): array
    {
        return [
            'clear after the event' => ['2026-01-01 00:06:00', false],
            'clear at the event time' => [self::EVENT_TIME, false],
            'clear before the event' => ['2026-01-01 00:04:59', true],
        ];
    }

    #[DataProvider('clearBlockProvider')]
    public function testBlockedIsSetOnlyForAnEventLaterThanTheLatestClearBlock(string $clearedAt, bool $sets): void
    {
        $this->givenState();
        $this->mockRepo->expects($this->once())
            ->method('latestFlagClearAt')
            ->with(42, 'email_blocked', 'admin')
            ->willReturn($clearedAt);
        $this->mockManager->expects($sets ? $this->once() : $this->never())->method('setSuppressedFromEvent');

        $this->assertTrue($this->apply('spam'), 'The event row is recorded either way');
    }

    public function testNoClearBlockRowSetsNoBound(): void
    {
        $this->givenState();
        $this->mockRepo->method('latestFlagClearAt')->willReturn(null);
        $this->mockManager->expects($this->once())->method('setSuppressedFromEvent');

        $this->apply('unsubscribed');
    }

    // --- Reason, transaction and arguments --------------------------------

    public function testAReasonIsCutTo255CharactersOfValidUtf8(): void
    {
        $this->mockRepo->method('lockEventGuardState')->willReturn(self::guardState());
        $stored = null;
        $this->mockRepo->method('insertOwnerEmailEvent')->willReturnCallback(
            function (int $u, string $a, string $e, ?string $reason) use (&$stored): int {
                $stored = $reason;
                return 1;
            }
        );

        $this->apply('delivered', reason: str_repeat('é', 5000) . "\xC3");

        $this->assertIsString($stored);
        $this->assertSame(255, mb_strlen($stored, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
    }

    public function testTheStepsRunInOneTransactionThatCommits(): void
    {
        $this->givenState();
        $this->mockRepo->expects($this->once())->method('beginTransaction');
        $this->mockRepo->expects($this->once())->method('commit');
        $this->mockRepo->expects($this->never())->method('rollback');

        $this->apply('hard_bounce');
    }

    /** A failure rolls back the event row and the flag, and reaches the caller as a retryable failure. */
    public function testAFlagWriteFailureRollsBackAndRethrows(): void
    {
        $this->givenState();
        $this->mockManager->method('setBouncedFromEvent')->willThrowException(new CarDatabaseException('write failed'));
        $this->mockRepo->expects($this->once())->method('rollback');
        $this->mockRepo->expects($this->never())->method('commit');

        $this->expectException(CarDatabaseException::class);

        $this->apply('hard_bounce');
    }

    public function testSourceIsPassedToTheOwnerFlagWriters(): void
    {
        $this->givenState();
        $this->mockRepo->method('countSoftBouncesSinceLastDelivered')->willReturn(3);
        $this->mockManager->expects($this->exactly(2))
            ->method('setBouncedFromEvent')
            ->with(5, self::ADDRESS, self::EVENT_TIME, 'reconciliation');

        $this->apply('hard_bounce', 5, source: 'reconciliation');
        $this->apply('soft_bounce', 5, source: 'reconciliation');
    }

    public function testUnknownSourceThrowsAndWritesNothing(): void
    {
        $this->mockRepo->expects($this->never())->method('beginTransaction');
        $this->mockRepo->expects($this->never())->method('insertOwnerEmailEvent');

        $this->expectException(\InvalidArgumentException::class);

        $this->apply('hard_bounce', source: 'bogus');
    }

    public function testUnknownModeThrowsAndWritesNothing(): void
    {
        $this->mockRepo->expects($this->never())->method('beginTransaction');

        $this->expectException(\InvalidArgumentException::class);

        $this->apply('hard_bounce', mode: 'bogus');
    }

    /**
     * @return array<string, array{EmailEventMatch}>
     */
    public static function unmatchedProvider(): array
    {
        return [
            'not ours' => [EmailEventMatch::notOurs()],
            'no user' => [EmailEventMatch::noUser()],
        ];
    }

    #[DataProvider('unmatchedProvider')]
    public function testAResultThatIsNotMatchedThrowsAndWritesNothing(EmailEventMatch $match): void
    {
        $this->mockRepo->expects($this->never())->method('beginTransaction');

        $this->expectException(\InvalidArgumentException::class);

        $this->applier->apply($match, self::ADDRESS, 'hard_bounce', null, 'm', self::EVENT_TIME, 'webhook', EmailEventApplier::MODE_EVENT);
    }

    public function testAnInvalidEventTimeThrowsBeforeTheTransaction(): void
    {
        $this->mockRepo->expects($this->never())->method('beginTransaction');

        $this->expectException(CarValidationException::class);

        $this->applier->apply(
            EmailEventMatch::matched(1, null, self::MESSAGE_TIME),
            self::ADDRESS,
            'hard_bounce',
            null,
            'm',
            '2026-02-30 00:00:00',
            'webhook',
            EmailEventApplier::MODE_EVENT
        );
    }

    private function assertNoLogEntries(): void
    {
        global $mockLogEntries;
        $this->assertSame([], $mockLogEntries, 'Expected no log entries for this event type');
    }
}
