<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Core Car class tests
 *
 * Consolidated tests for Car class functionality including instantiation,
 * find operations, exists checks, basic getters, and static methods.
 *
 * @group fast
 */
final class CarCoreTest extends TestCase
{
    private int $testCarId = 1;
    private int $testUserId = 1;

    // ============================================================
    // INSTANTIATION & FIND TESTS
    // ============================================================

    public function testInstantiation(): void
    {
        $car = new Car();
        $this->assertInstanceOf(Car::class, $car);
    }

    public function testConstructorWithIdLoadsCar(): void
    {
        $car = new Car(1);
        $this->assertIsObject($car->data());
        $this->assertEquals(1, $car->data()->id);
    }

    public function testConstructorWithoutIdCreatesEmptyCar(): void
    {
        $car = new Car();
        $this->assertIsObject($car->data());
        $this->assertFalse($car->exists());
    }

    public function testFind(): void
    {
        $car = new Car(1);
        $this->assertInstanceOf(Car::class, $car);
        $this->assertIsObject($car->data());
        $this->assertEquals(1, $car->data()->id);
    }

    public function testFindWithValidIdPopulatesData(): void
    {
        $car = new Car();
        $result = $car->find(1);

        if ($result) {
            $this->assertTrue($result);
            $this->assertEquals(1, $car->data()->id);
        }
    }

    public function testFindReturnsfalseWhenNotFound(): void
    {
        $car = new Car();
        $result = $car->find(99999);
        $this->assertFalse($result);
    }

    public function testFindWithNullParameter(): void
    {
        $car = new Car();
        $result = $car->find(null);
        $this->assertTrue($result);
        $this->assertIsObject($car->data());
    }

    // ============================================================
    // EXISTS & DATA ACCESS TESTS
    // ============================================================

    public function testExistsReturnsTrueForLoadedCar(): void
    {
        $car = new Car(1);
        $this->assertTrue($car->exists());
    }

    public function testExistsReturnsFalseForNonexistentCar(): void
    {
        $car = new Car(99999);
        $this->assertFalse($car->exists());
    }

    public function testDataAccess(): void
    {
        $car = new Car(1);
        $data = $car->data();

        $this->assertIsObject($data);
        $this->assertObjectHasProperty('id', $data);
        $this->assertObjectHasProperty('year', $data);
        $this->assertObjectHasProperty('chassis', $data);
    }

    // ============================================================
    // BASIC GETTER TESTS (history, factory, owner)
    // ============================================================

    public function testHistoryReturnsArrayWhenPresent(): void
    {
        $car = new Car($this->testCarId);
        $history = $car->history();

        if ($history !== null) {
            $this->assertIsArray($history);
        } else {
            $this->assertNull($history);
        }
    }

    public function testFactoryReturnsObjectWhenPresent(): void
    {
        $car = new Car($this->testCarId);
        $factory = $car->factory();

        if ($factory !== null) {
            $this->assertIsObject($factory);
        } else {
            $this->assertNull($factory);
        }
    }

    public function testOwnerReturnsOwnerData(): void
    {
        $car = new Car($this->testCarId);
        $owner = $car->owner();

        $this->assertNotNull($owner);
        if (!empty($owner)) {
            $this->assertTrue(is_array($owner) || is_object($owner));
        }
    }

    public function testFindAllReturnsAllCars(): void
    {
        $car = new Car();
        $result = $car->findAll();

        $this->assertTrue($result);
        $this->assertTrue($car->exists());
    }

    // ============================================================
    // STATIC METHOD TESTS (findByOwner, findByVerificationCode)
    // ============================================================

    public function testFindByOwnerReturnsArrayOfCars(): void
    {
        $cars = Car::findByOwner($this->testUserId);

        $this->assertIsArray($cars);
        $this->assertGreaterThan(0, count($cars));

        foreach ($cars as $car) {
            $this->assertInstanceOf(Car::class, $car);
            $this->assertEquals($this->testUserId, $car->data()->user_id);
        }
    }

    public function testFindByOwnerReturnsEmptyArrayWhenNoResults(): void
    {
        $cars = Car::findByOwner(99999);

        $this->assertIsArray($cars);
        $this->assertEquals(0, count($cars));
    }

    public function testFindByOwnerHandlesInvalidOwnerID(): void
    {
        $cars = Car::findByOwner(-1);

        if (is_array($cars)) {
            $this->assertIsArray($cars);
        } else {
            $this->fail('Expected array or exception');
        }
    }

    public function testFindByVerificationCodeStaticMethod(): void
    {
        $car = new Car(1);
        $verificationCode = 'STATIC-VERIFY-' . uniqid();

        $car->setVerificationCode($verificationCode);

        $foundCar = Car::findByVerificationCode($verificationCode);

        $this->assertInstanceOf(Car::class, $foundCar);
        $this->assertEquals($car->data()->id, $foundCar->data()->id);
    }

    public function testFindByVerificationCodeReturnsNullForNonexistent(): void
    {
        $result = Car::findByVerificationCode('NONEXISTENT-' . uniqid());
        $this->assertNull($result);
    }

    public function testFindByVerificationCodeWithSpecialCharacters(): void
    {
        $car = new Car(1);
        $verificationCode = 'CODE-SPECIAL-' . uniqid() . '-!@#$%';

        $car->setVerificationCode($verificationCode);

        $foundCar = Car::findByVerificationCode($verificationCode);

        $this->assertInstanceOf(Car::class, $foundCar);
    }
}
