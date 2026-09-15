<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CarVerificationManager service class
 */
#[Group('fast')]
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

    protected function setUp(): void
    {
        $this->mockRepo = $this->createMock(CarRepository::class);
        $this->manager = new CarVerificationManager($this->mockRepo);
    }

    /**
     * Stub the #1883 owner-level profile flag as "row exists, not yet
     * suppressed" — the normal precondition for an opt-out.
     *
     * Every setSuppressedForOwner() test needs this: an unstubbed mock returns
     * null from findProfileEmailSuppressed(), which the method correctly reads
     * as "owner has no profiles row" and aborts on, failing car-fan-out tests
     * for a reason unrelated to what they assert.
     *
     * Deliberately a helper the fan-out tests call rather than a setUp()
     * default. A stub configured in setUp() cannot be overridden by a later
     * ->expects() on the same method — the first configuration wins — so tests
     * that assert on the profile flag itself must be free to configure these
     * two methods themselves, and simply do not call this.
     */
    private function stubProfileNotYetSuppressed(): void
    {
        $this->mockRepo->method('findProfileEmailSuppressed')->willReturn(0);
        $this->mockRepo->method('updateProfileEmailSuppressed')->willReturn(true);
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

    public function testSetBouncedSucceeds(): void
    {
        $this->mockRepo->expects($this->once())->method('updateEmailBounced')
            ->with(1, true, 'owner@example.com')->willReturn(true);

        $carData = (object) ['id' => 1, 'email_bounced' => 0, 'email_bounced_address' => null];
        $result = $this->manager->setBounced($carData, 'owner@example.com');
        $this->assertTrue($result);
        $this->assertSame(1, $carData->email_bounced);
        $this->assertSame('owner@example.com', $carData->email_bounced_address);
    }

    public function testSetBouncedThrowsCarDatabaseExceptionWhenRepositoryReturnsFalse(): void
    {
        $this->mockRepo->expects($this->once())->method('updateEmailBounced')->willReturn(false);
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'email_bounced' => 0, 'email_bounced_address' => null];
        $this->manager->setBounced($carData, 'owner@example.com');
    }

    public function testSetBouncedThrowsCarDatabaseExceptionWhenRepositoryThrows(): void
    {
        $this->mockRepo->expects($this->once())->method('updateEmailBounced')
            ->willThrowException(new \RuntimeException('DB connection lost'));
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'email_bounced' => 0, 'email_bounced_address' => null];
        $this->manager->setBounced($carData, 'owner@example.com');
    }

    public function testClearBouncedSucceeds(): void
    {
        $this->mockRepo->expects($this->once())->method('updateEmailBounced')
            ->with(1, false, null)->willReturn(true);

        $carData = (object) ['id' => 1, 'email_bounced' => 1, 'email_bounced_address' => 'owner@example.com'];
        $result = $this->manager->clearBounced($carData);
        $this->assertTrue($result);
        $this->assertSame(0, $carData->email_bounced);
        $this->assertNull($carData->email_bounced_address);
    }

    public function testClearBouncedThrowsCarDatabaseExceptionWhenRepositoryReturnsFalse(): void
    {
        $this->mockRepo->expects($this->once())->method('updateEmailBounced')->willReturn(false);
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'email_bounced' => 1, 'email_bounced_address' => 'owner@example.com'];
        $this->manager->clearBounced($carData);
    }

    public function testClearBouncedThrowsCarDatabaseExceptionWhenRepositoryThrows(): void
    {
        $this->mockRepo->expects($this->once())->method('updateEmailBounced')
            ->willThrowException(new \RuntimeException('DB connection lost'));
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'email_bounced' => 1, 'email_bounced_address' => 'owner@example.com'];
        $this->manager->clearBounced($carData);
    }

    public function testSetSuppressedSucceeds(): void
    {
        $this->mockRepo->expects($this->once())->method('updateEmailSuppressed')
            ->with(1, true)->willReturn(true);

        $carData = (object) ['id' => 1, 'email_suppressed' => 0];
        $result = $this->manager->setSuppressed($carData);
        $this->assertTrue($result);
        $this->assertSame(1, $carData->email_suppressed);
    }

    public function testSetSuppressedThrowsCarDatabaseExceptionWhenRepositoryReturnsFalse(): void
    {
        $this->mockRepo->expects($this->once())->method('updateEmailSuppressed')->willReturn(false);
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'email_suppressed' => 0];
        $this->manager->setSuppressed($carData);
    }

    public function testSetSuppressedThrowsCarDatabaseExceptionWhenRepositoryThrows(): void
    {
        $this->mockRepo->expects($this->once())->method('updateEmailSuppressed')
            ->willThrowException(new \RuntimeException('DB connection lost'));
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'email_suppressed' => 0];
        $this->manager->setSuppressed($carData);
    }

    public function testClearSuppressedSucceeds(): void
    {
        $this->mockRepo->expects($this->once())->method('updateEmailSuppressed')
            ->with(1, false)->willReturn(true);

        $carData = (object) ['id' => 1, 'email_suppressed' => 1];
        $result = $this->manager->clearSuppressed($carData);
        $this->assertTrue($result);
        $this->assertSame(0, $carData->email_suppressed);
    }

    public function testClearSuppressedThrowsCarDatabaseExceptionWhenRepositoryReturnsFalse(): void
    {
        $this->mockRepo->expects($this->once())->method('updateEmailSuppressed')->willReturn(false);
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'email_suppressed' => 1];
        $this->manager->clearSuppressed($carData);
    }

    public function testClearSuppressedThrowsCarDatabaseExceptionWhenRepositoryThrows(): void
    {
        $this->mockRepo->expects($this->once())->method('updateEmailSuppressed')
            ->willThrowException(new \RuntimeException('DB connection lost'));
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'email_suppressed' => 1];
        $this->manager->clearSuppressed($carData);
    }

    // ------------------------------------------------------------------
    // setSuppressedForOwner() — #1883 owner-level fan-out
    // ------------------------------------------------------------------

    public function testSetSuppressedForOwnerSkipsAlreadySuppressedAndReturnsOnlyChangedCars(): void
    {
        $ownerId = 5;
        $this->stubProfileNotYetSuppressed();

        $this->mockRepo->expects($this->once())->method('findByOwner')
            ->with($ownerId)
            ->willReturn([
                (object) ['id' => 1],
                (object) ['id' => 2],
                (object) ['id' => 3],
            ]);

        $this->mockRepo->method('findById')->willReturnMap([
            [1, (object) ['id' => 1, 'email_suppressed' => 0]],
            [2, (object) ['id' => 2, 'email_suppressed' => 1]], // already suppressed — skipped
            [3, (object) ['id' => 3, 'email_suppressed' => 0]],
        ]);

        // Only cars 1 and 3 should be updated — car 2 is already suppressed.
        $this->mockRepo->expects($this->exactly(2))->method('updateEmailSuppressed')
            ->willReturnCallback(function (int $carId, bool $suppressed): bool {
                $this->assertTrue($suppressed);
                $this->assertContains($carId, [1, 3], 'Only non-already-suppressed cars must be updated');
                return true;
            });

        $changed = $this->manager->setSuppressedForOwner($ownerId);

        $this->assertCount(2, $changed);
        $changedIds = array_map(static fn (object $car): int => (int) $car->id, $changed);
        sort($changedIds);
        $this->assertSame([1, 3], $changedIds);

        foreach ($changed as $car) {
            $this->assertSame(
                0,
                (int) $car->email_suppressed,
                'Returned rows are pre-change snapshots for the cars_hist audit row'
            );
        }
    }

    public function testSetSuppressedForOwnerReturnsEmptyArrayWhenAllCarsAlreadySuppressed(): void
    {
        $ownerId = 9;
        $this->stubProfileNotYetSuppressed();

        $this->mockRepo->method('findByOwner')->willReturn([(object) ['id' => 1]]);
        $this->mockRepo->method('findById')->willReturn((object) ['id' => 1, 'email_suppressed' => 1]);

        $this->mockRepo->expects($this->never())->method('updateEmailSuppressed');

        $changed = $this->manager->setSuppressedForOwner($ownerId);

        $this->assertSame([], $changed);
    }

    public function testSetSuppressedForOwnerSkipsCarsFindByIdCannotResolve(): void
    {
        $ownerId = 11;
        $this->stubProfileNotYetSuppressed();

        $this->mockRepo->method('findByOwner')->willReturn([
            (object) ['id' => 1],
            (object) ['id' => 2],
        ]);
        $this->mockRepo->method('findById')->willReturnMap([
            [1, null], // vanished between findByOwner() and findById()
            [2, (object) ['id' => 2, 'email_suppressed' => 0]],
        ]);

        $this->mockRepo->expects($this->once())->method('updateEmailSuppressed')
            ->with(2, true)->willReturn(true);

        $changed = $this->manager->setSuppressedForOwner($ownerId);

        $this->assertCount(1, $changed);
        $this->assertSame(2, $changed[0]->id);
    }

    /**
     * The returned rows are PRE-change snapshots, not the live mutated objects.
     *
     * setSuppressed() writes email_suppressed=1 onto the car object in place, and
     * verify_car.php feeds these returned rows to verifyHistoryFields() to build a
     * cars_hist audit row. A cars_hist row must record the OLD value (matching the
     * cars_update trigger's OLD.* convention), so a returned row carrying 1 would
     * write the post-change state into history — the audit trail would claim the
     * car was already suppressed before the opt-out that suppressed it.
     */
    public function testSetSuppressedForOwnerReturnsPreChangeSnapshotsNotMutatedObjects(): void
    {
        $this->stubProfileNotYetSuppressed();
        $this->mockRepo->method('findByOwner')->willReturn([(object) ['id' => 7]]);

        $live = (object) ['id' => 7, 'email_suppressed' => 0];
        $this->mockRepo->method('findById')->willReturn($live);
        $this->mockRepo->expects($this->once())->method('updateEmailSuppressed')
            ->with(7, true)->willReturn(true);

        $changed = $this->manager->setSuppressedForOwner(3);

        $this->assertCount(1, $changed);
        $this->assertSame(
            0,
            (int) $changed[0]->email_suppressed,
            'Returned row must carry the PRE-change value (0) for the cars_hist audit row'
        );
        $this->assertNotSame($live, $changed[0], 'Returned row must be a snapshot, not the mutated live object');
        $this->assertSame(1, (int) $live->email_suppressed, 'The live object is still mutated in place');
    }

    public function testSetSuppressedForOwnerOpensNoTransaction(): void
    {
        $this->stubProfileNotYetSuppressed();
        $this->mockRepo->method('findByOwner')->willReturn([(object) ['id' => 1]]);
        $this->mockRepo->method('findById')->willReturn((object) ['id' => 1, 'email_suppressed' => 0]);
        $this->mockRepo->method('updateEmailSuppressed')->willReturn(true);

        $this->mockRepo->expects($this->never())->method('beginTransaction');
        $this->mockRepo->expects($this->never())->method('commit');
        $this->mockRepo->expects($this->never())->method('rollback');

        $this->manager->setSuppressedForOwner(1);
    }

    public function testSetSuppressedForOwnerPropagatesCarDatabaseExceptionFromSetSuppressed(): void
    {
        $this->stubProfileNotYetSuppressed();
        $this->mockRepo->method('findByOwner')->willReturn([(object) ['id' => 1]]);
        $this->mockRepo->method('findById')->willReturn((object) ['id' => 1, 'email_suppressed' => 0]);
        $this->mockRepo->expects($this->once())->method('updateEmailSuppressed')->willReturn(false);

        $this->expectException(CarDatabaseException::class);

        $this->manager->setSuppressedForOwner(1);
    }

    public function testSetSuppressedForOwnerPropagatesCarDatabaseExceptionFromFindByOwner(): void
    {
        $this->stubProfileNotYetSuppressed();
        $this->mockRepo->expects($this->once())->method('findByOwner')
            ->willThrowException(new CarDatabaseException('lookup failed'));

        $this->expectException(CarDatabaseException::class);

        $this->manager->setSuppressedForOwner(1);
    }

    // ------------------------------------------------------------------
    // setSuppressedForOwner() — #1883 owner-level profiles.email_suppressed
    // ------------------------------------------------------------------

    /**
     * The profile flag is owner-level, so it is written exactly once per call
     * no matter how many cars the fan-out touches.
     */
    public function testSetSuppressedForOwnerWritesProfileFlagOnceRegardlessOfCarCount(): void
    {
        $ownerId = 42;
        $this->mockRepo->method('findProfileEmailSuppressed')->willReturn(0);

        $this->mockRepo->method('findByOwner')->willReturn([
            (object) ['id' => 1],
            (object) ['id' => 2],
            (object) ['id' => 3],
        ]);
        $this->mockRepo->method('findById')->willReturnMap([
            [1, (object) ['id' => 1, 'email_suppressed' => 0]],
            [2, (object) ['id' => 2, 'email_suppressed' => 0]],
            [3, (object) ['id' => 3, 'email_suppressed' => 0]],
        ]);
        $this->mockRepo->method('updateEmailSuppressed')->willReturn(true);

        $this->mockRepo->expects($this->once())->method('updateProfileEmailSuppressed')
            ->with($ownerId, true)->willReturn(true);

        $changed = $this->manager->setSuppressedForOwner($ownerId);

        $this->assertCount(3, $changed, 'All three cars must still be suppressed');
    }

    /**
     * The profile flag is independent of the per-car fan-out: an owner whose
     * cars are all already suppressed may still have a profile flag that was
     * never set (e.g. cars suppressed by the Brevo path before this column
     * existed), and the opt-out must bring it up to date.
     */
    public function testSetSuppressedForOwnerWritesProfileFlagEvenWhenNoCarNeededChanging(): void
    {
        $ownerId = 43;
        $this->mockRepo->method('findProfileEmailSuppressed')->willReturn(0);

        $this->mockRepo->method('findByOwner')->willReturn([(object) ['id' => 1]]);
        $this->mockRepo->method('findById')->willReturn((object) ['id' => 1, 'email_suppressed' => 1]);
        $this->mockRepo->expects($this->never())->method('updateEmailSuppressed');

        $this->mockRepo->expects($this->once())->method('updateProfileEmailSuppressed')
            ->with($ownerId, true)->willReturn(true);

        $this->assertSame(
            [],
            $this->manager->setSuppressedForOwner($ownerId),
            'No car changed, so the returned array is still empty'
        );
    }

    /**
     * Idempotency, matching the per-car loop's read-then-skip: a profile flag
     * already reading 1 is left alone rather than rewritten. This is not just
     * an optimisation — MySQL reports 0 affected rows for an UPDATE that
     * changes nothing, which the write path cannot distinguish from a missing
     * profiles row, so a blind rewrite would throw on a repeat opt-out.
     */
    public function testSetSuppressedForOwnerSkipsProfileWriteWhenAlreadySuppressed(): void
    {
        $ownerId = 44;

        $this->mockRepo->method('findByOwner')->willReturn([(object) ['id' => 1]]);
        $this->mockRepo->method('findById')->willReturn((object) ['id' => 1, 'email_suppressed' => 0]);
        $this->mockRepo->method('updateEmailSuppressed')->willReturn(true);

        $this->mockRepo->expects($this->once())->method('findProfileEmailSuppressed')
            ->with($ownerId)->willReturn(1);
        $this->mockRepo->expects($this->never())->method('updateProfileEmailSuppressed');

        $changed = $this->manager->setSuppressedForOwner($ownerId);

        $this->assertCount(1, $changed, 'The car fan-out still runs even when the profile flag is already set');
    }

    /**
     * An owner with no profiles row has nowhere to record the opt-out, so the
     * whole operation fails rather than suppressing cars and losing the
     * decision. The abort happens BEFORE the fan-out, so no car is touched.
     */
    public function testSetSuppressedForOwnerThrowsAndTouchesNoCarWhenOwnerHasNoProfilesRow(): void
    {
        $ownerId = 45;

        $this->mockRepo->method('findProfileEmailSuppressed')->with($ownerId)->willReturn(null);

        $this->mockRepo->expects($this->never())->method('findByOwner');
        $this->mockRepo->expects($this->never())->method('updateEmailSuppressed');
        $this->mockRepo->expects($this->never())->method('updateProfileEmailSuppressed');

        $this->expectException(CarDatabaseException::class);

        $this->manager->setSuppressedForOwner($ownerId);
    }

    /**
     * A profiles UPDATE reporting 0 affected rows, despite the row having read
     * as unsuppressed moments earlier, means the row vanished mid-operation.
     * That must throw rather than silently leaving the opt-out unrecorded.
     */
    public function testSetSuppressedForOwnerThrowsWhenProfileUpdateAffectsNoRows(): void
    {
        $ownerId = 46;

        $this->mockRepo->method('findProfileEmailSuppressed')->with($ownerId)->willReturn(0);
        $this->mockRepo->expects($this->once())->method('updateProfileEmailSuppressed')
            ->with($ownerId, true)->willReturn(false);

        $this->mockRepo->expects($this->never())->method('findByOwner');
        $this->mockRepo->expects($this->never())->method('updateEmailSuppressed');

        $this->expectException(CarDatabaseException::class);

        $this->manager->setSuppressedForOwner($ownerId);
    }

    /**
     * A failure reading the profile flag propagates rather than being treated
     * as "no profiles row" — a DB error and a missing row are different things.
     */
    public function testSetSuppressedForOwnerPropagatesCarDatabaseExceptionFromProfileRead(): void
    {
        $this->mockRepo->expects($this->once())->method('findProfileEmailSuppressed')
            ->willThrowException(new CarDatabaseException('profile read failed'));
        $this->mockRepo->expects($this->never())->method('findByOwner');

        $this->expectException(CarDatabaseException::class);

        $this->manager->setSuppressedForOwner(47);
    }
}
