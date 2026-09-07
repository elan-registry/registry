<?php

declare(strict_types=1);

use ElanRegistry\Exceptions\CarDatabaseException;
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

// Shadows the ambient page-scope $db global (untyped — mixed per phpstan-bootstrap.php)
// with the same underlying connection through the interface-typed handle. dbi() is
// memoized around DB::getInstance(), the same singleton the ambient $db already wraps,
// so this is the same connection and transaction state, not a second one.
$db = dbi();
$repo = new \ElanRegistry\Car\CarRepository($db);
$id = (int) ($id ?? 0);

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

// Capture car list before cleanup so both branches below can log per-car
// after commit and clean up er_email_events rows (#1887) before the
// car->owner link is severed. Hoisted above the noowner-exists split so both
// branches — including the fallback, which previously never fetched a car
// list at all before calling reassignCarsByUser() — capture it identically.
try {
    $userCars = $repo->findByOwner($id);
} catch (CarDatabaseException $e) {
    logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, 'Cleanup aborted: findByOwner failed — ' . $e->getMessage());
    return;
}
$carCount = count($userCars);
$userCarIds = array_column($userCars, 'id');

// Populated inside whichever transaction closure below runs, with the actual
// row count deleteEmailEventsForCarIds() reports, so the post-commit log line
// states what was actually deleted rather than merely how many cars were
// considered — the count() difference: a user with cars but no event history
// should log "0 rows", not imply cleanup that never happened.
$deletedEmailEventCount = 0;

// Find the "no owner" user dynamically
$noOwnerQuery = $db->query('SELECT id FROM users WHERE username = ?', ['noowner']);
if ($db->error()) {
    logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, 'CRITICAL: noowner lookup query failed during cleanup: ' . $db->errorString());
    return;
}
if ($noOwnerQuery->count() > 0) {
    $noOwnerUserId = (int) $noOwnerQuery->first()->id;

    // Resolve the acting admin ID before the transaction. currentUserId() throws
    // RuntimeException if there is no authenticated session; guard it so a bad-session
    // edge case logs and exits cleanly rather than propagating uncaught after the
    // users row has already been deleted.
    //
    // ASSUMPTION: this hook is only invoked from admin-authenticated deleteUsers()
    // callers (currently only tab-account_cleanup.php, gated by securePage() +
    // isAdmin()). ADR-010 documents a potential future cron caller — if that is ever
    // added without a session, this catch will silently abort car reassignment (logged
    // only), leaving cars pointing at the now-deleted user ID. At that point, resolve
    // $adminUserId via a dedicated service/system account rather than currentUserId().
    try {
        $adminUserId = currentUserId();
    } catch (\RuntimeException $e) {
        logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, 'Cleanup aborted: no authenticated admin session — ' . $e->getMessage());
        return;
    }

    $committed = $inTransaction(function () use ($db, $repo, $id, $noOwnerUserId, $userCars, $userCarIds, $adminUserId, &$deletedEmailEventCount): void {
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
        // #1887: clear the departing owner's email-webhook event history before the
        // car->owner link is severed below.
        $deletedEmailEventCount = $repo->deleteEmailEventsForCarIds($userCarIds);
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
    $committed = $inTransaction(function () use ($db, $repo, $id, $userCarIds, &$deletedEmailEventCount): void {
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
        // #1887: clear the departing owner's email-webhook event history before the
        // car->owner link is severed below.
        $deletedEmailEventCount = $repo->deleteEmailEventsForCarIds($userCarIds);
        $repo->reassignCarsByUser($id, null);
    });

    if (!$committed) {
        return;
    }

    logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, 'Fallback cleanup: noowner user not found, set cars to NULL');
}

logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, "er_email_events: deleted $deletedEmailEventCount rows across $carCount cars for user $id");
