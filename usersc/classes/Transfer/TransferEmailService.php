<?php

declare(strict_types=1);

namespace ElanRegistry\Transfer;

use DB;
use LogCategories;
use Throwable;

/**
 * TransferEmailService
 *
 * Sends email notifications for car ownership transfer requests and decisions.
 * Wraps the four notification types with injectable DB and mailer dependencies
 * to allow unit testing without a live database or email server.
 */
class TransferEmailService
{
    /**
     * @param object $db Database instance
     * @param callable $mailer Email sender callable — signature: (string $to, string $subject, string $body): bool
     * @param string $basePath Site base path ($abs_us_root . $us_url_root) for template includes
     */
    public function __construct(
        private object $db,
        private mixed $mailer,
        private string $basePath,
    ) {
        if (!is_callable($this->mailer)) {
            throw new \InvalidArgumentException('TransferEmailService: $mailer must be callable');
        }
    }

    /**
     * Fetch and validate the transfer row, car row, and pre-built template objects
     * for a given transfer request ID.
     *
     * Returns an associative array with keys: transferData, carData, carInfo, on success.
     * Returns false and logs the failure reason on any missing/invalid record.
     *
     * @param int $transferRequestId The transfer request ID to look up
     * @param string $context Caller label used in error log messages (e.g. "Transfer request notification")
     * @return array{transferData: object, carData: object, carInfo: object}|false
     */
    private function fetchTransferContext(int $transferRequestId, string $context): array|false
    {
        $transferQuery = $this->db->query("SELECT * FROM car_transfer_requests WHERE id = ?", [$transferRequestId]);
        if ($transferQuery->count() === 0) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "$context failed: Request ID $transferRequestId not found");
            return false;
        }
        $transferData = $transferQuery->first();

        $carQuery = $this->db->query("SELECT * FROM cars WHERE id = ?", [$transferData->existing_car_id]);
        if ($carQuery->count() === 0) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "$context failed: Car ID {$transferData->existing_car_id} not found");
            return false;
        }
        $carData = $carQuery->first();

        $carInfo = (object)[
            'id'      => $carData->id ?? 0,
            'year'    => $carData->year ?? '',
            'series'  => $carData->series ?? '',
            'variant' => $carData->variant ?? '',
            'chassis' => $carData->chassis ?? '',
            'color'   => $carData->color ?? '',
            'engine'  => $carData->engine ?? '',
        ];

        return compact('transferData', 'carData', 'carInfo');
    }

    /**
     * Send transfer request notification to current owner.
     *
     * @param int $transferRequestId Transfer request ID
     * @return bool True if the email was delivered
     */
    public function sendRequest(int $transferRequestId): bool
    {
        try {
            $ctx = $this->fetchTransferContext($transferRequestId, 'Transfer request notification');
            if ($ctx === false) {
                return false;
            }
            ['transferData' => $transferData, 'carData' => $carData, 'carInfo' => $carInfo] = $ctx;

            $currentOwner = getUserWithProfile(dbInt($carData, 'user_id'));
            if (!$currentOwner) {
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer request notification failed: Current owner ID {$carData->user_id} not found");
                return false;
            }

            $requester = getUserWithProfile(dbInt($transferData, 'requested_by_user_id'));
            if (!$requester) {
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer request notification failed: Requester ID {$transferData->requested_by_user_id} not found");
                return false;
            }

            $transferRequest = (object)[
                'id'                  => $transferData->id ?? 0,
                'submitted_comments'  => $transferData->submitted_comments ?? '',
                'request_date'        => $transferData->request_date ?? '',
                'expires_at'          => $transferData->expires_at ?? '',
            ];

            ob_start();
            include $this->basePath . 'app/views/email/_transfer_request.php';
            $emailBody = ob_get_clean();

            $subject = "[ELANREGISTRY] Car Ownership Transfer Request - {$carData->year} {$carData->series} {$carData->variant} (Chassis: {$carData->chassis})";
            $result = ($this->mailer)($currentOwner->email, $subject, $emailBody);

            if ($result) {
                logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_SUCCESS, "Transfer request notification sent to current owner: {$currentOwner->email}");
                return true;
            }

            logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Failed to send transfer request notification to current owner: {$currentOwner->email}");
            return false;

        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                "Transfer request notification error [%s] in %s:%d: %s",
                get_class($e), $e->getFile(), $e->getLine(), $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Send transfer request admin alert.
     *
     * @param int $transferRequestId Transfer request ID
     * @return bool True if at least one admin email was delivered
     */
    public function sendAdminAlert(int $transferRequestId): bool
    {
        try {
            $adminEmails = array_filter(array_map('trim', explode(',', getAdminEmails())));
            if (empty($adminEmails)) {
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "No admin email addresses configured for transfer request alert");
                return false;
            }

            $ctx = $this->fetchTransferContext($transferRequestId, 'Transfer admin alert');
            if ($ctx === false) {
                return false;
            }
            ['transferData' => $transferData, 'carData' => $carData, 'carInfo' => $carInfo] = $ctx;

            $currentOwner = getUserWithProfile(dbInt($carData, 'user_id'));
            if (!$currentOwner) {
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer admin alert failed: Current owner ID {$carData->user_id} not found");
                return false;
            }

            $requester = getUserWithProfile(dbInt($transferData, 'requested_by_user_id'));
            if (!$requester) {
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer admin alert failed: Requester ID {$transferData->requested_by_user_id} not found");
                return false;
            }

            $transferRequest = (object)[
                'id'                 => $transferData->id ?? 0,
                'submitted_comments' => $transferData->submitted_comments ?? '',
                'request_date'       => $transferData->request_date ?? '',
                'expires_at'         => $transferData->expires_at ?? '',
            ];

            $reviewUrl = getBaseUrl() . '/app/admin/index.php';

            ob_start();
            include $this->basePath . 'app/views/email/_transfer_admin.php';
            $emailBody = ob_get_clean();

            $subject = "[ELANREGISTRY] ADMIN ALERT: Transfer Request #$transferRequestId - {$carData->year} {$carData->series} (Chassis: {$carData->chassis})";

            $totalCount = count($adminEmails);
            $successCount = 0;
            foreach ($adminEmails as $adminEmail) {
                if (($this->mailer)($adminEmail, $subject, $emailBody)) {
                    $successCount++;
                } else {
                    logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR,
                        "Transfer request #$transferRequestId admin alert failed to send to: {$adminEmail}");
                }
            }

            $failCount = $totalCount - $successCount;
            if ($failCount > 0) {
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR,
                    "Transfer request #$transferRequestId admin alerts: $successCount sent, $failCount FAILED out of $totalCount");
            }
            if ($successCount > 0) {
                logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_SUCCESS,
                    "Transfer request #$transferRequestId admin alerts sent to $successCount of $totalCount administrators");
            }
            return $successCount > 0;

        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                "Transfer admin alert error [%s] in %s:%d: %s",
                get_class($e), $e->getFile(), $e->getLine(), $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Send transfer response notification (approved/denied) to the requester,
     * and also notify the previous owner.
     *
     * @param int $transferRequestId Transfer request ID
     * @param bool $isApproved Whether the request was approved
     * @param string $adminNotes Optional admin notes
     * @param int|null $previousOwnerId Previous owner ID (for approved transfers)
     * @return bool True if at least one notification was delivered
     */
    public function sendResponse(int $transferRequestId, bool $isApproved, string $adminNotes = '', ?int $previousOwnerId = null): bool
    {
        try {
            $ctx = $this->fetchTransferContext($transferRequestId, 'Transfer response notification');
            if ($ctx === false) {
                return false;
            }
            ['transferData' => $transferData, 'carData' => $carData, 'carInfo' => $carInfo] = $ctx;

            $requester = getUserWithProfile(dbInt($transferData, 'requested_by_user_id'));
            if (!$requester) {
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer response notification failed: Requester ID {$transferData->requested_by_user_id} not found");
                return false;
            }

            $transferRequest = (object)[
                'id'             => $transferData->id,
                'request_date'   => $transferData->request_date,
                'completed_date' => $transferData->completed_date ?: date('Y-m-d H:i:s'),
            ];

            $carUrl = getBaseUrl() . '/app/owner/cars/details.php?car_id=' . $carData->id;

            ob_start();
            include $this->basePath . 'app/views/email/_transfer_response.php';
            $emailBody = ob_get_clean();

            $status = $isApproved ? 'APPROVED' : 'DENIED';
            $subject = "[ELANREGISTRY] Transfer Request $status - {$carData->year} {$carData->series} {$carData->variant} (Chassis: {$carData->chassis})";
            $requesterNotificationSent = (bool) ($this->mailer)($requester->email, $subject, $emailBody);

            if ($requesterNotificationSent) {
                logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_SUCCESS, "Transfer response notification ($status) sent to requester: {$requester->email}");
            } else {
                logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Failed to send transfer response notification to requester: {$requester->email}");
            }

            $previousOwnerNotificationSent = $this->sendPreviousOwnerNotification($transferRequestId, $isApproved, $adminNotes, $previousOwnerId);

            return $requesterNotificationSent || $previousOwnerNotificationSent;

        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                "Transfer response notification error [%s] in %s:%d: %s",
                get_class($e), $e->getFile(), $e->getLine(), $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Send transfer decision notification to the previous owner.
     *
     * For approved transfers, $previousOwnerId identifies the displaced owner.
     * For denied transfers (or when no ID is supplied), the car's current owner is used.
     *
     * @param int $transferRequestId Transfer request ID
     * @param bool $isApproved Whether the request was approved
     * @param string $adminNotes Optional admin notes
     * @param int|null $previousOwnerId Previous owner ID (for approved transfers)
     * @return bool True if the email was delivered
     */
    private function sendPreviousOwnerNotification(int $transferRequestId, bool $isApproved, string $adminNotes = '', ?int $previousOwnerId = null): bool
    {
        try {
            $ctx = $this->fetchTransferContext($transferRequestId, 'Transfer previous owner notification');
            if ($ctx === false) {
                return false;
            }
            ['transferData' => $transferData, 'carData' => $carData, 'carInfo' => $carInfo] = $ctx;

            $lookupId = ($isApproved && $previousOwnerId) ? $previousOwnerId : dbInt($carData, 'user_id');
            $previousOwner = getUserWithProfile($lookupId);

            if (!$previousOwner) {
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer previous owner notification failed: User ID $lookupId not found");
                return false;
            }

            $requester = getUserWithProfile(dbInt($transferData, 'requested_by_user_id'));
            if (!$requester) {
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer previous owner notification failed: Requester ID {$transferData->requested_by_user_id} not found");
                return false;
            }

            $transferRequest = (object)[
                'id'             => $transferData->id,
                'request_date'   => $transferData->request_date,
                'completed_date' => $transferData->completed_date ?: date('Y-m-d H:i:s'),
            ];

            ob_start();
            include $this->basePath . 'app/views/email/_transfer_previous_owner.php';
            $emailBody = ob_get_clean();

            $status = $isApproved ? 'APPROVED' : 'DENIED';
            $subject = "[ELANREGISTRY] Transfer Decision: $status - {$carData->year} {$carData->series} {$carData->variant} (Chassis: {$carData->chassis})";
            $result = ($this->mailer)($previousOwner->email, $subject, $emailBody);

            if ($result) {
                logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_SUCCESS, "Transfer decision notification ($status) sent to previous owner: {$previousOwner->email}");
                return true;
            }

            logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Failed to send transfer decision notification to previous owner: {$previousOwner->email}");
            return false;

        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                "Transfer previous owner notification error [%s] in %s:%d: %s",
                get_class($e), $e->getFile(), $e->getLine(), $e->getMessage()
            ));
            return false;
        }
    }
}
