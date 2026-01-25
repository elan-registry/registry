<?php
declare(strict_types=1);

/**
 * Transfer Email Notifications
 *
 * Functions for sending email notifications for car ownership transfer requests
 * and administrative actions.
 *
 * @author Elan Registry Team
 * @copyright 2025
 */

/**
 * Send transfer request notification to current owner
 *
 * @param int $transferRequestId Transfer request ID
 * @return bool Success status
 */
function sendTransferRequestNotification(int $transferRequestId): bool
{
    global $abs_us_root, $us_url_root;
    $db = DB::getInstance();


    try {
        // Get transfer request data using separate queries (more reliable than complex JOINs)
        $transferQuery = $db->query("SELECT * FROM car_transfer_requests WHERE id = ?", [$transferRequestId]);
        if ($transferQuery->count() === 0) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer request notification failed: Request ID $transferRequestId not found");
            return false;
        }

        $transferData = $transferQuery->first();

        // Get car data
        $carQuery = $db->query("SELECT * FROM cars WHERE id = ?", [$transferData->existing_car_id]);
        if ($carQuery->count() === 0) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer request notification failed: Car ID {$transferData->existing_car_id} not found");
            return false;
        }
        $carData = $carQuery->first();

        // Validate car data
        if (!$carData || !isset($carData->id)) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer request notification failed: Invalid car data for ID {$transferData->existing_car_id}");
            return false;
        }

        // Get current owner data using existing helper function
        $currentOwner = getUserWithProfile($carData->user_id);
        if (!$currentOwner) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer request notification failed: Current owner ID {$carData->user_id} not found");
            return false;
        }

        // Get requester data using existing helper function
        $requester = getUserWithProfile($transferData->requested_by_user_id);
        if (!$requester) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer request notification failed: Requester ID {$transferData->requested_by_user_id} not found");
            return false;
        }

        // Build data objects for template using separate query results with null safety
        $carInfo = (object)[
            'id' => $carData->id ?? 0,
            'year' => $carData->year ?? '',
            'series' => $carData->series ?? '',
            'variant' => $carData->variant ?? '',
            'chassis' => $carData->chassis ?? '',
            'color' => $carData->color ?? '',
            'engine' => $carData->engine ?? ''
        ];

        $transferRequest = (object)[
            'id' => $transferData->id ?? 0,
            'submitted_comments' => $transferData->submitted_comments ?? '',
            'request_date' => $transferData->request_date ?? '',
            'expires_at' => $transferData->expires_at ?? ''
        ];

        // Generate email content
        ob_start();
        include $abs_us_root . $us_url_root . 'usersc/views/_email_transfer_request.php';
        $emailBody = ob_get_clean();

        // Send email to current owner
        $subject = "[ELANREGISTRY] Car Ownership Transfer Request - " . $carData->year . " " . $carData->series . " " . $carData->variant . " (Chassis: " . $carData->chassis . ")";

        $result = email($currentOwner->email, $subject, $emailBody);

        if ($result) {
            logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_SUCCESS, "Transfer request notification sent to current owner: {$currentOwner->email}");
            return true;
        } else {
            logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Failed to send transfer request notification to current owner: {$currentOwner->email}");
            return false;
        }

    } catch (Exception $e) {
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer request notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send transfer request admin alert
 *
 * @param int $transferRequestId Transfer request ID
 * @return bool Success status
 */
function sendTransferRequestAdminAlert(int $transferRequestId): bool
{
    global $abs_us_root, $us_url_root;
    $db = DB::getInstance();


    try {
        // Get admin email addresses from settings
        $adminEmailsRaw = getAdminEmails();
        $adminEmails = array_map('trim', explode(',', $adminEmailsRaw));
        $adminEmails = array_filter($adminEmails); // Remove empty values

        if (empty($adminEmails)) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "No admin email addresses configured for transfer request alert");
            return false;
        }

        // Get transfer request data using separate queries (more reliable than complex JOINs)
        $transferQuery = $db->query("SELECT * FROM car_transfer_requests WHERE id = ?", [$transferRequestId]);
        if ($transferQuery->count() === 0) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer admin alert failed: Request ID $transferRequestId not found");
            return false;
        }

        $transferData = $transferQuery->first();

        // Get car data
        $carQuery = $db->query("SELECT * FROM cars WHERE id = ?", [$transferData->existing_car_id]);
        if ($carQuery->count() === 0) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer admin alert failed: Car ID {$transferData->existing_car_id} not found");
            return false;
        }
        $carData = $carQuery->first();

        // Validate car data
        if (!$carData || !isset($carData->id)) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer admin alert failed: Invalid car data for ID {$transferData->existing_car_id}");
            return false;
        }

        // Get current owner data using existing helper function
        $currentOwner = getUserWithProfile($carData->user_id);
        if (!$currentOwner) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer admin alert failed: Current owner ID {$carData->user_id} not found");
            return false;
        }

        // Get requester data using existing helper function
        $requester = getUserWithProfile($transferData->requested_by_user_id);
        if (!$requester) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer admin alert failed: Requester ID {$transferData->requested_by_user_id} not found");
            return false;
        }

        // Build data objects for template using separate query results with null safety
        $carInfo = (object)[
            'id' => $carData->id ?? 0,
            'year' => $carData->year ?? '',
            'series' => $carData->series ?? '',
            'variant' => $carData->variant ?? '',
            'chassis' => $carData->chassis ?? '',
            'color' => $carData->color ?? '',
            'engine' => $carData->engine ?? ''
        ];

        $transferRequest = (object)[
            'id' => $transferData->id ?? 0,
            'submitted_comments' => $transferData->submitted_comments ?? '',
            'request_date' => $transferData->request_date ?? '',
            'expires_at' => $transferData->expires_at ?? ''
        ];

        $reviewUrl = getBaseUrl() . '/app/admin/manage-consolidated.php';

        // Generate email content
        ob_start();
        include $abs_us_root . $us_url_root . 'usersc/views/_email_transfer_admin.php';
        $emailBody = ob_get_clean();

        // Send email to all admins
        $subject = "[ELANREGISTRY] ADMIN ALERT: Transfer Request #$transferRequestId - " . $carData->year . " " . $carData->series . " (Chassis: " . $carData->chassis . ")";

        $successCount = 0;

        foreach ($adminEmails as $adminEmail) {
            $result = email($adminEmail, $subject, $emailBody);
            if ($result) {
                $successCount++;
            } else {
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Failed to send admin alert to: {$adminEmail}");
            }
        }

        logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_SUCCESS, "Transfer request admin alerts sent to $successCount administrators");
        return $successCount > 0;

    } catch (Exception $e) {
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer admin alert error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send transfer response notification (approved/denied)
 *
 * @param int $transferRequestId Transfer request ID
 * @param bool $isApproved Whether request was approved
 * @param string $adminNotes Optional admin notes
 * @param int|null $previousOwnerId Previous owner ID (for approved transfers)
 * @return bool Success status
 */
function sendTransferResponseNotification(int $transferRequestId, bool $isApproved, string $adminNotes = '', ?int $previousOwnerId = null): bool
{
    global $abs_us_root, $us_url_root;
    $db = DB::getInstance();

    try {
        // Get transfer request data using separate queries (more reliable than complex JOINs)
        $transferQuery = $db->query("SELECT * FROM car_transfer_requests WHERE id = ?", [$transferRequestId]);
        if ($transferQuery->count() === 0) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer response notification failed: Request ID $transferRequestId not found");
            return false;
        }

        $transferData = $transferQuery->first();

        // Get car data
        $carQuery = $db->query("SELECT * FROM cars WHERE id = ?", [$transferData->existing_car_id]);
        if ($carQuery->count() === 0) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer response notification failed: Car ID {$transferData->existing_car_id} not found");
            return false;
        }
        $carData = $carQuery->first();

        // Get requester data using existing helper function
        $requester = getUserWithProfile($transferData->requested_by_user_id);
        if (!$requester) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer response notification failed: Requester ID {$transferData->requested_by_user_id} not found");
            return false;
        }

        // Build data objects for template using separate query results with null safety
        $carInfo = (object)[
            'id' => $carData->id ?? 0,
            'year' => $carData->year ?? '',
            'series' => $carData->series ?? '',
            'variant' => $carData->variant ?? '',
            'chassis' => $carData->chassis ?? '',
            'color' => $carData->color ?? '',
            'engine' => $carData->engine ?? ''
        ];

        $transferRequest = (object)[
            'id' => $transferData->id,
            'request_date' => $transferData->request_date,
            'completed_date' => $transferData->completed_date ?: date('Y-m-d H:i:s')
        ];

        $carUrl = getBaseUrl() . "/app/cars/details.php?id=" . $carData->id;

        // Generate email content
        ob_start();
        include $abs_us_root . $us_url_root . 'usersc/views/_email_transfer_response.php';
        $emailBody = ob_get_clean();

        // Send email to requester
        $status = $isApproved ? 'APPROVED' : 'DENIED';
        $subject = "[ELANREGISTRY] Transfer Request $status - " . $carData->year . " " . $carData->series . " " . $carData->variant . " (Chassis: " . $carData->chassis . ")";
        $result = email($requester->email, $subject, $emailBody);

        $requesterNotificationSent = $result;
        if ($requesterNotificationSent) {
            logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_SUCCESS, "Transfer response notification ($status) sent to requester: {$requester->email}");
        } else {
            logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Failed to send transfer response notification to requester: {$requester->email}");
        }

        // Also notify the previous owner as promised in the initial notification
        $previousOwnerNotificationSent = sendTransferPreviousOwnerNotification($transferRequestId, $isApproved, $adminNotes, $previousOwnerId);

        // Return true if at least one notification was sent successfully
        return $requesterNotificationSent || $previousOwnerNotificationSent;

    } catch (Exception $e) {
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer response notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send transfer decision notification to previous owner
 *
 * @param int $transferRequestId Transfer request ID
 * @param bool $isApproved Whether request was approved
 * @param string $adminNotes Optional admin notes
 * @param int|null $previousOwnerId Previous owner ID (for approved transfers)
 * @return bool Success status
 */
function sendTransferPreviousOwnerNotification(int $transferRequestId, bool $isApproved, string $adminNotes = '', ?int $previousOwnerId = null): bool
{
    global $abs_us_root, $us_url_root;
    $db = DB::getInstance();

    try {
        // Get transfer request data using separate queries (more reliable than complex JOINs)
        $transferQuery = $db->query("SELECT * FROM car_transfer_requests WHERE id = ?", [$transferRequestId]);
        if ($transferQuery->count() === 0) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer previous owner notification failed: Request ID $transferRequestId not found");
            return false;
        }

        $transferData = $transferQuery->first();

        // Get car data
        $carQuery = $db->query("SELECT * FROM cars WHERE id = ?", [$transferData->existing_car_id]);
        if ($carQuery->count() === 0) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer previous owner notification failed: Car ID {$transferData->existing_car_id} not found");
            return false;
        }
        $carData = $carQuery->first();

        // Determine the previous owner based on the transfer status and provided data
        if ($isApproved && $previousOwnerId) {
            // For approved transfers, use the provided previous owner ID
            $previousOwner = getUserWithProfile($previousOwnerId);
        } else {
            // For denied transfers, or approved transfers without previous owner ID,
            // the car owner should still be the original owner
            $previousOwner = getUserWithProfile($carData->user_id);
        }

        if (!$previousOwner) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer previous owner notification failed: Previous owner not found");
            return false;
        }

        // Get requester data using existing helper function
        $requester = getUserWithProfile($transferData->requested_by_user_id);
        if (!$requester) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer previous owner notification failed: Requester ID {$transferData->requested_by_user_id} not found");
            return false;
        }

        // Build data objects for template using separate query results with null safety
        $carInfo = (object)[
            'id' => $carData->id ?? 0,
            'year' => $carData->year ?? '',
            'series' => $carData->series ?? '',
            'variant' => $carData->variant ?? '',
            'chassis' => $carData->chassis ?? '',
            'color' => $carData->color ?? '',
            'engine' => $carData->engine ?? ''
        ];

        $transferRequest = (object)[
            'id' => $transferData->id,
            'request_date' => $transferData->request_date,
            'completed_date' => $transferData->completed_date ?: date('Y-m-d H:i:s')
        ];

        // Generate email content
        ob_start();
        include $abs_us_root . $us_url_root . 'usersc/views/_email_transfer_previous_owner.php';
        $emailBody = ob_get_clean();

        // Send email to previous owner
        $status = $isApproved ? 'APPROVED' : 'DENIED';
        $subject = "[ELANREGISTRY] Transfer Decision: $status - " . $carData->year . " " . $carData->series . " " . $carData->variant . " (Chassis: " . $carData->chassis . ")";

        $result = email($previousOwner->email, $subject, $emailBody);

        if ($result) {
            logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_SUCCESS, "Transfer decision notification ($status) sent to previous owner: {$previousOwner->email}");
            return true;
        } else {
            logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Failed to send transfer decision notification to previous owner: {$previousOwner->email}");
            return false;
        }

    } catch (Exception $e) {
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer previous owner notification error: " . $e->getMessage());
        return false;
    }
}
