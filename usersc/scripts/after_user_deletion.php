<?php

declare(strict_types=1);

use ElanRegistry\AccountDeletionPrivacyStep;
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
 * and `user_permission_matches` rows before this script runs. The
 * `users_delete_capture_addresses` trigger keeps the row's addresses for the
 * privacy step (AccountDeletionPrivacyStep). The privacy step and the car
 * cleanup each run in their own transaction — not the user row deletion itself.
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

// A user ID below 1 matches no row. Each later step would then work on the
// wrong rows or on none, so the hook stops here.
if ($id < 1) {
    logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, sprintf('CRITICAL: after_user_deletion received user ID %d, cleanup skipped', $id));
    return;
}

// Privacy step (#2358, #2419). It runs first, in its own transaction, so that
// each early return below keeps no personal data and leaves no transaction
// open. A failure rolls back this step only, and the car cleanup still runs.
$privacyResult = (new AccountDeletionPrivacyStep($db, $repo))->run($id);

// deleteUsers() ignores a failed DELETE FROM users and still includes this
// hook. A refusal means the users row may still exist, so the car cleanup
// must not move a live owner's cars. The step already logged why it refused.
// This line records that the cars still name the ID, because no tool moves
// them later.
if ($privacyResult->refused) {
    logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, sprintf(
        'CRITICAL: car cleanup skipped for user %d because the privacy step refused; the cars and pending transfer requests still name this ID',
        $id
    ));
    return;
}

// deleteUsers() deleted the users row just before this hook, so the
// users_delete_capture_addresses trigger wrote a holding row. A committed step
// that deleted none means the trigger is missing (for example after a UserSpice
// upgrade rebuilt users), and the current and pending address rows stay. It can
// also mean that a concurrent delete removed the users row first, so this
// delete removed no row and the trigger did not fire.
if ($privacyResult->committed && $privacyResult->deletedHoldingRowCount === 0) {
    logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, sprintf(
        'CRITICAL: privacy step for user %d found no er_deleted_user_addresses row; check trigger users_delete_capture_addresses, or the users row was already gone before this deletion',
        $id
    ));
}

// Capture the car list before cleanup so both branches below can log per car
// after commit.
try {
    $userCars = $repo->findByOwner($id);
} catch (CarDatabaseException $e) {
    logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, 'Cleanup aborted: findByOwner failed — ' . $e->getMessage());
    return;
}
$carCount = count($userCars);

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
    // callers (tab-account_cleanup.php and the upstream users/views/_admin_user.php
    // page, both admin-only). ADR-010 documents a potential future cron caller — if that is ever
    // added without a session, this catch will silently abort car reassignment (logged
    // only), leaving cars pointing at the now-deleted user ID. At that point, resolve
    // $adminUserId via a dedicated service/system account rather than currentUserId().
    try {
        $adminUserId = currentUserId();
    } catch (\RuntimeException $e) {
        logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, 'Cleanup aborted: no authenticated admin session — ' . $e->getMessage());
        return;
    }

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
        $repo->reassignCarsByUser($id, null);
    });

    if (!$committed) {
        return;
    }

    logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, 'Fallback cleanup: noowner user not found, set cars to NULL');
}
