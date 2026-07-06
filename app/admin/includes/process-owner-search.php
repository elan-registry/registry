<?php
declare(strict_types=1);

use ElanRegistry\Exceptions\OwnerSearchException;

/**
 * process-owner-search.php
 * AJAX endpoint for owner search functionality
 *
 * Implements ElanRegistryOwner search with data quality scoring
 */

// Include required files
require_once '../../../users/init.php';

requireAdminAjax('owner search', false);

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

} catch (OwnerSearchException $e) {
    ApiResponse::serverError($e->getUserMessage())
        ->withLogging(
            $user->data()->id,
            $e->getLogCategory(),
            'Owner search failed: ' . $e->getMessage()
        )
        ->send();
} catch (Exception $e) {
    ApiResponse::serverError('Search failed. Please try again.')
        ->withLogging(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            'Owner search unexpected error: ' . $e->getMessage()
        )
        ->send();
}
?>