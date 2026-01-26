<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * LocationGeocoderTest / LocationServiceTest
 *
 * Integration tests for location geocoding services using OpenStreetMap APIs:
 * - Forward geocoding (address → coordinates) via Photon/Nominatim
 * - Reverse geocoding (coordinates → address) via Nominatim
 * - Input validation
 * - Rate limiting
 * - Coordinate precision
 * - Error handling
 *
 * LocationService is the modern replacement for deprecated LocationGeocoder.
 * Uses free OpenStreetMap APIs (Photon/Nominatim) instead of Google Maps.
 *
 * Tests assume user ID 1 for rate limiting and logging context.
 *
 * @group Integration
 * @group Geocoding
 */
class LocationGeocoderTest extends IntegrationTestCase
{
    protected const TEST_USER_ID = 1;
    private LocationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->service = new LocationService();
    }

    /**
     * Test that LocationService class exists and has required methods
     */
    public function testLocationServiceClassStructure(): void
    {
        $this->assertTrue(class_exists(LocationService::class), 'LocationService class exists');

        $reflection = new ReflectionClass(LocationService::class);
        $this->assertTrue($reflection->hasMethod('searchLocation'), 'searchLocation method exists');
        $this->assertTrue($reflection->hasMethod('reverseGeocode'), 'reverseGeocode method exists');
        $this->assertTrue($reflection->hasMethod('validateCoordinates'), 'validateCoordinates method exists');
    }

    /**
     * Test forward geocoding with valid address
     * Tests: Portland, Oregon, United States → coordinates via Photon/Nominatim
     */
    public function testForwardGeocodingPortland(): void
    {

        try {
            $results = $this->service->searchLocation('Portland Oregon', self::TEST_USER_ID, 5);
        } catch (LocationServiceException $e) {
            $this->markTestSkipped('Location service unavailable: ' . $e->getMessage());
        }

        $this->assertIsArray($results, "Search should return array");
        $this->assertNotEmpty($results, "Should find Portland results");

        // Check first result structure
        $result = $results[0];
        $this->assertArrayHasKey('lat', $result, "Result should have latitude");
        $this->assertArrayHasKey('lon', $result, "Result should have longitude");
        $this->assertArrayHasKey('city', $result, "Result should have city");
        $this->assertArrayHasKey('country', $result, "Result should have country");

        // Verify data types
        $this->assertIsNumeric($result['lat'], "Latitude should be numeric");
        $this->assertIsNumeric($result['lon'], "Longitude should be numeric");

        // Verify Portland, OR is in reasonable bounds
        // Portland, OR is approximately at 45.52°N, 122.68°W
        $this->assertGreaterThan(45, $result['lat'], "Portland latitude should be > 45");
        $this->assertLessThan(46, $result['lat'], "Portland latitude should be < 46");
        $this->assertLessThan(-122, $result['lon'], "Portland longitude should be < -122");
        $this->assertGreaterThan(-123, $result['lon'], "Portland longitude should be > -123");

        echo "\n✓ Forward geocoding successful: Portland → ({$result['lat']}, {$result['lon']})\n";
    }

    /**
     * Test forward geocoding with London, UK
     */
    public function testForwardGeocodingLondon(): void
    {

        try {
            $results = $this->service->searchLocation('London United Kingdom', self::TEST_USER_ID, 5);
        } catch (LocationServiceException $e) {
            $this->markTestSkipped('Location service unavailable: ' . $e->getMessage());
        }

        $this->assertNotEmpty($results, "Should find London results");

        $result = $results[0];

        // London is approximately at 51.51°N, 0.13°W
        $this->assertGreaterThan(51, $result['lat'], "London latitude should be > 51");
        $this->assertLessThan(52, $result['lat'], "London latitude should be < 52");
        $this->assertGreaterThan(-1, $result['lon'], "London longitude should be > -1");
        $this->assertLessThan(1, $result['lon'], "London longitude should be < 1");

        echo "\n✓ Forward geocoding successful: London → ({$result['lat']}, {$result['lon']})\n";
    }

    /**
     * Test reverse geocoding with valid coordinates
     * Tests: 45.52°N, 122.68°W (Portland, OR) → address
     */
    public function testReverseGeocodingPortland(): void
    {

        $lat = 45.52;
        $lon = -122.68;

        try {
            $result = $this->service->reverseGeocode($lat, $lon, self::TEST_USER_ID);
        } catch (LocationServiceException $e) {
            $this->markTestSkipped('Location service unavailable: ' . $e->getMessage());
        }

        $this->assertIsArray($result, "Reverse geocode should return array");
        $this->assertArrayHasKey('lat', $result, "Result should have latitude");
        $this->assertArrayHasKey('lon', $result, "Result should have longitude");
        $this->assertArrayHasKey('city', $result, "Result should have city");
        $this->assertArrayHasKey('country', $result, "Result should have country");

        // Verify coordinates match input
        $this->assertEquals(45.52, $result['lat'], "Latitude should match input");
        $this->assertEquals(-122.68, $result['lon'], "Longitude should match input");

        // Should identify Portland, Oregon area
        $this->assertNotEmpty($result['city'], "Should identify city");
        $this->assertNotEmpty($result['country'], "Should identify country");

        echo "\n✓ Reverse geocoding successful: (45.52, -122.68) → {$result['city']}, {$result['country']}\n";
    }

    /**
     * Test reverse geocoding with London coordinates
     */
    public function testReverseGeocodingLondon(): void
    {

        $lat = 51.51;
        $lon = -0.13;

        try {
            $result = $this->service->reverseGeocode($lat, $lon, self::TEST_USER_ID);
        } catch (LocationServiceException $e) {
            $this->markTestSkipped('Location service unavailable: ' . $e->getMessage());
        }

        $this->assertArrayHasKey('city', $result, "Should identify city");
        $this->assertArrayHasKey('country', $result, "Should identify country");

        echo "\n✓ Reverse geocoding successful: (51.51, -0.13) → {$result['city']}, {$result['country']}\n";
    }

    /**
     * Test coordinate validation
     */
    public function testCoordinateValidation(): void
    {

        // Valid coordinates
        $this->assertTrue(
            $this->service->validateCoordinates(45.52, -122.68),
            "Valid coordinates should pass"
        );

        $this->assertTrue(
            $this->service->validateCoordinates(51.51, -0.13),
            "Valid London coordinates should pass"
        );

        // Invalid latitude
        $this->assertFalse(
            $this->service->validateCoordinates(91.0, 0.0),
            "Latitude > 90 should fail"
        );

        $this->assertFalse(
            $this->service->validateCoordinates(-91.0, 0.0),
            "Latitude < -90 should fail"
        );

        // Invalid longitude
        $this->assertFalse(
            $this->service->validateCoordinates(0.0, 181.0),
            "Longitude > 180 should fail"
        );

        $this->assertFalse(
            $this->service->validateCoordinates(0.0, -181.0),
            "Longitude < -180 should fail"
        );

        echo "\n✓ Coordinate validation working correctly\n";
    }

    /**
     * Test search with too short query
     */
    public function testSearchWithShortQuery(): void
    {

        $this->expectException(LocationServiceException::class);
        $this->expectExceptionMessage('at least 2 characters');

        $this->service->searchLocation('A', self::TEST_USER_ID);
    }

    /**
     * Test reverse geocode with invalid coordinates
     */
    public function testReverseGeocodeWithInvalidCoordinates(): void
    {

        // Invalid latitude
        $this->expectException(LocationServiceException::class);
        $this->service->reverseGeocode(91.0, 0.0, self::TEST_USER_ID);
    }

    /**
     * Test coordinate precision (should be 4 decimal places)
     */
    public function testCoordinatePrecision(): void
    {

        try {
            $results = $this->service->searchLocation('Portland Oregon', self::TEST_USER_ID, 1);
        } catch (LocationServiceException $e) {
            $this->markTestSkipped('Location service unavailable: ' . $e->getMessage());
        }

        $result = $results[0];
        $latStr = (string)$result['lat'];
        $lonStr = (string)$result['lon'];

        // Count decimal places
        if (strpos($latStr, '.') !== false) {
            $latDecimals = strlen(substr(strrchr($latStr, '.'), 1));
            $this->assertLessThanOrEqual(4, $latDecimals, "Latitude should have ≤ 4 decimal places");
        }

        if (strpos($lonStr, '.') !== false) {
            $lonDecimals = strlen(substr(strrchr($lonStr, '.'), 1));
            $this->assertLessThanOrEqual(4, $lonDecimals, "Longitude should have ≤ 4 decimal places");
        }

        echo "\n✓ Coordinates properly rounded to 4 decimal places (~11m accuracy)\n";
    }

    /**
     * Test that search returns expected result structure
     */
    public function testSearchResultStructure(): void
    {

        try {
            $results = $this->service->searchLocation('Paris France', self::TEST_USER_ID, 1);
        } catch (LocationServiceException $e) {
            $this->markTestSkipped('Location service unavailable: ' . $e->getMessage());
        }

        $this->assertNotEmpty($results, "Should return results");

        $result = $results[0];

        // Verify all expected fields are present
        $expectedFields = ['city', 'state', 'country', 'lat', 'lon', 'display'];
        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $result, "Result should have '{$field}' field");
        }

        // Verify types
        $this->assertIsString($result['city'], "City should be string");
        $this->assertIsString($result['country'], "Country should be string");
        $this->assertIsNumeric($result['lat'], "Latitude should be numeric");
        $this->assertIsNumeric($result['lon'], "Longitude should be numeric");

        echo "\n✓ Search result structure is correct\n";
    }

}
