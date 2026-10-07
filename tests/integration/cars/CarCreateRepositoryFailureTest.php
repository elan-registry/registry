<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarCreationException;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1440: Car::create() repository-failure branches. Integration tier because
 * the log assertion reads the real `logs` table. The post-insert find() failure
 * logs nothing, so it has no log test.
 */
#[Group('integration')]
#[Group('car-create')]
final class CarCreateRepositoryFailureTest extends IntegrationTestCase
{
    private \ReflectionProperty $repositoryProp;
    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->testUserId = $this->createTestUser();

        $this->repositoryProp = (new \ReflectionClass(Car::class))
            ->getProperty('repository');
    }

    private function validCarData(): array
    {
        return [
            'token'   => Token::generate(),
            'user_id' => $this->testUserId,
            'year'    => '1973',
            'model'   => 'Sprint|FHC|36',
            'series'  => 'Sprint',
            'variant' => 'FHC',
            'type'    => '36',
            'chassis' => 'RF' . substr(uniqid(), -8),
            'color'   => 'Repo Failure Test',
        ];
    }

    private function carWithStubRepo(): array
    {
        $car = new Car();
        $stubRepo = $this->createStub(CarRepository::class);
        $this->repositoryProp->setValue($car, $stubRepo);

        return [$car, $stubRepo];
    }

    public function testCreateThrowsCarCreationExceptionWhenInsertFails(): void
    {
        [$car, $stubRepo] = $this->carWithStubRepo();
        $stubRepo->method('insertCar')->willReturn(false);
        $stubRepo->method('errorString')->willReturn('mock insert failure');

        $this->expectException(CarCreationException::class);
        $this->expectExceptionMessage('Database error during car creation: mock insert failure');

        $car->create($this->validCarData());
    }

    public function testCreateLogsDatabaseErrorWhenInsertFails(): void
    {
        $before = $this->countMatchingLogs('DatabaseError', 'Car creation failed%');

        [$car, $stubRepo] = $this->carWithStubRepo();
        $stubRepo->method('insertCar')->willReturn(false);
        $stubRepo->method('errorString')->willReturn('mock insert failure');

        try {
            $car->create($this->validCarData());
        } catch (CarCreationException) {
            // expected
        }

        $after = $this->countMatchingLogs('DatabaseError', 'Car creation failed%');
        $this->assertSame($before + 1, $after, 'Car::create() must log under DatabaseError when insertCar() fails');
    }

    public function testCreateThrowsCarCreationExceptionWhenPostInsertFindFails(): void
    {
        [$car, $stubRepo] = $this->carWithStubRepo();
        $stubRepo->method('insertCar')->willReturn(true);
        $stubRepo->method('lastId')->willReturn(999999999);
        $stubRepo->method('findById')->willReturn(null);

        $this->expectException(CarCreationException::class);
        $this->expectExceptionMessage('Car ID 999999999 not found after insert');

        $car->create($this->validCarData());
    }
}
