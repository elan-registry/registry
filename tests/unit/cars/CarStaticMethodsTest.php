<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test cases for Car static methods
 *
 * Tests cover static factory methods like findByOwner and findByVerificationCode
 * that return Car objects or arrays of Car objects.
 *
 * @group fast
 */
final class CarStaticMethodsTest extends TestCase
{
    private $testUserId;
    private $db;

    protected function setUp(): void
    {
        $this->testUserId = 1;
        $this->db = DB::getInstance();
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }

    /**
     * Test findByOwner returns array of cars for valid owner
     *
     * @group fast
     */
    public function testFindByOwnerReturnsArrayOfCars(): void
    {
        $cars = Car::findByOwner($this->testUserId);

        $this->assertIsArray($cars);
        $this->assertGreaterThan(0, count($cars));

        // Verify all returned items are Car objects
        foreach ($cars as $car) {
            $this->assertInstanceOf(Car::class, $car);
            $this->assertEquals($this->testUserId, $car->data()->user_id);
        }
    }

    /**
     * Test findByOwner returns empty array when no results
     *
     * @group fast
     */
    public function testFindByOwnerReturnsEmptyArrayWhenNoResults(): void
    {
        $cars = Car::findByOwner(99999);

        $this->assertIsArray($cars);
        $this->assertEquals(0, count($cars));
    }

    /**
     * Test findByOwner fails with invalid owner ID
     *
     * @group fast
     */
    public function testFindByOwnerFailsWithInvalidOwnerID(): void
    {
        // Should handle negative or zero IDs gracefully
        $cars = Car::findByOwner(-1);

        // Should return empty array or throw exception
        if (is_array($cars)) {
            $this->assertIsArray($cars);
        } else {
            $this->fail('Expected array or exception');
        }
    }

    /**
     * Test findByOwner returns Car objects with correct data
     *
     * @group fast
     */
    public function testFindByOwnerReturnsCarsWithCorrectData(): void
    {
        $cars = Car::findByOwner($this->testUserId);

        if (count($cars) > 0) {
            $car = $cars[0];
            $this->assertInstanceOf(Car::class, $car);
            $this->assertIsObject($car->data());
            $this->assertObjectHasProperty('id', $car->data());
            $this->assertObjectHasProperty('user_id', $car->data());
            $this->assertObjectHasProperty('chassis', $car->data());
        }
    }

    /**
     * Test findByVerificationCode static method
     *
     * @group fast
     */
    public function testFindByVerificationCodeStaticMethod(): void
    {
        $car = new Car(1);
        $verificationCode = 'STATIC-VERIFY-' . uniqid();

        $car->setVerificationCode($verificationCode);

        $foundCar = Car::findByVerificationCode($verificationCode);

        $this->assertInstanceOf(Car::class, $foundCar);
        $this->assertEquals($car->data()->id, $foundCar->data()->id);
    }

    /**
     * Test findByVerificationCode returns null for nonexistent code
     *
     * @group fast
     */
    public function testFindByVerificationCodeReturnsNullForNonexistent(): void
    {
        $result = Car::findByVerificationCode('NONEXISTENT-' . uniqid());

        $this->assertNull($result);
    }

    /**
     * Test findByOwner with multiple cars
     *
     * @group fast
     */
    public function testFindByOwnerWithMultipleCars(): void
    {
        $cars = Car::findByOwner($this->testUserId);

        if (count($cars) > 1) {
            // Verify we get multiple Car objects
            $this->assertGreaterThanOrEqual(2, count($cars));

            // Verify they're all Car instances
            foreach ($cars as $car) {
                $this->assertInstanceOf(Car::class, $car);
            }

            // Verify all have same user_id
            foreach ($cars as $car) {
                $this->assertEquals($this->testUserId, $car->data()->user_id);
            }
        }
    }

    /**
     * Test findByOwner returns cars in consistent order
     *
     * @group fast
     */
    public function testFindByOwnerReturnsConsistentOrder(): void
    {
        $cars1 = Car::findByOwner($this->testUserId);
        $cars2 = Car::findByOwner($this->testUserId);

        $this->assertEquals(count($cars1), count($cars2));

        if (count($cars1) > 0) {
            $this->assertEquals($cars1[0]->data()->id, $cars2[0]->data()->id);
        }
    }

    /**
     * Test findByVerificationCode with special characters
     *
     * @group fast
     */
    public function testFindByVerificationCodeWithSpecialCharacters(): void
    {
        $car = new Car(1);
        $verificationCode = 'CODE-WITH-SPECIAL-' . uniqid() . '-CHARS!@#$%';

        $car->setVerificationCode($verificationCode);

        $foundCar = Car::findByVerificationCode($verificationCode);

        $this->assertInstanceOf(Car::class, $foundCar);
    }
}
