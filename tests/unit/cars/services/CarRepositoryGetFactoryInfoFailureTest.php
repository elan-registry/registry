<?php

declare(strict_types=1);

namespace Tests\Unit\Cars\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarDatabaseException;

/**
 * Unit test for CarRepository::getFactoryInfo()'s DB-error propagation
 *
 * Verifies CarDatabaseException is thrown when the underlying query errors
 * (found as an untested gap while working #1505 — getFactoryInfo() had no
 * error() check at all before this fix; it loops over up to two candidate
 * serial numbers, so the guard sits inside the foreach). Stubs
 * DatabaseInterface — CarRepository's declared collaborator type since
 * #1585 — so nothing here touches an actual database connection.
 */
#[Group('fast')]
final class CarRepositoryGetFactoryInfoFailureTest extends TestCase
{
    public function testGetFactoryInfoThrowsCarDatabaseExceptionOnQueryError(): void
    {
        $stubDb = $this->createStub(\ElanRegistry\DatabaseInterface::class);
        $stubDb->method('error')->willReturn(true);
        $stubDb->method('errorString')->willReturn('mock query failure');

        $repo = new CarRepository($stubDb);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('CarRepository::getFactoryInfo failed for serial=CHASSIS123: mock query failure');

        $repo->getFactoryInfo('CHASSIS123', 5);
    }
}
