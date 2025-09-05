<?php
/**
 * User Deletion Cleanup Script
 * 
 * This script is called whenever you delete a user. It cleans up related data
 * for GDPR compliance and database integrity.
 * 
 * Available variables:
 * - $id: The user ID being deleted
 * - $db: Database instance (global)
 */

// Find the "no owner" user dynamically
$noOwnerQuery = $db->query('SELECT id FROM users WHERE username = ?', ['noowner']);
if ($noOwnerQuery->count() > 0) {
    $noOwnerUserId = $noOwnerQuery->first()->id;
    
    // Get list of cars owned by deleted user before cleanup
    $userCarsQuery = $db->query('SELECT car_id FROM car_user WHERE userid = ?', [$id]);
    $userCars = $userCarsQuery->results();
    $carCount = count($userCars);
    
    // Clean up user profile record
    $db->query('DELETE FROM profiles WHERE user_id = ?', [$id]);
    
    // Clean up old car ownership records  
    $db->query('DELETE FROM car_user WHERE userid = ?', [$id]);
    
    // Reassign cars to noowner in car_user table
    foreach ($userCars as $car) {
        $db->query('INSERT INTO car_user (userid, car_id) VALUES (?, ?)', 
                   [$noOwnerUserId, $car->car_id]);
    }
    
    // Update primary car ownership (this triggers cars_hist via database trigger)
    $db->query('UPDATE cars SET user_id = ? WHERE user_id = ?', [$noOwnerUserId, $id]);
    
    // Log the cleanup for audit purposes
    logger($id, 'UserDeletion', "Complete cleanup: reassigned $carCount cars to noowner user (ID: $noOwnerUserId)");
} else {
    // Fallback if noowner doesn't exist - preserve cars but mark as ownerless
    $db->query('DELETE FROM profiles WHERE user_id = ?', [$id]);
    $db->query('DELETE FROM car_user WHERE userid = ?', [$id]);
    $db->query('UPDATE cars SET user_id = NULL WHERE user_id = ?', [$id]);
    
    // Log the fallback situation
    logger($id, 'UserDeletion', 'Fallback cleanup: noowner user not found, set cars to NULL');
}
