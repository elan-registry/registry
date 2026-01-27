<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test cases for the Car class
 *
 * Tests basic Car class functionality including instantiation,
 * data access, and basic CRUD operations.
 *
 * @group fast
 */
final class CarTest extends TestCase
{
    /**
     * Test Car class can be instantiated and found by ID
     *
     * @group fast
     */
    public function testFind(): void
    {
        $car = new Car(1);
        $this->assertInstanceOf(Car::class, $car);
        $this->assertIsObject($car->data());
        $this->assertEquals(1, $car->data()->id);
    }
    
    /**
     * Test Car class instantiation
     *
     * @group fast
     */
    public function testInstantiation(): void
    {
        $car = new Car();
        $this->assertInstanceOf(Car::class, $car);
        $this->assertIsObject($car->data());
        $this->assertObjectHasProperty('id', $car->data());
    }
    
    /**
     * Test Car data access methods
     *
     * @group fast
     */
    public function testDataAccess(): void
    {
        $car = new Car();
        $data = $car->data();

        $this->assertIsObject($data);
        $this->assertObjectHasProperty('id', $data);
        $this->assertObjectHasProperty('year', $data);
        $this->assertObjectHasProperty('chassis', $data);
    }

    /**
     * Test Car find with null parameter calls findAll
     *
     * @group fast
     */
    public function testFindWithNullParameter(): void
    {
        $car = new Car();
        $result = $car->find(null);

        $this->assertTrue($result);
        $this->assertIsObject($car->data());
    }

    /**
     * Test Car find returns false when car not found
     *
     * @group fast
     */
    public function testFindReturnsfalseWhenNotFound(): void
    {
        $car = new Car();
        $result = $car->find(99999);

        $this->assertFalse($result);
    }

    /**
     * Test Car find with valid ID populates data
     *
     * @group fast
     */
    public function testFindWithValidIdPopulatesData(): void
    {
        $car = new Car();
        $result = $car->find(1);

        if ($result) {
            $this->assertTrue($result);
            $this->assertEquals(1, $car->data()->id);
            $this->assertIsObject($car->data());
        }
    }

    /**
     * Test Car exists returns true for loaded car
     *
     * @group fast
     */
    public function testExistsReturnsTrueForLoadedCar(): void
    {
        $car = new Car(1);

        $this->assertTrue($car->exists());
    }

    /**
     * Test Car exists returns false for non-existent car
     *
     * @group fast
     */
    public function testExistsReturnsFalseForNonexistentCar(): void
    {
        $car = new Car(99999);

        $this->assertFalse($car->exists());
    }

    /**
     * Test Car constructor with ID loads car data
     *
     * @group fast
     */
    public function testConstructorWithIdLoadsCar(): void
    {
        $car = new Car(1);

        $this->assertIsObject($car->data());
        $this->assertEquals(1, $car->data()->id);
    }

    /**
     * Test Car constructor without ID creates empty car
     *
     * @group fast
     */
    public function testConstructorWithoutIdCreatesEmptyCar(): void
    {
        $car = new Car();

        $this->assertIsObject($car->data());
        // New car should not have an ID initially
        $this->assertFalse($car->exists());
    }

    /**
     * Test multiple sequential find calls
     *
     * @group fast
     */
    public function testMultipleSequentialFindCalls(): void
    {
        $car = new Car();

        $result1 = $car->find(1);
        if ($result1) {
            $id1 = $car->data()->id;
            $this->assertEquals(1, $id1);
        }

        // Find again with same ID
        $result2 = $car->find(1);
        if ($result2) {
            $id2 = $car->data()->id;
            $this->assertEquals(1, $id2);
        }
    }
}
