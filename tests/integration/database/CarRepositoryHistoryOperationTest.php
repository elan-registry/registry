<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration (real MySQL) tests for
 * CarRepository::findLatestHistoryOperationByCarIds() — the self-join on a
 * `(car_id, MAX(timestamp))` aggregate, filtered by operation in both the
 * subquery and the outer query. A mocked unit test
 * (CarRepositoryFindLatestHistoryOperationTest) cannot exercise the actual
 * SQL; this file proves the join and the operation filter are correct
 * against a real cars_hist table, mirroring
 * CarRepositoryEmailEventsTest::testEachCarReturnsItsOwnLatestEventNotTheOtherCarsEvent()'s
 * precedent for the sibling findLatestEmailEventsByCarIds() method.
 */
#[Group('integration')]
final class CarRepositoryHistoryOperationTest extends IntegrationTestCase
{
    private CarRepository $repo;
    private int $userId;
    private int $carId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repo = new CarRepository($this->db);
        $this->userId = $this->createTestUser();
        $this->carId = $this->createTestCar($this->userId);
    }

    /**
     * Insert a minimal, valid cars_hist row. Only the columns this test
     * cares about are parameterized; the rest take safe literal defaults
     * matching the NOT NULL columns' real schema (model/series/variant/type/
     * chassis are varchar/char NOT NULL with no default).
     */
    private function insertHistRow(int $carId, string $operation, string $timestamp): void
    {
        $inserted = $this->db->insert('cars_hist', [
            'operation' => $operation,
            'car_id'    => $carId,
            'model'     => 'Elan',
            'series'    => 'S4',
            'variant'   => 'SE',
            'type'      => 'FHC',
            'chassis'   => 'HISTTEST',
            'timestamp' => $timestamp,
        ]);
        $this->assertTrue($inserted, 'Failed to seed cars_hist row: ' . $this->db->errorString());
    }

    #[Group('fast')]
    public function testOperationFilterExcludesANewerButNonMatchingRow(): void
    {
        // The newest row for this car is EMAIL BOUNCED, which is NOT in the
        // requested operations list — the method must return the latest
        // EMAIL SUPPRESSED row instead, not the overall-latest row regardless
        // of operation. This is the case a join without the operation filter
        // in BOTH the subquery and the outer query would get wrong.
        $this->insertHistRow($this->carId, 'EMAIL SUPPRESSED', '2026-01-01 10:00:00');
        $this->insertHistRow($this->carId, 'EMAIL BOUNCED', '2026-01-05 10:00:00');

        $result = $this->repo->findLatestHistoryOperationByCarIds([$this->carId], ['EMAIL SUPPRESSED']);

        $this->assertArrayHasKey($this->carId, $result);
        $this->assertSame('EMAIL SUPPRESSED', $result[$this->carId]->operation);
        $this->assertSame('2026-01-01 10:00:00', $result[$this->carId]->timestamp);
    }

    #[Group('fast')]
    public function testPerCarAttributionUnderATimestampTie(): void
    {
        $otherCarId = $this->createTestCar($this->userId);

        // Both cars' latest matching row shares the exact same timestamp —
        // the hardest case for per-car attribution via a self-join. Each car
        // also has an older row, to confirm MAX() picks the right one per car,
        // not just the single row present.
        $this->insertHistRow($this->carId, 'EMAIL SUPPRESSED', '2026-02-01 08:00:00');
        $this->insertHistRow($this->carId, 'EMAIL SUPPRESSED', '2026-02-05 08:00:00');

        $this->insertHistRow($otherCarId, 'EMAIL SUPPRESSED', '2026-02-02 08:00:00');
        $this->insertHistRow($otherCarId, 'EMAIL SUPPRESSED', '2026-02-05 08:00:00');

        $result = $this->repo->findLatestHistoryOperationByCarIds(
            [$this->carId, $otherCarId],
            ['EMAIL SUPPRESSED']
        );

        $this->assertArrayHasKey($this->carId, $result);
        $this->assertArrayHasKey($otherCarId, $result);
        $this->assertSame('2026-02-05 08:00:00', $result[$this->carId]->timestamp);
        $this->assertSame('2026-02-05 08:00:00', $result[$otherCarId]->timestamp);
        $this->assertSame((string) $this->carId, (string) $result[$this->carId]->car_id);
        $this->assertSame((string) $otherCarId, (string) $result[$otherCarId]->car_id);

        $this->deleteTestCar($otherCarId);
    }

    #[Group('fast')]
    public function testNoMatchCarIsAbsentFromTheResult(): void
    {
        // $this->carId has zero cars_hist rows for this test — no
        // insertHistRow() call precedes this assertion.
        $result = $this->repo->findLatestHistoryOperationByCarIds([$this->carId], ['EMAIL SUPPRESSED']);

        $this->assertArrayNotHasKey(
            $this->carId,
            $result,
            'A car with no matching cars_hist row must be absent from the map, not present with a null value'
        );
    }

    #[Group('fast')]
    public function testEmptyCarIdsArrayReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->repo->findLatestHistoryOperationByCarIds([], ['EMAIL SUPPRESSED']));
    }

    #[Group('fast')]
    public function testEmptyOperationsArrayReturnsEmptyArray(): void
    {
        $this->insertHistRow($this->carId, 'EMAIL SUPPRESSED', '2026-01-01 10:00:00');

        $this->assertSame([], $this->repo->findLatestHistoryOperationByCarIds([$this->carId], []));
    }
}
