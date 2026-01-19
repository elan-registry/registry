<?php
declare(strict_types=1);
/**
 * process-owner-search.php
 * AJAX endpoint for owner search functionality
 *
 * Implements ElanRegistryOwner search with data quality scoring
 */

// Include required files
require_once '../../../users/init.php';

// Security check - admin permission required
if (!$user->isLoggedIn() || !isRegistryAdmin($user->data()->id)) {
    ApiResponse::forbidden('Unauthorized access')
        ->withLogging(0, 'SecurityError', 'Unauthorized owner search attempt')
        ->send();
}

// CSRF protection
if (!isset($_POST['csrf']) || !Token::check($_POST['csrf'])) {
    ApiResponse::error('Invalid CSRF token', 400)
        ->withLogging($user->data()->id, 'SecurityError', 'Invalid CSRF token in owner search')
        ->send();
}

// Validate input
$query = trim($_POST['query'] ?? '');
if (empty($query) || strlen($query) < 2) {
    ApiResponse::error('Search query must be at least 2 characters', 400)
        ->send();
}

try {
    // Use ElanRegistryOwner search functionality
    $ownerManager = new ElanRegistryOwner();
    $searchResults = $ownerManager->searchOwners($query, 25); // Limit to 25 results

    // Enhance results with data quality scoring
    $enhancedResults = [];
    foreach ($searchResults as $owner) {
        $ownerProfile = new ElanRegistryOwner((int)$owner->id);
        $qualityScore = $ownerProfile->getProfileQualityScore();

        $enhancedResults[] = [
            'id' => (int)$owner->id,
            'fname' => htmlspecialchars($owner->fname ?? ''),
            'lname' => htmlspecialchars($owner->lname ?? ''),
            'email' => htmlspecialchars($owner->email ?? ''),
            'city' => htmlspecialchars($owner->city ?? ''),
            'state' => htmlspecialchars($owner->state ?? ''),
            'country' => htmlspecialchars($owner->country ?? ''),
            'quality_score' => $qualityScore
        ];
    }

    // Return success response with search results
    ApiResponse::success("Found " . count($enhancedResults) . " owner(s)")
        ->withDataArray([
            'owners' => $enhancedResults,
            'total' => count($enhancedResults),
            'query' => $query
        ])
        ->withLogging(
            $user->data()->id,
            'OwnerActions',
            "Owner search performed: '{$query}' - " . count($enhancedResults) . " results"
        )
        ->send();

} catch (Exception $e) {
    ApiResponse::serverError('Search failed. Please try again.')
        ->withLogging(
            $user->data()->id,
            'SystemError',
            'Owner search failed: ' . $e->getMessage()
        )
        ->send();
}
?>