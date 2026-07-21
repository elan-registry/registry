<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;

/**
 * User Deletion Cleanup Script
 *
 * This script is called whenever you delete a user. It cleans up related data
 * for GDPR compliance and database integrity.
 *
 * Available variables:
 * - $id: The user ID being deleted
 * - $db: Database instance (global)
 *
 * Note: deleteUsers() (users/helpers/users.php) has already removed the `users`
 * and `user_permission_matches` rows before this script runs. This transaction
 * covers profiles/cars cleanup only — not the user row deletion itself.
 */

$repo = new \ElanRegistry\Car\CarRepository($db);
$id = (int) $id;

/**
 * Run $work inside a transaction, rolling back and returning false on failure.
 *
 * @param callable(): void $work
 * @return bool
 */
$inTransaction = function (callable $work) use ($repo, $id): bool {
    $repo->beginTransaction();
    try {
        $work();
        $repo->commit();
        return true;
    } catch (\Throwable $e) {
        $repo->rollback();
        logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, 'Cleanup failed, rolled back: ' . $e->getMessage());
        return false;
    }
};

// Find the "no owner" user dynamically
$noOwnerQuery = $db->query('SELECT id FROM users WHERE username = ?', ['noowner']);
if ($db->error()) {
    logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, 'CRITICAL: noowner lookup query failed during cleanup: ' . $db->errorString());
    return;
}
if ($noOwnerQuery->count() > 0) {
    $noOwnerUserId = (int) $noOwnerQuery->first()->id;

    // Capture car list before cleanup so we can log per-car after commit
    $userCars = $repo->findByOwner($id);
    $carCount = count($userCars);

    $adminUserId = currentUserId();
    $committed = $inTransaction(function () use ($db, $id, $noOwnerUserId, $userCars, $adminUserId): void {
        // Expire any non-terminal transfer requests the user initiated — prevents orphaned
        // requester FK references and ensures the current car owner sees a clean audit trail.
        $db->query(
            "UPDATE car_transfer_requests
                SET status = 'expired',
                    completed_date = NOW(),
                    admin_notes = IF(admin_notes IS NULL, 'Account deleted', CONCAT(admin_notes, ' | Account deleted'))
             WHERE requested_by_user_id = ? AND status IN ('pending', 'approved')",
            [$id]
        );
        if ($db->error()) {
            throw new \RuntimeException("Failed to expire pending transfer requests for user $id: " . $db->errorString());
        }
        $db->query('DELETE FROM profiles WHERE user_id = ?', [$id]);
        if ($db->error()) {
            throw new \RuntimeException("Failed to delete profile for user $id: " . $db->errorString());
        }
        // Transfer each car using the same code path as the admin reassign UI — updates
        // user_id and all denormalized owner fields (email, fname, lname, city, etc.).
        // $adminUserId is resolved above from currentUserId() (always valid — this hook
        // runs only from admin-authenticated deleteUsers() callers).
        foreach ($userCars as $carObj) {
            $car = new \ElanRegistry\Car\Car((int) $carObj->id);
            $car->transfer(
                $noOwnerUserId,
                "Account deleted — reassigned to noowner (ID: $noOwnerUserId)",
                'NEWOWNER',
                $adminUserId
            );
        }
    });

    if (!$committed) {
        return;
    }

    // Log after commit — only record what was actually persisted
    foreach ($userCars as $car) {
        logger($id, LogCategories::LOG_CATEGORY_CAR_ACTIONS, "User deletion: car ID {$car->id} reassigned from user $id to noowner (ID: $noOwnerUserId)");
    }
    logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, "Complete cleanup: reassigned $carCount cars to noowner user (ID: $noOwnerUserId)");
} else {
    // Fallback if noowner doesn't exist - preserve cars but mark as ownerless
    $committed = $inTransaction(function () use ($db, $repo, $id): void {
        $db->query(
            "UPDATE car_transfer_requests
                SET status = 'expired',
                    completed_date = NOW(),
                    admin_notes = IF(admin_notes IS NULL, 'Account deleted', CONCAT(admin_notes, ' | Account deleted'))
             WHERE requested_by_user_id = ? AND status IN ('pending', 'approved')",
            [$id]
        );
        if ($db->error()) {
            throw new \RuntimeException("Failed to expire pending transfer requests for user $id: " . $db->errorString());
        }
        $db->query('DELETE FROM profiles WHERE user_id = ?', [$id]);
        if ($db->error()) {
            throw new \RuntimeException("Failed to delete profile for user $id: " . $db->errorString());
        }
        $repo->reassignCarsByUser($id, null);
    });

    if (!$committed) {
        return;
    }

    logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, 'Fallback cleanup: noowner user not found, set cars to NULL');
}
