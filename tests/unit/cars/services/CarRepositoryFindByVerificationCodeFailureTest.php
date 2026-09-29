<?php

declare(strict_types=1);

namespace Tests\Unit\Cars\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarDatabaseException;

/**
 * Unit test for CarRepository::findByVerificationCode()'s DB-error propagation
 *
 * Verifies CarDatabaseException is thrown when the underlying query errors
 * (found as an untested gap while working #1505 — findByVerificationCode()
 * had no error() check at all before this fix). Stubs DatabaseInterface —
 * CarRepository's declared collaborator type since #1585 — so nothing here
 * touches an actual database connection.
 */
#[Group('fast')]
final class CarRepositoryFindByVerificationCodeFailureTest extends TestCase
{
    public function testFindByVerificationCodeThrowsCarDatabaseExceptionOnQueryError(): void
    {
        $stubDb = $this->createStub(\ElanRegistry\DatabaseInterface::class);
        $stubDb->method('error')->willReturn(true);
        $stubDb->method('errorString')->willReturn('mock query failure');

        $repo = new CarRepository($stubDb);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('CarRepository::findByVerificationCode failed: mock query failure');

        $repo->findByVerificationCode('ABC123');
    }
}
