<?php

declare(strict_types=1);

use ElanRegistry\EmailTemplate;

/**
 * Transfer Request Response Email Template
 *
 * Notifies requester about approved or denied transfer request
 * Variables available: $requester, $carInfo, $transferRequest, $isApproved, $adminNotes, $carUrl
 */

$emailTemplate = new EmailTemplate();

$carDetails =
    $emailTemplate->createDetailRow('Year', $carInfo->year) .
    $emailTemplate->createDetailRow('Model', $carInfo->series . ' ' . $carInfo->variant) .
    $emailTemplate->createDetailRow('Chassis', $carInfo->chassis) .
    $emailTemplate->createDetailRow('Color', $carInfo->color ?: 'Not specified');

$requestDetails =
    $emailTemplate->createDetailRow('Request ID', (string)$transferRequest->id) .
    $emailTemplate->createDetailRow('Submitted', date('M j, Y g:i A', strtotime($transferRequest->request_date) ?: time())) .
    $emailTemplate->createDetailRow('Reviewed', date('M j, Y g:i A', strtotime($transferRequest->completed_date) ?: time())) .
    $emailTemplate->createDetailRow('Status', $isApproved ? 'APPROVED' : 'DENIED');

$adminEmail = htmlspecialchars(getAdminEmails(), ENT_QUOTES, 'UTF-8');

if ($isApproved) {
    $statusStyle   = 'success';
    $statusTitle   = 'Transfer Request Approved';
    $statusContent = $requestDetails;
    $statusMessage = "
        <p><strong>Congratulations!</strong> Your car ownership transfer request has been approved by the registry administrators.</p>

        " . $emailTemplate->createButton('View Your Car', $carUrl, 'success') . "

        <p><strong>What this means:</strong></p>
        <ul>
            <li>You are now the registered owner of this Lotus Elan</li>
            <li>The car record has been updated with your information</li>
            <li>You can now edit and manage this car in your registry account</li>
            <li>The car will appear in your \"My Cars\" section</li>
        </ul>

        <p>Welcome to the ownership of this beautiful Lotus Elan! We encourage you to keep the registry updated with any changes or improvements to your car.</p>
    ";
} else {
    $statusStyle   = 'alert';
    $statusTitle   = 'Transfer Request Denied';
    $statusContent = $requestDetails;
    $statusMessage = "
        <p>After review, your car ownership transfer request has been denied by the registry administrators.</p>

        <p><strong>What this means:</strong></p>
        <ul>
            <li>The current owner remains the registered owner</li>
            <li>No changes have been made to the car record</li>
            <li>You may submit a new request with additional documentation if needed</li>
        </ul>

        <p>If you have additional information or documentation that supports your ownership claim, please contact the registry administrators at
        <a href=\"mailto:{$adminEmail}\">{$adminEmail}</a>.</p>
    ";
}

$content = "
    <p>Hello <strong>" . htmlspecialchars($requester->fname, ENT_QUOTES, 'UTF-8') . "</strong>,</p>

    <p>Your car ownership transfer request has been reviewed by the registry administrators.</p>

    " . $emailTemplate->createMessageBox($statusTitle, $statusContent, $statusStyle) . "

    " . $emailTemplate->createMessageBox('Car Information', $carDetails) . "

    {$statusMessage}
";

if (!empty($adminNotes)) {
    $content .= $emailTemplate->createMessageBox('Administrator Notes',
        $emailTemplate->createMessageContent($adminNotes), 'message');
}

if ($isApproved) {
    $content .= "
    <p>Thank you for being part of the Lotus Elan Registry.</p>
";
}

echo $emailTemplate->render(
    'Transfer Request ' . ($isApproved ? 'Approved' : 'Denied'),
    $isApproved ? 'Transfer Approved — You\'re the New Owner' : 'Transfer Request Not Approved',
    $content,
    ['footer_text' => 'This notification was sent in response to your car ownership transfer request.']
);
?>
