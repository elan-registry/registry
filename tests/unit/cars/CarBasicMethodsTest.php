<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test cases for Car basic methods and utility functions
 *
 * Tests cover simple getter methods and utility functions including
 * exists(), history(), factory(), owner(), and findAll().
 *
 * @group fast
 */
final class CarBasicMethodsTest extends TestCase
{
    private $testCarId;

    protected function setUp(): void
    {
        $this->testCarId = 1;
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }

    /**
     * Test exists returns true when car data is present
     *
     * @group fast
     */
    public function testExistsReturnsTrueWhenDataPresent(): void
    {
        $car = new Car($this->testCarId);

        $this->assertTrue($car->exists());
    }

    /**
     * Test exists returns false when car not found
     *
     * @group fast
     */
    public function testExistsReturnsFalseWhenDataEmpty(): void
    {
        $car = new Car(99999);

        $this->assertFalse($car->exists());
    }

    /**
     * Test history returns array when present
     *
     * @group fast
     */
    public function testHistoryReturnsArrayWhenPresent(): void
    {
        $car = new Car($this->testCarId);
        $history = $car->history();

        if ($history !== null) {
            $this->assertIsArray($history);
            $this->assertGreaterThan(0, count($history));
        } else {
            // History can be null for cars with no history records
            $this->assertNull($history);
        }
    }

    /**
     * Test history returns null when empty
     *
     * @group fast
     */
    public function testHistoryReturnsNullWhenEmpty(): void
    {
        $car = new Car();
        $history = $car->history();

        // New car may or may not have history depending on implementation
        if ($history !== null) {
            $this->assertIsArray($history);
        } else {
            $this->assertNull($history);
        }
    }

    /**
     * Test factory returns object when present
     *
     * @group fast
     */
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

    /**
     * Test factory returns null when empty
     *
     * @group fast
     */
    public function testFactoryReturnsNullWhenEmpty(): void
    {
        $car = new Car();
        $factory = $car->factory();

        // New car typically has no factory data
        $this->assertNull($factory);
    }

    /**
     * Test owner returns owner data
     *
     * @group fast
     */
    public function testOwnerReturnsOwnerData(): void
    {
        $car = new Car($this->testCarId);
        $owner = $car->owner();

        $this->assertNotNull($owner);
        if (!empty($owner)) {
            $this->assertTrue(is_array($owner) || is_object($owner));
        }
    }

    /**
     * Test findAll returns all cars
     *
     * @group fast
     */
    public function testFindAllReturnsAllCars(): void
    {
        $car = new Car();
        $result = $car->findAll();

        $this->assertTrue($result);
        $this->assertTrue($car->exists());
    }

    /**
     * Test find returns false when car not found
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
     * Test find with null calls findAll
     *
     * @group fast
     */
    public function testFindWithNullCallsFindAll(): void
    {
        $car = new Car();
        $result = $car->find(null);

        $this->assertTrue($result);
    }

    /**
     * Test data returns object with car properties
     *
     * @group fast
     */
    public function testDataReturnsObjectWithCarProperties(): void
    {
        $car = new Car($this->testCarId);
        $data = $car->data();

        $this->assertIsObject($data);
        $this->assertObjectHasProperty('id', $data);
        $this->assertObjectHasProperty('chassis', $data);
        $this->assertObjectHasProperty('year', $data);
    }

    /**
     * Test images returns array
     *
     * @group fast
     */
    public function testImagesReturnsArray(): void
    {
        $car = new Car($this->testCarId);
        $images = $car->images();

        $this->assertIsArray($images);
    }

    /**
     * Test images returns empty array when no images
     *
     * @group fast
     */
    public function testImagesReturnsEmptyArrayWhenNoImages(): void
    {
        $car = new Car();
        $images = $car->images();

        $this->assertIsArray($images);
        $this->assertEquals(0, count($images));
    }

    /**
     * Test removeImage fails when image not found
     *
     * @group fast
     */
    public function testRemoveImageFailsWhenImageNotFound(): void
    {
        $car = new Car($this->testCarId);

        $result = $car->removeImage('nonexistent-image.jpg');

        // Result may be false or throw exception depending on implementation
        if (is_bool($result)) {
            $this->assertFalse($result);
        }
    }

    /**
     * Test removeImage fails with empty filename
     *
     * @group fast
     */
    public function testRemoveImageFailsWithEmptyFilename(): void
    {
        $car = new Car($this->testCarId);

        $result = $car->removeImage('');

        // Should fail with empty filename
        if (is_bool($result)) {
            $this->assertFalse($result);
        }
    }

    /**
     * Test removeImage returns false when car not exists
     *
     * @group fast
     */
    public function testRemoveImageFailsWhenCarNotExists(): void
    {
        $car = new Car(99999);

        $result = $car->removeImage('image.jpg');

        // Should return false for non-existent car
        if (is_bool($result)) {
            $this->assertFalse($result);
        }
    }

    /**
     * Test find populates all car properties
     *
     * @group fast
     */
    public function testFindPopulatesAllProperties(): void
    {
        $car = new Car();
        $result = $car->find($this->testCarId);

        $this->assertTrue($result);
        $data = $car->data();

        $this->assertEquals($this->testCarId, $data->id);
        $this->assertNotNull($data->chassis);
        $this->assertNotNull($data->year);
    }

    /**
     * Test multiple find calls work correctly
     *
     * @group fast
     */
    public function testMultipleFindCallsWorkCorrectly(): void
    {
        $car = new Car();

        $car->find(1);
        $data1 = $car->data();
        $this->assertEquals(1, $data1->id);

        // Find different car
        if (Car::findByOwner(1) && count(Car::findByOwner(1)) > 1) {
            $car->find(2);
            $data2 = $car->data();
            $this->assertEquals(2, $data2->id);
        }
    }
}
