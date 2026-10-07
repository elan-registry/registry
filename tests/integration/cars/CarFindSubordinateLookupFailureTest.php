<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1505: only findById() may throw from Car::find(). A failure in getHistory()
 * or getFactoryInfo() is logged and find() returns degraded data. Integration
 * tier because the assertions read the real `logs` table.
 */
#[Group('integration')]
#[Group('car-find')]
final class CarFindSubordinateLookupFailureTest extends IntegrationTestCase
{
    private \ReflectionProperty $repositoryProp;
    private int $testUserId;
    private int $testCarId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->testUserId = $this->createTestUser();
        $this->testCarId = $this->createTestCar($this->testUserId, [
            'chassis' => 'FIND' . substr(uniqid(), -8),
        ]);

        $this->repositoryProp = (new \ReflectionClass(Car::class))
            ->getProperty('repository');
    }

    private function carWithFailingSubordinateLookups(): Car
    {
        $realRepo = new CarRepository($this->db);

        $stubRepo = $this->createStub(CarRepository::class);
        $stubRepo->method('findById')->willReturnCallback(
            fn(int $carId) => $realRepo->findById($carId)
        );
        $stubRepo->method('getHistory')->willThrowException(
            new CarDatabaseException('mock getHistory failure')
        );
        $stubRepo->method('getFactoryInfo')->willThrowException(
            new CarDatabaseException('mock getFactoryInfo failure')
        );

        $car = new Car();
        $this->repositoryProp->setValue($car, $stubRepo);

        return $car;
    }

    public function testFindStillSucceedsWithDegradedDataWhenSubordinateLookupsFail(): void
    {
        $car = $this->carWithFailingSubordinateLookups();

        $result = $car->find($this->testCarId);

        $this->assertTrue($result, 'find() must still return true when only the subordinate lookups fail');
        $this->assertSame([], $car->history(), 'history() must degrade to an empty array on getHistory() failure');
        $this->assertNull($car->factory(), 'factory() must degrade to null on getFactoryInfo() failure');
        $this->assertNotNull($car->data(), 'the primary car data must still be loaded');
    }

    /** The degraded result must not be silent to operators. */
    public function testFindLogsBothSubordinateLookupFailuresUnderDatabaseError(): void
    {
        $historyBefore = $this->countMatchingLogs('DatabaseError', '%getHistory failed for car ' . $this->testCarId . '%');
        $factoryBefore = $this->countMatchingLogs('DatabaseError', '%getFactoryInfo failed for car ' . $this->testCarId . '%');

        $this->carWithFailingSubordinateLookups()->find($this->testCarId);

        $historyAfter = $this->countMatchingLogs('DatabaseError', '%getHistory failed for car ' . $this->testCarId . '%');
        $factoryAfter = $this->countMatchingLogs('DatabaseError', '%getFactoryInfo failed for car ' . $this->testCarId . '%');

        $this->assertSame(
            $historyBefore + 1,
            $historyAfter,
            'find() must log under DatabaseError when getHistory() fails'
        );
        $this->assertSame(
            $factoryBefore + 1,
            $factoryAfter,
            'find() must log under DatabaseError when getFactoryInfo() fails'
        );
    }
}
