<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\StatisticsDataService;

/**
 * Integration tests for StatisticsDataService, the data layer behind the
 * statistics.php API endpoint, run against real database fixtures.
 *
 * The endpoint's `tab` parameter validation (empty tab and unknown tab both
 * rejected with 400) is pinned at source level in
 * tests/unit/api/StatisticsEndpointValidationTest.php.
 *
 * @author Elan Registry Development Team
 * @copyright 2025
 */
class StatisticsApiTest extends IntegrationTestCase
{
    private $testUserId;

    /**
     * Set up test database connection and create test fixture data
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Create the fixture the statistics assertions rely on, so registry-wide
        // aggregate queries are true by construction rather than by ambient data.
        $this->testUserId = $this->createTestUser();

        // The car ID is not needed by any assertion — the service calls below
        // return registry-wide aggregates. Creating it is what matters; the ID is
        // tracked by createTestCar() for tearDown() cleanup.
        $this->createTestCar($this->testUserId, [
            'country' => 'United States',
            'state'   => 'California',
        ]);
    }

    // =========================================================================
    // StatisticsDataService behavioral tests
    // =========================================================================

    /**
     * StatisticsDataService::getCountryData() returns rows with country and count keys.
     *
     * Replaced a tautological test that only asserted empty('') === true.
     */
    public function testGetCountryDataReturnsRowsWithExpectedShape(): void
    {
        $service = new StatisticsDataService($this->db);
        $result  = $service->getCountryData();

        $this->assertIsArray($result);
        $this->assertNotEmpty($result, 'Registry must have at least one car with a country');

        $row = $result[0];
        $this->assertObjectHasProperty('country', $row);
        $this->assertObjectHasProperty('count', $row);
    }

    /**
     * StatisticsDataService::getTypeData() returns rows with type and count keys.
     *
     * Replaced a tautological test that only compared a literal string against
     * a hardcoded array built in the same test.
     */
    public function testGetTypeDataReturnsRowsWithExpectedShape(): void
    {
        $service = new StatisticsDataService($this->db);
        $result  = $service->getTypeData();

        $this->assertIsArray($result);
        $this->assertNotEmpty($result, 'Registry must have at least one car with a type');

        $row = $result[0];
        $this->assertObjectHasProperty('type', $row);
        $this->assertObjectHasProperty('count', $row);
    }

    /**
     * StatisticsDataService::getSeriesCounts() returns all six expected series keys.
     *
     * Replaced a tautological test that asserted keys in a locally-constructed array.
     */
    public function testGetSeriesCountsReturnsAllSixKeys(): void
    {
        $service = new StatisticsDataService($this->db);
        $counts  = $service->getSeriesCounts();

        foreach (['s1', 's2', 's3', 's4', 'sprint', '+2'] as $key) {
            $this->assertArrayHasKey($key, $counts, "seriesCounts must have key '$key'");
            $this->assertIsNumeric($counts[$key], "seriesCounts['$key'] must be numeric");
        }
    }

    /**
     * StatisticsDataService::getMapPins() returns an array; each row has required fields.
     */
    public function testGetMapPinsReturnsRowsWithRequiredFields(): void
    {
        $service = new StatisticsDataService($this->db);
        $pins    = $service->getMapPins();

        $this->assertIsArray($pins);

        if (empty($pins)) {
            $this->createTestCar($this->testUserId, ['lat' => '51.5074', 'lon' => '-0.1278']);
            $pins = $service->getMapPins();
        }

        $this->assertNotEmpty($pins, 'getMapPins() must return at least one car with coordinates');
        $pin = $pins[0];
        foreach (['id', 'year', 'series', 'lat', 'lon', 'owner'] as $field) {
            $this->assertObjectHasProperty($field, $pin, "getMapPins() row must have '$field'");
        }
    }

    /**
     * StatisticsDataService::getDataCompleteness() returns an object with required fields.
     *
     * Replaced a tautological test that asserted a locally-constructed error array.
     */
    public function testGetDataCompletenessReturnsObjectWithRequiredFields(): void
    {
        $service    = new StatisticsDataService($this->db);
        $completeness = $service->getDataCompleteness();

        $this->assertNotNull($completeness);
        foreach (['total_cars', 'has_chassis', 'has_color', 'has_engine', 'has_location', 'verified_cars'] as $field) {
            $this->assertObjectHasProperty($field, $completeness, "getDataCompleteness() must return '$field'");
        }
        $this->assertGreaterThan(0, (int) $completeness->total_cars, 'Registry must have at least one car');
    }

    /**
     * verified_cars (fresh) and the stale count partition the registry: every car
     * is one or the other, so the two add up to total_cars.
     *
     * The stale count comes from CarRepository::stalenessSql(), the negation of
     * the rule getDataCompleteness() uses, not from a copy of that SQL.
     */
    public function testGetDataCompletenessFreshPlusStaleEqualsTotal(): void
    {
        $completeness = (new StatisticsDataService($this->db))->getDataCompleteness();
        $this->assertNotNull($completeness);

        $stale = $this->db->query(
            'SELECT COALESCE(SUM(CASE WHEN ' . CarRepository::stalenessSql() . ' THEN 1 ELSE 0 END), 0) AS stale FROM cars'
        )->first();
        $this->assertFalse($this->db->error(), 'Stale count query must succeed: ' . $this->db->errorString());

        $this->assertSame(
            (int) $completeness->total_cars,
            (int) $completeness->verified_cars + (int) $stale->stale,
            'verified_cars + stale cars must equal total_cars'
        );
    }

    /**
     * Adding one fresh car and one stale car raises verified_cars by exactly 1
     * and total_cars by exactly 2.
     *
     * The stale car has last_verified and owner_last_updated both two years old.
     * Under COUNT(last_verified) this car would count, so it pins the old defect.
     */
    public function testGetDataCompletenessCountsOnlyFreshCars(): void
    {
        $service  = new StatisticsDataService($this->db);
        $baseline = $service->getDataCompleteness();
        $this->assertNotNull($baseline);

        $this->createTestCar($this->testUserId, ['last_verified' => date('Y-m-d H:i:s')]);

        $staleStamp = date('Y-m-d H:i:s', strtotime('-2 years'));
        $staleCarId = $this->createTestCar($this->testUserId, ['last_verified' => $staleStamp]);
        $this->seedOwnerLastUpdated($staleCarId, $staleStamp);

        $stored = $this->db->query(
            'SELECT last_verified, owner_last_updated FROM cars WHERE id = ?',
            [$staleCarId]
        )->first();
        $this->assertSame($staleStamp, (string) $stored->owner_last_updated, 'Stale fixture: owner_last_updated must be 2 years old');
        $this->assertNotNull($stored->last_verified, 'Stale fixture: last_verified must be set but old');
        $this->assertLessThan(strtotime('-1 year'), strtotime((string) $stored->last_verified));

        $after = $service->getDataCompleteness();
        $this->assertNotNull($after);

        $this->assertSame(1, (int) $after->verified_cars - (int) $baseline->verified_cars, 'Only the fresh car adds to verified_cars');
        $this->assertSame(2, (int) $after->total_cars - (int) $baseline->total_cars, 'Both cars add to total_cars');
    }
}
