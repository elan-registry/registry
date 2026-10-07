<?php

declare(strict_types=1);

namespace Tests\Unit\Cars\Services;

use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for CarRepository::findLatestHistoryOperationByCarIds(), mocking
 * DatabaseInterface. Mirrors CarRepositoryFindByOwnerFailureTest.php's and
 * CarRepositoryEmailEventsTest.php's (sibling findLatestEmailEventsByCarIds())
 * conventions: a mocked DB double for the no-op/contract-shape behavior that
 * does not need a real self-join, and the live-DB integration test
 * (tests/integration/database/CarRepositoryHistoryOperationTest.php) for the
 * SQL correctness a mock cannot verify.
 */
#[Group('fast')]
final class CarRepositoryFindLatestHistoryOperationTest extends TestCase
{
    public function testEmptyCarIdsArrayIsANoOpAndNeverQueries(): void
    {
        $stubDb = $this->createMock(DatabaseInterface::class);
        $stubDb->expects($this->never())->method('query');

        $repo = new CarRepository($stubDb);

        $result = $repo->findLatestHistoryOperationByCarIds([], ['EMAIL SUPPRESSED']);

        $this->assertSame([], $result);
    }

    public function testEmptyOperationsArrayIsANoOpAndNeverQueries(): void
    {
        $stubDb = $this->createMock(DatabaseInterface::class);
        $stubDb->expects($this->never())->method('query');

        $repo = new CarRepository($stubDb);

        $result = $repo->findLatestHistoryOperationByCarIds([1, 2, 3], []);

        $this->assertSame([], $result);
    }

    public function testBothEmptyArraysIsANoOpAndNeverQueries(): void
    {
        $stubDb = $this->createMock(DatabaseInterface::class);
        $stubDb->expects($this->never())->method('query');

        $repo = new CarRepository($stubDb);

        $this->assertSame([], $repo->findLatestHistoryOperationByCarIds([], []));
    }

    public function testResultIsKeyedByCarIdAsInt(): void
    {
        $row1 = (object) ['car_id' => '5', 'operation' => 'EMAIL SUPPRESSED', 'timestamp' => '2026-01-01 10:00:00'];
        $row2 = (object) ['car_id' => 9, 'operation' => 'EMAIL SUPPRESSED', 'timestamp' => '2026-02-01 10:00:00'];

        $stubDb = $this->createStub(DatabaseInterface::class);
        $stubDb->method('query')->willReturn($stubDb);
        $stubDb->method('error')->willReturn(false);
        $stubDb->method('results')->willReturn([$row1, $row2]);

        $repo = new CarRepository($stubDb);

        $result = $repo->findLatestHistoryOperationByCarIds([5, 9], ['EMAIL SUPPRESSED']);

        $this->assertArrayHasKey(5, $result, 'car_id must be cast to int, matching the int key passed in');
        $this->assertArrayHasKey(9, $result, 'A plain int car_id must also work');
        $this->assertSame($row1, $result[5]);
        $this->assertSame($row2, $result[9]);
    }

    public function testCarWithNoMatchingRowIsAbsentFromTheResult(): void
    {
        $stubDb = $this->createStub(DatabaseInterface::class);
        $stubDb->method('query')->willReturn($stubDb);
        $stubDb->method('error')->willReturn(false);
        // Only car 5 has a row; car 9 (requested) has none.
        $stubDb->method('results')->willReturn([
            (object) ['car_id' => 5, 'operation' => 'EMAIL BOUNCED', 'timestamp' => '2026-01-01 10:00:00'],
        ]);

        $repo = new CarRepository($stubDb);

        $result = $repo->findLatestHistoryOperationByCarIds([5, 9], ['EMAIL BOUNCED']);

        $this->assertArrayHasKey(5, $result);
        $this->assertArrayNotHasKey(9, $result, 'A car with no matching history row must be absent, not null');
    }

    public function testThrowsCarDatabaseExceptionOnQueryError(): void
    {
        $stubDb = $this->createStub(DatabaseInterface::class);
        $stubDb->method('query')->willReturn($stubDb);
        $stubDb->method('error')->willReturn(true);
        $stubDb->method('errorString')->willReturn('mock query failure');

        $repo = new CarRepository($stubDb);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('mock query failure');

        $repo->findLatestHistoryOperationByCarIds([1], ['EMAIL SUPPRESSED']);
    }
}
