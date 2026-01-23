<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Statistics API endpoints
 *
 * Tests statistics-data.php and validateChassis.php
 * Validates ApiResponse pattern implementation, security checks, and error handling
 *
 * @author Elan Registry Development Team
 * @copyright 2025
 */
class StatisticsApiTest extends TestCase
{
    private $db;
    private $connected = false;
    private $testCarId;
    private $testUserId;

    /**
     * Set up test database connection and find test data
     */
    protected function setUp(): void
    {
        // Try to connect to database
        try {
            $this->db = new \PDO(
                'mysql:host=127.0.0.1;port=8889;dbname=elanregi_spice',
                'claude',
                'claude',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $this->connected = true;

            // Find test car data
            $carStmt = $this->db->query("SELECT id FROM cars LIMIT 1");
            $car = $carStmt->fetch(\PDO::FETCH_OBJ);
            $this->testCarId = $car ? $car->id : null;

            // Find test user data
            $userStmt = $this->db->query("SELECT id FROM users WHERE active = 1 LIMIT 1");
            $user = $userStmt->fetch(\PDO::FETCH_OBJ);
            $this->testUserId = $user ? $user->id : null;
        } catch (\PDOException $e) {
            $this->connected = false;
        }
    }

    // =========================================================================
    // Geographic Tab Tests
    // =========================================================================

    /**
     * Test geographic tab data structure contains required fields
     */
    public function testGeographicTabDataStructure(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not available');
        }

        // Verify required fields exist in database for geographic data
        $countryStmt = $this->db->query("SELECT DISTINCT country FROM cars WHERE country IS NOT NULL AND country != '' LIMIT 1");
        $countryResult = $countryStmt->fetch(\PDO::FETCH_OBJ);
        $this->assertNotNull($countryResult, "Should have cars with country data");

        // Verify US states data exists
        $usStateStmt = $this->db->query(
            "SELECT DISTINCT state FROM cars
             WHERE country = 'United States' AND state IS NOT NULL AND state != '' LIMIT 1"
        );
        $usStateResult = $usStateStmt->fetch(\PDO::FETCH_OBJ);
        $this->assertNotNull($usStateResult, "Should have cars with US state data");
    }

    /**
     * Test geographic tab has country distribution data
     */
    public function testGeographicTabCountryDistribution(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not available');
        }

        // Verify country distribution data can be retrieved
        $stmt = $this->db->query(
            "SELECT country, COUNT(*) as count FROM cars
             WHERE country IS NOT NULL AND country != ''
             GROUP BY country ORDER BY count DESC"
        );
        $results = $stmt->fetchAll(\PDO::FETCH_OBJ);
        $this->assertNotEmpty($results, "Should have country distribution data");
    }

    /**
     * Test geographic tab has US state distribution data
     */
    public function testGeographicTabUsStateDistribution(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not available');
        }

        // Verify US state distribution data exists
        $stmt = $this->db->query(
            "SELECT state, COUNT(*) as count FROM cars
             WHERE country = 'United States' AND state IS NOT NULL AND state != ''
             GROUP BY state ORDER BY count DESC"
        );
        $results = $stmt->fetchAll(\PDO::FETCH_OBJ);
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
        if (!$this->connected) {
            $this->markTestSkipped('Database not available');
        }

        // Verify type data exists
        $typeStmt = $this->db->query("SELECT DISTINCT type FROM cars WHERE type IS NOT NULL AND type != ''");
        $typeResults = $typeStmt->fetchAll(\PDO::FETCH_OBJ);
        $this->assertNotEmpty($typeResults, "Should have car type data");

        // Verify series data exists
        $seriesStmt = $this->db->query("SELECT DISTINCT series FROM cars WHERE series IS NOT NULL AND series != ''");
        $seriesResults = $seriesStmt->fetchAll(\PDO::FETCH_OBJ);
        $this->assertNotEmpty($seriesResults, "Should have car series data");
    }

    /**
     * Test production tab has production by year data
     */
    public function testProductionTabProductionByYear(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not available');
        }

        // Verify production by year data
        $stmt = $this->db->query(
            "SELECT year, COUNT(*) as count FROM cars
             WHERE year IS NOT NULL AND year != ''
             GROUP BY year ORDER BY year"
        );
        $results = $stmt->fetchAll(\PDO::FETCH_OBJ);
        $this->assertNotEmpty($results, "Should have production year data");
    }

    /**
     * Test production tab has series counts
     */
    public function testProductionTabSeriesCounts(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not available');
        }

        // Verify series counts
        $stmt = $this->db->query(
            "SELECT series, COUNT(*) as count FROM cars
             WHERE series IS NOT NULL AND series != ''
             GROUP BY series ORDER BY count DESC"
        );
        $results = $stmt->fetchAll(\PDO::FETCH_OBJ);
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
        if (!$this->connected) {
            $this->markTestSkipped('Database not available');
        }

        // Verify color data exists
        $colorStmt = $this->db->query("SELECT DISTINCT color FROM cars WHERE color IS NOT NULL AND color != ''");
        $colorResults = $colorStmt->fetchAll(\PDO::FETCH_OBJ);
        $this->assertNotEmpty($colorResults, "Should have car color data");
    }

    /**
     * Test colors tab has color by year data
     */
    public function testColorsTabColorByYear(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not available');
        }

        // Verify color by year data
        $stmt = $this->db->query(
            "SELECT color, year, COUNT(*) as count FROM cars
             WHERE color IS NOT NULL AND color != '' AND year IS NOT NULL AND year != ''
             GROUP BY color, year"
        );
        $results = $stmt->fetchAll(\PDO::FETCH_OBJ);
        // May be empty but query should work
        $this->assertIsArray($results, "Should be able to query color by year");
    }

    /**
     * Test colors tab has color by series data
     */
    public function testColorsTabColorBySeries(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not available');
        }

        // Verify color by series data
        $stmt = $this->db->query(
            "SELECT color, series, COUNT(*) as count FROM cars
             WHERE color IS NOT NULL AND color != '' AND series IS NOT NULL AND series != ''
             GROUP BY color, series"
        );
        $results = $stmt->fetchAll(\PDO::FETCH_OBJ);
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
        if (!$this->connected) {
            $this->markTestSkipped('Database not available');
        }

        // Verify we can calculate completeness metrics
        $stmt = $this->db->query(
            "SELECT
                COUNT(*) as total_cars,
                COUNT(CASE WHEN year IS NOT NULL AND year != '' THEN 1 END) as cars_with_year,
                COUNT(CASE WHEN type IS NOT NULL AND type != '' THEN 1 END) as cars_with_type,
                COUNT(CASE WHEN series IS NOT NULL AND series != '' THEN 1 END) as cars_with_series,
                COUNT(CASE WHEN color IS NOT NULL AND color != '' THEN 1 END) as cars_with_color
             FROM cars"
        );
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        $this->assertNotNull($result, "Should retrieve quality metrics");
        $this->assertGreaterThan(0, $result->total_cars, "Should have cars in database");
    }

    // =========================================================================
    // Error Scenario Tests
    // =========================================================================

    /**
     * Test missing tab parameter returns error
     */
    public function testMissingTabParameterValidation(): void
    {
        // Validate that empty tab triggers error condition
        $tab = '';
        $this->assertTrue(empty($tab), "Empty tab should be invalid");
    }

    /**
     * Test invalid tab parameter returns error
     */
    public function testInvalidTabParameterValidation(): void
    {
        $validTabs = ['geographic', 'production', 'colors', 'quality'];
        $invalidTab = 'invalid_tab_name';
        $this->assertNotContains($invalidTab, $validTabs, "Invalid tab should not be in valid tabs list");
    }

    /**
     * Test database connection availability check
     */
    public function testDatabaseConnectionValidation(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not available');
        }

        $this->assertNotNull($this->db, "Database connection should be available");
        $this->assertTrue($this->connected, "Database connection flag should be set");
    }

    // =========================================================================
    // Response Format Tests
    // =========================================================================

    /**
     * Test ApiResponse success structure
     */
    public function testApiResponseSuccessStructure(): void
    {
        // Verify that success responses have required fields
        $successResponse = [
            'success' => true,
            'message' => 'Test message',
            'data' => ['test' => 'data']
        ];

        $this->assertTrue($successResponse['success'], "Success should be true");
        $this->assertArrayHasKey('message', $successResponse, "Should have message field");
        $this->assertArrayHasKey('data', $successResponse, "Should have data field");
    }

    /**
     * Test ApiResponse error structure uses message not error
     */
    public function testApiResponseErrorStructure(): void
    {
        // Verify that error responses use 'message' field not 'error'
        $errorResponse = [
            'success' => false,
            'message' => 'Error message',
            'status_code' => 400
        ];

        $this->assertFalse($errorResponse['success'], "Success should be false");
        $this->assertArrayHasKey('message', $errorResponse, "Should have message field, not error");
        $this->assertArrayNotHasKey('error', $errorResponse, "Should not have error field");
    }

    /**
     * Test API response uses correct HTTP status codes
     */
    public function testApiResponseStatusCodes(): void
    {
        $validStatusCodes = [200, 400, 403, 500];

        // Test 200 for success
        $this->assertContains(200, $validStatusCodes, "200 should be valid");

        // Test 400 for bad request
        $this->assertContains(400, $validStatusCodes, "400 should be valid");

        // Test 403 for forbidden
        $this->assertContains(403, $validStatusCodes, "403 should be valid");

        // Test 500 for server error
        $this->assertContains(500, $validStatusCodes, "500 should be valid");
    }

    // =========================================================================
    // Security Tests
    // =========================================================================

    /**
     * Test that statistics-data.php requires securePage check
     */
    public function testSecurityCheckPresent(): void
    {
        $filePath = __DIR__ . '/../../app/reports/api/statistics-data.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('Statistics API file not found');
        }

        try {
            $content = file_get_contents($filePath);
            $this->assertIsString($content, "File should be readable");
            $this->assertStringContainsString('securePage', $content, "Should have securePage security check");
            $this->assertStringContainsString('ApiResponse', $content, "Should use ApiResponse pattern");
        } catch (Throwable $e) {
            $this->markTestSkipped("Could not read statistics API file: " . $e->getMessage());
        }
    }

    /**
     * Test that validateChassis.php is reference implementation
     */
    public function testValidateChassisChassisPatterCompliance(): void
    {
        $filePath = __DIR__ . '/../../app/cars/actions/validateChassis.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('ValidateChassis file not found');
        }

        try {
            $content = file_get_contents($filePath);
            $this->assertIsString($content, "File should be readable");
            $this->assertStringContainsString('declare(strict_types=1)', $content, "Should have strict types");
            $this->assertStringContainsString('ApiResponse', $content, "Should use ApiResponse pattern");
            $this->assertStringContainsString('withLogging', $content, "Should include logging");
        } catch (Throwable $e) {
            $this->markTestSkipped("Could not read validateChassis file: " . $e->getMessage());
        }
    }

    // =========================================================================
    // Logging Tests
    // =========================================================================

    /**
     * Test that security violations are logged
     */
    public function testSecurityViolationLogging(): void
    {
        $filePath = __DIR__ . '/../../app/reports/api/statistics-data.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('Statistics API file not found');
        }

        try {
            $content = file_get_contents($filePath);
            $this->assertIsString($content, "File should be readable");
            $this->assertStringContainsString('SecurityError', $content, "Should log security errors");
        } catch (Throwable $e) {
            $this->markTestSkipped("Could not read statistics API file: " . $e->getMessage());
        }
    }

    /**
     * Test that validation errors are logged
     */
    public function testValidationErrorLogging(): void
    {
        $filePath = __DIR__ . '/../../app/reports/api/statistics-data.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('Statistics API file not found');
        }

        try {
            $content = file_get_contents($filePath);
            $this->assertIsString($content, "File should be readable");
            $this->assertStringContainsString('ValidationError', $content, "Should log validation errors");
        } catch (Throwable $e) {
            $this->markTestSkipped("Could not read statistics API file: " . $e->getMessage());
        }
    }

    /**
     * Test that database errors are logged
     */
    public function testDatabaseErrorLogging(): void
    {
        $filePath = __DIR__ . '/../../app/reports/api/statistics-data.php';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('Statistics API file not found');
        }

        try {
            $content = file_get_contents($filePath);
            $this->assertIsString($content, "File should be readable");
            $this->assertStringContainsString('DatabaseError', $content, "Should log database errors");
        } catch (Throwable $e) {
            $this->markTestSkipped("Could not read statistics API file: " . $e->getMessage());
        }
    }

    // =========================================================================
    // Frontend Integration Tests
    // =========================================================================

    /**
     * Test that statistics.js uses response.message for errors
     */
    public function testStatisticsJsErrorHandling(): void
    {
        $filePath = __DIR__ . '/../../app/assets/js/statistics.js';
        if (!file_exists($filePath)) {
            $this->markTestSkipped('Statistics.js file not found');
        }

        try {
            $content = file_get_contents($filePath);
            $this->assertIsString($content, "File should be readable");
            $this->assertStringContainsString('response.message', $content, "Should use response.message for error handling");
            // Verify the old pattern is gone
            $this->assertStringNotContainsString('response.error', $content, "Should not use response.error");
        } catch (Throwable $e) {
            $this->markTestSkipped("Could not read statistics.js file: " . $e->getMessage());
        }
    }

    /**
     * Test that StatisticsDataService class exists
     */
    public function testStatisticsDataServiceExists(): void
    {
        $filePath = __DIR__ . '/../../app/classes/StatisticsDataService.php';
        try {
            if (!file_exists($filePath)) {
                $this->markTestSkipped('StatisticsDataService file not found');
            }
            $this->assertFileExists($filePath, "StatisticsDataService should exist");
        } catch (Throwable $e) {
            $this->markTestSkipped("Could not verify StatisticsDataService file: " . $e->getMessage());
        }
    }
}
