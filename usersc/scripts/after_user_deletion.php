<?php
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
 * covers profiles/car_user/cars cleanup only — not the user row deletion itself.
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
    } catch (\Exception $e) {
        $repo->rollback();
        logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, 'Cleanup failed, rolled back: ' . $e->getMessage());
        return false;
    }
};

// Find the "no owner" user dynamically
$noOwnerQuery = $db->query('SELECT id FROM users WHERE username = ?', ['noowner']);
if ($noOwnerQuery->count() > 0) {
    $noOwnerUserId = (int) $noOwnerQuery->first()->id;

    // Capture car list before cleanup so we can log per-car after commit
    $userCars = $db->query('SELECT car_id FROM car_user WHERE userid = ?', [$id])->results();
    $carCount = count($userCars);

    $committed = $inTransaction(function () use ($db, $repo, $id, $noOwnerUserId, $userCars): void {
        $db->query('DELETE FROM profiles WHERE user_id = ?', [$id]);
        $repo->deleteCarUserByUserId($id);
        foreach ($userCars as $car) {
            $repo->insertCarUser($noOwnerUserId, (int) $car->car_id);
        }
        // Update primary car ownership (this triggers cars_hist via database trigger)
        $db->query('UPDATE cars SET user_id = ? WHERE user_id = ?', [$noOwnerUserId, $id]);
    });

    if (!$committed) {
        return;
    }

    // Log after commit — only record what was actually persisted
    foreach ($userCars as $car) {
        logger($id, LogCategories::LOG_CATEGORY_CAR_ACTIONS, "User deletion: car ID {$car->car_id} reassigned from user $id to noowner (ID: $noOwnerUserId)");
    }
    logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, "Complete cleanup: reassigned $carCount cars to noowner user (ID: $noOwnerUserId)");
} else {
    // Fallback if noowner doesn't exist - preserve cars but mark as ownerless
    $committed = $inTransaction(function () use ($db, $repo, $id): void {
        $db->query('DELETE FROM profiles WHERE user_id = ?', [$id]);
        $repo->deleteCarUserByUserId($id);
        $db->query('UPDATE cars SET user_id = NULL WHERE user_id = ?', [$id]);
    });

    if (!$committed) {
        return;
    }

    logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, 'Fallback cleanup: noowner user not found, set cars to NULL');
}
