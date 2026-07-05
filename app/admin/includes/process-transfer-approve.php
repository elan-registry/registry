<?php

declare(strict_types=1);

use ElanRegistry\Exceptions\CarTransferException;
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

// Include required files
require_once '../../../users/init.php';

requireAdminAjax('transfer approval');

// Validate transfer ID
$transferId = (int)($_POST['transfer_id'] ?? 0);
if ($transferId <= 0) {
    ApiResponse::error('Invalid transfer request ID', 400)
        ->send();
}

$db = DB::getInstance();

try {
    // Fetch transfer request with car details
    $transferQuery = $db->query(
        'SELECT ctr.*, c.id as car_id, c.user_id as current_owner_id
         FROM car_transfer_requests ctr
         JOIN cars c ON ctr.existing_car_id = c.id
         WHERE ctr.id = ? AND ctr.status = "pending"',
        [$transferId]
    );

    if ($transferQuery->count() === 0) {
        ApiResponse::notFound('Transfer request not found or already processed')
            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_TRANSFER_ERROR, "Transfer approval failed: request #{$transferId} not found or not pending")
            ->send();
    }

    $transfer = $transferQuery->first();

    // Load car and validate
    $car = new Car((int)$transfer->car_id);
    if (!$car->data()) {
        ApiResponse::notFound('Car not found')
            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_TRANSFER_ERROR, "Transfer approval failed: car #{$transfer->car_id} not found")
            ->send();
    }

    // Get target user details
    $targetUser = getUserWithProfile(dbInt($transfer, 'requested_by_user_id'));
    $targetName = $targetUser && $targetUser->fname && $targetUser->lname
        ? "{$targetUser->fname} {$targetUser->lname}"
        : "User ID {$transfer->requested_by_user_id}";

    // Execute transfer
    $reason = "Car was reassigned to $targetName (User ID: {$transfer->requested_by_user_id}) by admin " . $user->data()->id;
    $transferSuccess = $car->transfer((int)$transfer->requested_by_user_id, $reason);

    if (!$transferSuccess) {
        throw new CarTransferException('Failed to transfer car ownership');
    }

    // Update transfer request status to completed
    $updateResult = $db->query(
        'UPDATE car_transfer_requests SET status = "completed", completed_date = NOW(), admin_notes = ? WHERE id = ?',
        ["Approved by admin user {$user->data()->id}", $transferId]
    );

    if (!$updateResult) {
        throw new CarTransferException('Failed to update transfer request status');
    }

    // Log successful approval
    logger(
        $user->data()->id,
        LogCategories::LOG_CATEGORY_CAR_TRANSFER,
        "Transfer request #{$transferId} approved - Car {$transfer->car_id} transferred to user {$transfer->requested_by_user_id}"
    );

    // Send approval notification email with error handling
    try {
        $emailService = new TransferEmailService(DB::getInstance(), 'email', $abs_us_root . $us_url_root);
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

} catch (CarTransferException $e) {
    ApiResponse::error($e->getUserMessage(), 400)
        ->withLogging(
            $user->data()->id,
            $e->getLogCategory(),
            "Transfer approval failed for request #{$transferId}: " . $e->getMessage()
        )
        ->send();

} catch (\Throwable $e) {
    ApiResponse::serverError('An unexpected error occurred while processing the transfer.')
        ->withLogging(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            "Transfer approval system error for request #{$transferId} [" . get_class($e) . "]: " . $e->getMessage()
        )
        ->send();
}
?>
