<?php

declare(strict_types=1);

use ElanRegistry\Exceptions\CarTransferException;
use ElanRegistry\Input;
use ElanRegistry\Transfer\CarTransferRepository;
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
    if (!Input::exists('post') || !Token::check(Input::get('csrf'))) {
        throw new CarTransferException('Invalid request token');
    }

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
    $repo = new CarTransferRepository($db);

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
    if ($repo->hasPendingForCar((int)$existingCar->id, (int)$user->data()->id)) {
        throw new CarTransferException('You already have a pending transfer request for this car');
    }

    $securityToken = bin2hex(random_bytes(32));
    $expiresAt     = date('Y-m-d H:i:s', strtotime('+30 days'));
    $userData      = $user->data();

    $fields = [
        'existing_car_id' => $existingCar->id,
        'requested_by_user_id' => $user->data()->id,
        'security_token' => $securityToken,
        'expires_at' => $expiresAt,
        'submitted_model' => $model,
        'submitted_series' => $series,
        'submitted_variant' => $variant,
        'submitted_year' => $year,
        'submitted_type' => $type,
        'submitted_chassis' => $chassis,
        'submitted_color' => $color,
        'submitted_engine' => $engine,
        'submitted_comments' => $comments,
        'submitted_email' => $userData->email,
        'submitted_fname' => $userData->fname,
        'submitted_lname' => $userData->lname,
        'submitted_city' => $userData->city ?? '',
        'submitted_state' => $userData->state ?? '',
        'submitted_country' => $userData->country ?? '',
        'created_by' => $user->data()->id,
    ];

    $transferRequestId = $repo->create($fields);

    logger($user->data()->id, LogCategories::LOG_CATEGORY_CAR_TRANSFER, "Transfer request created for car ID {$existingCar->id}, chassis {$chassis}, transfer request ID: {$transferRequestId}");

    // Send email notifications with timeout protection
    try {
        $emailService = new TransferEmailService(DB::getInstance(), 'email', $abs_us_root . $us_url_root);

        // Set time limit for email operations
        set_time_limit(60);

        // Send notification to current owner with error handling
        try {
            $ownerNotified = $emailService->sendRequest($transferRequestId);
            if (!$ownerNotified) {
                logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Owner notification failed for transfer request #$transferRequestId — owner may not be aware of this request");
            }
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
