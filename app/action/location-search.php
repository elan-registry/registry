<?php
declare(strict_types=1);

use ElanRegistry\Exceptions\LocationServiceException;

/**
 * AJAX Endpoint: Location Search
 *
 * Provides autocomplete suggestions for location searches using
 * Photon and Nominatim APIs via LocationService.
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
    // Get search query
    $query = Input::get('query');
    if (empty($query)) {
        throw new LocationServiceException('Search query is required');
    }

    // Validate query length
    if (strlen($query) < 2) {
        throw new LocationServiceException('Search query must be at least 2 characters');
    }

    // Get optional limit parameter
    $limit = Input::get('limit') ? (int)Input::get('limit') : 8;
    if ($limit < 1 || $limit > 20) {
        $limit = 8;
    }

    // Create LocationService instance and search
    $locationService = new LocationService();
    $results = $locationService->searchLocation($query, $userId, $limit);

    // Return results
    ApiResponse::success('Search completed')
        ->withData('results', $results)
        ->withData('count', count($results))
        ->withLogging($userId, 'LocationService', 'Location search: ' . $query)
        ->send();

} catch (LocationServiceException $e) {
    // Location service specific error (rate limit, API failure, etc.)
    ApiResponse::error($e->getMessage(), 400)
        ->withLogging($userId, 'LocationService', 'Location search failed: ' . $e->getMessage())
        ->send();

} catch (\Throwable $e) {
    // Catch-all for unexpected errors (database, file system, etc.)
    ApiResponse::serverError('An error occurred while searching locations')
        ->withLogging($userId, 'SystemError', 'Location search error: ' . $e->getMessage())
        ->send();
}
