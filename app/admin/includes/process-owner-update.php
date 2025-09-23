<?php
declare(strict_types=1);
/**
 * process-owner-update.php
 * AJAX endpoint for updating owner profiles
 *
 * Processes owner profile updates using ElanRegistryOwner class
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

// Validate owner ID
$ownerId = (int)($_POST['owner_id'] ?? 0);
if ($ownerId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid owner ID']);
    exit;
}

try {
    // Load existing owner
    $owner = new ElanRegistryOwner($ownerId);
    if (!$owner->data()) {
        echo json_encode(['success' => false, 'message' => 'Owner not found']);
        exit;
    }

    // Prepare update fields
    $updateFields = [
        'id' => $ownerId,
        'csrf' => $_POST['csrf']
    ];

    // Basic information fields
    $basicFields = ['fname', 'lname', 'email', 'website'];
    foreach ($basicFields as $field) {
        if (isset($_POST[$field])) {
            $updateFields[$field] = trim($_POST[$field]);
        }
    }

    // Location fields
    $locationFields = ['city', 'state', 'country'];
    foreach ($locationFields as $field) {
        if (isset($_POST[$field])) {
            $updateFields[$field] = trim($_POST[$field]);
        }
    }

    // Attempt to update owner profile
    $success = $owner->update($updateFields);

    if ($success) {
        // Get updated quality score
        $newQualityScore = $owner->getProfileQualityScore();
        $missingFields = $owner->validateProfileCompleteness();

        echo json_encode([
            'success' => true,
            'message' => 'Owner profile updated successfully!',
            'quality_score' => $newQualityScore,
            'missing_fields' => $missingFields
        ]);

        // Log the successful update
        logger($user->data()->id, 'OwnerActions', "Updated owner profile for user ID {$ownerId} (Admin: {$user->data()->fname} {$user->data()->lname})");

    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update owner profile. Please check your input and try again.'
        ]);
    }

} catch (OwnerValidationException $e) {
    // Validation error - return user-friendly message
    echo json_encode([
        'success' => false,
        'message' => 'Validation error: ' . $e->getMessage()
    ]);

    logger($user->data()->id, 'ValidationError', "Owner update validation failed for user ID {$ownerId}: " . $e->getMessage());

} catch (OwnerUpdateException $e) {
    // Update error - return user-friendly message
    echo json_encode([
        'success' => false,
        'message' => 'Update failed: ' . $e->getMessage()
    ]);

    logger($user->data()->id, 'DatabaseError', "Owner update failed for user ID {$ownerId}: " . $e->getMessage());

} catch (Exception $e) {
    // General error - log details but return generic message
    logger($user->data()->id, 'SystemError', "Owner update system error for user ID {$ownerId}: " . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please try again.'
    ]);
}
?>