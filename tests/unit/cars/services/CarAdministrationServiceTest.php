<?php

declare(strict_types=1);

use ElanRegistry\Car\CarAdministrationService;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for CarAdministrationService service class
 */
#[Group('fast')]
final class CarAdministrationServiceTest extends TestCase
{
    private CarAdministrationService $service;
    private CarRepository $repo;

    protected function setUp(): void
    {
        $this->service = new CarAdministrationService();
        $this->repo = new CarRepository(DB::getInstance());
    }

    public function testDeleteSucceedsWithValidData(): void
    {
        $carData = (object) [
            'id' => 999,
            'chassis' => 'TEST99999',
            'ctime' => '2024-01-01 00:00:00',
            'model' => 'Elan',
            'series' => 'S4',
            'variant' => 'SE',
            'year' => '1970',
            'type' => 'FHC',
            'color' => 'Red',
            'engine' => 'ENG001',
            'purchasedate' => null,
            'solddate' => null,
            'image' => ''
        ];

        $result = $this->service->delete($carData, 'Test deletion', 1, $this->repo);
        $this->assertTrue($result);
    }

    public function testMergeRejectsSelfMerge(): void
    {
        $this->expectException(CarValidationException::class);

        $carData = (object) [
            'id' => 1,
            'chassis' => 'TEST00001'
        ];

        $this->service->merge($carData, 1, 'Test merge', 1, $this->repo);
    }

    public function testTransferThrowsCarValidationExceptionWhenUserNotFound(): void
    {
        $this->expectException(CarValidationException::class);

        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999'];

        $this->service->transfer(
            $carData,
            0,
            'Test transfer reason',
            'NEWOWNER',
            1,
            $this->repo,
            fn($fields) => true,
            fn($carId) => (object) []
        );
    }

    public function testTransferThrowsCarDatabaseExceptionWhenUpdateFails(): void
    {
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999'];

        $this->service->transfer(
            $carData,
            1,
            'Test transfer reason',
            'NEWOWNER',
            1,
            $this->repo,
            fn($fields) => false,
            fn($carId) => (object) []
        );
    }
}
