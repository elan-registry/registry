<?php
declare(strict_types=1);

use ElanRegistry\Exceptions\LocationServiceException;

/**
 * AJAX Endpoint: Location Reverse Geocoding
 *
 * Converts GPS coordinates (lat/lon) to standardized address format
 * using Nominatim API via LocationService.
 *
 * Used for HTML5 Geolocation (GPS) feature in location picker.
 *
 * @package ElanRegistry
 * @version 2.12.0
 * @since 2.11.0
 * @link https://github.com/unibrain1/elanregistry/issues/245
 */

require_once '../../users/init.php';

// Only allow POST requests
if ($method !== 'POST') {
    ApiResponse::error('Method not allowed', 405)->send();
}

// Get user ID (0 for anonymous users during registration)
$userId = $user->isLoggedIn() ? (int)$user->data()->id : 0;

// Verify CSRF token (required for all requests)
if (!Token::check(Input::get('csrf'))) {
    ApiResponse::forbidden('Invalid CSRF token')->send();
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
    ApiResponse::success('Reverse geocoding completed')
        ->withData('location', $result)
        ->withLogging($userId, 'LocationService', "Reverse geocoding: lat=$lat, lon=$lon")
        ->send();

} catch (LocationServiceException $e) {
    // Location service specific error (rate limit, API failure, invalid coordinates)
    ApiResponse::error($e->getMessage(), 400)
        ->withLogging($userId, 'LocationService', 'Reverse geocoding failed: ' . $e->getMessage())
        ->send();

} catch (\Throwable $e) {
    // Catch-all for unexpected errors (database, file system, etc.)
    ApiResponse::serverError('An error occurred while reverse geocoding coordinates')
        ->withLogging($userId, 'SystemError', 'Reverse geocoding error: ' . $e->getMessage())
        ->send();
}
