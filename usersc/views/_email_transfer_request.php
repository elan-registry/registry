<?php

declare(strict_types=1);

/**
 * Transfer Request Notification Email Template
 *
 * Notifies current car owner about a transfer request
 * Variables available: $currentOwner, $requester, $carInfo, $transferRequest, $approveUrl, $denyUrl
 */

require_once $abs_us_root . $us_url_root . 'usersc/classes/EmailTemplate.php';

$emailTemplate = new EmailTemplate();

$carDetails =
    $emailTemplate->createDetailRow('Year', $carInfo->year) .
    $emailTemplate->createDetailRow('Model', $carInfo->series . ' ' . $carInfo->variant) .
    $emailTemplate->createDetailRow('Chassis', $carInfo->chassis) .
    $emailTemplate->createDetailRow('Color', $carInfo->color ?: 'Not specified') .
    $emailTemplate->createDetailRow('Engine', $carInfo->engine ?: 'Not specified');

// Privacy: only first name shown to current owner
$requesterDetails =
    $emailTemplate->createDetailRow('Name', $requester->fname) .
    $emailTemplate->createDetailRow('Email', $requester->email) .
    $emailTemplate->createDetailRow('Location', trim($requester->city . ', ' . $requester->state . ', ' . $requester->country, ', ') ?: 'Not specified');

$adminEmail = htmlspecialchars(getAdminEmails(), ENT_QUOTES, 'UTF-8');

$content = "
    <p>Hello <strong>" . htmlspecialchars($currentOwner->fname, ENT_QUOTES, 'UTF-8') . "</strong>,</p>

    <p>A transfer request has been submitted for one of your registered Lotus Elans. Another registry member believes they are the rightful owner of this vehicle and has requested ownership transfer.</p>

    " . $emailTemplate->createMessageBox('Your Car Information', $carDetails) . "

    " . $emailTemplate->createMessageBox('Transfer Requested By', $requesterDetails) . "

    " . (!empty($transferRequest->submitted_comments) ?
        $emailTemplate->createMessageBox('Requester\'s Comments',
            $emailTemplate->createMessageContent($transferRequest->submitted_comments), 'message') : '') . "

    <p>No changes have been made to your registration. Registry administrators will review this request carefully before any transfer is considered.</p>

    " . $emailTemplate->createButton('View Your Car in the Registry', $us_url_root . 'app/cars/detail.php?id=' . $carInfo->id, 'primary') . "

    <p><strong>What happens next?</strong></p>
    <ul>
        <li>This request will be reviewed by registry administrators</li>
        <li>You may be contacted for additional verification</li>
        <li>If approved, car ownership will be transferred to the requester</li>
    </ul>

    <p><strong>Questions or concerns?</strong> Please contact the registry administrators at
    <a href=\"mailto:{$adminEmail}\">{$adminEmail}</a> with any questions about this transfer request.</p>
";

echo $emailTemplate->render(
    'Car Ownership Transfer Request',
    'Transfer Request Notification',
    $content,
    ['footer_text' => 'This notification was sent because a transfer request was submitted for your registered vehicle.']
);
?>
