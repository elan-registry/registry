<?php

declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\Exceptions\CarTransferException;
use ElanRegistry\LogCategories;
use ElanRegistry\Transfer\CarTransferRepository;
use ElanRegistry\Transfer\TransferStatus;
use ElanRegistry\Transfer\TransferEmailService;

/**
 * process-transfer-deny.php
 * AJAX endpoint for denying car transfer requests
 *
 * Processes the denial of pending car transfer requests,
 * marking them as denied without changing car ownership.
 *
 * @author Elan Registry Team
 * @copyright 2025
 */

require_once '../../../users/init.php';

requireAdminAjax('transfer denial');

// Validate transfer ID
$transferId = (int)($_POST['transfer_id'] ?? 0);
if ($transferId <= 0) {
    ApiResponse::error('Invalid transfer request ID', 400)
        ->send();
}

$db = DB::getInstance();
$repo = new CarTransferRepository($db);

try {
    // Check that transfer request exists and is pending
    $transfer = $repo->findPendingById((int)$transferId);

    if (!$transfer) {
        ApiResponse::notFound('Transfer request not found or already processed')
            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_TRANSFER_ERROR, "Transfer denial failed: request #{$transferId} not found or not pending")
            ->send();
    }

    // Update transfer request status to denied.
    // No transaction needed — this is the only write in the denial flow.
    // If a second write is added here in future, wrap both in beginTransaction()/rollBack().
    if (!$repo->updateStatus((int)$transferId, TransferStatus::Denied, "Denied by admin user {$user->data()->id}")) {
        throw new CarTransferException(
            "updateStatus returned false for transfer #{$transferId} — request already processed (TOCTOU)",
            0,
            null,
            'This request was already processed by another admin.'
        );
    }

    // Log successful denial
    logger($user->data()->id, LogCategories::LOG_CATEGORY_CAR_TRANSFER, "Transfer request #{$transferId} denied by admin");

    // Send denial notification email with error handling
    try {
        $emailService = new TransferEmailService(DB::getInstance(), 'email', $abs_us_root . $us_url_root);
        $notificationSent = $emailService->sendResponse(
            $transferId,
            false,
            "Denied by admin user {$user->data()->id}"
        );

        if ($notificationSent) {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_SUCCESS, "At least one transfer denial notification sent for request #$transferId");
        } else {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "All transfer denial notifications failed for request #$transferId (see email log for details)");
        }
    } catch (\Throwable $emailEx) {
        logger(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_EMAIL_ERROR,
            "Email error during transfer denial for request #$transferId: " . $emailEx->getMessage()
        );
    }

    // Return success response
    ApiResponse::success('Transfer request denied successfully.')
        ->withData('transfer_id', $transferId)
        ->withLogging(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_CAR_TRANSFER,
            "Transfer denial processed by admin for request #{$transferId}"
        )
        ->send();

} catch (CarTransferException $e) {
    ApiResponse::error($e->getUserMessage(), $e->getHttpStatusCode())
        ->withLogging(
            $user->data()->id,
            $e->getLogCategory(),
            "Transfer denial failed for request #{$transferId}: " . $e->getMessage()
        )
        ->send();

} catch (\Throwable $e) {
    ApiResponse::serverError('An unexpected error occurred while processing the transfer.')
        ->withLogging(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            "Transfer denial system error for request #{$transferId} [" . get_class($e) . "]: " . $e->getMessage()
        )
        ->send();
}
?>
