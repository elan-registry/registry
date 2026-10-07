<?php

declare(strict_types=1);

namespace Tests\Unit\Cars\Services;

use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * findByVerificationCode(), getFactoryInfo() and getHistory() had no error()
 * check before #1505. findByOwner() was a gap found in #1440.
 */
#[Group('fast')]
final class CarRepositoryQueryFailureTest extends TestCase
{
    /**
     * @param callable(CarRepository): mixed $call
     */
    #[DataProvider('queryProvider')]
    public function testThrowsCarDatabaseExceptionOnQueryError(callable $call, string $expectedMessage): void
    {
        $stubDb = $this->createStub(DatabaseInterface::class);
        $stubDb->method('error')->willReturn(true);
        $stubDb->method('errorString')->willReturn('mock query failure');

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage($expectedMessage);

        $call(new CarRepository($stubDb));
    }

    /**
     * @return array<string, array{callable(CarRepository): mixed, string}>
     */
    public static function queryProvider(): array
    {
        return [
            'findByOwner' => [
                static fn (CarRepository $repo) => $repo->findByOwner(1),
                'CarRepository::findByOwner failed for user=1: mock query failure',
            ],
            'findByVerificationCode' => [
                static fn (CarRepository $repo) => $repo->findByVerificationCode('ABC123'),
                'CarRepository::findByVerificationCode failed: mock query failure',
            ],
            'getFactoryInfo' => [
                static fn (CarRepository $repo) => $repo->getFactoryInfo('CHASSIS123', 5),
                'CarRepository::getFactoryInfo failed for serial=CHASSIS123: mock query failure',
            ],
            'getHistory' => [
                static fn (CarRepository $repo) => $repo->getHistory(42),
                'CarRepository::getHistory failed for car=42: mock query failure',
            ],
        ];
    }
}
