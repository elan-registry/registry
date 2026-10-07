<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real-SQL test of CarRepository::findLatestHistoryOperationByCarIds(): the
 * self-join on (car_id, MAX(timestamp)) with the operation filter in both the
 * subquery and the outer query. The mocked unit test cannot run the SQL.
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
     * model/series/variant/type/chassis are NOT NULL with no default.
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
        // The newest row (EMAIL BOUNCED) is not a requested operation. Without the
        // filter in both subquery and outer query, the wrong row is returned.
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

        // Same timestamp for both cars is the hardest case for per-car attribution;
        // the older rows confirm MAX() picks per car.
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
