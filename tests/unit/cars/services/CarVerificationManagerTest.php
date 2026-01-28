<?php

declare(strict_types=1);

use ElanRegistry\Car\CarVerificationManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CarVerificationManager service class
 *
 * @group fast
 */
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

    public function testMarkSoldRejectsInvalidDate(): void
    {
        $this->expectException(CarValidationException::class);

        $carData = (object) ['id' => 1, 'solddate' => null];
        $this->manager->markSold($carData, 'not-a-date', $this->db);
    }
}
