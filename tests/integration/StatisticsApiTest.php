<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * Integration tests for Statistics API endpoints
 *
 * Tests statistics.php and chassis-validate.php
 * Validates ApiResponse pattern implementation, security checks, and error handling
 *
 * @author Elan Registry Development Team
 * @copyright 2025
 */
class StatisticsApiTest extends IntegrationTestCase
{
    private $testCarId;
    private $testUserId;

    /**
     * Set up test database connection and find test data
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Find test car data
        $car = $this->db->query("SELECT id FROM cars LIMIT 1")->first();
        $this->testCarId = $car ? $car->id : null;

        // Find test user data
        $user = $this->db->query("SELECT id FROM users WHERE active = 1 LIMIT 1")->first();
        $this->testUserId = $user ? $user->id : null;
    }

    // =========================================================================
    // Geographic Tab Tests
    // =========================================================================

    /**
     * Test geographic tab data structure contains required fields
     */
    public function testGeographicTabDataStructure(): void
    {
        // Verify required fields exist in database for geographic data
        $countryResult = $this->db->query("SELECT DISTINCT country FROM cars WHERE country IS NOT NULL AND country != '' LIMIT 1")->first();
        $this->assertNotNull($countryResult, "Should have cars with country data");

        // Verify US states data exists
        $usStateResult = $this->db->query(
            "SELECT DISTINCT state FROM cars
             WHERE country = 'United States' AND state IS NOT NULL AND state != '' LIMIT 1"
        )->first();
        $this->assertNotNull($usStateResult, "Should have cars with US state data");
    }

    /**
     * Test geographic tab has country distribution data
     */
    public function testGeographicTabCountryDistribution(): void
    {

        // Verify country distribution data can be retrieved
        $results = $this->db->query(
            "SELECT country, COUNT(*) as count FROM cars
             WHERE country IS NOT NULL AND country != ''
             GROUP BY country ORDER BY count DESC"
        )->results();
        $this->assertNotEmpty($results, "Should have country distribution data");
    }

    /**
     * Test geographic tab has US state distribution data
     */
    public function testGeographicTabUsStateDistribution(): void
    {

        // Verify US state distribution data exists
        $results = $this->db->query(
            "SELECT state, COUNT(*) as count FROM cars
             WHERE country = 'United States' AND state IS NOT NULL AND state != ''
             GROUP BY state ORDER BY count DESC"
        )->results();
        // May be empty if no US cars, but query should work
        $this->assertIsArray($results, "Should be able to query US states");
    }

    // =========================================================================
    // Production Tab Tests
    // =========================================================================

    /**
     * Test production tab data structure contains required fields
     */
    public function testProductionTabDataStructure(): void
    {

        // Verify type data exists
        $typeResults = $this->db->query("SELECT DISTINCT type FROM cars WHERE type IS NOT NULL AND type != ''")->results();
        $this->assertNotEmpty($typeResults, "Should have car type data");

        // Verify series data exists
        $seriesResults = $this->db->query("SELECT DISTINCT series FROM cars WHERE series IS NOT NULL AND series != ''")->results();
        $this->assertNotEmpty($seriesResults, "Should have car series data");
    }

    /**
     * Test production tab has production by year data
     */
    public function testProductionTabProductionByYear(): void
    {

        // Verify production by year data
        $results = $this->db->query(
            "SELECT year, COUNT(*) as count FROM cars
             WHERE year IS NOT NULL AND year != ''
             GROUP BY year ORDER BY year"
        )->results();
        $this->assertNotEmpty($results, "Should have production year data");
    }

    /**
     * Test production tab has series counts
     */
    public function testProductionTabSeriesCounts(): void
    {

        // Verify series counts
        $results = $this->db->query(
            "SELECT series, COUNT(*) as count FROM cars
             WHERE series IS NOT NULL AND series != ''
             GROUP BY series ORDER BY count DESC"
        )->results();
        $this->assertNotEmpty($results, "Should have series count data");
    }

    // =========================================================================
    // Colors Tab Tests
    // =========================================================================

    /**
     * Test colors tab data structure contains required fields
     */
    public function testColorsTabDataStructure(): void
    {

        // Verify color data exists
        $colorResults = $this->db->query("SELECT DISTINCT color FROM cars WHERE color IS NOT NULL AND color != ''")->results();
        $this->assertNotEmpty($colorResults, "Should have car color data");
    }

    /**
     * Test colors tab has color by year data
     */
    public function testColorsTabColorByYear(): void
    {

        // Verify color by year data
        $results = $this->db->query(
            "SELECT color, year, COUNT(*) as count FROM cars
             WHERE color IS NOT NULL AND color != '' AND year IS NOT NULL AND year != ''
             GROUP BY color, year"
        )->results();
        // May be empty but query should work
        $this->assertIsArray($results, "Should be able to query color by year");
    }

    /**
     * Test colors tab has color by series data
     */
    public function testColorsTabColorBySeries(): void
    {

        // Verify color by series data
        $results = $this->db->query(
            "SELECT color, series, COUNT(*) as count FROM cars
             WHERE color IS NOT NULL AND color != '' AND series IS NOT NULL AND series != ''
             GROUP BY color, series"
        )->results();
        // May be empty but query should work
        $this->assertIsArray($results, "Should be able to query color by series");
    }

    // =========================================================================
    // Quality Tab Tests
    // =========================================================================

    /**
     * Test quality tab can retrieve data completeness metrics
     */
    public function testQualityTabDataCompleteness(): void
    {

        // Verify we can calculate completeness metrics
        $result = $this->db->query(
            "SELECT
                COUNT(*) as total_cars,
                COUNT(CASE WHEN year IS NOT NULL AND year != '' THEN 1 END) as cars_with_year,
                COUNT(CASE WHEN type IS NOT NULL AND type != '' THEN 1 END) as cars_with_type,
                COUNT(CASE WHEN series IS NOT NULL AND series != '' THEN 1 END) as cars_with_series,
                COUNT(CASE WHEN color IS NOT NULL AND color != '' THEN 1 END) as cars_with_color
             FROM cars"
        )->first();
        $this->assertNotNull($result, "Should retrieve quality metrics");
        $this->assertGreaterThan(0, $result->total_cars, "Should have cars in database");
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

    /**
     * statistics.php endpoint handles missing and invalid tab parameters with 400 responses.
     *
     * Replaced a tautological test that asserted hardcoded status codes against
     * a hardcoded list built in the same test.
     */
    public function testStatisticsApiEndpointValidatesTabParameter(): void
    {
        $filePath = __DIR__ . '/../../app/api/shared/statistics.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('Statistics API file not found');
        }

        $content = file_get_contents($filePath);

        // Missing tab → 400
        $this->assertStringContainsString('empty($tab)', $content, 'Must check empty($tab) for missing-parameter validation');
        $this->assertMatchesRegularExpression('/ApiResponse::error\([^)]*400/', $content, 'Must return 400 for missing tab');

        // Invalid tab → 400 via default case
        $this->assertStringContainsString('default:', $content, 'Switch must have a default case for unknown tabs');

        // All four valid tab names must be present as switch cases
        foreach (['geographic', 'production', 'colors', 'quality'] as $tab) {
            $this->assertStringContainsString("case '$tab':", $content, "Switch must handle tab '$tab'");
        }
    }

    /**
     * Test database connection availability check
     */
    public function testDatabaseConnectionValidation(): void
    {
        $this->assertNotNull($this->db, "Database connection should be available");
        $this->assertTrue($this->databaseConnected, "Database connection flag should be set");
    }

    // =========================================================================
    // Security Tests
    // =========================================================================

    /**
     * Test that statistics.php is a public endpoint without securePage (v2.25.3 #1059)
     */
    public function testSecurityCheckAbsent(): void
    {
        $filePath = __DIR__ . '/../../app/api/shared/statistics.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('Statistics API file not found');
        }

        $content = file_get_contents($filePath);
        $this->assertIsString($content, "File should be readable");
        $this->assertStringNotContainsString('securePage', $content, "Public statistics endpoint must not use securePage()");
        $this->assertStringContainsString('ApiResponse', $content, "Should use ApiResponse pattern");
    }

    /**
     * Test that chassis-validate.php is reference implementation
     */
    public function testValidateChassisPatternCompliance(): void
    {
        $filePath = __DIR__ . '/../../app/api/cars/chassis-validate.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('ValidateChassis file not found');
        }

        $content = file_get_contents($filePath);
        $this->assertIsString($content, "File should be readable");
        $this->assertStringContainsString('declare(strict_types=1)', $content, "Should have strict types");
        $this->assertStringContainsString('ApiResponse', $content, "Should use ApiResponse pattern");
        $this->assertStringContainsString('withLogging', $content, "Should include logging");
    }

    // =========================================================================
    // Logging Tests
    // =========================================================================

    /**
     * Test that validation errors are logged (public endpoint — no security log category)
     */
    public function testValidationAndDatabaseErrorsLogged(): void
    {
        $filePath = __DIR__ . '/../../app/api/shared/statistics.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('Statistics API file not found');
        }

        $content = file_get_contents($filePath);
        $this->assertIsString($content, "File should be readable");
        $this->assertStringNotContainsString('LOG_CATEGORY_SECURITY', $content, "Public statistics endpoint must not log security violations");
        $this->assertStringContainsString('LOG_CATEGORY_VALIDATION_ERROR', $content, "Should log validation errors via LogCategories");
        $this->assertStringContainsString('LOG_CATEGORY_DATABASE_ERROR', $content, "Should log database errors via LogCategories");
    }

    /**
     * Test that validation errors are logged
     */
    public function testValidationErrorLogging(): void
    {
        $filePath = __DIR__ . '/../../app/api/shared/statistics.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('Statistics API file not found');
        }

        $content = file_get_contents($filePath);
        $this->assertIsString($content, "File should be readable");
        $this->assertStringContainsString('LOG_CATEGORY_VALIDATION_ERROR', $content, "Should log validation errors via LogCategories");
    }

    /**
     * Test that database errors are logged
     */
    public function testDatabaseErrorLogging(): void
    {
        $filePath = __DIR__ . '/../../app/api/shared/statistics.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('Statistics API file not found');
        }

        $content = file_get_contents($filePath);
        $this->assertIsString($content, "File should be readable");
        $this->assertStringContainsString('LOG_CATEGORY_DATABASE_ERROR', $content, "Should log database errors via LogCategories");
    }

    // =========================================================================
    // Frontend Integration Tests
    // =========================================================================

    /**
     * Test that statistics.js uses NotificationHelper for API error handling (not innerHTML injection)
     */
    public function testStatisticsJsErrorHandling(): void
    {
        $filePath = __DIR__ . '/../../app/assets/js/statistics.js';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('Statistics.js file not found');
        }

        $content = file_get_contents($filePath);
        $this->assertIsString($content, "File should be readable");
        $this->assertStringNotContainsString('content.html(', $content, "Should not use innerHTML injection for error display");
        $this->assertStringContainsString('NotificationHelper.show(', $content, "Should use NotificationHelper for error display");
    }

    /**
     * Test that StatisticsDataService class exists
     */
    public function testStatisticsDataServiceExists(): void
    {
        $filePath = __DIR__ . '/../../usersc/classes/StatisticsDataService.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('StatisticsDataService file not found');
        }
        $this->assertFileExists($filePath, "StatisticsDataService should exist");
    }
}
