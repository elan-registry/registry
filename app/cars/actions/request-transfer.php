<?php

declare(strict_types=1);

use ElanRegistry\Exceptions\CarTransferException;
use ElanRegistry\Transfer\TransferEmailService;

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

try {
    // Check CSRF token
    if (!Input::exists('post') || !Token::check(Input::get('csrf'))) {
        throw new CarTransferException('Invalid request token');
    }

    // Check authentication
    if (!$user->isLoggedIn()) {
        throw new CarTransferException('You must be logged in to request transfers');
    }

    if (!checkRateLimit('transfer_request', $user->data()->id)) {
        logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_ACCESS_DENIED, 'request-transfer.php: rate limit exceeded from ' . $remote_addr);
        recordRateLimit('transfer_request', false, (int)$user->data()->id);
        throw new CarTransferException('Too many transfer requests. Please wait before trying again.');
    }
    recordRateLimit('transfer_request', true, (int)$user->data()->id);

    // Get and validate input
    $chassis = trim(Input::get('chassis'));
    $year = trim(Input::get('year'));
    $model = trim(Input::get('model'));
    $color = trim(Input::get('color'));
    $engine = trim(Input::get('engine'));
    $comments = trim(Input::get('comments'));

    // Validate comment length (server-side validation)
    if (strlen($comments) > 1000) {
        throw new CarTransferException('Transfer explanation must be 1000 characters or less');
    }

    if (empty($chassis) || empty($year) || empty($model)) {
        throw new CarTransferException('Chassis, year, and model are required');
    }

    // Parse model to get series, variant, type
    $modelParts = explode('|', $model);
    if (count($modelParts) !== 3) {
        throw new CarTransferException('Invalid model format');
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
        throw new CarTransferException('No car found with this chassis number');
    }

    $existingCar = $existingCarQuery->first();

    // Check if user is trying to transfer to themselves
    if ($existingCar->user_id == $user->data()->id) {
        throw new CarTransferException('You already own this car');
    }

    // Check for existing pending transfer request
    $existingTransferQuery = $db->query(
        'SELECT id FROM car_transfer_requests WHERE existing_car_id = ? AND requested_by_user_id = ? AND status = "pending"',
        [$existingCar->id, $user->data()->id]
    );

    if ($existingTransferQuery->count() > 0) {
        throw new CarTransferException('You already have a pending transfer request for this car');
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
        throw new CarTransferException('Failed to create transfer request');
    }

    // Get the transfer request ID
    $transferRequestId = $db->lastId();

    // Validate that we got a valid ID
    if ($transferRequestId <= 0) {
        logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "Failed to get transfer request ID from lastId()");
        throw new CarTransferException('Failed to retrieve transfer request ID');
    }

    // Log the transfer request creation
    logger($user->data()->id, LogCategories::LOG_CATEGORY_CAR_TRANSFER, "Transfer request created for car ID {$existingCar->id}, chassis {$chassis}, transfer request ID: {$transferRequestId}");

    // Send email notifications with timeout protection
    $emailMessages = [];

    try {
        $emailService = new TransferEmailService(DB::getInstance(), 'email', $abs_us_root . $us_url_root);

        // Set time limit for email operations
        set_time_limit(60);

        // Send notification to current owner with error handling
        try {
            $emailMessages[] = $emailService->sendRequest($transferRequestId)
                ? 'Current owner notified'
                : 'Owner notification failed';
        } catch (Exception $emailEx) {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Unexpected exception sending owner notification for request #$transferRequestId: " . $emailEx->getMessage());
            $emailMessages[] = 'Owner notification error';
        }

        // Send alert to administrators with error handling
        try {
            $emailMessages[] = $emailService->sendAdminAlert($transferRequestId)
                ? 'Administrators notified'
                : 'Admin notification failed';
        } catch (Exception $emailEx) {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Unexpected exception sending admin alert for request #$transferRequestId: " . $emailEx->getMessage());
            $emailMessages[] = 'Admin notification error';
        }

    } catch (Exception $generalEmailEx) {
        logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "General email error for request #$transferRequestId: " . $generalEmailEx->getMessage());
        $emailMessages[] = 'Email system error';
    }

    // Build email status message
    $emailStatus = !empty($emailMessages) ? ' Email status: ' . implode(', ', $emailMessages) . '.' : '';

    // Return success response
    ApiResponse::success('Transfer request submitted successfully.' . $emailStatus)
        ->withData('transfer_request_id', $transferRequestId)
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_TRANSFER, "Transfer request submitted for car ID {$existingCar->id}")
        ->send();

} catch (CarTransferException $e) {
    ApiResponse::error($e->getUserMessage(), 400)
        ->withLogging($user->data()->id, $e->getLogCategory(), 'Transfer request failed: ' . $e->getMessage())
        ->send();

} catch (Exception $e) {
    ApiResponse::serverError('An unexpected error occurred while processing your transfer request.')
        ->withLogging($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Transfer request system error: ' . $e->getMessage())
        ->send();
}
?>
