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
if (!$user->isLoggedIn() || !hasPerm([1, 2], $user->data()->id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// CSRF protection
if (!isset($_POST['csrf']) || !Token::check($_POST['csrf'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Validate input
$query = trim($_POST['query'] ?? '');
if (empty($query) || strlen($query) < 2) {
    echo json_encode(['success' => false, 'message' => 'Search query must be at least 2 characters']);
    exit;
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

    // Log search activity
    logger($user->data()->id, 'OwnerActions', "Owner search performed: '{$query}' - {" . count($enhancedResults) . "} results");

    echo json_encode([
        'success' => true,
        'owners' => $enhancedResults,
        'total' => count($enhancedResults),
        'query' => $query
    ]);

} catch (Exception $e) {
    logger($user->data()->id, 'SystemError', 'Owner search failed: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Search failed. Please try again.'
    ]);
}
?>