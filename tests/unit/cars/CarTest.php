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
}
