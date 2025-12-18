<?php
declare(strict_types=1);
/**
 * process-owner-sync-location.php
 * AJAX endpoint for syncing owner location to owned cars
 *
 * Uses ElanRegistryOwner class to sync location data to all cars owned by the owner
 */

// Include required files
require_once '../../../users/init.php';

// Security check - admin permission required
if (!$user->isLoggedIn() || !isRegistryAdmin($user->data()->id)) {
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
    // Load owner
    $owner = new ElanRegistryOwner($ownerId);
    if (!$owner->data()) {
        echo json_encode(['success' => false, 'message' => 'Owner not found']);
        exit;
    }

    // Check if owner has location data
    $ownerData = $owner->data();
    if (empty($ownerData->lat) || empty($ownerData->lon)) {
        echo json_encode([
            'success' => false,
            'message' => 'Owner location coordinates are missing. Please update the owner\'s location first.'
        ]);
        exit;
    }

    // Sync location to all owned cars
    $carsUpdated = $owner->syncLocationToCars();

    if ($carsUpdated > 0) {
        echo json_encode([
            'success' => true,
            'message' => "Successfully synchronized location to {$carsUpdated} car(s).",
            'cars_updated' => $carsUpdated
        ]);

        // Log the successful sync
        logger(
            $user->data()->id,
            'OwnerActions',
            "Admin synchronized location from owner ID {$ownerId} to {$carsUpdated} cars (Admin: {$user->data()->fname} {$user->data()->lname})"
        );

    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No cars found to update, or synchronization failed.'
        ]);
    }

} catch (Exception $e) {
    // Log error and return generic message
    logger($user->data()->id, 'SystemError', "Location sync error for owner ID {$ownerId}: " . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Location synchronization failed. Please try again.'
    ]);
}
?>