<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Exceptions\CarValidationException;
use PHPUnit\Framework\Attributes\Group;

/** #1440: Car find(), exists(), history(), and findByOwner() edge cases. */
#[Group('integration')]
final class CarCoreAccessorsTest extends IntegrationTestCase
{
    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->testUserId = $this->createTestUser();
    }

    #[Group('integration')]
    public function testFindReturnsFalseForNonexistentId(): void
    {
        $car = new Car();
        $result = $car->find(999999999);

        $this->assertFalse($result);
    }

    #[Group('integration')]
    public function testExistsReturnsFalseBeforeFind(): void
    {
        $car = new Car();

        $this->assertFalse($car->exists());
    }

    #[Group('integration')]
    public function testExistsReturnsTrueAfterFind(): void
    {
        $carId = $this->createTestCar($this->testUserId);
        $car = new Car($carId);

        $this->assertTrue($car->exists());
    }

    #[Group('integration')]
    public function testHistoryDefaultsToEmptyArrayForNewCarInstance(): void
    {
        $car = new Car();

        $this->assertSame([], $car->history());
    }

    #[Group('integration')]
    public function testHistoryReturnsPopulatedRecordsAfterUpdate(): void
    {
        $carId = $this->createTestCar($this->testUserId);
        $car = new Car($carId);

        // update() reloads via find(), so history() shows the trigger row.
        $car->update([
            'id'    => $carId,
            'token' => Token::generate(),
            'color' => 'History Test Color',
        ]);

        $history = $car->history();

        $this->assertNotEmpty($history, 'Expected at least one history record after update()');
        $operations = array_map(fn($record) => $record->operation, $history);
        $this->assertContains('UPDATE', $operations, 'Expected an UPDATE operation record in history');
    }

    #[Group('integration')]
    public function testFindByOwnerReturnsEmptyArrayWhenNoCars(): void
    {
        $ownerWithNoCars = $this->createTestUser();

        $cars = Car::findByOwner($ownerWithNoCars);

        $this->assertSame([], $cars);
    }

    #[Group('integration')]
    public function testFindByOwnerReturnsPopulatedCarsForOwner(): void
    {
        // A decoy car for another user proves the WHERE clause filters by owner.
        $otherOwnerId = $this->createTestUser();
        $decoyCarId = $this->createTestCar($otherOwnerId);

        $carId1 = $this->createTestCar($this->testUserId);
        $carId2 = $this->createTestCar($this->testUserId);

        $cars = Car::findByOwner($this->testUserId);

        $this->assertCount(2, $cars);

        $returnedIds = [];
        foreach ($cars as $car) {
            $this->assertInstanceOf(Car::class, $car);
            $this->assertTrue($car->exists());
            $this->assertSame($this->testUserId, (int) $car->data()->user_id);
            $returnedIds[] = (int) $car->data()->id;
        }

        $this->assertNotContains($decoyCarId, $returnedIds, 'findByOwner() must not return another owner\'s car');

        $expectedIds = [$carId1, $carId2];
        sort($returnedIds);
        sort($expectedIds);
        $this->assertSame($expectedIds, $returnedIds);
    }

    #[Group('integration')]
    public function testFindByOwnerThrowsOnInvalidOwnerId(): void
    {
        $this->expectException(CarValidationException::class);

        Car::findByOwner(0);
    }
}
