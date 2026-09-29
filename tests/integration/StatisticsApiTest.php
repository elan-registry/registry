<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

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
        foreach (['total_cars', 'has_chassis', 'has_color', 'has_engine', 'has_location'] as $field) {
            $this->assertObjectHasProperty($field, $completeness, "getDataCompleteness() must return '$field'");
        }
        $this->assertGreaterThan(0, (int) $completeness->total_cars, 'Registry must have at least one car');
    }
}
