<?php

declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarTransferException;
use ElanRegistry\Input;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarValidator;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\LogCategories;
use ElanRegistry\Transfer\CarTransferRepository;
use ElanRegistry\Transfer\TransferEmailService;
use ElanRegistry\Transfer\TransferNoticeOutcome;

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

// $user->data() is null for guests; pre-resolve before any exception can reach the catch blocks.
$logUserId = $user->isLoggedIn() ? (int) $user->data()->id : 0;

try {
    if (!Input::existsPost() || !Token::check(Input::get('csrf'))) {
        throw CarTransferException::withUserMessage('Invalid request token', 'Invalid request token');
    }

    if (!$user->isLoggedIn()) {
        throw CarTransferException::withUserMessage(
            'You must be logged in to request transfers',
            'You must be logged in to request transfers'
        );
    }

    if (!checkRateLimit('transfer_request', $user->data()->id)) {
        logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_ACCESS_DENIED, 'request-transfer.php: rate limit exceeded from ' . $remote_addr);
        recordRateLimit('transfer_request', false, (int)$user->data()->id);
        throw CarTransferException::withUserMessage(
            'Too many transfer requests. Please wait before trying again.',
            'Too many transfer requests. Please wait before trying again.'
        );
    }
    recordRateLimit('transfer_request', true, (int)$user->data()->id);

    // Get and validate input
    $chassis = trim(Input::raw('chassis') ?? '');
    $year = trim(Input::raw('year') ?? '');
    $model = trim(Input::raw('model') ?? '');
    $color = trim(Input::raw('color') ?? '');
    $engine = trim(Input::raw('engine') ?? '');
    $comments = trim(Input::raw('comments') ?? '');

    if (empty($chassis) || empty($year) || empty($model)) {
        throw CarTransferException::withUserMessage(
            'Chassis, year, and model are required',
            'Chassis, year, and model are required'
        );
    }

    // Validate input lengths/ranges against DB column widths and domain constraints
    if (strlen($chassis) > 15) {
        throw CarTransferException::withUserMessage(
            'Chassis number must be 15 characters or less',
            'Chassis number must be 15 characters or less'
        );
    }
    if (!ctype_digit((string) $year) || (int) $year < 1963 || (int) $year > 1974) {
        throw CarTransferException::withUserMessage(
            'Year must be between 1963 and 1974',
            'Year must be between 1963 and 1974'
        );
    }
    if (strlen($color) > 25) {
        throw CarTransferException::withUserMessage(
            'Color must be 25 characters or less',
            'Color must be 25 characters or less'
        );
    }
    if (strlen($engine) > 15) {
        throw CarTransferException::withUserMessage(
            'Engine must be 15 characters or less',
            'Engine must be 15 characters or less'
        );
    }
    if (strlen($comments) > 1000) {
        throw CarTransferException::withUserMessage(
            'Transfer explanation must be 1000 characters or less',
            'Transfer explanation must be 1000 characters or less'
        );
    }

    // Parse model to get series, variant, type
    try {
        [$series, $variant, $type] = CarValidator::parseModel($model);
    } catch (CarValidationException $e) {
        // Do not echo raw user input ($model) back as the user-facing message —
        // keep the technical message detailed for logs, but the user message generic.
        throw CarTransferException::withUserMessage(
            'Invalid model format: ' . $model,
            'Invalid model format. Expected series|variant|type (e.g. S4|SE|FHC).',
            0,
            $e
        );
    }

    // Validate model-derived field lengths against DB column widths
    if (strlen($model) > 30) {
        throw CarTransferException::withUserMessage(
            'Model identifier must be 30 characters or less',
            'Model identifier must be 30 characters or less'
        );
    }
    if (strlen($series) > 12) {
        throw CarTransferException::withUserMessage(
            'Series must be 12 characters or less',
            'Series must be 12 characters or less'
        );
    }
    if (strlen($variant) > 15) {
        throw CarTransferException::withUserMessage(
            'Variant must be 15 characters or less',
            'Variant must be 15 characters or less'
        );
    }
    if (strlen($type) > 3) {
        throw CarTransferException::withUserMessage(
            'Type must be 3 characters or less',
            'Type must be 3 characters or less'
        );
    }

    $repo = new CarTransferRepository(dbi());

    // Find the existing car
    try {
        $result = (new CarRepository(dbi()))->findByChassisKey($year, $type, $chassis);
    } catch (CarDatabaseException $e) {
        throw CarTransferException::withUserMessage(
            'transfer-request: DB error on chassis lookup for chassis=' . $chassis . ': ' . $e->getMessage(),
            'Unable to verify chassis at this time. Please try again.'
        );
    }

    if ($result === null) {
        throw CarTransferException::withUserMessage(
            'No car found with this chassis number',
            'No car found with this chassis number'
        );
    }

    $existingCar = $result;

    // Check if user is trying to transfer to themselves
    if ($existingCar->user_id == $user->data()->id) {
        throw CarTransferException::withUserMessage('You already own this car', 'You already own this car');
    }

    // Check for existing pending transfer request
    if ($repo->hasPendingForCar((int)$existingCar->id, (int)$user->data()->id)) {
        throw CarTransferException::withUserMessage(
            'You already have a pending transfer request for this car',
            'You already have a pending transfer request for this car'
        );
    }

    $securityToken = bin2hex(random_bytes(32));
    $expiresAt     = date('Y-m-d H:i:s', strtotime('+' . TRANSFER_REQUEST_EXPIRY_DAYS . ' days'));
    $userData      = $user->data();

    $fields = [
        'existing_car_id' => $existingCar->id,
        'requested_by_user_id' => $userData->id,
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
        'created_by' => $userData->id,
    ];

    $transferRequestId = $repo->create($fields);

    logger($userData->id, LogCategories::LOG_CATEGORY_CAR_TRANSFER, "Transfer request created for car ID {$existingCar->id}, chassis {$chassis}, transfer request ID: {$transferRequestId}");

    // Send email notifications; outer catch covers TransferEmailService constructor only —
    // sendRequest() and sendAdminAlert() each catch Throwable internally. sendRequest()
    // returns a TransferNoticeOutcome and sendAdminAlert() returns a bool.
    try {
        $emailService = new TransferEmailService(dbi(), 'email');
        set_time_limit(60);

        $ownerNotice = $emailService->sendRequest($transferRequestId);
        match ($ownerNotice) {
            TransferNoticeOutcome::Skipped => logger($userData->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Owner notification skipped by the send policy for transfer request #$transferRequestId — owner may not be aware of this request"),
            TransferNoticeOutcome::Failed  => logger($userData->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Owner notification failed for transfer request #$transferRequestId — owner may not be aware of this request"),
            TransferNoticeOutcome::Sent    => null,
        };

        $adminNotified = $emailService->sendAdminAlert($transferRequestId, $ownerNotice);
        if (!$adminNotified) {
            logger($userData->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Admin alert failed for transfer request #$transferRequestId");
        }
    } catch (\Throwable $generalEmailEx) {
        logger($userData->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "General email error for request #$transferRequestId: " . $generalEmailEx->getMessage());
    }

    ApiResponse::success('Transfer request submitted successfully.')
        ->withData('transfer_request_id', $transferRequestId)
        ->withLogging($userData->id, LogCategories::LOG_CATEGORY_CAR_TRANSFER, "Transfer request submitted for car ID {$existingCar->id}")
        ->send();

} catch (CarDatabaseException $e) {
    ApiResponse::serverError($e->getUserMessage())
        ->withLogging($logUserId, $e->getLogCategory(), 'Transfer request DB error: ' . $e->getMessage())
        ->send();

} catch (CarTransferException $e) {
    ApiResponse::error($e->getUserMessage(), 400)
        ->withLogging($logUserId, $e->getLogCategory(), 'Transfer request failed: ' . $e->getMessage())
        ->send();

} catch (\Throwable $e) {
    ApiResponse::serverError('An unexpected error occurred while processing your transfer request.')
        ->withLogging($logUserId, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Transfer request system error [' . get_class($e) . ']: ' . $e->getMessage())
        ->send();
}
?>
