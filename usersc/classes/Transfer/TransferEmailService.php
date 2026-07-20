<?php

declare(strict_types=1);

namespace ElanRegistry\Transfer;

use ElanRegistry\EmailTemplate;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;
use Throwable;

/**
 * TransferEmailService
 *
 * Sends email notifications for car ownership transfer requests and decisions.
 * Exposes three public methods (sendRequest, sendAdminAlert, sendResponse);
 * sendResponse() also sends a parallel previous-owner notification internally.
 * All dependencies are injectable to allow unit testing without a live database
 * or email server.
 */
class TransferEmailService
{
    /**
     * @param object $db Database instance
     * @param mixed $mailer Email sender callable — signature: (string $to, string $subject, string $body): bool
     * @throws \InvalidArgumentException if $mailer is not callable
     */
    public function __construct(
        private object $db,
        private mixed $mailer,
    ) {
        if (!is_callable($this->mailer)) {
            throw new \InvalidArgumentException('TransferEmailService: $mailer must be callable');
        }
    }

    /**
     * Fetch and validate the transfer row, car row, and a normalized car DTO
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
        $transferData = (new CarTransferRepository($this->db))->findById($transferRequestId);
        if (!$transferData) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "$context failed: Request ID $transferRequestId not found");
            return false;
        }

        // Raw query rather than CarRepository::findById() because that method goes through $db->get().
        // The unit-test double returns synthetic data from get() regardless of car ID, which would
        // prevent testing the not-found path; query() returns empty by design.
        $carQuery = $this->db->query('SELECT * FROM cars WHERE id = ?', [$transferData->existing_car_id]);
        $carData = $carQuery->count() > 0 ? $carQuery->first() : null;
        if (!$carData) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "$context failed: Transfer #$transferRequestId Car ID {$transferData->existing_car_id} not found");
            return false;
        }

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

            $currentOwner = (new Owner(dbInt($carData, 'user_id')))->data();
            if (!$currentOwner) {
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer request notification failed: Current owner ID {$carData->user_id} not found");
                return false;
            }

            $requester = (new Owner(dbInt($transferData, 'requested_by_user_id')))->data();
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

            $emailBody = $this->buildRequestEmailBody($currentOwner, $requester, $carInfo, $transferRequest);

            $subject = "[ELANREGISTRY] Car Ownership Transfer Request - {$carData->year} {$carData->series} {$carData->variant} (Chassis: {$carData->chassis})";
            $result = ($this->mailer)($currentOwner->email, $subject, $emailBody);

            if ($result) {
                logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_SUCCESS, "Transfer request notification sent to current owner: {$currentOwner->email}");
                return true;
            }

            logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Failed to send transfer request notification to current owner: {$currentOwner->email}");
            return false;

        } catch (Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                "Transfer request notification error for request #%d [%s] in %s:%d: %s",
                $transferRequestId,
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

            $currentOwner = (new Owner(dbInt($carData, 'user_id')))->data();
            if (!$currentOwner) {
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer admin alert failed: Current owner ID {$carData->user_id} not found");
                return false;
            }

            $requester = (new Owner(dbInt($transferData, 'requested_by_user_id')))->data();
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

            $emailBody = $this->buildAdminEmailBody($currentOwner, $requester, $carInfo, $transferRequest, $reviewUrl);

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
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                "Transfer admin alert error for request #%d [%s] in %s:%d: %s",
                $transferRequestId,
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
     * @param string $adminNotes Admin notes from the administrator; empty string if none
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

            $requester = (new Owner(dbInt($transferData, 'requested_by_user_id')))->data();
            if (!$requester) {
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer response notification failed: Requester ID {$transferData->requested_by_user_id} not found");
                return false;
            }

            $transferRequest = (object)[
                'id'             => $transferData->id,
                'request_date'   => $transferData->request_date ?? '',
                'completed_date' => $transferData->completed_date ?: date('Y-m-d H:i:s'),
            ];

            $carUrl = getBaseUrl() . '/app/owner/cars/details.php?car_id=' . $carData->id;

            $emailBody = $this->buildResponseEmailBody($requester, $carInfo, $transferRequest, $isApproved, $adminNotes, $carUrl);

            $status = $isApproved ? 'APPROVED' : 'DENIED';
            $subject = "[ELANREGISTRY] Transfer Request $status - {$carData->year} {$carData->series} {$carData->variant} (Chassis: {$carData->chassis})";
            $requesterNotificationSent = (bool) ($this->mailer)($requester->email, $subject, $emailBody);

            if ($requesterNotificationSent) {
                logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_SUCCESS, "Transfer response notification ($status) sent to requester: {$requester->email}");
            } else {
                logger($transferData->requested_by_user_id, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Failed to send transfer response notification to requester: {$requester->email}");
            }

            $previousOwnerNotificationSent = $this->sendPreviousOwnerNotification($ctx, $requester, $isApproved, $adminNotes, $previousOwnerId);

            return $requesterNotificationSent || $previousOwnerNotificationSent;

        } catch (Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                "Transfer response notification error for request #%d [%s] in %s:%d: %s",
                $transferRequestId,
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
     * @param array{transferData: object, carData: object, carInfo: object} $ctx Transfer context from fetchTransferContext()
     * @param object $requester Requester user data, pre-fetched by sendResponse()
     * @param bool $isApproved Whether the request was approved
     * @param string $adminNotes Admin notes from the administrator; empty string if none
     * @param int|null $previousOwnerId Previous owner ID (for approved transfers)
     * @return bool True if the email was delivered
     */
    private function sendPreviousOwnerNotification(array $ctx, object $requester, bool $isApproved, string $adminNotes = '', ?int $previousOwnerId = null): bool
    {
        try {
            ['transferData' => $transferData, 'carData' => $carData, 'carInfo' => $carInfo] = $ctx;

            if ($isApproved && $previousOwnerId === null) {
                // After Car::transfer() commits, $carData->user_id is already the new owner.
                // Callers must always supply $previousOwnerId for approved transfers.
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR,
                    "sendPreviousOwnerNotification: previousOwnerId is null for an approved transfer #"
                    . ($ctx['transferData']->id ?? 'unknown')
                    . " — falling back to car's current user_id which may now be the new owner");
            }
            $lookupId = ($isApproved && $previousOwnerId) ? $previousOwnerId : dbInt($carData, 'user_id');
            $previousOwner = (new Owner($lookupId))->data();

            if (!$previousOwner) {
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer previous owner notification failed: User ID $lookupId not found");
                return false;
            }

            $transferRequest = (object)[
                'id'             => $transferData->id,
                'request_date'   => $transferData->request_date ?? '',
                'completed_date' => $transferData->completed_date ?: date('Y-m-d H:i:s'),
            ];

            $emailBody = $this->buildPreviousOwnerEmailBody($previousOwner, $requester, $carInfo, $transferRequest, $isApproved, $adminNotes);

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
            $ctxTransferId = $ctx['transferData']->id ?? 'unknown';
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                "Transfer previous owner notification error for request #%s [%s] in %s:%d: %s",
                $ctxTransferId,
                get_class($e), $e->getFile(), $e->getLine(), $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Build the transfer request notification email body (sent to the current owner).
     *
     * @param object $currentOwner Current registered owner
     * @param object $requester Member who requested the transfer
     * @param object $carInfo Car detail object
     * @param object $transferRequest Transfer request detail object
     * @return string Rendered HTML email body
     */
    private function buildRequestEmailBody(
        object $currentOwner,
        object $requester,
        object $carInfo,
        object $transferRequest
    ): string {
        $et = new EmailTemplate();
        $carDetails =
            $et->createDetailRow('Year', $carInfo->year) .
            $et->createDetailRow('Model', $carInfo->series . ' ' . $carInfo->variant) .
            $et->createDetailRow('Chassis', $carInfo->chassis) .
            $et->createDetailRow('Color', $carInfo->color ?: 'Not specified') .
            $et->createDetailRow('Engine', $carInfo->engine ?: 'Not specified');
        $requesterDetails =
            $et->createDetailRow('Name', $requester->fname) .
            $et->createDetailRow('Email', $requester->email) .
            $et->createDetailRow('Location', trim($requester->city . ', ' . $requester->state . ', ' . $requester->country, ', ') ?: 'Not specified');
        $adminEmail = htmlspecialchars(getAdminEmails(), ENT_QUOTES, 'UTF-8');
        $content = '
    <p>Hello <strong>' . htmlspecialchars($currentOwner->fname, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
    <p>A transfer request has been submitted for one of your registered Lotus Elans. Another registry member believes they are the rightful owner of this vehicle and has requested ownership transfer.</p>
    ' . $et->createMessageBox('Your Car Information', $carDetails) . '
    ' . $et->createMessageBox('Transfer Requested By', $requesterDetails) . '
    ' . (!empty($transferRequest->submitted_comments) ?
        $et->createMessageBox("Requester's Comments",
            $et->createMessageContent($transferRequest->submitted_comments), 'message') : '') . '
    <p>No changes have been made to your registration. Registry administrators will review this request carefully before any transfer is considered.</p>
    ' . $et->createButton('View Your Car in the Registry', getBaseUrl() . '/app/owner/cars/details.php?car_id=' . $carInfo->id, 'primary') . '
    <p><strong>What happens next?</strong></p>
    <ul>
        <li>This request will be reviewed by registry administrators</li>
        <li>You may be contacted for additional verification</li>
        <li>If approved, car ownership will be transferred to the requester</li>
    </ul>
    <p><strong>Questions or concerns?</strong> Please contact the registry administrators at
    <a href="mailto:' . $adminEmail . '">' . $adminEmail . '</a> with any questions about this transfer request.</p>
';
        return $et->render(
            'Car Ownership Transfer Request',
            'Transfer Request Notification',
            $content,
            ['footer_text' => 'This notification was sent because a transfer request was submitted for your registered vehicle.']
        );
    }

    /**
     * Build the transfer request admin alert email body.
     *
     * @param object $currentOwner Current registered owner
     * @param object $requester Member who requested the transfer
     * @param object $carInfo Car detail object
     * @param object $transferRequest Transfer request detail object
     * @param string $reviewUrl Admin review URL
     * @return string Rendered HTML email body
     */
    private function buildAdminEmailBody(
        object $currentOwner,
        object $requester,
        object $carInfo,
        object $transferRequest,
        string $reviewUrl
    ): string {
        $et = new EmailTemplate();
        $carDetails =
            $et->createDetailRow('Car ID', (string)$carInfo->id) .
            $et->createDetailRow('Year', $carInfo->year) .
            $et->createDetailRow('Model', $carInfo->series . ' ' . $carInfo->variant) .
            $et->createDetailRow('Chassis', $carInfo->chassis) .
            $et->createDetailRow('Color', $carInfo->color ?: 'Not specified');
        $currentOwnerDetails =
            $et->createDetailRow('Name', $currentOwner->fname . ' ' . $currentOwner->lname) .
            $et->createDetailRow('Email', $currentOwner->email) .
            $et->createDetailRow('User ID', (string)$currentOwner->id) .
            $et->createDetailRow('Location', trim($currentOwner->city . ', ' . $currentOwner->state . ', ' . $currentOwner->country, ', ') ?: 'Not specified');
        $requesterDetails =
            $et->createDetailRow('Name', $requester->fname . ' ' . $requester->lname) .
            $et->createDetailRow('Email', $requester->email) .
            $et->createDetailRow('User ID', (string)$requester->id) .
            $et->createDetailRow('Location', trim($requester->city . ', ' . $requester->state . ', ' . $requester->country, ', ') ?: 'Not specified');
        $requestDetails =
            $et->createDetailRow('Request ID', (string)$transferRequest->id) .
            $et->createDetailRow('Submitted', $this->formatDate($transferRequest->request_date, 'request_date')) .
            $et->createDetailRow('Expires', $this->formatDate($transferRequest->expires_at, 'expires_at'));
        $content = '
    <p><strong>A new car ownership transfer request requires administrative review.</strong></p>
    ' . $et->createMessageBox('Transfer Request Details', $requestDetails, 'alert') . '
    ' . $et->createButton('Review Transfer Request', $reviewUrl, 'primary') . '
    ' . $et->createMessageBox('Car Information', $carDetails) . '
    ' . $et->createMessageBox('Current Owner', $currentOwnerDetails) . '
    ' . $et->createMessageBox('Requested By', $requesterDetails) . '
    ' . (!empty($transferRequest->submitted_comments) ?
        $et->createMessageBox("Requester's Comments",
            $et->createMessageContent($transferRequest->submitted_comments), 'message') : '') . '
';
        return $et->render(
            'Transfer Request - Admin Review Required',
            'Administrative Review Required',
            $content,
            ['footer_text' => 'This is an automated administrative alert from the registry transfer system.']
        );
    }

    /**
     * Build the transfer response (approved/denied) email body sent to the requester.
     *
     * @param object $requester Member who requested the transfer
     * @param object $carInfo Car detail object
     * @param object $transferRequest Transfer request detail object
     * @param bool $isApproved Whether the request was approved
     * @param string $adminNotes Admin notes from the administrator; empty string if none
     * @param string $carUrl Car detail page URL; only used in the approved branch
     * @return string Rendered HTML email body
     */
    private function buildResponseEmailBody(
        object $requester,
        object $carInfo,
        object $transferRequest,
        bool $isApproved,
        string $adminNotes,
        string $carUrl
    ): string {
        $et = new EmailTemplate();
        $carDetails =
            $et->createDetailRow('Year', $carInfo->year) .
            $et->createDetailRow('Model', $carInfo->series . ' ' . $carInfo->variant) .
            $et->createDetailRow('Chassis', $carInfo->chassis) .
            $et->createDetailRow('Color', $carInfo->color ?: 'Not specified');
        $requestDetails =
            $et->createDetailRow('Request ID', (string)$transferRequest->id) .
            $et->createDetailRow('Submitted', $this->formatDate($transferRequest->request_date, 'request_date')) .
            $et->createDetailRow('Reviewed', $this->formatDate($transferRequest->completed_date, 'completed_date')) .
            $et->createDetailRow('Status', $isApproved ? 'APPROVED' : 'DENIED');
        $adminEmail = htmlspecialchars(getAdminEmails(), ENT_QUOTES, 'UTF-8');
        if ($isApproved) {
            $statusStyle   = 'success';
            $statusTitle   = 'Transfer Request Approved';
            $statusMessage = '
        <p><strong>Congratulations!</strong> Your car ownership transfer request has been approved by the registry administrators.</p>
        ' . $et->createButton('View Your Car', $carUrl, 'success') . '
        <p><strong>What this means:</strong></p>
        <ul>
            <li>You are now the registered owner of this Lotus Elan</li>
            <li>The car record has been updated with your information</li>
            <li>You can now edit and manage this car in your registry account</li>
            <li>The car will appear in your "My Cars" section</li>
        </ul>
        <p>Welcome to the ownership of this beautiful Lotus Elan! We encourage you to keep the registry updated with any changes or improvements to your car.</p>
        ';
        } else {
            $statusStyle   = 'alert';
            $statusTitle   = 'Transfer Request Denied';
            $statusMessage = '
        <p>After review, your car ownership transfer request has been denied by the registry administrators.</p>
        <p><strong>What this means:</strong></p>
        <ul>
            <li>The current owner remains the registered owner</li>
            <li>No changes have been made to the car record</li>
            <li>You may submit a new request with additional documentation if needed</li>
        </ul>
        <p>If you have additional information or documentation that supports your ownership claim, please contact the registry administrators at
        <a href="mailto:' . $adminEmail . '">' . $adminEmail . '</a>.</p>
        ';
        }
        $content = '
    <p>Hello <strong>' . htmlspecialchars($requester->fname, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
    <p>Your car ownership transfer request has been reviewed by the registry administrators.</p>
    ' . $et->createMessageBox($statusTitle, $requestDetails, $statusStyle) . '
    ' . $et->createMessageBox('Car Information', $carDetails) . '
    ' . $statusMessage . '
';
        if (!empty($adminNotes)) {
            $content .= $et->createMessageBox('Administrator Notes',
                $et->createMessageContent($adminNotes), 'message');
        }
        if ($isApproved) {
            $content .= '<p>Thank you for being part of the Lotus Elan Registry.</p>';
        }
        return $et->render(
            'Transfer Request ' . ($isApproved ? 'Approved' : 'Denied'),
            $isApproved ? "Transfer Approved — You're the New Owner" : 'Transfer Request Not Approved',
            $content,
            ['footer_text' => 'This notification was sent in response to your car ownership transfer request.']
        );
    }

    /**
     * Build the transfer decision email body sent to the previous owner.
     *
     * @param object $previousOwner Previous registered owner
     * @param object $requester Member who requested the transfer; only used in the approved branch to show new-owner details
     * @param object $carInfo Car detail object
     * @param object $transferRequest Transfer request detail object
     * @param bool $isApproved Whether the request was approved
     * @param string $adminNotes Admin notes from the administrator; empty string if none
     * @return string Rendered HTML email body
     */
    private function buildPreviousOwnerEmailBody(
        object $previousOwner,
        object $requester,
        object $carInfo,
        object $transferRequest,
        bool $isApproved,
        string $adminNotes
    ): string {
        $et = new EmailTemplate();
        $carDetails =
            $et->createDetailRow('Year', $carInfo->year) .
            $et->createDetailRow('Model', $carInfo->series . ' ' . $carInfo->variant) .
            $et->createDetailRow('Chassis', $carInfo->chassis) .
            $et->createDetailRow('Color', $carInfo->color ?: 'Not specified') .
            $et->createDetailRow('Engine', $carInfo->engine ?: 'Not specified');
        $decisionDetails =
            $et->createDetailRow('Request ID', (string)$transferRequest->id) .
            $et->createDetailRow('Decision Date', $this->formatDate($transferRequest->completed_date, 'completed_date')) .
            $et->createDetailRow('Status', $isApproved ? 'APPROVED' : 'DENIED');
        if ($isApproved) {
            $statusMessage = '<p><strong>Ownership of your ' . htmlspecialchars($carInfo->year, ENT_QUOTES, 'UTF-8') . ' Lotus Elan has been transferred to the new owner following our review.</strong></p>';
            $newOwnerDetails =
                $et->createDetailRow('Name', $requester->fname) .
                $et->createDetailRow('Location', trim($requester->city . ', ' . $requester->state . ', ' . $requester->country, ', ') ?: 'Not specified');
            $nextSteps = '
        <p><strong>What this means:</strong></p>
        <ul>
            <li>Car ownership has been officially transferred to the new owner</li>
            <li>You no longer have access to edit this car\'s registry information</li>
            <li>The new owner can now manage and update the car details</li>
            <li>Your account remains active for any other cars you may have registered</li>
        </ul>
        <p><strong>New Owner Contact Information:</strong></p>
        <p>You may contact the new owner directly if needed using the information below.</p>
        ' . $et->createMessageBox('New Owner Details', $newOwnerDetails);
        } else {
            $statusMessage = '<p><strong>Good news — your ownership of this Lotus Elan remains unchanged. The transfer request has been reviewed and denied.</strong></p>';
            $nextSteps = '
        <p><strong>What this means:</strong></p>
        <ul>
            <li>You remain the registered owner of this vehicle</li>
            <li>No changes have been made to your car\'s registry information</li>
            <li>You continue to have full access to manage your car details</li>
            <li>The transfer request has been closed and archived</li>
        </ul>
        <p><strong>Why was it denied?</strong></p>
        <p>Registry administrators carefully review each transfer request to protect legitimate owners. Common reasons include insufficient proof of ownership, disputed claims, or incomplete documentation.</p>';
        }
        $adminEmail = htmlspecialchars(getAdminEmails(), ENT_QUOTES, 'UTF-8');
        $content = '
    <p>Hello <strong>' . htmlspecialchars($previousOwner->fname, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
    ' . $statusMessage . '
    <p>As promised in our initial notification, we\'re writing to inform you of the final decision regarding the ownership transfer request for your registered Lotus Elan.</p>
    ' . $et->createMessageBox('Your Car Information', $carDetails) . '
    ' . $et->createMessageBox('Transfer Decision', $decisionDetails, $isApproved ? 'success' : 'alert') . '
    ' . $nextSteps . '
    ' . (!empty($adminNotes) ?
        $et->createMessageBox('Administrator Notes',
            $et->createMessageContent($adminNotes), 'message') : '') . '
    <p><strong>Questions or concerns?</strong> Please contact the registry administrators at
    <a href="mailto:' . $adminEmail . '">' . $adminEmail . '</a> if you have any questions about this decision.</p>
';
        return $et->render(
            'Car Ownership Transfer Decision',
            $isApproved ? 'Transfer Approved' : 'Transfer Denied',
            $content,
            ['footer_text' => 'This notification was sent because a transfer request decision was made for your registered vehicle.']
        );
    }

    /**
     * Format a date string for display in email templates.
     * Logs and falls back to the current time when the value is empty or unparseable.
     *
     * @param string $dateString Raw date value from the transfer record
     * @param string $field Column name, used in the log message for diagnostics
     * @return string Formatted date string (e.g. "Jan 1, 2026 12:00 PM")
     */
    private function formatDate(string $dateString, string $field): string
    {
        $ts = $dateString !== '' ? strtotime($dateString) : false;
        if ($ts === false) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Transfer email: unparseable date for '$field' ('$dateString') — substituting current time");
            return date('M j, Y g:i A');
        }
        return date('M j, Y g:i A', $ts);
    }
}
