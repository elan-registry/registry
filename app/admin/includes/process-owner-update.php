<?php
declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\Exceptions\AdminOperationException;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use ElanRegistry\Exceptions\OwnerUpdateException;
use ElanRegistry\Exceptions\OwnerValidationException;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;

/**
 * process-owner-update.php
 * AJAX endpoint for updating owner profiles
 *
 * Processes owner profile updates using Owner class
 */

require_once '../../../users/init.php';

requireAdminAjax('owner update');

// Validate owner ID
$ownerId = (int)($_POST['owner_id'] ?? 0);
if ($ownerId <= 0) {
    ApiResponse::error('Invalid owner ID', 400)
        ->send();
}

try {
    // Load existing owner
    $owner = new Owner($ownerId);
    if (!$owner->data()) {
        ApiResponse::notFound('Owner not found')
            ->send();
    }

    // Prepare update fields
    $updateFields = [
        'id' => $ownerId,
    ];

    // Text fields: basic info and location (coordinates handled separately below)
    foreach (['fname', 'lname', 'email', 'website', 'city', 'state', 'country'] as $field) {
        if (isset($_POST[$field])) {
            $updateFields[$field] = trim($_POST[$field]);
        }
    }

    // Accept coordinates from location picker (frontend provides precise coordinates)
    if (isset($_POST['lat']) && $_POST['lat'] !== '' && isset($_POST['lon']) && $_POST['lon'] !== '') {
        $updateFields['lat'] = (float)$_POST['lat'];
        $updateFields['lon'] = (float)$_POST['lon'];
    }

    // Attempt to update owner profile — throws OwnerValidationException or OwnerUpdateException on failure
    // update() calls find() internally, so $owner->data() is already fresh after this returns
    $owner->update($updateFields);

    $newQualityScore = $owner->getProfileQualityScore();
    $missingFields = $owner->validateProfileCompleteness();

    // Push the updated contact fields onto the owner's cars, same as
    // usersc/user_settings.php and process-owner-sync-location.php already
    // do after their own writes to these fields (#1873). Without this, an
    // admin editing a member's profile here left every car stale until the
    // owner separately triggered a sync themselves — the one owner-field
    // write path this milestone otherwise missed.
    $syncMessage = '';
    try {
        $syncResult = $owner->syncOwnerFieldsToCars();
        if ($syncResult->isCompleteSuccess()) {
            if ($syncResult->updatedCount() > 0) {
                $syncMessage = " Synchronized to {$syncResult->updatedCount()} car(s).";
            }
        } else {
            // totalCount() includes skipped cars, so the denominator can
            // exceed updated + failed. Name the skips too, or the count
            // reads as unexplained missing cars (#1954).
            $syncMessage = sprintf(
                ' Synchronized to only %d of %d car(s). %s',
                $syncResult->updatedCount(),
                $syncResult->totalCount(),
                $syncResult->failedCarsPhrase()
            );
            if ($syncResult->skippedCount() > 0) {
                $syncMessage .= ' ' . $syncResult->skippedCarsPhrase();
            }
        }
    } catch (OwnerDatabaseException | CarDatabaseException $e) {
        // An infrastructure fault mid-sync: syncOwnerFieldsToCars() propagates rather
        // than reporting per-car failures, which discards the result — the profile
        // write above already committed, so this must degrade gracefully rather than
        // fail the whole request. Matches user_settings.php's precedent for this
        // exact failure mode.
        logger(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_DATABASE_ERROR,
            "process-owner-update.php: car owner-field sync failed for owner ID {$ownerId}: " . $e->getMessage()
        );
        $syncMessage = ' Car synchronization encountered an error; please contact support if this persists.';
    } catch (\Throwable $e) {
        // \Throwable, not Exception: PHP's Error hierarchy (TypeError et al.) does not
        // extend Exception, and syncOwnerFieldsToCars() builds its field bundle from
        // untyped $_data properties. Matches process-owner-sync-location.php's
        // precedent for this same class of failure.
        logger(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            'process-owner-update.php: unexpected ' . get_class($e)
                . " during owner-field sync for owner ID {$ownerId}: " . $e->getMessage()
        );
        $syncMessage = ' Car synchronization encountered an error; please contact support if this persists.';
    }

    ApiResponse::success('Owner profile updated successfully!' . $syncMessage)
        ->withDataArray([
            'quality_score' => $newQualityScore,
            'missing_fields' => $missingFields
        ])
        ->withLogging(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_OWNER_ACTIONS,
            "Updated owner profile for user ID {$ownerId} (Admin: {$user->data()->fname} {$user->data()->lname})"
        )
        ->send();

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
            LogCategories::LOG_CATEGORY_DATABASE_ERROR,
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