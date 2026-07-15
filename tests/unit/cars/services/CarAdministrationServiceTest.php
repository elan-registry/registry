<?php

declare(strict_types=1);

use ElanRegistry\Car\CarAdministrationService;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarNotFoundException;
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

        // Use a mocked repo so this unit test is independent of DB state.
        // The production deleteCar() now throws CarNotFoundException on 0-row
        // deletes, so car 999 (which doesn't exist in the test DB) would fail
        // with a real CarRepository.
        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('beginTransaction');
        $repo->expects($this->once())->method('deleteCar')->willReturn(true);
        $repo->expects($this->once())->method('commit');

        $result = $this->service->delete($carData, 'Test deletion', 1, $repo);
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

    // =========================================================================
    // delete() + merge() propagation tests (issue #1311)
    // =========================================================================

    /**
     * delete() must re-throw CarNotFoundException when deleteCar() discovers the
     * car was already deleted (0 rows affected).  The service catch block must
     * not swallow CarException subclasses.
     */
    public function testDeletePropagatesCarNotFoundExceptionFromDeleteCar(): void
    {
        $carData = (object) ['id' => 999, 'chassis' => 'GHOST01'];

        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('beginTransaction');
        $repo->expects($this->once())->method('deleteCar')
            ->willThrowException(new CarNotFoundException('Car 999 not found for deletion'));
        $repo->expects($this->once())->method('rollback');

        $this->expectException(CarNotFoundException::class);
        $this->service->delete($carData, 'Test deletion', 1, $repo);
    }

    /**
     * merge() must throw CarNotFoundException when findByIdForUpdate() returns
     * null, indicating the source car was deleted between the caller's initial
     * check and the locked re-read inside the transaction.
     */
    public function testMergePropagatesCarNotFoundExceptionWhenSourceCarGone(): void
    {
        $targetCarData = (object) ['id' => 1, 'chassis' => 'TARGET01'];

        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('beginTransaction');
        $repo->expects($this->once())->method('findByIdForUpdate')->willReturn(null);
        $repo->expects($this->once())->method('rollback');

        $this->expectException(CarNotFoundException::class);
        $this->service->merge($targetCarData, 999, 'Test merge', 1, $repo);
    }
}
