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

    public function testSetVerificationCodeSucceeds(): void
    {
        $this->mockRepo->expects($this->once())->method('updateVerificationCode')->willReturn(true);

        $carData = (object) ['id' => 1, 'vericode' => null];
        $result = $this->manager->setVerificationCode($carData, 'VERIFY12345678');
        $this->assertTrue($result);
        $this->assertEquals('VERIFY12345678', $carData->vericode);
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
        $this->mockRepo->expects($this->once())->method('updateVerificationCode')->willReturn(false);
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'vericode' => null];
        $this->manager->setVerificationCode($carData, 'VERIFY12345678');
    }

    public function testMarkVerifiedSucceeds(): void
    {
        $this->mockRepo->expects($this->once())->method('updateLastVerified')->willReturn(true);

        $carData = (object) ['id' => 1, 'last_verified' => null];
        $result = $this->manager->markVerified($carData);
        $this->assertTrue($result);
        $this->assertNotNull($carData->last_verified);
    }

    public function testMarkVerifiedThrowsCarDatabaseExceptionWhenRepositoryReturnsFalse(): void
    {
        $this->mockRepo->expects($this->once())->method('updateLastVerified')->willReturn(false);
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'last_verified' => null];
        $this->manager->markVerified($carData);
    }

    public function testMarkSoldSucceeds(): void
    {
        $this->mockRepo->expects($this->once())->method('updateSoldDate')->willReturn(true);

        $carData = (object) ['id' => 1, 'solddate' => null];
        $result = $this->manager->markSold($carData, '2024-06-15');
        $this->assertTrue($result);
        $this->assertEquals('2024-06-15', $carData->solddate);
    }

    public function testMarkSoldDefaultsToToday(): void
    {
        $this->mockRepo->expects($this->once())->method('updateSoldDate')->willReturn(true);

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
        $this->mockRepo->expects($this->never())->method('updateSoldDate');
        $this->expectException(CarValidationException::class);

        $carData = (object) ['id' => 1, 'solddate' => null];
        $this->manager->markSold($carData, $date);
    }

    public function testMarkSoldAcceptsLeapDay(): void
    {
        $this->mockRepo->expects($this->once())->method('updateSoldDate')->willReturn(true);

        $carData = (object) ['id' => 1, 'solddate' => null];
        $result = $this->manager->markSold($carData, '2024-02-29');
        $this->assertTrue($result);
        $this->assertEquals('2024-02-29', $carData->solddate);
    }

    public function testMarkSoldThrowsCarDatabaseExceptionWhenRepositoryReturnsFalse(): void
    {
        $this->mockRepo->expects($this->once())->method('updateSoldDate')->willReturn(false);
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'solddate' => null];
        $this->manager->markSold($carData, '2024-06-15');
    }

    public function testSetVerificationCodeThrowsCarDatabaseExceptionWhenRepositoryThrows(): void
    {
        $this->mockRepo->expects($this->once())->method('updateVerificationCode')
            ->willThrowException(new \RuntimeException('DB connection lost'));
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'vericode' => null];
        $this->manager->setVerificationCode($carData, 'VERIFY12345678');
    }

    public function testMarkVerifiedThrowsCarDatabaseExceptionWhenRepositoryThrows(): void
    {
        $this->mockRepo->expects($this->once())->method('updateLastVerified')
            ->willThrowException(new \RuntimeException('DB connection lost'));
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 1, 'last_verified' => null];
        $this->manager->markVerified($carData);
    }

    public function testMarkSoldThrowsCarDatabaseExceptionWhenRepositoryThrows(): void
    {
        $this->mockRepo->expects($this->once())->method('updateSoldDate')
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
}
