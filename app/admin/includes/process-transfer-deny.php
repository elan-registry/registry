<?php

declare(strict_types=1);

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

// Include required files
require_once '../../../users/init.php';

// Security check - admin permission required
if (!$user->isLoggedIn() || !isRegistryAdmin($user->data()->id)) {
    ApiResponse::forbidden('Unauthorized access')
        ->withLogging(0, LogCategories::LOG_CATEGORY_ACCESS_DENIED, 'Unauthorized transfer denial attempt')
        ->send();
}

// CSRF protection
if (!isset($_POST['csrf']) || !Token::check($_POST['csrf'])) {
    ApiResponse::error('Invalid CSRF token', 400)
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY, 'Invalid CSRF token in transfer denial')
        ->send();
}

// Validate transfer ID
$transferId = (int)($_POST['transfer_id'] ?? 0);
if ($transferId <= 0) {
    ApiResponse::error('Invalid transfer request ID', 400)
        ->send();
}

$db = DB::getInstance();

try {
    // Check that transfer request exists and is pending
    $transferQuery = $db->query(
        'SELECT id, requested_by_user_id FROM car_transfer_requests WHERE id = ? AND status = "pending"',
        [$transferId]
    );

    if ($transferQuery->count() === 0) {
        ApiResponse::notFound('Transfer request not found or already processed')
            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_TRANSFER_ERROR, "Transfer denial failed: request #{$transferId} not found or not pending")
            ->send();
    }

    $transfer = $transferQuery->first();

    // Update transfer request status to denied
    $updateResult = $db->query(
        'UPDATE car_transfer_requests SET status = "denied", admin_notes = ?, completed_date = NOW() WHERE id = ?',
        ["Denied by admin user {$user->data()->id}", $transferId]
    );

    if (!$updateResult) {
        throw new CarTransferException('Failed to update transfer request status');
    }

    // Log successful denial
    logger($user->data()->id, LogCategories::LOG_CATEGORY_CAR_TRANSFER, "Transfer request #{$transferId} denied by admin");

    // Send denial notification email with error handling
    try {
        require_once $abs_us_root . $us_url_root . 'usersc/includes/transfer_email_notifications.php';
        $notificationSent = sendTransferResponseNotification(
            $transferId,
            false,
            "Denied by admin user {$user->data()->id}"
        );

        if ($notificationSent) {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_SUCCESS, "Transfer denial notification sent for request #$transferId");
        } else {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Failed to send transfer denial notification for request #$transferId");
        }
    } catch (Exception $emailEx) {
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
    ApiResponse::error($e->getUserMessage(), 400)
        ->withLogging(
            $user->data()->id,
            $e->getLogCategory(),
            "Transfer denial failed for request #{$transferId}: " . $e->getMessage()
        )
        ->send();

} catch (Exception $e) {
    ApiResponse::serverError('An unexpected error occurred while processing the transfer.')
        ->withLogging(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            "Transfer denial system error for request #{$transferId}: " . $e->getMessage()
        )
        ->send();
}
?>
