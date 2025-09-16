<?php
declare(strict_types=1);

/**
 * Test file that intentionally FAILS CSRF validation
 * This simulates a form submission handler without proper CSRF protection
 */

require_once '../users/init.php';

// This should FAIL: Form processing without CSRF token validation
if ($_POST['action'] == 'update_car') {
    // Missing Token::check() validation - SECURITY VIOLATION
    $car_id = (int)$_POST['car_id'];
    $make = $_POST['make'];

    // Direct database update without CSRF protection (but using prepared statement)
    $db->query("UPDATE cars SET make = ? WHERE id = ?", [$make, $car_id]);

    echo "Car updated successfully";
}

/**
 * Another example of missing CSRF protection
 */
function processUserRegistration(): void {
    // This should FAIL: No CSRF validation before processing form
    if (!empty($_POST['username'])) {
        $username = $_POST['username'];
        $email = $_POST['email'];

        // Process registration without CSRF check - VIOLATION
        // Missing: if (!Token::check(Input::get('token'))) { ... }
    }
}