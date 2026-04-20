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

// Build car information display
$carDetails =
    $emailTemplate->createDetailRow('Year', $carInfo->year) .
    $emailTemplate->createDetailRow('Model', $carInfo->series . ' ' . $carInfo->variant) .
    $emailTemplate->createDetailRow('Chassis', $carInfo->chassis) .
    $emailTemplate->createDetailRow('Color', $carInfo->color ?: 'Not specified') .
    $emailTemplate->createDetailRow('Engine', $carInfo->engine ?: 'Not specified');

// Build requester information display (privacy: only first name shown)
$requesterDetails =
    $emailTemplate->createDetailRow('Name', $requester->fname) .
    $emailTemplate->createDetailRow('Email', $requester->email) .
    $emailTemplate->createDetailRow('Location', trim($requester->city . ', ' . $requester->state . ', ' . $requester->country, ', ') ?: 'Not specified');

// Build main content
$content = "
    <p>Hello <strong>" . htmlspecialchars($currentOwner->fname) . "</strong>,</p>

    <p>A transfer request has been submitted for one of your registered Lotus Elans. Another registry member believes they are the rightful owner of this vehicle and has requested ownership transfer.</p>

    " . $emailTemplate->createMessageBox('Your Car Information', $carDetails) . "

    " . $emailTemplate->createMessageBox('Transfer Requested By', $requesterDetails) . "

    " . (!empty($transferRequest->submitted_comments) ?
        $emailTemplate->createMessageBox('Requester\'s Comments',
            $emailTemplate->createMessageContent($transferRequest->submitted_comments), 'message') : '') . "

    <p><strong>What happens next?</strong></p>
    <ul>
        <li>This request will be reviewed by registry administrators</li>
        <li>You may be contacted for additional verification</li>
        <li>If approved, car ownership will be transferred to the requester</li>
        <li>You will be notified of the final decision</li>
    </ul>

    <p><strong>Questions or concerns?</strong> Please contact the registry administrators at
    <a href=\"mailto:<?= htmlspecialchars(getAdminEmails()) ?>\"><?= htmlspecialchars(getAdminEmails()) ?></a> with any questions about this transfer request.</p>

    <h3>📚 Transfer System Information</h3>
    <p>Learn more about how car transfers work:</p>
    <ul>
        <li><a href=\"{$us_url_root}docs/view.php?doc=CAR_TRANSFER_FAQ.md\">Transfer FAQ</a> - How the transfer system works</li>
        <li><a href=\"{$us_url_root}docs/view.php?doc=CAR_TRANSFER_USER_GUIDE.md\">Transfer User Guide</a> - Complete transfer process documentation</li>
        <li><a href=\"{$us_url_root}docs/faq/index.php\">Registry Help</a> - All documentation and user guides</li>
    </ul>
    <p>These resources explain the transfer process, your rights as a current owner, and what to expect during the review.</p>
";

// Generate the complete email
echo $emailTemplate->render(
    'Car Ownership Transfer Request',
    'Transfer Request Notification',
    $content,
    ['footer_text' => 'This notification was sent because a transfer request was submitted for your registered vehicle.']
);
?>
