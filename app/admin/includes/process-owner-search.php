<?php
declare(strict_types=1);

use ElanRegistry\Exceptions\OwnerSearchException;

/**
 * process-owner-search.php
 * AJAX endpoint for owner search functionality
 *
 * Implements Owner search with data quality scoring
 */

require_once '../../../users/init.php';

requireAdminAjax('owner search', false);

// Validate input
$query = trim($_POST['query'] ?? '');
if (empty($query) || strlen($query) < 2) {
    ApiResponse::error('Search query must be at least 2 characters', 400)
        ->send();
}
if (strlen($query) > 100) {
    ApiResponse::error('Search query must be 100 characters or less', 400)
        ->send();
}

try {
    $searchResults = Owner::searchOwners($query, 25);

    // Enhance results with data quality scoring
    $enhancedResults = [];
    foreach ($searchResults as $owner) {
        $ownerProfile = new Owner((int)$owner->id);
        $qualityScore = $ownerProfile->getProfileQualityScore();

        $enhancedResults[] = [
            'id' => (int)$owner->id,
            'fname' => htmlspecialchars($owner->fname ?? '', ENT_QUOTES, 'UTF-8'),
            'lname' => htmlspecialchars($owner->lname ?? '', ENT_QUOTES, 'UTF-8'),
            'email' => htmlspecialchars($owner->email ?? '', ENT_QUOTES, 'UTF-8'),
            'city' => htmlspecialchars($owner->city ?? '', ENT_QUOTES, 'UTF-8'),
            'state' => htmlspecialchars($owner->state ?? '', ENT_QUOTES, 'UTF-8'),
            'country' => htmlspecialchars($owner->country ?? '', ENT_QUOTES, 'UTF-8'),
            'quality_score' => $qualityScore
        ];
    }

    $resultCount = count($enhancedResults);
    ApiResponse::success("Found {$resultCount} owner(s)")
        ->withDataArray([
            'owners' => $enhancedResults,
            'total' => $resultCount,
            'query' => $query
        ])
        ->withLogging(
            $user->data()->id,
            'OwnerActions',
            "Owner search performed: '{$query}' - {$resultCount} results"
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