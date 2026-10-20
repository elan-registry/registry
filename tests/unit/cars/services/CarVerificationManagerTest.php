<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\ClearBlockOutcome;
use ElanRegistry\Car\DeliverabilityFlagLog;
use ElanRegistry\Email\BrevoUnblockResult;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBrevoBlocklistClient;

/**
 * Unit tests for CarVerificationManager service class
 */
#[Group('fast')]
#[AllowMockObjectsWithoutExpectations]
final class CarVerificationManagerTest extends TestCase
{
    /**
     * Declared type stays CarRepository so the constructor injection below is
     * accepted without a cast — the intersection with MockObject (needed for
     * ->expects()/->method()) is expressed via @var, since PHPStan doesn't
     * infer it from createMock() through a plain property assignment.
     *
     * @var CarRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private CarRepository $mockRepo;
    private CarVerificationManager $manager;

    /** @var list<array{userId: int, logtype: string, lognote: string, metadata: array<string, int|string>}> */
    private array $flagLogRows = [];

    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];
        $this->flagLogRows = [];

        $this->mockRepo = $this->createMock(CarRepository::class);
        // The recorder replaces logger(): the flag-change audit rows are
        // asserted by value, with no database.
        $recorder = function (int $userId, string $logtype, string $lognote, array $metadata): int {
            $this->flagLogRows[] = compact('userId', 'logtype', 'lognote', 'metadata');

            return count($this->flagLogRows);
        };
        $this->manager = new CarVerificationManager($this->mockRepo, new DeliverabilityFlagLog($recorder));
    }

    /**
     * Assert that exactly one flag audit row was written, and return its metadata.
     *
     * @return array<string, int|string>
     */
    private function assertOneFlagLogRow(int $actingUserId, int $ownerId, string $flag, int $old, int $new, string $source): array
    {
        $this->assertCount(1, $this->flagLogRows, 'Exactly one audit row per flag change');
        $row = $this->flagLogRows[0];
        $this->assertSame($actingUserId, $row['userId']);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, $row['logtype']);
        $this->assertSame(
            ['owner_id' => $ownerId, 'flag' => $flag, 'old' => $old, 'new' => $new, 'source' => $source],
            $row['metadata']
        );
        $this->assertStringNotContainsString('@', $row['lognote'], 'The audit row must not hold an email address');

        return $row['metadata'];
    }

    /**
     * Assert one flag audit row whose metadata holds the core keys and the extra keys.
     *
     * @param list<string> $extraKeys
     * @return array<string, int|string>
     */
    private function assertOneFlagLogRowWithKeys(int $actingUserId, int $ownerId, string $flag, int $old, int $new, string $source, array $extraKeys): array
    {
        $this->assertCount(1, $this->flagLogRows, 'Exactly one audit row per flag change');
        $metadata = $this->flagLogRows[0]['metadata'];
        $this->assertSame($actingUserId, $this->flagLogRows[0]['userId']);
        $this->assertSame(
            ['owner_id' => $ownerId, 'flag' => $flag, 'old' => $old, 'new' => $new, 'source' => $source],
            array_diff_key($metadata, array_flip($extraKeys))
        );
        foreach ($extraKeys as $key) {
            $this->assertArrayHasKey($key, $metadata);
        }
        $this->assertStringNotContainsString('@', $this->flagLogRows[0]['lognote'], 'The audit row must not hold an email address');

        return $metadata;
    }

    public function testSetVerificationCodeSucceeds(): void
    {
        $code = 'VERIFY12345678';

        $this->mockRepo->expects($this->once())->method('updateVerificationCode')
            ->with(1, hashVericode($code))->willReturn(true);

        $carData = (object) ['id' => 1, 'vericode' => null];
        $result = $this->manager->setVerificationCode($carData, $code);
        $this->assertTrue($result);
        $this->assertEquals($code, $carData->vericode);
    }

    public function testSetVerificationCodeRejectsShortCode(): void
    {
        $this->mockRepo->expects($this->never())->method('updateVerificationCode');
        $this->expectException(CarValidationException::class);

        $carData = (object) ['id' => 1, 'vericode' => null];
        $this->manager->setVerificationCode($carData, 'SHORT');
    }

    public function testSetVerificationCodeRejectsEmptyCode(): void
    {
        $this->mockRepo->expects($this->never())->method('updateVerificationCode');
        $this->expectException(CarValidationException::class);

        $carData = (object) ['id' => 1, 'vericode' => null];
        $this->manager->setVerificationCode($carData, '');
    }

    public function testSetVerificationCodeThrowsCarDatabaseExceptionWhenRepositoryReturnsFalse(): void
    {
        $code = 'VERIFY12345678';

        $this->mockRepo->expects($this->once())->method('updateVerificationCode')
            ->with(1, hashVericode($code))->willReturn(false);
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'vericode' => null];
        $this->manager->setVerificationCode($carData, $code);
    }

    public function testMarkVerifiedSucceeds(): void
    {
        $this->mockRepo->expects($this->once())->method('updateCar')->willReturn(true);

        $carData = (object) ['id' => 1, 'last_verified' => null];
        $result = $this->manager->markVerified($carData);
        $this->assertTrue($result);
        $this->assertNotNull($carData->last_verified);
    }

    public function testMarkVerifiedThrowsCarDatabaseExceptionWhenRepositoryReturnsFalse(): void
    {
        $this->mockRepo->expects($this->once())->method('updateCar')->willReturn(false);
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'last_verified' => null];
        $this->manager->markVerified($carData);
    }

    public function testMarkSoldSucceeds(): void
    {
        $this->mockRepo->expects($this->once())->method('updateCar')->willReturn(true);

        $carData = (object) ['id' => 1, 'solddate' => null];
        $result = $this->manager->markSold($carData, '2024-06-15');
        $this->assertTrue($result);
        $this->assertEquals('2024-06-15', $carData->solddate);
    }

    public function testMarkSoldDefaultsToToday(): void
    {
        $this->mockRepo->expects($this->once())->method('updateCar')->willReturn(true);

        $carData = (object) ['id' => 1, 'solddate' => null];
        $result = $this->manager->markSold($carData, null);
        $this->assertTrue($result);
        $this->assertEquals(date('Y-m-d'), $carData->solddate);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSoldDateProvider(): array
    {
        return [
            'non-date string'        => ['not-a-date'],
            'empty string'           => [''],
            'slash-delimited format' => ['2024/01/15'],
            'invalid month 13'       => ['2024-13-01'],
            'Feb overflow day 30'    => ['2024-02-30'],
            'Feb overflow day 31'    => ['2024-02-31'],
            'non-leap Feb 29'        => ['2023-02-29'],
        ];
    }

    #[DataProvider('invalidSoldDateProvider')]
    public function testMarkSoldRejectsInvalidDate(string $date): void
    {
        $this->mockRepo->expects($this->never())->method('updateCar');
        $this->expectException(CarValidationException::class);

        $carData = (object) ['id' => 1, 'solddate' => null];
        $this->manager->markSold($carData, $date);
    }

    public function testMarkSoldAcceptsLeapDay(): void
    {
        $this->mockRepo->expects($this->once())->method('updateCar')->willReturn(true);

        $carData = (object) ['id' => 1, 'solddate' => null];
        $result = $this->manager->markSold($carData, '2024-02-29');
        $this->assertTrue($result);
        $this->assertEquals('2024-02-29', $carData->solddate);
    }

    public function testMarkSoldThrowsCarDatabaseExceptionWhenRepositoryReturnsFalse(): void
    {
        $this->mockRepo->expects($this->once())->method('updateCar')->willReturn(false);
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'solddate' => null];
        $this->manager->markSold($carData, '2024-06-15');
    }

    public function testSetVerificationCodeThrowsCarDatabaseExceptionWhenRepositoryThrows(): void
    {
        $code = 'VERIFY12345678';

        $this->mockRepo->expects($this->once())->method('updateVerificationCode')
            ->with(1, hashVericode($code))
            ->willThrowException(new \RuntimeException('DB connection lost'));
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'vericode' => null];
        $this->manager->setVerificationCode($carData, $code);
    }

    public function testMarkVerifiedThrowsCarDatabaseExceptionWhenRepositoryThrows(): void
    {
        $this->mockRepo->expects($this->once())->method('updateCar')
            ->willThrowException(new \RuntimeException('DB connection lost'));
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'last_verified' => null];
        $this->manager->markVerified($carData);
    }

    public function testMarkSoldThrowsCarDatabaseExceptionWhenRepositoryThrows(): void
    {
        $this->mockRepo->expects($this->once())->method('updateCar')
            ->willThrowException(new \RuntimeException('DB connection lost'));
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'solddate' => null];
        $this->manager->markSold($carData, '2024-06-15');
    }

    public function testGenerateVerificationCodeReturnsThirtyTwoCharHexString(): void
    {
        $this->mockRepo->expects($this->never())->method('updateVerificationCode');

        $code = $this->manager->generateVerificationCode();

        $this->assertSame(32, strlen($code));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $code);
    }

    public function testGenerateVerificationCodeReturnsDifferentValues(): void
    {
        $this->mockRepo->expects($this->never())->method('updateVerificationCode');

        $this->assertNotSame(
            $this->manager->generateVerificationCode(),
            $this->manager->generateVerificationCode()
        );
    }

    public function testSetVerificationSentAtSucceeds(): void
    {
        $this->mockRepo->expects($this->once())->method('updateVerificationSentAt')->willReturn(true);

        $carData = (object) ['id' => 1, 'vericode_sent_at' => null];
        $result = $this->manager->setVerificationSentAt($carData, '2024-06-15 10:30:00');
        $this->assertTrue($result);
        $this->assertEquals('2024-06-15 10:30:00', $carData->vericode_sent_at);
    }

    public function testSetVerificationSentAtThrowsCarDatabaseExceptionWhenRepositoryReturnsFalse(): void
    {
        $this->mockRepo->expects($this->once())->method('updateVerificationSentAt')->willReturn(false);
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'vericode_sent_at' => null];
        $this->manager->setVerificationSentAt($carData, '2024-06-15 10:30:00');
    }

    public function testSetVerificationSentAtThrowsCarDatabaseExceptionWhenRepositoryThrows(): void
    {
        $this->mockRepo->expects($this->once())->method('updateVerificationSentAt')
            ->willThrowException(new \RuntimeException('DB connection lost'));
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'vericode_sent_at' => null];
        $this->manager->setVerificationSentAt($carData, '2024-06-15 10:30:00');
    }

    // ------------------------------------------------------------------
    // Event writers: setBouncedFromEvent() / setSuppressedFromEvent()
    // ------------------------------------------------------------------

    public function testSetBouncedFromEventWritesOneProfileRowForTheOwnerAndLogsTheChange(): void
    {
        $this->mockRepo->expects($this->once())->method('setProfileBounced')
            ->with(42, 'owner@example.com', '2026-09-01 10:00:00')->willReturn(0);
        $this->mockRepo->expects($this->never())->method('updateCar');
        $this->mockRepo->expects($this->never())->method('findByOwner');

        $changed = $this->manager->setBouncedFromEvent(42, 'owner@example.com', '2026-09-01 10:00:00', 'webhook');

        $this->assertTrue($changed);
        $this->assertOneFlagLogRow(0, 42, 'email_bounced', 0, 1, 'webhook');
    }

    /** A repeat bounce on the same address changes nothing, so it logs nothing. */
    public function testSetBouncedFromEventReturnsFalseAndLogsNothingWhenAlreadyBounced(): void
    {
        $this->mockRepo->method('setProfileBounced')->willReturn(1);

        $this->assertFalse($this->manager->setBouncedFromEvent(42, 'owner@example.com', '2026-09-01 10:00:00', 'webhook'));
        $this->assertSame([], $this->flagLogRows);
    }

    public function testSetBouncedFromEventPassesTheReconciliationSourceToTheAuditRow(): void
    {
        $this->mockRepo->method('setProfileBounced')->willReturn(0);

        $this->manager->setBouncedFromEvent(42, 'owner@example.com', '2026-09-01 10:00:00', 'reconciliation');

        $this->assertOneFlagLogRow(0, 42, 'email_bounced', 0, 1, 'reconciliation');
    }

    /** Brevo does not retry, so an event for an owner with no profile is logged and skipped, not thrown. */
    public function testSetBouncedFromEventReturnsFalseAndLogsWhenTheOwnerHasNoProfileRow(): void
    {
        $this->mockRepo->expects($this->once())->method('setProfileBounced')
            ->with(42, 'owner@example.com', '2026-09-01 10:00:00')
            ->willReturn(null);

        $this->assertFalse($this->manager->setBouncedFromEvent(42, 'owner@example.com', '2026-09-01 10:00:00', 'webhook'));

        global $mockLogEntries;
        $this->assertCount(1, $mockLogEntries, 'A skipped event must leave one log row so the cause can be found');
        // A skipped event is Brevo event tracking, so it logs under the webhook category (#2358).
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $mockLogEntries[0]['category']);
        $this->assertSame(42, $mockLogEntries[0]['user_id'], 'The log row names the owner as its user');
        $this->assertStringContainsString('setBouncedFromEvent', $mockLogEntries[0]['message']);
        $this->assertStringContainsString('for owner 42', $mockLogEntries[0]['message']);
        $this->assertStringContainsString('the owner has no profiles row', $mockLogEntries[0]['message']);
        $this->assertStringNotContainsString('@', $mockLogEntries[0]['message']);
        $this->assertSame([], $this->flagLogRows);
    }

    public function testSetBouncedFromEventRejectsAnUnknownSourceBeforeAnyWrite(): void
    {
        $this->mockRepo->expects($this->never())->method('setProfileBounced');

        $this->expectException(\InvalidArgumentException::class);
        $this->manager->setBouncedFromEvent(42, 'owner@example.com', '2026-09-01 10:00:00', 'bogus');
    }

    public function testSetBouncedFromEventPropagatesCarDatabaseExceptionFromTheProfileWrite(): void
    {
        $this->mockRepo->method('setProfileBounced')->willThrowException(new CarDatabaseException('lock timeout'));

        $this->expectException(CarDatabaseException::class);
        $this->manager->setBouncedFromEvent(42, 'owner@example.com', '2026-09-01 10:00:00', 'webhook');
    }

    public function testSetBouncedFromEventPropagatesCarValidationExceptionForAnEmptyAddress(): void
    {
        $this->mockRepo->method('setProfileBounced')->willThrowException(new CarValidationException('address required'));

        $this->expectException(CarValidationException::class);
        $this->manager->setBouncedFromEvent(42, '', '2026-09-01 10:00:00', 'webhook');
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function blockCauseProvider(): array
    {
        return [
            'spam' => ['spam'],
            'unsubscribed' => ['unsubscribed'],
            'blocklist' => ['blocklist'],
        ];
    }

    #[DataProvider('blockCauseProvider')]
    public function testSetSuppressedFromEventWritesOneProfileRowWithTheCauseAndLogsTheChange(string $cause): void
    {
        $this->mockRepo->expects($this->once())->method('setProfileBlocked')
            ->with(42, 'owner@example.com', $cause, '2026-09-01 10:00:00')->willReturn(0);
        $this->mockRepo->expects($this->never())->method('updateCar');

        $changed = $this->manager->setSuppressedFromEvent(42, 'owner@example.com', $cause, '2026-09-01 10:00:00', 'import');

        $this->assertTrue($changed);
        $this->assertOneFlagLogRow(0, 42, 'email_blocked', 0, 1, 'import');
    }

    public function testSetSuppressedFromEventReturnsFalseAndLogsNothingWhenAlreadyBlocked(): void
    {
        $this->mockRepo->method('setProfileBlocked')->willReturn(1);

        $this->assertFalse($this->manager->setSuppressedFromEvent(42, 'owner@example.com', 'spam', '2026-09-01 10:00:00', 'webhook'));
        $this->assertSame([], $this->flagLogRows);
    }

    public function testSetSuppressedFromEventReturnsFalseAndLogsWhenTheOwnerHasNoProfileRow(): void
    {
        $this->mockRepo->expects($this->once())->method('setProfileBlocked')
            ->with(42, 'owner@example.com', 'spam', '2026-09-01 10:00:00')
            ->willReturn(null);

        $this->assertFalse($this->manager->setSuppressedFromEvent(42, 'owner@example.com', 'spam', '2026-09-01 10:00:00', 'webhook'));

        global $mockLogEntries;
        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $mockLogEntries[0]['category']);
        $this->assertSame(42, $mockLogEntries[0]['user_id'], 'The log row names the owner as its user');
        $this->assertStringContainsString('setSuppressedFromEvent', $mockLogEntries[0]['message']);
        $this->assertStringContainsString('for owner 42', $mockLogEntries[0]['message']);
        $this->assertStringContainsString('the owner has no profiles row', $mockLogEntries[0]['message']);
        $this->assertStringNotContainsString('@', $mockLogEntries[0]['message']);
        $this->assertSame([], $this->flagLogRows);
    }

    public function testSetSuppressedFromEventRejectsAnUnknownSourceBeforeAnyWrite(): void
    {
        $this->mockRepo->expects($this->never())->method('setProfileBlocked');

        $this->expectException(\InvalidArgumentException::class);
        $this->manager->setSuppressedFromEvent(42, 'owner@example.com', 'spam', '2026-09-01 10:00:00', 'bogus');
    }

    // ------------------------------------------------------------------
    // setSuppressedForOwner() — owner opt-out (#1883, #2101)
    // ------------------------------------------------------------------

    public function testSetSuppressedForOwnerWritesOneProfileRowAndNoCarRow(): void
    {
        $this->mockRepo->expects($this->once())->method('setProfileOptedOut')->with(42, true)->willReturn(0);
        $this->mockRepo->expects($this->never())->method('findByOwner');
        $this->mockRepo->expects($this->never())->method('updateCar');
        $this->mockRepo->expects($this->never())->method('insertHistory');

        $changed = $this->manager->setSuppressedForOwner(42);

        $this->assertTrue($changed);
        $this->assertOneFlagLogRow(42, 42, 'email_opted_out', 0, 1, 'owner');
    }

    public function testSetSuppressedForOwnerReturnsFalseAndLogsNothingWhenAlreadyOptedOut(): void
    {
        $this->mockRepo->method('setProfileOptedOut')->willReturn(1);

        $this->assertFalse($this->manager->setSuppressedForOwner(42));
        $this->assertSame([], $this->flagLogRows);
    }

    public function testSetSuppressedForOwnerOpensNoTransaction(): void
    {
        $this->mockRepo->method('setProfileOptedOut')->willReturn(0);
        $this->mockRepo->expects($this->never())->method('beginTransaction');
        $this->mockRepo->expects($this->never())->method('commit');

        $this->manager->setSuppressedForOwner(42);
    }

    public function testSetSuppressedForOwnerThrowsAndLogsNothingWhenOwnerHasNoProfilesRow(): void
    {
        $this->mockRepo->method('setProfileOptedOut')->willReturn(null);

        try {
            $this->manager->setSuppressedForOwner(43);
            $this->fail('Expected CarDatabaseException');
        } catch (CarDatabaseException $e) {
            $this->assertStringContainsString('owner 43 has no profiles row', $e->getMessage());
            $this->assertSame(
                'Your opt-out could not be recorded. Please try again or contact support.',
                $e->getUserMessage()
            );
        }
        $this->assertSame([], $this->flagLogRows);
    }

    public function testSetSuppressedForOwnerPropagatesCarDatabaseExceptionFromTheProfileWrite(): void
    {
        $this->mockRepo->method('setProfileOptedOut')->willThrowException(new CarDatabaseException('lock timeout'));

        $this->expectException(CarDatabaseException::class);
        $this->manager->setSuppressedForOwner(42);
    }

    // ------------------------------------------------------------------
    // setBouncedForOwner() — admin Mark Bounced (#1884, #2101)
    // ------------------------------------------------------------------

    public function testSetBouncedForOwnerWritesOneProfileRowAndLogsTheAdmin(): void
    {
        $this->mockRepo->expects($this->once())->method('setProfileBounced')
            ->with(42, 'owner@example.com', $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/'))
            ->willReturn(0);
        $this->mockRepo->expects($this->never())->method('findByOwner');
        $this->mockRepo->expects($this->never())->method('updateCar');

        $changed = $this->manager->setBouncedForOwner(42, 'owner@example.com', 7);

        $this->assertTrue($changed);
        $this->assertOneFlagLogRow(7, 42, 'email_bounced', 0, 1, 'admin');
    }

    public function testSetBouncedForOwnerReturnsFalseAndLogsNothingWhenAlreadyBounced(): void
    {
        $this->mockRepo->method('setProfileBounced')->willReturn(1);

        $this->assertFalse($this->manager->setBouncedForOwner(42, 'owner@example.com', 7));
        $this->assertSame([], $this->flagLogRows);
    }

    public function testSetBouncedForOwnerThrowsAndLogsNothingWhenOwnerHasNoProfilesRow(): void
    {
        $this->mockRepo->method('setProfileBounced')->willReturn(null);

        try {
            $this->manager->setBouncedForOwner(44, 'owner@example.com', 7);
            $this->fail('Expected CarDatabaseException');
        } catch (CarDatabaseException $e) {
            $this->assertStringContainsString('owner 44 has no profiles row', $e->getMessage());
        }
        $this->assertSame([], $this->flagLogRows);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankAddressProvider(): array
    {
        return ['empty' => [''], 'spaces' => ['   ']];
    }

    #[DataProvider('blankAddressProvider')]
    public function testSetBouncedForOwnerThrowsCarValidationExceptionForABlankAddressBeforeAnyQuery(string $address): void
    {
        $this->mockRepo->expects($this->never())->method('setProfileBounced');

        $this->expectException(CarValidationException::class);
        $this->manager->setBouncedForOwner(42, $address, 7);
    }

    /** The profile UPDATE is keyed by user_id and never sets it, so Mark Bounced cannot move a record. */
    public function testSetBouncedForOwnerNeverWritesACarRow(): void
    {
        $this->mockRepo->method('setProfileBounced')->willReturn(0);
        $this->mockRepo->expects($this->never())->method('updateCar');
        $this->mockRepo->expects($this->never())->method('updateCarForOwner');
        $this->mockRepo->expects($this->never())->method('reassignCarsByUser');

        $this->manager->setBouncedForOwner(42, 'owner@example.com', 7);
    }

    // ------------------------------------------------------------------
    // clearBouncedForOwner() — admin Clear Bounced
    // ------------------------------------------------------------------

    public function testClearBouncedForOwnerClearsTheProfileOnlyAndLogsTheAdmin(): void
    {
        $this->mockRepo->expects($this->once())->method('clearProfileBounced')->with(42)->willReturn(1);
        $this->mockRepo->expects($this->never())->method('findByOwner');
        $this->mockRepo->expects($this->never())->method('updateCar');

        $changed = $this->manager->clearBouncedForOwner(42, 7);

        $this->assertTrue($changed);
        $this->assertOneFlagLogRow(7, 42, 'email_bounced', 1, 0, 'admin');
    }

    public function testClearBouncedForOwnerReturnsFalseAndLogsNothingWhenNotBounced(): void
    {
        $this->mockRepo->method('clearProfileBounced')->willReturn(0);

        $this->assertFalse($this->manager->clearBouncedForOwner(42, 7));
        $this->assertSame([], $this->flagLogRows);
    }

    public function testClearBouncedForOwnerThrowsWhenOwnerHasNoProfilesRow(): void
    {
        $this->mockRepo->method('clearProfileBounced')->willReturn(null);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/owner 45 has no profiles row/');
        $this->manager->clearBouncedForOwner(45, 7);
    }

    // ------------------------------------------------------------------
    // clearBlockedForOwner() — admin Clear Block (#2357)
    // ------------------------------------------------------------------

    /**
     * A Clear Block read as findBlockClearTarget() returns it.
     *
     * @param array{blocked?: bool, blockedAddress?: ?string, userEmail?: string} $overrides
     * @return array{blocked: bool, blockedAddress: ?string, userEmail: string}
     */
    private function blockedState(array $overrides = []): array
    {
        return array_merge([
            'blocked' => true,
            'blockedAddress' => 'owner@example.org',
            'userEmail' => 'owner@example.org',
        ], $overrides);
    }

    /**
     * Script the repository for a Clear Block that reaches the write step.
     *
     * @param array{blocked: bool, blockedAddress: ?string, userEmail: string} $state
     * @param array{old: int, addressMatched: bool, stillBounced: bool, stillOptedOut: bool}|null $writeResult
     */
    private function scriptClearBlock(array $state, ?array $writeResult, bool $otherUserHasAddress = false): void
    {
        $this->mockRepo->method('findBlockClearTarget')->willReturn($state);
        $this->mockRepo->method('findUserIdsByAddress')->willReturn($otherUserHasAddress ? [42, 99] : [42]);
        $this->mockRepo->method('clearProfileBlockedIfAddress')->willReturn($writeResult);
    }

    private function removedClient(?\Closure $onUnblock = null): FakeBrevoBlocklistClient
    {
        return new FakeBrevoBlocklistClient(BrevoUnblockResult::removed(), $onUnblock);
    }

    public function testClearBlockedForOwnerWhenFlagIsZeroCallsNothingAndWritesNothing(): void
    {
        $this->mockRepo->method('findBlockClearTarget')->willReturn($this->blockedState(['blocked' => false]));
        $this->mockRepo->expects($this->never())->method('beginTransaction');
        $this->mockRepo->expects($this->never())->method('clearProfileBlockedIfAddress');
        $client = $this->removedClient();

        $outcome = $this->manager->clearBlockedForOwner(42, 7, $client);

        $this->assertSame(ClearBlockOutcome::NOT_BLOCKED, $outcome->kind);
        $this->assertSame([], $client->calls);
        $this->assertSame([], $this->flagLogRows);
    }

    public function testClearBlockedForOwnerMatchingAddressCallsBrevoThenClearsAndLogsTheRow(): void
    {
        $this->scriptClearBlock($this->blockedState(), ['old' => 1, 'addressMatched' => true, 'stillBounced' => false, 'stillOptedOut' => false]);
        $this->mockRepo->expects($this->once())->method('beginTransaction');
        $this->mockRepo->expects($this->once())->method('clearProfileBlockedIfAddress')->with(42, 'owner@example.org');
        $this->mockRepo->expects($this->once())->method('commit');
        $this->mockRepo->expects($this->never())->method('rollback');
        $client = $this->removedClient();
        $before = time();

        $outcome = $this->manager->clearBlockedForOwner(42, 7, $client);

        $this->assertSame(ClearBlockOutcome::CLEARED, $outcome->kind);
        $this->assertFalse($outcome->stillBounced);
        $this->assertSame(['owner@example.org'], $client->calls, 'Brevo is called once with the blocked address');
        $metadata = $this->assertOneFlagLogRowWithKeys(7, 42, 'email_blocked', 1, 0, 'admin', ['cleared_at', 'owner_requested']);
        $this->assertSame(1, $metadata['owner_requested']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $metadata['cleared_at']);
        $this->assertGreaterThanOrEqual($before, strtotime((string) $metadata['cleared_at']));
        $this->assertLessThanOrEqual(time(), strtotime((string) $metadata['cleared_at']));
    }

    /**
     * The clear time is taken before the Brevo call, so an import entry that
     * Brevo dated during the call counts as a new block.
     */
    public function testClearBlockedForOwnerRecordsTheClearTimeBeforeTheBrevoCall(): void
    {
        $this->scriptClearBlock($this->blockedState(), ['old' => 1, 'addressMatched' => true, 'stillBounced' => false, 'stillOptedOut' => false]);
        $client = $this->removedClient(static function (): void {
            sleep(2);
        });
        $before = time();

        $this->manager->clearBlockedForOwner(42, 7, $client);

        $clearedAt = strtotime((string) $this->flagLogRows[0]['metadata']['cleared_at']);
        $this->assertLessThanOrEqual($before + 1, $clearedAt, 'cleared_at is the time before the 2 s Brevo call');
    }

    public function testClearBlockedForOwnerBrevoNotBlockedCountsAsSuccess(): void
    {
        $this->scriptClearBlock($this->blockedState(), ['old' => 1, 'addressMatched' => true, 'stillBounced' => false, 'stillOptedOut' => false]);
        $client = new FakeBrevoBlocklistClient(BrevoUnblockResult::notBlocked());

        $outcome = $this->manager->clearBlockedForOwner(42, 7, $client);

        $this->assertSame(ClearBlockOutcome::CLEARED, $outcome->kind);
        $this->assertCount(1, $this->flagLogRows);
    }

    public function testClearBlockedForOwnerPassesStillBouncedToTheOutcome(): void
    {
        $this->scriptClearBlock($this->blockedState(), ['old' => 1, 'addressMatched' => true, 'stillBounced' => true, 'stillOptedOut' => false]);

        $outcome = $this->manager->clearBlockedForOwner(42, 7, $this->removedClient());

        $this->assertTrue($outcome->stillBounced);
    }

    public function testClearBlockedForOwnerPassesStillOptedOutToTheOutcome(): void
    {
        $this->scriptClearBlock($this->blockedState(), ['old' => 1, 'addressMatched' => true, 'stillBounced' => true, 'stillOptedOut' => true]);

        $outcome = $this->manager->clearBlockedForOwner(42, 7, $this->removedClient());

        $this->assertTrue($outcome->stillBounced);
        $this->assertTrue($outcome->stillOptedOut);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function brevoFailureReasonProvider(): array
    {
        return [
            'no API key' => [BrevoUnblockResult::REASON_NO_API_KEY, ClearBlockOutcome::BREVO_NOT_SET_UP],
            'no SDK' => [BrevoUnblockResult::REASON_NO_SDK, ClearBlockOutcome::BREVO_NOT_SET_UP],
            'HTTP error' => [BrevoUnblockResult::REASON_HTTP, ClearBlockOutcome::BREVO_FAILED],
            'transport error' => [BrevoUnblockResult::REASON_TRANSPORT, ClearBlockOutcome::BREVO_FAILED],
            // A failed key query is not a missing key: a retry can work.
            'key query DB error' => [BrevoUnblockResult::REASON_DB_ERROR, ClearBlockOutcome::BREVO_FAILED],
        ];
    }

    #[DataProvider('brevoFailureReasonProvider')]
    public function testClearBlockedForOwnerMapsTheBrevoFailureReasonToItsOutcome(string $reason, string $expectedKind): void
    {
        $this->mockRepo->method('findBlockClearTarget')->willReturn($this->blockedState());
        $this->mockRepo->method('findUserIdsByAddress')->willReturn([42]);
        $this->mockRepo->expects($this->never())->method('clearProfileBlockedIfAddress');

        $outcome = $this->manager->clearBlockedForOwner(42, 7, new FakeBrevoBlocklistClient(BrevoUnblockResult::failed($reason)));

        $this->assertSame($expectedKind, $outcome->kind);
        $this->assertFalse($outcome->isCleared());
        $this->assertSame([], $this->flagLogRows, 'No flag-change row');
    }

    public function testClearBlockedForOwnerEmptyBlockedAddressUsesTheUserEmail(): void
    {
        $this->scriptClearBlock(
            $this->blockedState(['blockedAddress' => '  ', 'userEmail' => 'user@example.org']),
            ['old' => 1, 'addressMatched' => true, 'stillBounced' => false, 'stillOptedOut' => false]
        );
        $this->mockRepo->expects($this->once())->method('clearProfileBlockedIfAddress')->with(42, '  ');
        $client = $this->removedClient();

        $outcome = $this->manager->clearBlockedForOwner(42, 7, $client);

        $this->assertSame(ClearBlockOutcome::CLEARED, $outcome->kind);
        $this->assertSame(['user@example.org'], $client->calls);
    }

    public function testClearBlockedForOwnerNullBlockedAddressUsesTheUserEmail(): void
    {
        $this->scriptClearBlock(
            $this->blockedState(['blockedAddress' => null, 'userEmail' => 'user@example.org']),
            ['old' => 1, 'addressMatched' => true, 'stillBounced' => false, 'stillOptedOut' => false]
        );
        $this->mockRepo->expects($this->once())->method('clearProfileBlockedIfAddress')->with(42, null);
        $client = $this->removedClient();

        $this->manager->clearBlockedForOwner(42, 7, $client);

        $this->assertSame(['user@example.org'], $client->calls);
    }

    public function testClearBlockedForOwnerNoAddressAtAllClearsTheFlagWithoutABrevoCall(): void
    {
        $this->scriptClearBlock(
            $this->blockedState(['blockedAddress' => null, 'userEmail' => '']),
            ['old' => 1, 'addressMatched' => true, 'stillBounced' => false, 'stillOptedOut' => false]
        );
        $client = $this->removedClient();

        $outcome = $this->manager->clearBlockedForOwner(42, 7, $client);

        $this->assertSame(ClearBlockOutcome::CLEARED_NO_ADDRESS, $outcome->kind);
        $this->assertTrue($outcome->isCleared());
        $this->assertSame([], $client->calls);
        $this->assertCount(1, $this->flagLogRows);
    }

    public function testClearBlockedForOwnerAddressNoLongerTheOwnersClearsTheFlagWithoutABrevoCall(): void
    {
        $this->scriptClearBlock(
            $this->blockedState(['blockedAddress' => 'old@example.org', 'userEmail' => 'new@example.org']),
            ['old' => 1, 'addressMatched' => true, 'stillBounced' => false, 'stillOptedOut' => false]
        );
        $client = $this->removedClient();

        $outcome = $this->manager->clearBlockedForOwner(42, 7, $client);

        $this->assertSame(ClearBlockOutcome::CLEARED_ADDRESS_MOVED, $outcome->kind);
        $this->assertSame([], $client->calls);
        $this->assertCount(1, $this->flagLogRows);
    }

    public function testClearBlockedForOwnerComparesAddressesUnderTheNormalizeRule(): void
    {
        $this->scriptClearBlock(
            $this->blockedState(['blockedAddress' => ' Owner@Example.ORG ', 'userEmail' => 'owner@example.org']),
            ['old' => 1, 'addressMatched' => true, 'stillBounced' => false, 'stillOptedOut' => false]
        );
        $client = $this->removedClient();

        $outcome = $this->manager->clearBlockedForOwner(42, 7, $client);

        $this->assertSame(ClearBlockOutcome::CLEARED, $outcome->kind, 'Case and outer spaces do not make the address "moved"');
        $this->assertSame(['Owner@Example.ORG'], $client->calls, 'Brevo gets the address without the outer spaces');
    }

    public function testClearBlockedForOwnerTrimsTheUserEmailFallbackBeforeTheBrevoCall(): void
    {
        $this->scriptClearBlock(
            $this->blockedState(['blockedAddress' => null, 'userEmail' => ' user@example.org ']),
            ['old' => 1, 'addressMatched' => true, 'stillBounced' => false, 'stillOptedOut' => false]
        );
        $client = $this->removedClient();

        $outcome = $this->manager->clearBlockedForOwner(42, 7, $client);

        $this->assertSame(ClearBlockOutcome::CLEARED, $outcome->kind);
        $this->assertSame(['user@example.org'], $client->calls, 'Spaces would reach the Brevo path as %20');
    }

    public function testClearBlockedForOwnerAddressUsedByAnotherAccountClearsTheFlagWithoutABrevoCall(): void
    {
        $this->scriptClearBlock(
            $this->blockedState(),
            ['old' => 1, 'addressMatched' => true, 'stillBounced' => false, 'stillOptedOut' => false],
            otherUserHasAddress: true
        );
        $client = $this->removedClient();

        $outcome = $this->manager->clearBlockedForOwner(42, 7, $client);

        $this->assertSame(ClearBlockOutcome::CLEARED_SHARED_ADDRESS, $outcome->kind);
        $this->assertSame([], $client->calls);
        $this->assertCount(1, $this->flagLogRows);
    }

    public function testClearBlockedForOwnerOwnerAsOnlyHolderOfTheAddressIsNotShared(): void
    {
        $this->scriptClearBlock($this->blockedState(), ['old' => 1, 'addressMatched' => true, 'stillBounced' => false, 'stillOptedOut' => false]);

        $outcome = $this->manager->clearBlockedForOwner(42, 7, $this->removedClient());

        $this->assertSame(ClearBlockOutcome::CLEARED, $outcome->kind);
    }

    public function testClearBlockedForOwnerBrevoFailureWritesNothingAndLogsOwnerIdAndStatusOnly(): void
    {
        global $mockLogEntries;
        $this->mockRepo->method('findBlockClearTarget')->willReturn($this->blockedState());
        $this->mockRepo->method('findUserIdsByAddress')->willReturn([42]);
        $this->mockRepo->expects($this->never())->method('beginTransaction');
        $this->mockRepo->expects($this->never())->method('clearProfileBlockedIfAddress');
        $client = new FakeBrevoBlocklistClient(BrevoUnblockResult::failed(BrevoUnblockResult::REASON_HTTP, 500));

        $outcome = $this->manager->clearBlockedForOwner(42, 7, $client);

        $this->assertSame(ClearBlockOutcome::BREVO_FAILED, $outcome->kind);
        $this->assertFalse($outcome->isCleared());
        $this->assertSame([], $this->flagLogRows, 'No flag-change row');
        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(7, $mockLogEntries[0]['user_id']);
        $this->assertSame(LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $mockLogEntries[0]['category']);
        $this->assertSame(
            'Verification: Clear Block Brevo unblock failed for owner 42 (reason http, HTTP 500)',
            $mockLogEntries[0]['message']
        );
        $this->assertStringNotContainsString('owner@example.org', json_encode($mockLogEntries, JSON_THROW_ON_ERROR));
    }

    public function testClearBlockedForOwnerBrevoFailureWithNoStatusLogsNone(): void
    {
        global $mockLogEntries;
        $this->mockRepo->method('findBlockClearTarget')->willReturn($this->blockedState());
        $this->mockRepo->method('findUserIdsByAddress')->willReturn([42]);
        $client = new FakeBrevoBlocklistClient(BrevoUnblockResult::failed(BrevoUnblockResult::REASON_NO_API_KEY));

        $this->manager->clearBlockedForOwner(42, 7, $client);

        $this->assertSame(
            'Verification: Clear Block Brevo unblock failed for owner 42 (reason no_api_key, HTTP none)',
            $mockLogEntries[0]['message']
        );
    }

    public function testClearBlockedForOwnerAddressChangedDuringTheCallRollsBackAndClearsNothing(): void
    {
        $this->scriptClearBlock($this->blockedState(), ['old' => 1, 'addressMatched' => false, 'stillBounced' => false, 'stillOptedOut' => false]);
        $this->mockRepo->expects($this->once())->method('rollback');
        $this->mockRepo->expects($this->never())->method('commit');

        $outcome = $this->manager->clearBlockedForOwner(42, 7, $this->removedClient());

        $this->assertSame(ClearBlockOutcome::ADDRESS_CHANGED, $outcome->kind);
        $this->assertFalse($outcome->isCleared());
        $this->assertSame([], $this->flagLogRows);
    }

    public function testClearBlockedForOwnerFlagAlreadyClearedDuringTheCallCommitsWithNoRow(): void
    {
        $this->scriptClearBlock($this->blockedState(), ['old' => 0, 'addressMatched' => true, 'stillBounced' => false, 'stillOptedOut' => false]);
        $this->mockRepo->expects($this->once())->method('commit');
        $this->mockRepo->expects($this->never())->method('rollback');

        $outcome = $this->manager->clearBlockedForOwner(42, 7, $this->removedClient());

        $this->assertSame(ClearBlockOutcome::NOT_BLOCKED, $outcome->kind);
        $this->assertSame([], $this->flagLogRows);
    }

    #[Group('regression')]
    public function testClearBlockedForOwnerFalsyLoggerIdRollsBackAndThrows(): void
    {
        $this->scriptClearBlock($this->blockedState(), ['old' => 1, 'addressMatched' => true, 'stillBounced' => false, 'stillOptedOut' => false]);
        $this->mockRepo->expects($this->once())->method('beginTransaction');
        $this->mockRepo->expects($this->once())->method('rollback');
        $this->mockRepo->expects($this->never())->method('commit');
        $manager = new CarVerificationManager($this->mockRepo, new DeliverabilityFlagLog(static fn (): int => 0));

        $this->expectException(CarDatabaseException::class);
        $manager->clearBlockedForOwner(42, 7, $this->removedClient());
    }

    /**
     * A throw from rollback() must not replace the real failure, and its log row holds no exception message.
     */
    public function testClearBlockedForOwnerRollbackThrowsLogsTheSummaryAndKeepsTheOriginalException(): void
    {
        global $mockLogEntries;
        $this->mockRepo->method('findBlockClearTarget')->willReturn($this->blockedState());
        $this->mockRepo->method('findUserIdsByAddress')->willReturn([42]);
        $original = new CarDatabaseException('original');
        $this->mockRepo->method('clearProfileBlockedIfAddress')->willThrowException($original);
        $this->mockRepo->expects($this->once())->method('rollback')
            ->willThrowException(new \RuntimeException('rollback lost owner@example.org'));
        $this->mockRepo->expects($this->never())->method('commit');

        try {
            $this->manager->clearBlockedForOwner(42, 7, $this->removedClient());
            $this->fail('Expected CarDatabaseException');
        } catch (CarDatabaseException $e) {
            $this->assertSame($original, $e);
        }

        $this->assertCount(1, $mockLogEntries, 'The rollback failure leaves one log row');
        $this->assertSame(LogCategories::LOG_CATEGORY_DATABASE_ERROR, $mockLogEntries[0]['category']);
        $this->assertSame(7, $mockLogEntries[0]['user_id']);
        $this->assertStringContainsString('rollback failed for owner 42', $mockLogEntries[0]['message']);
        $this->assertStringContainsString('RuntimeException', $mockLogEntries[0]['message']);
        $this->assertStringNotContainsString('rollback lost', $mockLogEntries[0]['message']);
        $this->assertStringNotContainsString('owner@example.org', $mockLogEntries[0]['message']);
    }

    public function testClearBlockedForOwnerThrowsWhenOwnerHasNoProfilesRow(): void
    {
        $this->mockRepo->method('findBlockClearTarget')->willReturn(null);
        $this->mockRepo->expects($this->never())->method('beginTransaction');
        $client = $this->removedClient();

        try {
            $this->manager->clearBlockedForOwner(46, 7, $client);
            $this->fail('Expected CarDatabaseException');
        } catch (CarDatabaseException $e) {
            $this->assertStringContainsString('owner 46 has no profiles row', $e->getMessage());
            $this->assertSame('The block could not be cleared. Please try again or contact support.', $e->getUserMessage());
        }
        $this->assertSame([], $client->calls);
    }

    public function testClearBlockedForOwnerThrowsAndRollsBackWhenTheProfileVanishesDuringTheCall(): void
    {
        $this->scriptClearBlock($this->blockedState(), null);
        $this->mockRepo->expects($this->once())->method('rollback');
        $this->mockRepo->expects($this->never())->method('commit');

        $this->expectException(CarDatabaseException::class);
        $this->manager->clearBlockedForOwner(42, 7, $this->removedClient());
    }

    // ------------------------------------------------------------------
    // clearOptedOutForOwner() — admin Clear Opt-out (#2357)
    // ------------------------------------------------------------------

    public function testClearOptedOutForOwnerClearsOnlyTheOptOutAndLogsOwnerRequested(): void
    {
        $this->mockRepo->expects($this->once())->method('setProfileOptedOut')->with(42, false)->willReturn(1);
        $this->mockRepo->expects($this->never())->method('clearProfileBlocked');
        $this->mockRepo->expects($this->never())->method('clearProfileBlockedIfAddress');
        $this->mockRepo->expects($this->never())->method('clearProfileBounced');
        $this->mockRepo->expects($this->never())->method('findByOwner');
        $this->mockRepo->expects($this->never())->method('updateCar');

        $changed = $this->manager->clearOptedOutForOwner(42, 7);

        $this->assertTrue($changed);
        $metadata = $this->assertOneFlagLogRowWithKeys(7, 42, 'email_opted_out', 1, 0, 'admin', ['owner_requested']);
        $this->assertSame(1, $metadata['owner_requested']);
        $this->assertArrayNotHasKey('cleared_at', $metadata, 'Only Clear Block records cleared_at');
    }

    public function testClearOptedOutForOwnerReturnsFalseAndLogsNothingWhenNotOptedOut(): void
    {
        $this->mockRepo->method('setProfileOptedOut')->willReturn(0);

        $this->assertFalse($this->manager->clearOptedOutForOwner(42, 7));
        $this->assertSame([], $this->flagLogRows);
    }

    #[Group('regression')]
    public function testClearOptedOutForOwnerNoProfileRowPutsDetailInMessageAndFriendlyTextInUserMessage(): void
    {
        $this->mockRepo->method('setProfileOptedOut')->willReturn(null);
        $this->mockRepo->expects($this->never())->method('findByOwner');

        try {
            $this->manager->clearOptedOutForOwner(46, 7);
            $this->fail('Expected CarDatabaseException');
        } catch (CarDatabaseException $e) {
            $this->assertStringContainsString('owner 46 has no profiles row', $e->getMessage());
            $this->assertStringContainsString('clearOptedOutForOwner', $e->getMessage());
            $this->assertSame(
                'The opt-out could not be cleared. Please try again or contact support.',
                $e->getUserMessage()
            );
        }
        $this->assertSame([], $this->flagLogRows);
    }

    // ------------------------------------------------------------------
    // clearSuppressedForOwnerByOwner() — owner self-service Resume (#1895, #2101)
    // ------------------------------------------------------------------

    /**
     * Resume clears the owner's opt-out and nothing else. A block and a
     * bounce are not the owner's to clear, so the block, its address, time
     * and cause stay as they were.
     */
    public function testClearSuppressedForOwnerByOwnerClearsOnlyTheOptOut(): void
    {
        $this->mockRepo->expects($this->once())->method('setProfileOptedOut')->with(42, false)->willReturn(1);
        $this->mockRepo->expects($this->never())->method('clearProfileBlocked');
        $this->mockRepo->expects($this->never())->method('clearProfileBounced');
        $this->mockRepo->expects($this->never())->method('findByOwner');
        $this->mockRepo->expects($this->never())->method('updateCar');

        $changed = $this->manager->clearSuppressedForOwnerByOwner(42);

        $this->assertTrue($changed);
        $this->assertOneFlagLogRow(42, 42, 'email_opted_out', 1, 0, 'owner');
    }

    public function testClearSuppressedForOwnerByOwnerReturnsFalseAndLogsNothingWhenNotOptedOut(): void
    {
        $this->mockRepo->method('setProfileOptedOut')->willReturn(0);

        $this->assertFalse($this->manager->clearSuppressedForOwnerByOwner(42));
        $this->assertSame([], $this->flagLogRows);
    }

    /**
     * The technical detail goes into getMessage() and the friendly text into
     * getUserMessage(). The method writes no logger() row: it runs inside the
     * caller's transaction, and a rollback would delete that row.
     */
    #[Group('regression')]
    public function testClearSuppressedForOwnerByOwnerNoProfileRowPutsDetailInMessageAndFriendlyTextInUserMessage(): void
    {
        $this->mockRepo->method('setProfileOptedOut')->willReturn(null);
        $this->mockRepo->expects($this->never())->method('findByOwner');

        try {
            $this->manager->clearSuppressedForOwnerByOwner(45);
            $this->fail('Expected CarDatabaseException');
        } catch (CarDatabaseException $e) {
            $this->assertStringContainsString('owner 45 has no profiles row', $e->getMessage());
            $this->assertStringContainsString('clearSuppressedForOwnerByOwner', $e->getMessage());
            $this->assertSame(
                'Verification emails could not be resumed. Please try again or contact support.',
                $e->getUserMessage()
            );
        }
        $this->assertSame([], $this->flagLogRows);
        global $mockLogEntries;
        $this->assertSame([], $mockLogEntries, 'No logger() row inside the caller transaction');
    }

    /**
     * persist() chains the repository's exception and keeps the friendly
     * text as the user message.
     */
    #[Group('regression')]
    public function testPersistChainsPreviousExceptionAndKeepsFriendlyUserMessage(): void
    {
        $cause = new \RuntimeException('deadlock found');
        $this->mockRepo->expects($this->once())->method('updateCar')->willThrowException($cause);

        try {
            $this->manager->markVerified((object) ['id' => 7, 'last_verified' => null]);
            $this->fail('Expected CarDatabaseException');
        } catch (CarDatabaseException $e) {
            $this->assertSame($cause, $e->getPrevious());
            $this->assertStringContainsString('car 7', $e->getMessage());
            $this->assertStringContainsString('deadlock found', $e->getMessage());
            $this->assertNotSame('', $e->getUserMessage());
            $this->assertStringNotContainsString('deadlock', $e->getUserMessage());
        }
    }

    /**
     * A throw from rollback() must not replace the real failure (#2393).
     */
    public function test_clearFlagsForConfirmedEmailChange_rollbackThrows_originalExceptionPropagates(): void
    {
        $this->mockRepo->method('clearFlagsForConfirmedEmailChange')
            ->willThrowException(new CarDatabaseException('original'));
        $this->mockRepo->expects($this->once())->method('rollback')
            ->willThrowException(new \RuntimeException('rb'));
        $this->mockRepo->expects($this->never())->method('commit');

        try {
            $this->manager->clearFlagsForConfirmedEmailChange(9, 42);
            $this->fail('Expected CarDatabaseException');
        } catch (CarDatabaseException $e) {
            $this->assertSame('original', $e->getMessage());
        }

        global $mockLogEntries;
        $this->assertCount(1, $mockLogEntries, 'The rollback failure must leave one log row');
        $this->assertSame(LogCategories::LOG_CATEGORY_DATABASE_ERROR, $mockLogEntries[0]['category']);
        $this->assertSame(42, $mockLogEntries[0]['user_id']);
        $this->assertStringContainsString('rollback failed', $mockLogEntries[0]['message']);
        $this->assertStringContainsString('rb', $mockLogEntries[0]['message']);
        $this->assertStringContainsString('userId=42', $mockLogEntries[0]['message']);
    }
}
