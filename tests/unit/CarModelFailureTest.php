<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use ElanRegistry\Reference\CarModel;
use ElanRegistry\Exceptions\CarDatabaseException;

/**
 * Unit test for CarModel::exists() and CarModel::byValue()'s DB-error propagation
 *
 * Verifies CarDatabaseException is thrown when the underlying query errors
 * (found as an untested gap while working #1505 — neither method had any
 * error() check before this fix; CarModel had no DB-failure handling
 * anywhere in the class). Stubs DatabaseInterface (injected into CarModel's
 * constructor, so its dbi() fallback is never reached) so nothing here
 * touches an actual database connection.
 *
 * Named CarModelFailureTest (not CarModelExistsFailureTest /
 * CarModelByValueFailureTest) since both fallible methods live in the same
 * small class.
 */
#[Group('fast')]
final class CarModelFailureTest extends TestCase
{
    public function testExistsThrowsCarDatabaseExceptionOnQueryError(): void
    {
        $stubDb = $this->createStub(\ElanRegistry\DatabaseInterface::class);
        $stubDb->method('error')->willReturn(true);
        $stubDb->method('errorString')->willReturn('mock query failure');

        $carModel = new CarModel($stubDb);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('CarModel::exists failed for S4|FHC|36: mock query failure');

        $carModel->exists('S4', 'FHC', '36');
    }

    public function testByValueThrowsCarDatabaseExceptionOnQueryError(): void
    {
        $stubDb = $this->createStub(\ElanRegistry\DatabaseInterface::class);
        $stubDb->method('error')->willReturn(true);
        $stubDb->method('errorString')->willReturn('mock query failure');

        $carModel = new CarModel($stubDb);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('CarModel::byValue failed for modelValue=S4|FHC|36: mock query failure');

        $carModel->byValue('S4|FHC|36');
    }
}
