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

    // Check if location fields are being updated (for geocoding detection)
    $locationFields = ['city', 'state', 'country'];
    $hasLocationUpdate = false;
    $oldLocationData = [];

    foreach ($locationFields as $field) {
        if (isset($updateFields[$field])) {
            $hasLocationUpdate = true;
            $oldLocationData[$field] = $owner->data()->$field ?? '';
        }
    }

    // Capture old coordinates if location is being updated
    $oldLat = $owner->data()->lat ?? null;
    $oldLon = $owner->data()->lon ?? null;

    // Attempt to update owner profile
    $success = $owner->update($updateFields);

    if ($success) {
        // Get updated data to check for geocoding changes
        $updatedOwner = new ElanRegistryOwner($ownerId);
        $newLat = $updatedOwner->data()->lat ?? null;
        $newLon = $updatedOwner->data()->lon ?? null;

        // Check if geocoding was successful or failed
        $geocodingSuccess = false;
        $geocodingMessage = '';
        $geocodingFailed = false;

        if ($hasLocationUpdate) {
            // Build complete address for context
            $newAddress = trim(($updatedOwner->data()->city ?? '') . ', ' .
                             ($updatedOwner->data()->state ?? '') . ', ' .
                             ($updatedOwner->data()->country ?? ''));

            if ($newLat && $newLon && ($newLat != $oldLat || $newLon != $oldLat)) {
                // Coordinates changed - geocoding succeeded
                $geocodingSuccess = true;
                $geocodingMessage = "Location geocoded successfully! New coordinates: " . round($newLat, 4) . ", " . round($newLon, 4);
            } elseif (!$newLat || !$newLon) {
                // No coordinates at all - geocoding failed completely
                $geocodingFailed = true;
                $geocodingMessage = "Geocoding failed: Could not determine coordinates for '{$newAddress}'. Location text updated but no coordinates available.";
            } else {
                // Has coordinates but they didn't change - geocoding likely failed but old coordinates preserved
                $geocodingFailed = true;
                $geocodingMessage = "Geocoding may have failed for '{$newAddress}'. Previous coordinates retained: " . round($newLat, 4) . ", " . round($newLon, 4);
            }
        }

        // Get updated quality score
        $newQualityScore = $updatedOwner->getProfileQualityScore();
        $missingFields = $updatedOwner->validateProfileCompleteness();

        $response = [
            'success' => true,
            'message' => 'Owner profile updated successfully!',
            'quality_score' => $newQualityScore,
            'missing_fields' => $missingFields
        ];

        if ($geocodingSuccess) {
            $response['geocoding_success'] = true;
            $response['geocoding_message'] = $geocodingMessage;
            $response['new_coordinates'] = ['lat' => $newLat, 'lon' => $newLon];
        } elseif ($geocodingFailed) {
            $response['geocoding_failed'] = true;
            $response['geocoding_message'] = $geocodingMessage;
        } elseif (!empty($geocodingMessage)) {
            $response['geocoding_message'] = $geocodingMessage;
        }

        echo json_encode($response);

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