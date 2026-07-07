<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarShowcaseService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for CarShowcaseService::buildShowcasePool()
 *
 * Verifies that:
 * - The pool is always an array (even on an empty registry)
 * - The pool contains at most 12 items (RECENT_LIMIT + RANDOM_LIMIT)
 * - Every item in the pool carries the expected scalar fields
 * - The `is_new` property is present and typed as bool on every item
 *
 * Requires a live database connection. Tests are skipped gracefully when
 * the connection is unavailable or when the database contains no eligible
 * cars (cars with a non-empty, valid JSON `image` column).
 */
#[Group('integration')]
final class CarShowcaseServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /**
     * buildShowcasePool() must always return an array — never null or false.
     */
    public function testBuildShowcasePoolReturnsArray(): void
    {
        $pool = CarShowcaseService::buildShowcasePool($this->db);

        $this->assertIsArray($pool);
    }

    /**
     * The pool must contain at most 12 items (6 recent + 6 random).
     */
    public function testBuildShowcasePoolMaxSize(): void
    {
        $pool = CarShowcaseService::buildShowcasePool($this->db);

        $this->assertLessThanOrEqual(12, count($pool));
    }

    /**
     * Every item must have an `is_new` property typed as bool.
     */
    public function testBuildShowcasePoolItemsHaveIsNewProperty(): void
    {
        $pool = CarShowcaseService::buildShowcasePool($this->db);

        if (empty($pool)) {
            $this->markTestSkipped('No cars with images in test database — is_new check skipped.');
        }

        foreach ($pool as $car) {
            $this->assertObjectHasProperty('is_new', $car);
            $this->assertIsBool($car->is_new);
        }
    }

    /**
     * Every item must expose the scalar fields the home-page template relies on.
     */
    public function testBuildShowcasePoolItemsHaveRequiredFields(): void
    {
        $pool = CarShowcaseService::buildShowcasePool($this->db);

        if (empty($pool)) {
            $this->markTestSkipped('No cars with images in test database — required-fields check skipped.');
        }

        $car = $pool[0];
        $this->assertObjectHasProperty('id', $car);
        $this->assertObjectHasProperty('year', $car);
        $this->assertObjectHasProperty('series', $car);
        $this->assertObjectHasProperty('variant', $car);
        $this->assertObjectHasProperty('type', $car);
        $this->assertObjectHasProperty('ctime', $car);
    }

    /**
     * Pool size must never exceed 12 even when the registry has more eligible cars.
     * Also verifies no car appears twice (recent and random pools are mutually exclusive).
     *
     * Creates 13 cars with a synthetic image payload and asserts the cap holds.
     * Test rows are cleaned up by IntegrationTestCase::tearDown().
     */
    public function testBuildShowcasePoolCapAt12WhenManyEligibleCarsExist(): void
    {
        $userId = $this->createTestUser();

        // Minimal valid JSON image array that passes the SQL image conditions
        $imageJson = json_encode([[
            'path' => '/userimages/cars/test-showcase-fixture.jpg',
            'name' => 'test-showcase-fixture.jpg',
        ]]);

        for ($i = 0; $i < 13; $i++) {
            $carId = $this->createTestCar($userId);
            $this->db->query(
                'UPDATE cars SET image = ? WHERE id = ?',
                [$imageJson, $carId]
            );
        }

        $pool = CarShowcaseService::buildShowcasePool($this->db);

        $this->assertLessThanOrEqual(12, count($pool));

        $ids = array_map(fn($car) => (int) $car->id, $pool);
        $this->assertSame(count($ids), count(array_unique($ids)), 'Pool must not contain duplicate car IDs');
    }

    /**
     * Cars added within NEW_DAYS (90 days) are stamped is_new = true.
     *
     * Creates 13 fixture cars with ctime = NOW() so they dominate the recent-6
     * query (most-recently-added). All are within 90 days, so Condition A fires.
     */
    public function testIsNewTrueForCarsWithinRecentWindow(): void
    {
        $userId = $this->createTestUser();

        $imageJson = json_encode([[
            'path' => '/userimages/cars/test-is-new.jpg',
            'name' => 'test-is-new.jpg',
        ]]);

        $fixtureIds = [];
        for ($i = 0; $i < 13; $i++) {
            $carId = $this->createTestCar($userId);
            $this->db->query('UPDATE cars SET image = ? WHERE id = ?', [$imageJson, $carId]);
            $fixtureIds[] = $carId;
        }

        $pool = CarShowcaseService::buildShowcasePool($this->db);

        $fixtureIdSet = array_flip($fixtureIds);
        $poolFixtureCars = array_filter($pool, fn($car) => isset($fixtureIdSet[(int) $car->id]));

        if (empty($poolFixtureCars)) {
            $this->markTestSkipped('No fixture cars appeared in pool — DB state prevented is_new assertion.');
        }

        foreach ($poolFixtureCars as $car) {
            $this->assertTrue($car->is_new, "Fixture car {$car->id} (ctime=NOW) should have is_new=true");
        }
    }

    // -----------------------------------------------------------------------
    // getNewCarIds() — floor and tie-breaking coverage
    // -----------------------------------------------------------------------

    /**
     * getNewCarIds() must return a list of ints — never null, false, or mixed types.
     */
    public function testGetNewCarIdsReturnsArrayOfIntegers(): void
    {
        $userId = $this->createTestUser();
        $this->createTestCar($userId);

        $ids = CarShowcaseService::getNewCarIds($this->db);

        $this->assertIsArray($ids);
        foreach ($ids as $id) {
            $this->assertIsInt($id, 'Every element returned by getNewCarIds() must be a PHP int');
        }
    }

    /**
     * A car added within 90 days is always included — the date rule fires.
     */
    public function testGetNewCarIdsIncludesCarWithinNinetyDays(): void
    {
        $userId = $this->createTestUser();
        $carId  = $this->createTestCar($userId); // ctime = NOW()

        $ids = CarShowcaseService::getNewCarIds($this->db);

        $this->assertContains($carId, $ids, 'Car with ctime=NOW() must appear in getNewCarIds() via the 90-day rule');
    }

    /**
     * A car outside 90 days and outside the top-5 must not be included.
     *
     * Five helper cars (ctime=NOW()) occupy the global top-5 floor slots,
     * guaranteeing the backdated fixture sits at position 6+. Works on any
     * database — no skip needed.
     */
    public function testGetNewCarIdsOldCarOutsideTopFiveIsExcluded(): void
    {
        $userId = $this->createTestUser();

        // Occupy the 5 floor slots with recent cars so the fixture cannot sneak in.
        for ($i = 0; $i < 5; $i++) {
            $this->createTestCar($userId); // ctime = NOW()
        }

        $carId = $this->createTestCar($userId);
        $this->db->query('UPDATE cars SET ctime = DATE_SUB(NOW(), INTERVAL 91 DAY) WHERE id = ?', [$carId]);

        $ids = CarShowcaseService::getNewCarIds($this->db);

        $this->assertNotContains(
            $carId,
            $ids,
            'Car with ctime 91 days ago and outside the top-5 must not appear in getNewCarIds()'
        );
    }

    /**
     * A car outside the 90-day window but among the top-5 most-recently-added
     * must still be included — the floor guarantee fires.
     *
     * Requires a database with no cars added within the last 91 days (other than
     * fixture cars), so the fixture cars occupy the global top-5 positions. Skips
     * on populated databases where production cars hold those slots.
     */
    public function testGetNewCarIdsFloorIncludesOldCarInTopFive(): void
    {
        $this->db->query('SELECT COUNT(*) AS cnt FROM cars WHERE ctime > DATE_SUB(NOW(), INTERVAL 91 DAY)');
        $recentCount = (int) ($this->db->results()[0]->cnt ?? 0);
        if ($recentCount > 0) {
            $this->markTestSkipped(
                "Floor-inclusion test requires zero pre-existing cars with ctime within 91 days; " .
                "found {$recentCount}. Run against an empty test database."
            );
        }

        $userId = $this->createTestUser();
        $carIds = [];
        for ($i = 0; $i < 6; $i++) {
            $carIds[] = $this->createTestCar($userId);
        }

        $this->db->query(
            'UPDATE cars SET ctime = DATE_SUB(NOW(), INTERVAL 91 DAY) WHERE id IN (' .
            implode(',', array_map('intval', $carIds)) . ')'
        );

        sort($carIds);
        // 6 cars, equal ctimes — top-5 by id DESC = the 5 highest IDs (indices 1–5).
        // The second-lowest ID is at position 5 of 6 and must be included via the floor.
        $fifthNewestId = $carIds[1];

        $ids = CarShowcaseService::getNewCarIds($this->db);

        $this->assertContains(
            $fifthNewestId,
            $ids,
            'Car at position 5 of 6 by id DESC must be included via the floor guarantee even when older than 90 days'
        );
    }

    /**
     * When multiple cars share the same ctime, the top-5 is broken by id DESC.
     *
     * Six cars with identical ctimes 91 days ago: the 5 with highest IDs must be
     * included (floor) and the lowest-ID car must be excluded (position 6 of 6).
     *
     * Requires an empty test database for the same reason as the floor-inclusion
     * test above — production cars with recent ctimes would occupy the top-5.
     */
    public function testGetNewCarIdsTieBrokenByIdDesc(): void
    {
        $this->db->query('SELECT COUNT(*) AS cnt FROM cars WHERE ctime > DATE_SUB(NOW(), INTERVAL 91 DAY)');
        $recentCount = (int) ($this->db->results()[0]->cnt ?? 0);
        if ($recentCount > 0) {
            $this->markTestSkipped(
                "Tie-breaking test requires zero pre-existing cars with ctime within 91 days; " .
                "found {$recentCount}. Run against an empty test database."
            );
        }

        $userId = $this->createTestUser();
        $carIds = [];
        for ($i = 0; $i < 6; $i++) {
            $carIds[] = $this->createTestCar($userId);
        }

        // Identical ctime for all six — tie-breaking is purely by id DESC.
        $this->db->query(
            "UPDATE cars SET ctime = DATE_SUB(NOW(), INTERVAL 91 DAY) WHERE id IN (" .
            implode(',', array_map('intval', $carIds)) . ')'
        );

        sort($carIds);
        $lowestId   = $carIds[0];                 // Position 6 of 6 by id DESC — must be excluded
        $topFiveIds = array_slice($carIds, 1);    // Positions 1–5 by id DESC — must be included

        $ids = CarShowcaseService::getNewCarIds($this->db);

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
