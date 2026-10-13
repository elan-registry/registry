<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\DeliverabilityFlagLog;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

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

    /** Stub findOwnerOfCar() with a live individual owner. */
    private function stubOwnerOfCar(int $carId = 11, int $userId = 42, string $username = 'realowner'): void
    {
        $this->mockRepo->method('findOwnerOfCar')->with($carId)
            ->willReturn((object) ['user_id' => $userId, 'username' => $username]);
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
    // Event writers: setBounced() / setSuppressed()
    // ------------------------------------------------------------------

    public function testSetBouncedWritesOneProfileRowForTheCarsOwnerAndLogsTheChange(): void
    {
        $this->stubOwnerOfCar();
        $this->mockRepo->expects($this->once())->method('setProfileBounced')
            ->with(42, 'owner@example.com', '2026-09-01 10:00:00')->willReturn(0);
        $this->mockRepo->expects($this->never())->method('updateCar');
        $this->mockRepo->expects($this->never())->method('findByOwner');

        $changed = $this->manager->setBounced(11, 'owner@example.com', '2026-09-01 10:00:00', 'webhook');

        $this->assertTrue($changed);
        $this->assertOneFlagLogRow(0, 42, 'email_bounced', 0, 1, 'webhook');
    }

    /** A repeat bounce on the same address changes nothing, so it logs nothing. */
    public function testSetBouncedReturnsFalseAndLogsNothingWhenAlreadyBounced(): void
    {
        $this->stubOwnerOfCar();
        $this->mockRepo->method('setProfileBounced')->willReturn(1);

        $this->assertFalse($this->manager->setBounced(11, 'owner@example.com', '2026-09-01 10:00:00', 'webhook'));
        $this->assertSame([], $this->flagLogRows);
    }

    public function testSetBouncedPassesTheReconciliationSourceToTheAuditRow(): void
    {
        $this->stubOwnerOfCar();
        $this->mockRepo->method('setProfileBounced')->willReturn(0);

        $this->manager->setBounced(11, 'owner@example.com', '2026-09-01 10:00:00', 'reconciliation');

        $this->assertOneFlagLogRow(0, 42, 'email_bounced', 0, 1, 'reconciliation');
    }

    /**
     * @return array<string, array{?object, string}>
     */
    public static function unwritableEventOwnerProvider(): array
    {
        return [
            'car does not exist' => [null, 'the car does not exist'],
            'car has no owner' => [(object) ['user_id' => null, 'username' => null], 'the car has no owner'],
            'owner account deleted' => [(object) ['user_id' => 42, 'username' => null], 'the owner account does not exist'],
            'noowner system account' => [(object) ['user_id' => 3, 'username' => 'noowner'], 'noowner system account'],
        ];
    }

    #[DataProvider('unwritableEventOwnerProvider')]
    public function testSetBouncedSkipsAndLogsWhenThereIsNoIndividualOwner(?object $owner, string $why): void
    {
        $this->mockRepo->method('findOwnerOfCar')->willReturn($owner);
        $this->mockRepo->expects($this->never())->method('setProfileBounced');

        $this->assertFalse($this->manager->setBounced(11, 'owner@example.com', '2026-09-01 10:00:00', 'webhook'));

        global $mockLogEntries;
        $this->assertCount(1, $mockLogEntries, 'A skipped event must leave one log row so the cause can be found');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, $mockLogEntries[0]['category']);
        $this->assertStringContainsString($why, $mockLogEntries[0]['message']);
        $this->assertStringNotContainsString('@', $mockLogEntries[0]['message']);
        $this->assertSame([], $this->flagLogRows);
    }

    /** Brevo does not retry, so an event for an owner with no profile is logged and skipped, not thrown. */
    public function testSetBouncedSkipsAndLogsWhenTheOwnerHasNoProfileRow(): void
    {
        $this->stubOwnerOfCar();
        $this->mockRepo->method('setProfileBounced')->willReturn(null);

        $this->assertFalse($this->manager->setBounced(11, 'owner@example.com', '2026-09-01 10:00:00', 'webhook'));

        global $mockLogEntries;
        $this->assertCount(1, $mockLogEntries);
        $this->assertStringContainsString('owner 42 has no profiles row', $mockLogEntries[0]['message']);
        $this->assertSame([], $this->flagLogRows);
    }

    public function testSetBouncedRejectsAnUnknownSourceBeforeAnyRead(): void
    {
        $this->mockRepo->expects($this->never())->method('findOwnerOfCar');
        $this->mockRepo->expects($this->never())->method('setProfileBounced');

        $this->expectException(\InvalidArgumentException::class);
        $this->manager->setBounced(11, 'owner@example.com', '2026-09-01 10:00:00', 'bogus');
    }

    public function testSetBouncedPropagatesCarDatabaseExceptionFromTheProfileWrite(): void
    {
        $this->stubOwnerOfCar();
        $this->mockRepo->method('setProfileBounced')->willThrowException(new CarDatabaseException('lock timeout'));

        $this->expectException(CarDatabaseException::class);
        $this->manager->setBounced(11, 'owner@example.com', '2026-09-01 10:00:00', 'webhook');
    }

    public function testSetBouncedPropagatesCarValidationExceptionForAnEmptyAddress(): void
    {
        $this->stubOwnerOfCar();
        $this->mockRepo->method('setProfileBounced')->willThrowException(new CarValidationException('address required'));

        $this->expectException(CarValidationException::class);
        $this->manager->setBounced(11, '', '2026-09-01 10:00:00', 'webhook');
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
    public function testSetSuppressedWritesOneProfileRowWithTheCauseAndLogsTheChange(string $cause): void
    {
        $this->stubOwnerOfCar();
        $this->mockRepo->expects($this->once())->method('setProfileBlocked')
            ->with(42, 'owner@example.com', $cause, '2026-09-01 10:00:00')->willReturn(0);
        $this->mockRepo->expects($this->never())->method('updateCar');

        $changed = $this->manager->setSuppressed(11, 'owner@example.com', $cause, '2026-09-01 10:00:00', 'import');

        $this->assertTrue($changed);
        $this->assertOneFlagLogRow(0, 42, 'email_blocked', 0, 1, 'import');
    }

    public function testSetSuppressedReturnsFalseAndLogsNothingWhenAlreadyBlocked(): void
    {
        $this->stubOwnerOfCar();
        $this->mockRepo->method('setProfileBlocked')->willReturn(1);

        $this->assertFalse($this->manager->setSuppressed(11, 'owner@example.com', 'spam', '2026-09-01 10:00:00', 'webhook'));
        $this->assertSame([], $this->flagLogRows);
    }

    #[DataProvider('unwritableEventOwnerProvider')]
    public function testSetSuppressedSkipsAndLogsWhenThereIsNoIndividualOwner(?object $owner, string $why): void
    {
        $this->mockRepo->method('findOwnerOfCar')->willReturn($owner);
        $this->mockRepo->expects($this->never())->method('setProfileBlocked');

        $this->assertFalse($this->manager->setSuppressed(11, 'owner@example.com', 'spam', '2026-09-01 10:00:00', 'webhook'));

        global $mockLogEntries;
        $this->assertCount(1, $mockLogEntries);
        $this->assertStringContainsString($why, $mockLogEntries[0]['message']);
    }

    public function testSetSuppressedSkipsAndLogsWhenTheOwnerHasNoProfileRow(): void
    {
        $this->stubOwnerOfCar();
        $this->mockRepo->method('setProfileBlocked')->willReturn(null);

        $this->assertFalse($this->manager->setSuppressed(11, 'owner@example.com', 'spam', '2026-09-01 10:00:00', 'webhook'));

        global $mockLogEntries;
        $this->assertStringContainsString('owner 42 has no profiles row', $mockLogEntries[0]['message']);
        $this->assertSame([], $this->flagLogRows);
    }

    public function testSetSuppressedRejectsAnUnknownSourceBeforeAnyRead(): void
    {
        $this->mockRepo->expects($this->never())->method('findOwnerOfCar');
        $this->mockRepo->expects($this->never())->method('setProfileBlocked');

        $this->expectException(\InvalidArgumentException::class);
        $this->manager->setSuppressed(11, 'owner@example.com', 'spam', '2026-09-01 10:00:00', 'bogus');
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
    // clearSuppressedForOwner() — admin Clear Suppression (interim until #2357)
    // ------------------------------------------------------------------

    public function testClearSuppressedForOwnerClearsBlockAndOptOutOnTheProfileAndNothingOnCars(): void
    {
        $this->mockRepo->expects($this->once())->method('clearProfileBlocked')->with(42)->willReturn(1);
        $this->mockRepo->expects($this->once())->method('setProfileOptedOut')->with(42, false)->willReturn(1);
        $this->mockRepo->expects($this->never())->method('findByOwner');
        $this->mockRepo->expects($this->never())->method('updateCar');
        // The bounce is not a suppression: Clear Suppression leaves it.
        $this->mockRepo->expects($this->never())->method('clearProfileBounced');

        $changed = $this->manager->clearSuppressedForOwner(42, 7);

        $this->assertTrue($changed);
        $this->assertCount(2, $this->flagLogRows, 'One audit row per flag that changed');
        $this->assertSame(
            [
                ['owner_id' => 42, 'flag' => 'email_blocked', 'old' => 1, 'new' => 0, 'source' => 'admin'],
                ['owner_id' => 42, 'flag' => 'email_opted_out', 'old' => 1, 'new' => 0, 'source' => 'admin'],
            ],
            array_column($this->flagLogRows, 'metadata')
        );
        $this->assertSame([7, 7], array_column($this->flagLogRows, 'userId'));
    }

    public function testClearSuppressedForOwnerLogsOnlyTheFlagThatChanged(): void
    {
        $this->mockRepo->method('clearProfileBlocked')->willReturn(0);
        $this->mockRepo->method('setProfileOptedOut')->willReturn(1);

        $this->assertTrue($this->manager->clearSuppressedForOwner(42, 7));
        $this->assertOneFlagLogRow(7, 42, 'email_opted_out', 1, 0, 'admin');
    }

    public function testClearSuppressedForOwnerReturnsFalseAndLogsNothingWhenNothingWasSet(): void
    {
        $this->mockRepo->method('clearProfileBlocked')->willReturn(0);
        $this->mockRepo->method('setProfileOptedOut')->willReturn(0);

        $this->assertFalse($this->manager->clearSuppressedForOwner(42, 7));
        $this->assertSame([], $this->flagLogRows);
    }

    #[Group('regression')]
    public function testClearSuppressedForOwnerNoProfileRowPutsDetailInMessageAndFriendlyTextInUserMessage(): void
    {
        $this->mockRepo->method('clearProfileBlocked')->willReturn(null);
        $this->mockRepo->expects($this->never())->method('findByOwner');

        try {
            $this->manager->clearSuppressedForOwner(46, 7);
            $this->fail('Expected CarDatabaseException');
        } catch (CarDatabaseException $e) {
            $this->assertStringContainsString('owner 46 has no profiles row', $e->getMessage());
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
}
