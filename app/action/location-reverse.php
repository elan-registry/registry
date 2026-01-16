<?php
declare(strict_types=1);

/**
 * AJAX Endpoint: Location Reverse Geocoding
 *
 * Converts GPS coordinates (lat/lon) to standardized address format
 * using Nominatim API via LocationService.
 *
 * Used for HTML5 Geolocation (GPS) feature in location picker.
 *
 * @package ElanRegistry
 * @version 2.11.0
 * @since 2.11.0
 * @link https://github.com/unibrain1/elanregistry/issues/245
 */

require_once '../../users/init.php';

// Set JSON header
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit;
}

// Get user ID (0 for anonymous users during registration)
$userId = $user->isLoggedIn() ? (int)$user->data()->id : 0;

// Verify CSRF token (required for all requests)
if (!Token::check(Input::get('csrf'))) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid CSRF token'
    ]);
    exit;
}

try {
    // Get coordinates
    $lat = Input::get('lat');
    $lon = Input::get('lon');

    if ($lat === null || $lon === null) {
        throw new LocationServiceException('Latitude and longitude are required');
    }

    // Convert to float
    $lat = (float)$lat;
    $lon = (float)$lon;

    // Create LocationService instance and reverse geocode
    $locationService = new LocationService();
    $result = $locationService->reverseGeocode($lat, $lon, $userId);

    // Return result
    echo json_encode([
        'success' => true,
        'location' => $result
    ]);

} catch (LocationServiceException $e) {
    // Location service specific error (rate limit, API failure, invalid coordinates)
    http_response_code($e->getMessage() === 'Rate limit exceeded. Please try again in a moment.' ? 429 : 400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);

    // Log error
    logger(
        $userId,
        'LocationService',
        'Reverse geocoding failed: ' . $e->getMessage()
    );

} catch (\Throwable $e) {
    // Catch-all for unexpected errors (database, file system, etc.)
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while reverse geocoding coordinates'
    ]);

    // Log error
    logger(
        $userId,
        'SystemError',
        'Reverse geocoding error: ' . $e->getMessage()
    );
}
