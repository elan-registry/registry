<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\StatisticsDataService;

/**
 * StatisticsDataService (behind the statistics.php endpoint) against real
 * fixtures. Tab validation: tests/unit/api/StatisticsEndpointValidationTest.php.
 */
class StatisticsApiTest extends IntegrationTestCase
{
    private $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Registry-wide aggregates must be true by construction, not by ambient data.
        $this->testUserId = $this->createTestUser();

        $this->createTestCar($this->testUserId, [
            'country' => 'United States',
            'state'   => 'California',
        ]);
    }

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

    public function testGetSeriesCountsReturnsAllSixKeys(): void
    {
        $service = new StatisticsDataService($this->db);
        $counts  = $service->getSeriesCounts();

        foreach (['s1', 's2', 's3', 's4', 'sprint', '+2'] as $key) {
            $this->assertArrayHasKey($key, $counts, "seriesCounts must have key '$key'");
            $this->assertIsNumeric($counts[$key], "seriesCounts['$key'] must be numeric");
        }
    }

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
     * Fresh and stale counts partition the registry. The stale count uses
     * CarRepository::stalenessSql(), not a copy of the SQL.
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
     * One fresh and one stale car: verified_cars +1, total_cars +2. Pins the
     * old COUNT(last_verified) defect.
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

    /**
     * A sold car with a fresh owner_last_updated counts: the figure follows
     * freshness only, not CarBadges. Hence the label "Fresh (12 mo)".
     */
    public function testGetDataCompletenessCountsFreshSoldCar(): void
    {
        $service  = new StatisticsDataService($this->db);
        $baseline = $service->getDataCompleteness();
        $this->assertNotNull($baseline);

        $soldCarId = $this->createTestCar($this->testUserId, ['solddate' => '2025-01-01']);
        $this->seedOwnerLastUpdated($soldCarId, date('Y-m-d H:i:s'));

        $after = $service->getDataCompleteness();
        $this->assertNotNull($after);

        $this->assertSame(1, (int) $after->verified_cars - (int) $baseline->verified_cars, 'A fresh sold car must add to verified_cars');
        $this->assertSame(1, (int) $after->total_cars - (int) $baseline->total_cars);
    }
}
