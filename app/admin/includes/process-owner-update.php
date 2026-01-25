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
if (!$user->isLoggedIn() || !isRegistryAdmin($user->data()->id)) {
    ApiResponse::forbidden('Unauthorized access')
        ->withLogging(0, 'SecurityError', 'Unauthorized owner update attempt')
        ->send();
}

// CSRF protection
if (!isset($_POST['csrf']) || !Token::check($_POST['csrf'])) {
    ApiResponse::error('Invalid CSRF token', 400)
        ->withLogging($user->data()->id, 'SecurityError', 'Invalid CSRF token in owner update')
        ->send();
}

// Validate owner ID
$ownerId = (int)($_POST['owner_id'] ?? 0);
if ($ownerId <= 0) {
    ApiResponse::error('Invalid owner ID', 400)
        ->send();
}

try {
    // Load existing owner
    $owner = new ElanRegistryOwner($ownerId);
    if (!$owner->data()) {
        ApiResponse::notFound('Owner not found')
            ->send();
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

    // Location fields (city, state, country, lat, lon)
    $locationFields = ['city', 'state', 'country'];
    foreach ($locationFields as $field) {
        if (isset($_POST[$field])) {
            $updateFields[$field] = trim($_POST[$field]);
        }
    }

    // Accept coordinates from location picker (frontend provides precise coordinates)
    if (!empty($_POST['lat']) && !empty($_POST['lon'])) {
        $updateFields['lat'] = (float)$_POST['lat'];
        $updateFields['lon'] = (float)$_POST['lon'];
    }

    // Attempt to update owner profile
    $success = $owner->update($updateFields);

    if ($success) {
        // Get updated data
        $updatedOwner = new ElanRegistryOwner($ownerId);

        // Get updated quality score
        $newQualityScore = $updatedOwner->getProfileQualityScore();
        $missingFields = $updatedOwner->validateProfileCompleteness();

        ApiResponse::success('Owner profile updated successfully!')
            ->withDataArray([
                'quality_score' => $newQualityScore,
                'missing_fields' => $missingFields
            ])
            ->withLogging(
                $user->data()->id,
                'OwnerActions',
                "Updated owner profile for user ID {$ownerId} (Admin: {$user->data()->fname} {$user->data()->lname})"
            )
            ->send();

    } else {
        ApiResponse::error(
            'Failed to update owner profile. Please check your input and try again.',
            400
        )
        ->send();
    }

} catch (OwnerValidationException $e) {
    ApiResponse::error(
        'Validation error: ' . $e->getUserMessage(),
        422
    )
    ->withLogging(
        $user->data()->id,
        $e->getLogCategory(),
        "Owner update validation failed for user ID {$ownerId}: " . $e->getMessage()
    )
    ->send();

} catch (OwnerUpdateException $e) {
    ApiResponse::serverError('Update failed: ' . $e->getUserMessage())
        ->withLogging(
            $user->data()->id,
            'DatabaseError',
            "Owner update failed for user ID {$ownerId}: " . $e->getMessage()
        )
        ->send();

} catch (AdminOperationException $e) {
    ApiResponse::serverError($e->getUserMessage())
        ->withLogging(
            $user->data()->id,
            $e->getLogCategory(),
            "Owner update error for user ID {$ownerId}: " . $e->getMessage()
        )
        ->send();
} catch (Exception $e) {
    ApiResponse::serverError('An unexpected error occurred. Please try again.')
        ->withLogging(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            "Owner update unexpected error for user ID {$ownerId}: " . $e->getMessage()
        )
        ->send();
}
?>