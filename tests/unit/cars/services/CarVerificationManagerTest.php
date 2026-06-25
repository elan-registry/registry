<?php

declare(strict_types=1);

use ElanRegistry\Car\CarVerificationManager;
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
    private CarVerificationManager $manager;
    private DB $db;

    protected function setUp(): void
    {
        $this->manager = new CarVerificationManager();
        $this->db = DB::getInstance();
    }

    public function testSetVerificationCodeSucceeds(): void
    {
        $carData = (object) ['id' => 1, 'vericode' => null];
        $result = $this->manager->setVerificationCode($carData, 'VERIFY12345678', $this->db);
        $this->assertTrue($result);
        $this->assertEquals('VERIFY12345678', $carData->vericode);
    }

    public function testSetVerificationCodeRejectsShortCode(): void
    {
        $this->expectException(CarValidationException::class);

        $carData = (object) ['id' => 1, 'vericode' => null];
        $this->manager->setVerificationCode($carData, 'SHORT', $this->db);
    }

    public function testSetVerificationCodeRejectsEmptyCode(): void
    {
        $this->expectException(CarValidationException::class);

        $carData = (object) ['id' => 1, 'vericode' => null];
        $this->manager->setVerificationCode($carData, '', $this->db);
    }

    public function testMarkVerifiedSucceeds(): void
    {
        $carData = (object) ['id' => 1, 'last_verified' => null];
        $result = $this->manager->markVerified($carData, $this->db);
        $this->assertTrue($result);
        $this->assertNotNull($carData->last_verified);
    }

    public function testMarkSoldSucceeds(): void
    {
        $carData = (object) ['id' => 1, 'solddate' => null];
        $result = $this->manager->markSold($carData, '2024-06-15', $this->db);
        $this->assertTrue($result);
        $this->assertEquals('2024-06-15', $carData->solddate);
    }

    public function testMarkSoldDefaultsToToday(): void
    {
        $carData = (object) ['id' => 1, 'solddate' => null];
        $result = $this->manager->markSold($carData, null, $this->db);
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
        $this->expectException(CarValidationException::class);

        $carData = (object) ['id' => 1, 'solddate' => null];
        $this->manager->markSold($carData, $date, $this->db);
    }

    public function testMarkSoldAcceptsLeapDay(): void
    {
        $carData = (object) ['id' => 1, 'solddate' => null];
        $result = $this->manager->markSold($carData, '2024-02-29', $this->db);
        $this->assertTrue($result);
        $this->assertEquals('2024-02-29', $carData->solddate);
    }
}
