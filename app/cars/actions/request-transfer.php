<?php

/**
 * request-transfer.php - Car Transfer Request Handler
 *
 * Handles transfer requests for cars with duplicate chassis numbers.
 * Creates transfer request records in the database and notifies relevant parties.
 *
 * @author Elan Registry Team
 * @copyright 2025
 */

require_once '../../../users/init.php';

// Set JSON content type
header('Content-Type: application/json');

// Initialize response
$response = ['success' => false, 'message' => ''];

try {
    // Check CSRF token
    if (!Input::exists('post') || !Token::check(Input::get('csrf'))) {
        throw new Exception('Invalid request token');
    }

    // Check authentication
    if (!$user->isLoggedIn()) {
        throw new Exception('You must be logged in to request transfers');
    }

    // Get and validate input
    $chassis = trim(Input::get('chassis'));
    $year = trim(Input::get('year'));
    $model = trim(Input::get('model'));
    $color = trim(Input::get('color'));
    $engine = trim(Input::get('engine'));
    $comments = trim(Input::get('comments'));

    if (empty($chassis) || empty($year) || empty($model)) {
        throw new Exception('Chassis, year, and model are required');
    }

    // Parse model to get series, variant, type
    $modelParts = explode('|', $model);
    if (count($modelParts) !== 3) {
        throw new Exception('Invalid model format');
    }

    $series = $modelParts[0];
    $variant = $modelParts[1];
    $type = $modelParts[2];

    $db = DB::getInstance();

    // Find the existing car
    $existingCarQuery = $db->query(
        'SELECT id, user_id FROM cars WHERE year = ? AND type = ? AND chassis = ?',
        [$year, $type, $chassis]
    );

    if ($existingCarQuery->count() === 0) {
        throw new Exception('No car found with this chassis number');
    }

    $existingCar = $existingCarQuery->first();

    // Check if user is trying to transfer to themselves
    if ($existingCar->user_id == $user->data()->id) {
        throw new Exception('You already own this car');
    }

    // Check for existing pending transfer request
    $existingTransferQuery = $db->query(
        'SELECT id FROM car_transfer_requests WHERE existing_car_id = ? AND requested_by_user_id = ? AND status = "pending"',
        [$existingCar->id, $user->data()->id]
    );

    if ($existingTransferQuery->count() > 0) {
        throw new Exception('You already have a pending transfer request for this car');
    }

    // Generate security token
    $securityToken = hash('sha256', $existingCar->id . $user->data()->id . time() . rand());

    // Set expiration date (30 days from now)
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

    // Get user details for submitted fields
    $userData = $user->data();

    // Create transfer request
    $insertResult = $db->query(
        'INSERT INTO car_transfer_requests (
            existing_car_id, requested_by_user_id, security_token, expires_at,
            submitted_model, submitted_series, submitted_variant, submitted_year, submitted_type,
            submitted_chassis, submitted_color, submitted_engine, submitted_comments,
            submitted_email, submitted_fname, submitted_lname, submitted_city, submitted_state, submitted_country,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $existingCar->id,
            $user->data()->id,
            $securityToken,
            $expiresAt,
            $model,
            $series,
            $variant,
            $year,
            $type,
            $chassis,
            $color,
            $engine,
            $comments,
            $userData->email,
            $userData->fname,
            $userData->lname,
            $userData->city ?? '',
            $userData->state ?? '',
            $userData->country ?? '',
            $user->data()->id
        ]
    );

    if (!$insertResult) {
        throw new Exception('Failed to create transfer request');
    }

    // Get the transfer request ID
    $transferRequestId = $db->lastId();

    // Validate that we got a valid ID
    if ($transferRequestId <= 0) {
        logger($user->data()->id, 'DatabaseError', "Failed to get transfer request ID from lastId()");
        throw new Exception('Failed to retrieve transfer request ID');
    }

    // Log the transfer request creation
    logger($user->data()->id, 'CarTransfer', "Transfer request created for car ID {$existingCar->id}, chassis {$chassis}, transfer request ID: {$transferRequestId}");

    // Send email notifications
    require_once '../../../usersc/includes/transfer_email_notifications.php';

    // Send notification to current owner
    $ownerNotificationSent = sendTransferRequestNotification($transferRequestId);

    // Send alert to administrators
    $adminAlertSent = sendTransferRequestAdminAlert($transferRequestId);

    // Log email results
    if ($ownerNotificationSent) {
        logger($user->data()->id, 'EmailSuccess', "Transfer request notification sent to current owner for request #$transferRequestId");
    } else {
        logger($user->data()->id, 'EmailError', "Failed to send transfer request notification to current owner for request #$transferRequestId");
    }

    if ($adminAlertSent) {
        logger($user->data()->id, 'EmailSuccess', "Transfer request admin alert sent for request #$transferRequestId");
    } else {
        logger($user->data()->id, 'EmailError', "Failed to send transfer request admin alert for request #$transferRequestId");
    }

    $response['success'] = true;
    $response['message'] = 'Transfer request submitted successfully. Notifications have been sent to the current owner and registry administrators.';

} catch (Exception $e) {
    $response['message'] = $e->getMessage();

    // Log the error
    logger($user->data()->id ?? 0, 'CarTransferError', 'Transfer request failed: ' . $e->getMessage());
}

// Output JSON response
echo json_encode($response);
?>