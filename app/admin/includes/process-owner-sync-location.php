<?php
declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\Exceptions\AdminOperationException;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use ElanRegistry\Exceptions\LocationServiceException;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;

/**
 * process-owner-sync-location.php
 * AJAX endpoint for syncing an owner's contact fields to their owned cars
 *
 * Uses Owner::syncOwnerFieldsToCars() to copy the nine denormalized
 * owner-contact columns — fname, lname, email, city, state, country, lat, lon,
 * website — onto every car the owner owns. The filename and route are retained
 * for the existing admin UI caller (app/admin/assets/js/load-owner-profile.js).
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
    // No coordinate pre-check: the sync covers nine owner-contact fields, so an
    // owner without lat/lon still has eight worth pushing to their cars (#1873).

    // Sync the owner's contact fields to all owned cars
    $syncResult = $owner->syncOwnerFieldsToCars();

    if (!$syncResult->isCompleteSuccess()) {
        $failedList = implode(', ', $syncResult->failed);
        // totalCount() includes skipped cars, so the denominator can exceed
        // updated + failed. Name the skips too, or the count reads as
        // unexplained missing cars (#1954).
        $partialMessage = sprintf(
            'Synchronized owner details to only %d of %d car(s). %s',
            $syncResult->updatedCount(),
            $syncResult->totalCount(),
            $syncResult->failedCarsPhrase()
        );
        if ($syncResult->skippedCount() > 0) {
            $partialMessage .= ' ' . $syncResult->skippedCarsPhrase();
        }
        ApiResponse::error(
            $partialMessage,
            500
        )
            ->withData('cars_updated', $syncResult->updatedCount())
            ->withData('cars_failed', $syncResult->failed)
            ->withData('cars_skipped', $syncResult->skipped)
            ->withLogging(
                $user->data()->id,
                LogCategories::LOG_CATEGORY_OWNER_ACTIONS,
                "Admin owner-field sync for owner ID {$ownerId} partially failed: "
                . "{$syncResult->updatedCount()} of {$syncResult->totalCount()} cars updated, "
                . "failed car IDs: {$failedList}"
                . ($syncResult->skippedCount() > 0 ? "; {$syncResult->skippedCarsPhrase()}" : '')
                . " (Admin: {$user->data()->fname} {$user->data()->lname})"
            )
            ->send();
    }

    if ($syncResult->totalCount() === 0) {
        ApiResponse::success('This owner has no cars to synchronize.')
            ->withData('cars_updated', 0)
            ->send();
    }

    // successMessage() words a skip-only outcome (updatedCount() === 0) as "No
    // cars were synchronized." rather than "...to 0 car(s)." — the latter
    // reads as a failure even though nothing here needed updating (#1954).
    ApiResponse::success($syncResult->successMessage())
        ->withData('cars_updated', $syncResult->updatedCount())
        ->withData('cars_skipped', $syncResult->skipped)
        ->withLogging(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_OWNER_ACTIONS,
            "Admin synchronized owner details from owner ID {$ownerId} to {$syncResult->updatedCount()} cars (Admin: {$user->data()->fname} {$user->data()->lname})"
        )
        ->send();

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
} catch (OwnerDatabaseException | CarDatabaseException $e) {
    // An infrastructure fault mid-sync: syncOwnerFieldsToCars() propagates rather
    // than reporting per-car failures, which discards the result — so some cars may
    // already have synced and committed. Say so, rather than implying nothing
    // happened. Retrying is safe: the sync is idempotent.
    ApiResponse::serverError(
        'Owner synchronization failed partway through. Some cars may already have been '
        . 'updated; retrying is safe. See the logs for the cars that completed.'
    )
        ->withLogging(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_DATABASE_ERROR,
            "Owner-field sync DB failure for owner ID {$ownerId}: " . $e->getMessage()
        )
        ->send();
} catch (\Throwable $e) {
    // \Throwable, not Exception: PHP's Error hierarchy (TypeError et al.) does not
    // extend Exception, and syncOwnerFieldsToCars() builds its field bundle from
    // untyped $_data properties. An escaping Error would end this AJAX request as an
    // uncaught fatal, sending the client an HTML error page instead of JSON and
    // leaving no row in `logs` at all.
    ApiResponse::serverError('Owner field synchronization failed. Please try again.')
        ->withLogging(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            'Owner-field sync unexpected ' . get_class($e) . " for owner ID {$ownerId}: " . $e->getMessage()
        )
        ->send();
}
?>