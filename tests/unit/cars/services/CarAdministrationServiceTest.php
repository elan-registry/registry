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
        // delete() only reads ->id and ->chassis from carData; minimal fixture is sufficient.
        // A mocked repo is required because the real deleteCar() throws CarNotFoundException
        // on 0-row deletes, and car 999 does not exist in the test DB.
        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999'];
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

        $this->service->transfer($carData, 0, 'Test transfer reason', 'NEWOWNER', 1, $this->repo);
    }

    public function testTransferSucceeds(): void
    {
        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999'];

        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('beginTransaction');
        $repo->expects($this->once())->method('updateCar')->willReturn(true);
        $repo->expects($this->once())->method('insertHistory')->willReturn(true);
        $repo->expects($this->once())->method('commit');
        $repo->expects($this->never())->method('rollback');

        $result = $this->service->transfer($carData, 1, 'Test transfer reason', 'NEWOWNER', 1, $repo);
        $this->assertTrue($result);
    }

    public function testTransferThrowsCarDatabaseExceptionWhenUpdateFails(): void
    {
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999'];

        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('beginTransaction');
        $repo->expects($this->once())->method('updateCar')->willReturn(false);
        $repo->expects($this->once())->method('rollback');
        $repo->expects($this->never())->method('commit');

        $this->service->transfer($carData, 1, 'Test transfer reason', 'NEWOWNER', 1, $repo);
    }

    public function testTransferThrowsCarDatabaseExceptionWhenInsertHistoryFails(): void
    {
        $this->expectException(CarDatabaseException::class);

        $carData = (object) ['id' => 999, 'chassis' => 'TEST99999'];

        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('beginTransaction');
        $repo->expects($this->once())->method('updateCar')->willReturn(true);
        $repo->expects($this->once())->method('insertHistory')->willReturn(false);
        $repo->expects($this->once())->method('rollback');
        $repo->expects($this->never())->method('commit');

        $this->service->transfer($carData, 1, 'Test transfer reason', 'NEWOWNER', 1, $repo);
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
     * delete() must wrap a false return from deleteCar() in CarDatabaseException.
     * This is the DB-level error path, distinct from the CarNotFoundException
     * thrown when 0 rows are affected.
     */
    public function testDeleteThrowsCarDatabaseExceptionWhenDeleteCarReturnsFalse(): void
    {
        $carData = (object) ['id' => 999, 'chassis' => 'GHOST02'];

        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('beginTransaction');
        $repo->expects($this->once())->method('deleteCar')->willReturn(false);
        $repo->expects($this->once())->method('rollback');

        $this->expectException(CarDatabaseException::class);
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

    /**
     * merge() must wrap a false return from transferHistory() in CarDatabaseException.
     * This covers the DB-level failure path during the history-transfer step.
     */
    public function testMergeThrowsCarDatabaseExceptionWhenTransferHistoryFails(): void
    {
        $targetCarData = (object) ['id' => 1, 'chassis' => 'TARGET01'];
        $sourceData = (object) ['id' => 999, 'chassis' => 'SOURCE01'];

        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('beginTransaction');
        $repo->expects($this->once())->method('findByIdForUpdate')->willReturn($sourceData);
        $repo->expects($this->once())->method('transferHistory')->willReturn(false);
        $repo->expects($this->once())->method('rollback');

        $this->expectException(CarDatabaseException::class);
        $this->service->merge($targetCarData, 999, 'Test merge', 1, $repo);
    }

    public function testMergeThrowsCarDatabaseExceptionWhenDeleteCarFails(): void
    {
        $targetCarData = (object) ['id' => 1, 'chassis' => 'TARGET01'];
        $sourceData = (object) ['id' => 999, 'chassis' => 'SOURCE01'];

        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('beginTransaction');
        $repo->expects($this->once())->method('findByIdForUpdate')->willReturn($sourceData);
        $repo->expects($this->once())->method('transferHistory')->willReturn(true);
        $repo->expects($this->once())->method('deleteCar')->willReturn(false);
        $repo->expects($this->once())->method('rollback');
        $repo->expects($this->never())->method('commit');

        $this->expectException(CarDatabaseException::class);
        $this->service->merge($targetCarData, 999, 'Test merge', 1, $repo);
    }

    public function testMergeThrowsCarDatabaseExceptionWhenInsertHistoryFails(): void
    {
        $targetCarData = (object) ['id' => 1, 'chassis' => 'TARGET01'];
        $sourceData = (object) ['id' => 999, 'chassis' => 'SOURCE01'];

        $repo = $this->createMock(CarRepository::class);
        $repo->expects($this->once())->method('beginTransaction');
        $repo->expects($this->once())->method('findByIdForUpdate')->willReturn($sourceData);
        $repo->expects($this->once())->method('transferHistory')->willReturn(true);
        $repo->expects($this->once())->method('deleteCar')->willReturn(true);
        $repo->expects($this->once())->method('insertHistory')->willReturn(false);
        $repo->expects($this->once())->method('rollback');
        $repo->expects($this->never())->method('commit');

        $this->expectException(CarDatabaseException::class);
        $this->service->merge($targetCarData, 999, 'Test merge', 1, $repo);
    }
}
