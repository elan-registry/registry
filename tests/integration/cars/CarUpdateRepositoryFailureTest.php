<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\Group;

/**
 * #934: when CarRepository::update() fails, Car::update() writes exactly one
 * DATABASE_ERROR log entry and throws CarDatabaseException. Integration tier
 * because countMatchingLogs() reads the real `logs` table.
 *
 * @see usersc/classes/Car/Car.php Car::update()
 */
#[Group('integration')]
#[Group('car-update')]
final class CarUpdateRepositoryFailureTest extends IntegrationTestCase
{
    private \ReflectionProperty $repositoryProp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repositoryProp = (new \ReflectionClass(Car::class))
            ->getProperty('repository');
    }

    /** Build a Car whose stub repository returns false from updateCar(). */
    private function carWithFailingRepo(): Car
    {
        $car = new Car();

        $stubRepo = $this->createStub(CarRepository::class);
        $stubRepo->method('updateCar')->willReturn(false);

        $this->repositoryProp->setValue($car, $stubRepo);

        return $car;
    }

    public function testUpdateThrowsCarDatabaseExceptionOnRepositoryFailure(): void
    {
        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('Database update failed');

        $this->carWithFailingRepo()->update([
            'id'    => 1,
            'token' => Token::generate(),
        ]);
    }

    /** #934: one DATABASE_ERROR entry, none under CAR_UPDATE (a duplicate guard logged twice). */
    public function testUpdateLogsExactlyOnceUnderDatabaseErrorOnFailure(): void
    {
        $dbErrBefore = $this->countMatchingLogs('DatabaseError', 'Car update failed%');
        $carUpdBefore = $this->countMatchingLogs('CarUpdate', '%');

        try {
            $this->carWithFailingRepo()->update([
                'id'    => 1,
                'token' => Token::generate(),
            ]);
        } catch (CarDatabaseException) {
            // expected
        }

        $dbErrAfter  = $this->countMatchingLogs('DatabaseError', 'Car update failed%');
        $carUpdAfter = $this->countMatchingLogs('CarUpdate', '%');

        $this->assertSame(
            $dbErrBefore + 1,
            $dbErrAfter,
            'Car::update() must log exactly once under DatabaseError when the repository returns false'
        );

        $this->assertSame(
            $carUpdBefore,
            $carUpdAfter,
            'LOG_CATEGORY_CAR_UPDATE must not fire on update failure after #934 fix'
        );
    }

    /**
     * A failed reload after a successful updateCar() logs a "state may be stale"
     * warning and returns true. create() throws in the same case.
     */
    public function testUpdateStillSucceedsButLogsWhenPostUpdateReloadFails(): void
    {
        $stubRepo = $this->createStub(CarRepository::class);
        $stubRepo->method('updateCar')->willReturn(true);
        $stubRepo->method('findById')->willReturn(null);

        $car = new Car();
        $this->repositoryProp->setValue($car, $stubRepo);

        $before = $this->countMatchingLogs('DatabaseError', '%reload via find() failed%');

        $result = $car->update([
            'id'    => 999999999,
            'token' => Token::generate(),
            'color' => 'Reload Failure Test',
        ]);

        $this->assertTrue($result, 'update() must still return true even when the post-update reload fails');

        $after = $this->countMatchingLogs('DatabaseError', '%reload via find() failed%');
        $this->assertSame(
            $before + 1,
            $after,
            'update() must log under DatabaseError when the post-update find() fails to reload'
        );
    }
}
