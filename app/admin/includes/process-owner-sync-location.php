<?php
declare(strict_types=1);

use ElanRegistry\Exceptions\AdminOperationException;
use ElanRegistry\Exceptions\LocationServiceException;

/**
 * process-owner-sync-location.php
 * AJAX endpoint for syncing owner location to owned cars
 *
 * Uses Owner class to sync location data to all cars owned by the owner
 */

// Include required files
require_once '../../../users/init.php';

requireAdminAjax('location sync');

// Validate owner ID
$ownerId = (int)($_POST['owner_id'] ?? 0);
if ($ownerId <= 0) {
    ApiResponse::error('Invalid owner ID', 400)
        ->send();
}

try {
    // Load owner
    $owner = new Owner($ownerId);
    $ownerData = $owner->data();
    if (!$ownerData) {
        ApiResponse::notFound('Owner not found')
            ->send();
    }
    if (empty($ownerData->lat) || empty($ownerData->lon)) {
        ApiResponse::error(
            'Owner location coordinates are missing. Please update the owner\'s location first.',
            400
        )
        ->send();
    }

    // Sync location to all owned cars
    $carsUpdated = $owner->syncLocationToCars();

    if ($carsUpdated > 0) {
        ApiResponse::success("Successfully synchronized location to {$carsUpdated} car(s).")
            ->withData('cars_updated', $carsUpdated)
            ->withLogging(
                $user->data()->id,
                'OwnerActions',
                "Admin synchronized location from owner ID {$ownerId} to {$carsUpdated} cars (Admin: {$user->data()->fname} {$user->data()->lname})"
            )
            ->send();

    } else {
        ApiResponse::error('No cars found to update, or synchronization failed.', 400)
            ->send();
    }

} catch (LocationServiceException $e) {
    ApiResponse::serverError($e->getUserMessage())
        ->withLogging(
            $user->data()->id,
            $e->getLogCategory(),
            "Location sync error for owner ID {$ownerId}: " . $e->getMessage()
        )
        ->send();
} catch (AdminOperationException $e) {
    ApiResponse::serverError($e->getUserMessage())
        ->withLogging(
            $user->data()->id,
            $e->getLogCategory(),
            "Location sync error for owner ID {$ownerId}: " . $e->getMessage()
        )
        ->send();
} catch (Exception $e) {
    ApiResponse::serverError('Location synchronization failed. Please try again.')
        ->withLogging(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            "Location sync unexpected error for owner ID {$ownerId}: " . $e->getMessage()
        )
        ->send();
}
?>