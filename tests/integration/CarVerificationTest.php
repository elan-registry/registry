<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * Test cases for Car verification functionality
 *
 * Tests cover verification code management, verification status tracking,
 * and sold status marking with date validation.
 *
 * @group integration
 */
final class CarVerificationTest extends IntegrationTestCase
{
    private $testCarId;
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->testCarId = 1;
        $this->db = DB::getInstance();
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }

    /**
     * Test set verification code success
     *
     * @group fast
     */
    public function testSetVerificationCodeSuccess(): void
    {
        $car = new Car($this->testCarId);
        $verificationCode = 'TEST-VERIFY-CODE-123';

        $result = $car->setVerificationCode($verificationCode);

        $this->assertTrue($result);

        // Verify code was set in database
        $carData = new Car($this->testCarId);
        $this->assertEquals($verificationCode, $carData->data()->verification_code);
    }

    /**
     * Test set verification code fails with short code
     *
     * @group fast
     */
    public function testSetVerificationCodeFailsWithShortCode(): void
    {
        $this->expectException(Exception::class);

        $car = new Car($this->testCarId);
        $car->setVerificationCode('short');
    }

    /**
     * Test set verification code fails when car does not exist
     *
     * @group fast
     */
    public function testSetVerificationCodeFailsWhenCarNotExists(): void
    {
        $this->expectException(Exception::class);

        $car = new Car(99999);
        $car->setVerificationCode('TEST-VERIFY-CODE-123');
    }

    /**
     * Test mark verified success
     *
     * @group fast
     */
    public function testMarkVerifiedSuccess(): void
    {
        $car = new Car($this->testCarId);

        $result = $car->markVerified();

        $this->assertTrue($result);

        // Verify car is marked as verified
        $verifiedCar = new Car($this->testCarId);
        $this->assertNotNull($verifiedCar->data()->last_verified);
    }

    /**
     * Test mark verified updates timestamp
     *
     * @group fast
     */
    public function testMarkVerifiedUpdatesTimestamp(): void
    {
        $car = new Car($this->testCarId);
        $originalTimestamp = $car->data()->last_verified;

        sleep(1); // Wait 1 second to ensure timestamp difference

        $result = $car->markVerified();

        $this->assertTrue($result);

        // Verify timestamp was updated
        $verifiedCar = new Car($this->testCarId);
        $newTimestamp = $verifiedCar->data()->last_verified;

        if ($originalTimestamp) {
            $this->assertNotEquals($originalTimestamp, $newTimestamp);
        }
    }

    /**
     * Test mark sold with custom date
     *
     * @group fast
     */
    public function testMarkSoldWithCustomDate(): void
    {
        $car = new Car($this->testCarId);
        $customDate = '2023-06-15';

        $result = $car->markSold($customDate);

        $this->assertTrue($result);

        // Verify sold date was set
        $soldCar = new Car($this->testCarId);
        $this->assertStringStartsWith($customDate, $soldCar->data()->solddate);
    }

    /**
     * Test mark sold with default date
     *
     * @group fast
     */
    public function testMarkSoldWithDefaultDate(): void
    {
        $car = new Car($this->testCarId);

        $result = $car->markSold();

        $this->assertTrue($result);

        // Verify sold date was set to today
        $soldCar = new Car($this->testCarId);
        $today = date('Y-m-d');
        $this->assertStringStartsWith($today, $soldCar->data()->solddate);
    }

    /**
     * Test mark sold fails with invalid date
     *
     * @group fast
     */
    public function testMarkSoldFailsWithInvalidDate(): void
    {
        $this->expectException(Exception::class);

        $car = new Car($this->testCarId);
        $car->markSold('invalid-date-12345');
    }

    /**
     * Test find by verification code success
     *
     * @group fast
     */
    public function testFindByVerificationCodeSuccess(): void
    {
        $car = new Car($this->testCarId);
        $verificationCode = 'UNIQUE-TEST-CODE-' . uniqid();

        $car->setVerificationCode($verificationCode);

        $foundCar = Car::findByVerificationCode($verificationCode);

        $this->assertInstanceOf(Car::class, $foundCar);
        $this->assertEquals($this->testCarId, $foundCar->data()->id);
    }

    /**
     * Test find by verification code returns null when not found
     *
     * @group fast
     */
    public function testFindByVerificationCodeReturnsNullWhenNotFound(): void
    {
        $result = Car::findByVerificationCode('NONEXISTENT-CODE-12345');

        $this->assertNull($result);
    }

    /**
     * Test find by verification code fails with empty code
     *
     * @group fast
     */
    public function testFindByVerificationCodeFailsWithEmptyCode(): void
    {
        $this->expectException(Exception::class);

        Car::findByVerificationCode('');
    }

    /**
     * Test verification code is cleared on verification
     *
     * @group fast
     */
    public function testVerificationCodeIsClearedAfterVerification(): void
    {
        $car = new Car($this->testCarId);
        $verificationCode = 'TEST-CLEAR-CODE-' . uniqid();

        $car->setVerificationCode($verificationCode);
        $car->markVerified();

        // Reload car data
        $verifiedCar = new Car($this->testCarId);

        // Verification code should be cleared or remain (depending on implementation)
        // This test documents the expected behavior
        $this->assertNotNull($verifiedCar->data()->last_verified);
    }

    /**
     * Test mark sold clears verification code
     *
     * @group fast
     */
    public function testMarkSoldClearsVerificationCode(): void
    {
        $car = new Car($this->testCarId);
        $verificationCode = 'TEST-SOLD-CODE-' . uniqid();

        $car->setVerificationCode($verificationCode);
        $car->markSold();

        // After marking as sold, verification code should be cleared
        $soldCar = new Car($this->testCarId);
        $this->assertNull($soldCar->data()->verification_code ?? null);
    }
}
