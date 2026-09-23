<?php

declare(strict_types=1);

namespace Tests\Unit\Cars\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarDatabaseException;

/**
 * Unit test for CarRepository::getHistory()'s DB-error propagation
 *
 * Verifies CarDatabaseException is thrown when the underlying query errors
 * (found as an untested gap while working #1505 — getHistory() had no
 * error() check at all before this fix). Stubs DatabaseInterface —
 * CarRepository's declared collaborator type since #1585 — so nothing here
 * touches an actual database connection.
 */
#[Group('fast')]
final class CarRepositoryGetHistoryFailureTest extends TestCase
{
    public function testGetHistoryThrowsCarDatabaseExceptionOnQueryError(): void
    {
        $stubDb = $this->createStub(\ElanRegistry\DatabaseInterface::class);
        $stubDb->method('error')->willReturn(true);
        $stubDb->method('errorString')->willReturn('mock query failure');

        $repo = new CarRepository($stubDb);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('CarRepository::getHistory failed for car=42: mock query failure');

        $repo->getHistory(42);
    }
}
