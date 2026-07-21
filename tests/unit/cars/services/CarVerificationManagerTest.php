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
}
