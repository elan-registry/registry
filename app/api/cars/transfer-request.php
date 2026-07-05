<?php

declare(strict_types=1);

use ElanRegistry\Exceptions\CarTransferException;
use ElanRegistry\Input;
use ElanRegistry\Transfer\TransferEmailService;

/**
 * transfer-request.php - Car Transfer Request Handler
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
    $chassis = trim(Input::raw('chassis') ?? '');
    $year = trim(Input::raw('year') ?? '');
    $model = trim(Input::raw('model') ?? '');
    $color = trim(Input::raw('color') ?? '');
    $engine = trim(Input::raw('engine') ?? '');
    $comments = trim(Input::raw('comments') ?? '');

    // Validate input lengths against DB column widths
    if (strlen($chassis) > 15) {
        throw new CarTransferException('Chassis number must be 15 characters or less');
    }
    if (strlen($year) > 4) {
        throw new CarTransferException('Year must be 4 characters or less');
    }
    if (strlen($color) > 25) {
        throw new CarTransferException('Color must be 25 characters or less');
    }
    if (strlen($engine) > 15) {
        throw new CarTransferException('Engine must be 15 characters or less');
    }
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

    // Validate model-derived field lengths against DB column widths
    if (strlen($model) > 30) {
        throw new CarTransferException('Model identifier must be 30 characters or less');
    }
    if (strlen($series) > 12) {
        throw new CarTransferException('Series must be 12 characters or less');
    }
    if (strlen($variant) > 15) {
        throw new CarTransferException('Variant must be 15 characters or less');
    }
    if (strlen($type) > 3) {
        throw new CarTransferException('Type must be 3 characters or less');
    }

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
    $securityToken = bin2hex(random_bytes(32));

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
            $emailService->sendRequest($transferRequestId);
        } catch (\Throwable $emailEx) {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Unexpected exception sending owner notification for request #$transferRequestId: " . $emailEx->getMessage());
        }

        // Send alert to administrators with error handling
        try {
            $emailService->sendAdminAlert($transferRequestId);
        } catch (\Throwable $emailEx) {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Unexpected exception sending admin alert for request #$transferRequestId: " . $emailEx->getMessage());
        }

    } catch (\Throwable $generalEmailEx) {
        logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "General email error for request #$transferRequestId: " . $generalEmailEx->getMessage());
    }

    // Return success response
    ApiResponse::success('Transfer request submitted successfully.')
        ->withData('transfer_request_id', $transferRequestId)
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_TRANSFER, "Transfer request submitted for car ID {$existingCar->id}")
        ->send();

} catch (CarTransferException $e) {
    ApiResponse::error($e->getUserMessage(), 400)
        ->withLogging($user->data()?->id ?? 0, $e->getLogCategory(), 'Transfer request failed: ' . $e->getMessage())
        ->send();

} catch (\Throwable $e) {
    ApiResponse::serverError('An unexpected error occurred while processing your transfer request.')
        ->withLogging($user->data()?->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Transfer request system error [' . get_class($e) . ']: ' . $e->getMessage())
        ->send();
}
?>
