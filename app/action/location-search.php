<?php
declare(strict_types=1);

/**
 * AJAX Endpoint: Location Search
 *
 * Provides autocomplete suggestions for location searches using
 * Photon and Nominatim APIs via LocationService.
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
    echo json_encode([
        'success' => true,
        'results' => $results,
        'count' => count($results)
    ]);

} catch (LocationServiceException $e) {
    // Location service specific error (rate limit, API failure, etc.)
    http_response_code(429); // Too Many Requests for rate limit
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);

    // Log error
    logger(
        $userId,
        'LocationService',
        'Location search failed: ' . $e->getMessage()
    );

} catch (\Throwable $e) {
    // Catch-all for unexpected errors (database, file system, etc.)
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while searching locations'
    ]);

    // Log error
    logger(
        $userId,
        'SystemError',
        'Location search error: ' . $e->getMessage()
    );
}
