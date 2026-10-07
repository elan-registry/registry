<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarShowcaseService;
use PHPUnit\Framework\Attributes\Group;

/**
 * CarShowcaseService against a real database. Each test creates the data it
 * needs instead of relying on ambient rows.
 */
#[Group('integration')]
final class CarShowcaseServiceIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /**
     * Create a car that passes IMAGE_CONDITION. `cars.image` is a JSON array
     * of bare filenames, the shape production writes.
     *
     * @return int The created car's id
     */
    private function createShowcaseEligibleCar(): int
    {
        $userId = $this->createTestUser();
        $carId = $this->createTestCar($userId);

        $imageJson = json_encode(['img_' . bin2hex(random_bytes(16)) . '.jpg']);
        $update = $this->db->query('UPDATE cars SET image = ? WHERE id = ?', [$imageJson, $carId]);
        if ($update->error()) {
            $this->fail("Failed to set fixture image for car {$carId}: " . $update->errorString());
        }

        return $carId;
    }

    /**
     * Delete leftover cars (and their transfer/history rows). getNewCarIds()
     * ranks the whole table, so floor and tie-break tests need a known start.
     * Any row here is debris from an interrupted run; the test schema is never
     * seeded with cars. Do not backdate and restore instead: that rewrote mtime
     * (ON UPDATE) and orphaned cars_hist rows.
     *
     * @return void
     */
    private function clearAmbientCars(): void
    {
        $result = $this->db->query('SELECT id FROM cars');
        if ($result->error()) {
            $this->fail('Failed to query ambient cars: ' . $result->errorString());
        }

        $ids = array_map(fn($row) => (int) $row->id, $result->results());
        if ($ids === []) {
            return;
        }
        $idList = implode(',', $ids);

        $delTransfers = $this->db->query("DELETE FROM car_transfer_requests WHERE existing_car_id IN ({$idList})");
        if ($delTransfers->error()) {
            $this->fail('Failed to delete ambient car_transfer_requests rows: ' . $delTransfers->errorString());
        }

        $delCars = $this->db->query("DELETE FROM cars WHERE id IN ({$idList})");
        if ($delCars->error()) {
            $this->fail('Failed to delete ambient cars: ' . $delCars->errorString());
        }

        // After the cars delete, so the trigger's new audit rows are included.
        $delHist = $this->db->query("DELETE FROM cars_hist WHERE car_id IN ({$idList})");
        if ($delHist->error()) {
            $this->fail('Failed to delete ambient cars_hist rows: ' . $delHist->errorString());
        }
    }

    public function testBuildShowcasePoolReturnsArray(): void
    {
        $pool = (new CarShowcaseService($this->db))->buildShowcasePool();

        $this->assertIsArray($pool);
    }

    /** At most 12 items (6 recent + 6 random). */
    public function testBuildShowcasePoolMaxSize(): void
    {
        $pool = (new CarShowcaseService($this->db))->buildShowcasePool();

        $this->assertLessThanOrEqual(12, count($pool));
    }

    public function testBuildShowcasePoolItemsHaveIsNewProperty(): void
    {
        $this->createShowcaseEligibleCar();

        $pool = (new CarShowcaseService($this->db))->buildShowcasePool();

        $this->assertNotEmpty($pool, 'Pool must be non-empty once at least one image-eligible car exists');
        foreach ($pool as $car) {
            $this->assertObjectHasProperty('is_new', $car);
            $this->assertIsBool($car->is_new);
        }
    }

    /** Fields the home-page template relies on. */
    public function testBuildShowcasePoolItemsHaveRequiredFields(): void
    {
        $this->createShowcaseEligibleCar();

        $pool = (new CarShowcaseService($this->db))->buildShowcasePool();

        $this->assertNotEmpty($pool, 'Pool must be non-empty once at least one image-eligible car exists');
        $car = $pool[0];
        $this->assertObjectHasProperty('id', $car);
        $this->assertObjectHasProperty('year', $car);
        $this->assertObjectHasProperty('series', $car);
        $this->assertObjectHasProperty('variant', $car);
        $this->assertObjectHasProperty('type', $car);
        $this->assertObjectHasProperty('ctime', $car);
    }

    /** The 12-item cap holds with 13 eligible cars, and no car appears twice. */
    public function testBuildShowcasePoolCapAt12WhenManyEligibleCarsExist(): void
    {
        $userId = $this->createTestUser();

        $imageJson = json_encode(['img_test_showcase_fixture.jpg']);

        for ($i = 0; $i < 13; $i++) {
            $carId = $this->createTestCar($userId);
            $update = $this->db->query('UPDATE cars SET image = ? WHERE id = ?', [$imageJson, $carId]);
            if ($update->error()) {
                $this->fail("Failed to set fixture image for car {$carId}: " . $update->errorString());
            }
        }

        $pool = (new CarShowcaseService($this->db))->buildShowcasePool();

        $this->assertLessThanOrEqual(12, count($pool));

        $ids = array_map(fn($car) => (int) $car->id, $pool);
        $this->assertSame(count($ids), count(array_unique($ids)), 'Pool must not contain duplicate car IDs');
    }

    /** Cars added within NEW_DAYS (90) are stamped is_new = true. */
    public function testIsNewTrueForCarsWithinRecentWindow(): void
    {
        $userId = $this->createTestUser();

        $imageJson = json_encode(['img_test_is_new.jpg']);

        $fixtureIds = [];
        for ($i = 0; $i < 13; $i++) {
            $carId = $this->createTestCar($userId);
            $update = $this->db->query('UPDATE cars SET image = ? WHERE id = ?', [$imageJson, $carId]);
            if ($update->error()) {
                $this->fail("Failed to set fixture image for car {$carId}: " . $update->errorString());
            }
            $fixtureIds[] = $carId;
        }

        $pool = (new CarShowcaseService($this->db))->buildShowcasePool();

        $fixtureIdSet = array_flip($fixtureIds);
        $poolFixtureCars = array_filter($pool, fn($car) => isset($fixtureIdSet[(int) $car->id]));

        if (empty($poolFixtureCars)) {
            $this->markTestSkipped('No fixture cars appeared in pool — DB state prevented is_new assertion.');
        }

        foreach ($poolFixtureCars as $car) {
            $this->assertTrue($car->is_new, "Fixture car {$car->id} (ctime=NOW) should have is_new=true");
        }
    }


    public function testGetNewCarIdsReturnsArrayOfIntegers(): void
    {
        $userId = $this->createTestUser();
        $this->createTestCar($userId);

        $ids = (new CarShowcaseService($this->db))->getNewCarIds();

        $this->assertIsArray($ids);
        foreach ($ids as $id) {
            $this->assertIsInt($id, 'Every element returned by getNewCarIds() must be a PHP int');
        }
    }

    public function testGetNewCarIdsIncludesCarWithinNinetyDays(): void
    {
        $userId = $this->createTestUser();
        $carId  = $this->createTestCar($userId); // ctime = NOW()

        $ids = (new CarShowcaseService($this->db))->getNewCarIds();

        $this->assertContains($carId, $ids, 'Car with ctime=NOW() must appear in getNewCarIds() via the 90-day rule');
    }

    /** A car outside 90 days and outside the top 5 is excluded. */
    public function testGetNewCarIdsOldCarOutsideTopFiveIsExcluded(): void
    {
        $userId = $this->createTestUser();

        // Occupy the 5 floor slots with recent cars so the fixture cannot sneak in.
        for ($i = 0; $i < 5; $i++) {
            $this->createTestCar($userId); // ctime = NOW()
        }

        $carId = $this->createTestCar($userId);
        $backdate = $this->db->query('UPDATE cars SET ctime = DATE_SUB(NOW(), INTERVAL 91 DAY) WHERE id = ?', [$carId]);
        if ($backdate->error()) {
            $this->fail("Failed to backdate fixture car {$carId}: " . $backdate->errorString());
        }

        $ids = (new CarShowcaseService($this->db))->getNewCarIds();

        $this->assertNotContains(
            $carId,
            $ids,
            'Car with ctime 91 days ago and outside the top-5 must not appear in getNewCarIds()'
        );
    }

    /** A car outside 90 days but in the top 5 is included (the floor). */
    public function testGetNewCarIdsFloorIncludesOldCarInTopFive(): void
    {
        $this->clearAmbientCars();

        $userId = $this->createTestUser();
        $carIds = [];
        for ($i = 0; $i < 6; $i++) {
            $carIds[] = $this->createTestCar($userId);
        }

        $backdate = $this->db->query(
            'UPDATE cars SET ctime = DATE_SUB(NOW(), INTERVAL 91 DAY) WHERE id IN (' .
            implode(',', array_map('intval', $carIds)) . ')'
        );
        if ($backdate->error()) {
            $this->fail('Failed to backdate fixture cars: ' . $backdate->errorString());
        }

        sort($carIds);
        // 6 cars, equal ctimes — top-5 by id DESC = the 5 highest IDs (indices 1–5).
        // The second-lowest ID is at position 5 of 6 and must be included via the floor.
        $fifthNewestId = $carIds[1];

        $ids = (new CarShowcaseService($this->db))->getNewCarIds();

        $this->assertContains(
            $fifthNewestId,
            $ids,
            'Car at position 5 of 6 by id DESC must be included via the floor guarantee even when older than 90 days'
        );
    }

    /** Equal ctimes: the top 5 is chosen by id DESC. */
    public function testGetNewCarIdsTieBrokenByIdDesc(): void
    {
        $this->clearAmbientCars();

        $userId = $this->createTestUser();
        $carIds = [];
        for ($i = 0; $i < 6; $i++) {
            $carIds[] = $this->createTestCar($userId);
        }

        // Identical ctime for all six — tie-breaking is purely by id DESC.
        $backdate = $this->db->query(
            "UPDATE cars SET ctime = DATE_SUB(NOW(), INTERVAL 91 DAY) WHERE id IN (" .
            implode(',', array_map('intval', $carIds)) . ')'
        );
        if ($backdate->error()) {
            $this->fail('Failed to backdate fixture cars: ' . $backdate->errorString());
        }

        sort($carIds);
        $lowestId   = $carIds[0];                 // Position 6 of 6 by id DESC — must be excluded
        $topFiveIds = array_slice($carIds, 1);    // Positions 1–5 by id DESC — must be included

        $ids = (new CarShowcaseService($this->db))->getNewCarIds();

        $this->assertNotContains(
            $lowestId,
            $ids,
            'Car with the lowest id must be excluded (position 6 of 6) when all six share the same ctime'
        );
        foreach ($topFiveIds as $id) {
            $this->assertContains($id, $ids, "Car id={$id} (top-5 by id DESC) must be included via the floor guarantee");
        }
    }
}
