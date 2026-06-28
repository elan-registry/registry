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
}
