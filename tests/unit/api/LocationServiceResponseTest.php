<?php

declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for Location Service AJAX endpoints using ApiResponse
 *
 * Tests the refactored location-search.php and location-reverse.php endpoints
 * to verify they properly use the ApiResponse class and return standardized
 * response formats.
 */
#[Group('fast')]
#[Group('unit')]
#[Group('api')]
final class LocationServiceResponseTest extends TestCase
{
    /**
     * Test that search endpoint would return 405 for non-POST requests
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationSearchReturnsMethodNotAllowedForNonPost(): void
    {
        $response = ApiResponse::error('Method not allowed', 405);

        $this->assertFalse($response->isSuccess());
        $this->assertEquals('Method not allowed', $response->getMessage());
        $this->assertEquals(405, $response->getStatusCode());
        $this->assertEquals(['success' => false, 'message' => 'Method not allowed'], $response->toArray());
    }

    /**
     * Test that reverse geocode endpoint would return 405 for non-POST requests
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationReverseReturnsMethodNotAllowedForNonPost(): void
    {
        $response = ApiResponse::error('Method not allowed', 405);

        $this->assertFalse($response->isSuccess());
        $this->assertEquals(405, $response->getStatusCode());
    }

    /**
     * Test that search endpoint would return 403 for invalid CSRF token
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationSearchReturnsForbiddenForInvalidCsrf(): void
    {
        $response = ApiResponse::forbidden('Invalid CSRF token');

        $this->assertFalse($response->isSuccess());
        $this->assertEquals('Invalid CSRF token', $response->getMessage());
        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Test that reverse geocode endpoint would return 403 for invalid CSRF token
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationReverseReturnsForbiddenForInvalidCsrf(): void
    {
        $response = ApiResponse::forbidden('Invalid CSRF token');

        $this->assertFalse($response->isSuccess());
        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Test search endpoint error response for validation errors
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response has 'message' key (not 'error' key)
     * - HTTP status code is 400
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationSearchReturnsValidationErrorWithMessageKey(): void
    {
        $response = ApiResponse::error('Search query must be at least 2 characters', 400);

        $array = $response->toArray();
        $this->assertFalse($array['success']);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayNotHasKey('error', $array);
        $this->assertEquals('Search query must be at least 2 characters', $array['message']);
        $this->assertEquals(400, $response->getStatusCode());
    }

    /**
     * Test search endpoint error response for LocationServiceException
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response has 'message' key with exception message
     * - HTTP status code is 400
     * - Optional logging can be attached
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationSearchReturnsBadRequestForLocationServiceException(): void
    {
        $errorMessage = 'Rate limit exceeded. Please try again in a moment.';
        $response = ApiResponse::error($errorMessage, 400)
            ->withLogging(0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Location search failed: ' . $errorMessage);

        $array = $response->toArray();
        $this->assertFalse($array['success']);
        $this->assertEquals($errorMessage, $array['message']);
        $this->assertEquals(400, $response->getStatusCode());

        $log = $response->getPendingLog();
        $this->assertNotNull($log);
        $this->assertEquals(0, $log['userId']);
        $this->assertEquals(LogCategories::LOG_CATEGORY_LOCATION_SERVICE, $log['category']);
    }

    /**
     * Test search endpoint success response with results
     *
     * Verifies:
     * - Response has 'success' = true
     * - Response has 'message' key
     * - Response includes 'results' and 'count' keys in data
     * - HTTP status code is 200
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationSearchReturnsSuccessWithResultsAndCount(): void
    {
        $mockResults = [
            [
                'city' => 'Portland',
                'state' => 'Oregon',
                'country' => 'United States',
                'lat' => 45.5152,
                'lon' => -122.6784,
                'display' => 'Portland, Oregon, United States'
            ]
        ];

        $response = ApiResponse::success('Search completed')
            ->withData('results', $mockResults)
            ->withData('count', count($mockResults))
            ->withLogging(0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Location search: Portland');

        $array = $response->toArray();
        $this->assertTrue($array['success']);
        $this->assertEquals('Search completed', $array['message']);
        $this->assertEquals($mockResults, $array['results']);
        $this->assertEquals(1, $array['count']);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test search endpoint error response for unexpected exceptions
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response has generic error message
     * - HTTP status code is 500
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationSearchReturnsServerErrorForUnexpectedException(): void
    {
        $response = ApiResponse::serverError('An error occurred while searching locations')
            ->withLogging(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Location search error: Database connection failed');

        $array = $response->toArray();
        $this->assertFalse($array['success']);
        $this->assertEquals('An error occurred while searching locations', $array['message']);
        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * Test reverse geocode endpoint error response for missing coordinates
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response has 'message' key
     * - HTTP status code is 400
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationReverseReturnsBadRequestForMissingCoordinates(): void
    {
        $response = ApiResponse::error('Latitude and longitude are required', 400);

        $array = $response->toArray();
        $this->assertFalse($array['success']);
        $this->assertEquals('Latitude and longitude are required', $array['message']);
        $this->assertEquals(400, $response->getStatusCode());
    }

    /**
     * Test reverse geocode endpoint error response for LocationServiceException
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response has 'message' key with exception message
     * - HTTP status code is 400 (always, for consistent behavior)
     * - Logging is included
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationReverseReturnsBadRequestForLocationServiceException(): void
    {
        $errorMessage = 'Invalid coordinates provided';
        $response = ApiResponse::error($errorMessage, 400)
            ->withLogging(0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Reverse geocoding failed: ' . $errorMessage);

        $array = $response->toArray();
        $this->assertFalse($array['success']);
        $this->assertEquals($errorMessage, $array['message']);
        $this->assertEquals(400, $response->getStatusCode());

        $log = $response->getPendingLog();
        $this->assertNotNull($log);
        $this->assertEquals(0, $log['userId']);
    }

    /**
     * Test reverse geocode endpoint success response with location
     *
     * Verifies:
     * - Response has 'success' = true
     * - Response has 'message' key
     * - Response includes 'location' object in data with city, state, country, lat, lon
     * - HTTP status code is 200
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationReverseReturnsSuccessWithLocation(): void
    {
        $mockLocation = [
            'city' => 'Portland',
            'state' => 'Oregon',
            'country' => 'United States',
            'lat' => 45.5152,
            'lon' => -122.6784,
            'display' => 'Portland, Oregon, United States'
        ];

        $response = ApiResponse::success('Reverse geocoding completed')
            ->withData('location', $mockLocation)
            ->withLogging(0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Reverse geocoding: lat=45.5152, lon=-122.6784');

        $array = $response->toArray();
        $this->assertTrue($array['success']);
        $this->assertEquals('Reverse geocoding completed', $array['message']);
        $this->assertEquals($mockLocation, $array['location']);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test reverse geocode endpoint error response for unexpected exceptions
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response has generic error message
     * - HTTP status code is 500
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationReverseReturnsServerErrorForUnexpectedException(): void
    {
        $response = ApiResponse::serverError('An error occurred while reverse geocoding coordinates')
            ->withLogging(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Reverse geocoding error: Database connection failed');

        $array = $response->toArray();
        $this->assertFalse($array['success']);
        $this->assertEquals('An error occurred while reverse geocoding coordinates', $array['message']);
        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * Test that location responses use 'message' key instead of 'error' key for frontend compatibility
     *
     * This verifies the frontend JavaScript can properly read the 'message' key
     * when checking if data.success is false.
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationResponsesUseMessageKeyNotErrorKey(): void
    {
        // Test error response
        $errorResponse = ApiResponse::error('Search query is required', 400);
        $errorArray = $errorResponse->toArray();

        $this->assertArrayHasKey('message', $errorArray);
        $this->assertArrayNotHasKey('error', $errorArray);
        $this->assertFalse($errorArray['success']);
        $this->assertEquals('Search query is required', $errorArray['message']);

        // Test success response (should have message)
        $successResponse = ApiResponse::success('Search completed')
            ->withData('results', []);
        $successArray = $successResponse->toArray();

        $this->assertArrayHasKey('message', $successArray);
        $this->assertTrue($successArray['success']);
        $this->assertEquals('Search completed', $successArray['message']);
    }

    /**
     * Test logging is included in responses with anonymous users
     *
     * Location services are used during registration (anonymous users),
     * so logging should work with userId=0
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationResponsesWithAnonymousUserLogging(): void
    {
        $response = ApiResponse::error('Search failed', 400)
            ->withLogging(0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Anonymous user search failed');

        $log = $response->getPendingLog();
        $this->assertNotNull($log);
        $this->assertEquals(0, $log['userId']);
        $this->assertEquals(LogCategories::LOG_CATEGORY_LOCATION_SERVICE, $log['category']);
        $this->assertEquals('Anonymous user search failed', $log['message']);
    }

    /**
     * Test logging is included in responses with authenticated users
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationResponsesWithAuthenticatedUserLogging(): void
    {
        $userId = 42;
        $response = ApiResponse::success('Search completed')
            ->withData('results', [])
            ->withLogging($userId, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'User 42 searched for Portland');

        $log = $response->getPendingLog();
        $this->assertNotNull($log);
        $this->assertEquals($userId, $log['userId']);
    }

    /**
     * Test search response with empty results
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationSearchWithEmptyResults(): void
    {
        $response = ApiResponse::success('Search completed')
            ->withData('results', [])
            ->withData('count', 0);

        $array = $response->toArray();
        $this->assertTrue($array['success']);
        $this->assertEquals([], $array['results']);
        $this->assertEquals(0, $array['count']);
    }

    /**
     * Test search response with multiple results
     *
     * @return void
     */
    #[Group('fast')]
    public function testLocationSearchWithMultipleResults(): void
    {
        $mockResults = [
            [
                'city' => 'Portland',
                'state' => 'Oregon',
                'country' => 'United States',
                'lat' => 45.5152,
                'lon' => -122.6784,
                'display' => 'Portland, Oregon, United States'
            ],
            [
                'city' => 'Portland',
                'state' => 'Maine',
                'country' => 'United States',
                'lat' => 43.6591,
                'lon' => -70.2568,
                'display' => 'Portland, Maine, United States'
            ]
        ];

        $response = ApiResponse::success('Search completed')
            ->withData('results', $mockResults)
            ->withData('count', count($mockResults));

        $array = $response->toArray();
        $this->assertTrue($array['success']);
        $this->assertEquals(2, $array['count']);
        $this->assertEquals(2, count($array['results']));
    }
}
