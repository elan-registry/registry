<?php

declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\Car\Car;
use ElanRegistry\Exceptions\CarException;
use ElanRegistry\Exceptions\CarTransferException;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;
use ElanRegistry\Transfer\CarTransferRepository;
use ElanRegistry\Transfer\TransferStatus;
use ElanRegistry\Transfer\TransferEmailService;

/**
 * process-transfer-approve.php
 * AJAX endpoint for approving car transfer requests
 *
 * Processes the approval of pending car transfer requests,
 * transferring car ownership to the requesting user.
 *
 * @author Elan Registry Team
 * @copyright 2025
 */

require_once '../../../users/init.php';

requireAdminAjax('transfer approval');

// Validate transfer ID
$transferId = (int)($_POST['transfer_id'] ?? 0);
if ($transferId <= 0) {
    ApiResponse::error('Invalid transfer request ID', 400)
        ->send();
}

$db = DB::getInstance();
$repo = new CarTransferRepository($db);

try {
    // Fetch transfer request with car details
    $transfer = $repo->findPendingWithCarById((int)$transferId);

    if (!$transfer) {
        ApiResponse::notFound('Transfer request not found or already processed')
            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_TRANSFER_ERROR, "Transfer approval failed: request #{$transferId} not found or not pending")
            ->send();
    }

    // Load car and validate
    $car = new Car((int)$transfer->car_id);
    if (!$car->data()) {
        ApiResponse::notFound('Car not found')
            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_TRANSFER_ERROR, "Transfer approval failed: car #{$transfer->car_id} not found")
            ->send();
    }

    // Get target user details
    $targetUser = (new Owner(dbInt($transfer, 'requested_by_user_id')))->data();
    $targetName = $targetUser && $targetUser->fname && $targetUser->lname
        ? "{$targetUser->fname} {$targetUser->lname}"
        : "User ID {$transfer->requested_by_user_id}";

    // Execute transfer
    $reason = "Car was reassigned to $targetName (User ID: {$transfer->requested_by_user_id}) by admin " . $user->data()->id;

    $db->beginTransaction();

    // 1. Claim the request atomically — TOCTOU gate
    if (!$repo->updateStatus((int)$transferId, TransferStatus::Completed, "Approved by admin user {$user->data()->id}")) {
        throw new CarTransferException(
            "updateStatus returned false for transfer #{$transferId} — request already processed (TOCTOU)",
            0,
            null,
            'This request was already processed by another admin.'
        );
    }

    // 2. Transfer car ownership — CarRepository defers to this outer transaction
    // Car::transfer() always returns true or throws; no false return path exists.
    $car->transfer((int)$transfer->requested_by_user_id, $reason, 'NEWOWNER', (int)$user->data()->id);

    $db->commit();

    // Log successful approval
    logger(
        $user->data()->id,
        LogCategories::LOG_CATEGORY_CAR_TRANSFER,
        "Transfer request #{$transferId} approved - Car {$transfer->car_id} transferred to user {$transfer->requested_by_user_id}"
    );

    // Send approval notification email with error handling
    try {
        $emailService = new TransferEmailService(DB::getInstance(), 'email');
        $notificationSent = $emailService->sendResponse(
            $transferId,
            true,
            "Approved by admin user {$user->data()->id}",
            (int)$transfer->current_owner_id
        );

        if ($notificationSent) {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_SUCCESS, "At least one transfer approval notification sent for request #$transferId");
        } else {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "All transfer approval notifications failed for request #$transferId (see email log for details)");
        }
    } catch (\Throwable $emailEx) {
        logger(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_EMAIL_ERROR,
            "Email error during transfer approval for request #$transferId: " . $emailEx->getMessage()
        );
    }

    // Return success response with transfer details
    ApiResponse::success("Transfer request approved successfully. Car ownership has been transferred to $targetName.")
        ->withDataArray([
            'transfer_id' => $transferId,
            'car_id' => (int)$transfer->car_id,
            'new_owner_id' => (int)$transfer->requested_by_user_id,
            'new_owner_name' => $targetName,
        ])
        ->withLogging(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_CAR_TRANSFER,
            "Transfer approval processed by admin for request #{$transferId}"
        )
        ->send();

} catch (CarException $e) {
    try {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    } catch (\Throwable $rollbackEx) {
        logger($user->data()->id, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, "rollBack() failed during transfer error handling for request #{$transferId}: " . $rollbackEx->getMessage());
    }
    ApiResponse::error($e->getUserMessage(), $e->getHttpStatusCode())
        ->withLogging(
            $user->data()->id,
            $e->getLogCategory(),
            "Transfer approval failed for request #{$transferId}: " . $e->getMessage()
        )
        ->send();

} catch (\Throwable $e) {
    try {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    } catch (\Throwable $rollbackEx) {
        logger($user->data()->id, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, "rollBack() failed during transfer error handling for request #{$transferId}: " . $rollbackEx->getMessage());
    }
    ApiResponse::serverError('An unexpected error occurred while processing the transfer.')
        ->withLogging(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            "Transfer approval system error for request #{$transferId} [" . get_class($e) . "]: " . $e->getMessage()
        )
        ->send();
}
?>
