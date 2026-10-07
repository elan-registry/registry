<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Exceptions\LocationServiceException;
use ElanRegistry\LocationService;

use PHPUnit\Framework\Attributes\Group;

/**
 * LocationService geocoding against Photon/Nominatim.
 *
 * Methods in the `live-network` group call the real providers and are
 * excluded from default runs (#1759). They are not mocked on purpose: they
 * prove live compatibility; parsing of canned responses is covered by
 * tests/unit/location/LocationServiceRateLimitTest.php. Run them with:
 *   vendor/bin/phpunit -c phpunit-integration.xml --group live-network
 */
#[Group('Integration')]
#[Group('Geocoding')]
class LocationServiceTest extends IntegrationTestCase
{
    protected const TEST_USER_ID = 1;
    private LocationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->service = new LocationService();
    }

    public function testLocationServiceClassStructure(): void
    {
        $this->assertTrue(class_exists(LocationService::class), 'LocationService class exists');

        $reflection = new ReflectionClass(LocationService::class);
        $this->assertTrue($reflection->hasMethod('searchLocation'), 'searchLocation method exists');
        $this->assertTrue($reflection->hasMethod('reverseGeocode'), 'reverseGeocode method exists');
        $this->assertTrue($reflection->hasMethod('validateCoordinates'), 'validateCoordinates method exists');
    }

    #[Group('live-network')]
    public function testForwardGeocodingPortland(): void
    {
        try {
            $results = $this->service->searchLocation('Portland Oregon', self::TEST_USER_ID, 5);
        } catch (LocationServiceException $e) {
            $this->markTestSkipped('Location service unavailable: ' . $e->getMessage());
        }

        $this->assertIsArray($results, "Search should return array");
        $this->assertNotEmpty($results, "Should find Portland results");

        $result = $results[0];
        $this->assertArrayHasKey('lat', $result, "Result should have latitude");
        $this->assertArrayHasKey('lon', $result, "Result should have longitude");
        $this->assertArrayHasKey('city', $result, "Result should have city");
        $this->assertArrayHasKey('country', $result, "Result should have country");

        $this->assertIsNumeric($result['lat'], "Latitude should be numeric");
        $this->assertIsNumeric($result['lon'], "Longitude should be numeric");

        // Portland, OR is approximately at 45.52°N, 122.68°W
        $this->assertGreaterThan(45, $result['lat'], "Portland latitude should be > 45");
        $this->assertLessThan(46, $result['lat'], "Portland latitude should be < 46");
        $this->assertLessThan(-122, $result['lon'], "Portland longitude should be < -122");
        $this->assertGreaterThan(-123, $result['lon'], "Portland longitude should be > -123");

        echo "\n✓ Forward geocoding successful: Portland → ({$result['lat']}, {$result['lon']})\n";
    }

    #[Group('live-network')]
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

    #[Group('live-network')]
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

        $this->assertEquals(45.52, $result['lat'], "Latitude should match input");
        $this->assertEquals(-122.68, $result['lon'], "Longitude should match input");

        $this->assertNotEmpty($result['city'], "Should identify city");
        $this->assertNotEmpty($result['country'], "Should identify country");

        echo "\n✓ Reverse geocoding successful: (45.52, -122.68) → {$result['city']}, {$result['country']}\n";
    }

    #[Group('live-network')]
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

    public function testCoordinateValidation(): void
    {
        $this->assertTrue(
            $this->service->validateCoordinates(45.52, -122.68),
            "Valid coordinates should pass"
        );

        $this->assertTrue(
            $this->service->validateCoordinates(51.51, -0.13),
            "Valid London coordinates should pass"
        );

        $this->assertFalse(
            $this->service->validateCoordinates(91.0, 0.0),
            "Latitude > 90 should fail"
        );

        $this->assertFalse(
            $this->service->validateCoordinates(-91.0, 0.0),
            "Latitude < -90 should fail"
        );

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

    public function testSearchWithShortQuery(): void
    {
        $this->expectException(LocationServiceException::class);
        $this->expectExceptionMessage('at least 2 characters');

        $this->service->searchLocation('A', self::TEST_USER_ID);
    }

    public function testReverseGeocodeWithInvalidCoordinates(): void
    {
        $this->expectException(LocationServiceException::class);
        $this->service->reverseGeocode(91.0, 0.0, self::TEST_USER_ID);
    }

    #[Group('live-network')]
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
     * #1400: the service must return distinct same-named cities in different
     * states so the frontend can tell them apart. Fails, not skips, if live
     * ranking stops returning 2+ states; then raise the limit or change the query.
     */
    #[Group('live-network')]
    public function testForwardGeocodingDisambiguatesSameNameCities(): void
    {
        try {
            $results = $this->service->searchLocation('Springfield', self::TEST_USER_ID, 8);
        } catch (LocationServiceException $e) {
            $this->markTestSkipped('Location service unavailable: ' . $e->getMessage());
        }

        $this->assertIsArray($results, "Search should return array");
        $this->assertNotEmpty($results, "Should find Springfield results");

        $states = [];
        foreach ($results as $result) {
            if (strcasecmp((string)($result['city'] ?? ''), 'Springfield') === 0) {
                $state = trim((string)($result['state'] ?? ''));
                if ($state !== '') {
                    $states[strtolower($state)] = $state;
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            2,
            count($states),
            'Expected at least two distinct states among Springfield results, got: '
                . implode(', ', $states)
        );

        echo "\n✓ Forward geocoding disambiguation: found Springfields in "
            . implode(', ', $states) . "\n";
    }

    #[Group('live-network')]
    public function testSearchResultStructure(): void
    {
        try {
            $results = $this->service->searchLocation('Paris France', self::TEST_USER_ID, 1);
        } catch (LocationServiceException $e) {
            $this->markTestSkipped('Location service unavailable: ' . $e->getMessage());
        }

        $this->assertNotEmpty($results, "Should return results");

        $result = $results[0];

        $expectedFields = ['city', 'state', 'country', 'lat', 'lon', 'display'];
        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $result, "Result should have '{$field}' field");
        }

        $this->assertIsString($result['city'], "City should be string");
        $this->assertIsString($result['country'], "Country should be string");
        $this->assertIsNumeric($result['lat'], "Latitude should be numeric");
        $this->assertIsNumeric($result['lon'], "Longitude should be numeric");

        echo "\n✓ Search result structure is correct\n";
    }

}
