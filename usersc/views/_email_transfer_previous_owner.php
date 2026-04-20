<?php

declare(strict_types=1);

/**
 * Transfer Decision Notification Email Template for Previous Owner
 *
 * Notifies the previous car owner about the final decision on a transfer request
 * Variables available: $previousOwner, $requester, $carInfo, $transferRequest, $isApproved, $adminNotes
 */

require_once $abs_us_root . $us_url_root . 'usersc/classes/EmailTemplate.php';

$emailTemplate = new EmailTemplate();

$carDetails =
    $emailTemplate->createDetailRow('Year', $carInfo->year) .
    $emailTemplate->createDetailRow('Model', $carInfo->series . ' ' . $carInfo->variant) .
    $emailTemplate->createDetailRow('Chassis', $carInfo->chassis) .
    $emailTemplate->createDetailRow('Color', $carInfo->color ?: 'Not specified') .
    $emailTemplate->createDetailRow('Engine', $carInfo->engine ?: 'Not specified');

$decisionDetails =
    $emailTemplate->createDetailRow('Request ID', (string)$transferRequest->id) .
    $emailTemplate->createDetailRow('Decision Date', date('M j, Y g:i A', strtotime($transferRequest->completed_date) ?: time())) .
    $emailTemplate->createDetailRow('Status', $isApproved ? 'APPROVED' : 'DENIED');

if ($isApproved) {
    $statusMessage = '<p><strong>Ownership of your ' . htmlspecialchars($carInfo->year, ENT_QUOTES, 'UTF-8') . ' Lotus Elan has been transferred to the new owner following our review.</strong></p>';

    $newOwnerDetails =
        $emailTemplate->createDetailRow('Name', $requester->fname) .
        $emailTemplate->createDetailRow('Location', trim($requester->city . ', ' . $requester->state . ', ' . $requester->country, ', ') ?: 'Not specified');

    $nextSteps = "
        <p><strong>What this means:</strong></p>
        <ul>
            <li>Car ownership has been officially transferred to the new owner</li>
            <li>You no longer have access to edit this car's registry information</li>
            <li>The new owner can now manage and update the car details</li>
            <li>Your account remains active for any other cars you may have registered</li>
        </ul>

        <p><strong>New Owner Contact Information:</strong></p>
        <p>You may contact the new owner directly if needed using the information below.</p>
        " . $emailTemplate->createMessageBox('New Owner Details', $newOwnerDetails);

} else {
    $statusMessage = '<p><strong>Good news — your ownership of this Lotus Elan remains unchanged. The transfer request has been reviewed and denied.</strong></p>';

    $nextSteps = "
        <p><strong>What this means:</strong></p>
        <ul>
            <li>You remain the registered owner of this vehicle</li>
            <li>No changes have been made to your car's registry information</li>
            <li>You continue to have full access to manage your car details</li>
            <li>The transfer request has been closed and archived</li>
        </ul>

        <p><strong>Why was it denied?</strong></p>
        <p>Registry administrators carefully review each transfer request to protect legitimate owners. Common reasons include insufficient proof of ownership, disputed claims, or incomplete documentation.</p>";
}

$adminEmail = htmlspecialchars(getAdminEmails(), ENT_QUOTES, 'UTF-8');

$content = "
    <p>Hello <strong>" . htmlspecialchars($previousOwner->fname, ENT_QUOTES, 'UTF-8') . "</strong>,</p>

    $statusMessage

    <p>As promised in our initial notification, we're writing to inform you of the final decision regarding the ownership transfer request for your registered Lotus Elan.</p>

    " . $emailTemplate->createMessageBox('Your Car Information', $carDetails) . "

    " . $emailTemplate->createMessageBox('Transfer Decision', $decisionDetails, $isApproved ? 'success' : 'alert') . "

    $nextSteps

    " . (!empty($adminNotes) ?
        $emailTemplate->createMessageBox('Administrator Notes',
            $emailTemplate->createMessageContent($adminNotes), 'message') : '') . "

    <p><strong>Questions or concerns?</strong> Please contact the registry administrators at
    <a href=\"mailto:{$adminEmail}\">{$adminEmail}</a> if you have any questions about this decision.</p>

";

$statusText = $isApproved ? 'Transfer Approved' : 'Transfer Denied';
echo $emailTemplate->render(
    'Car Ownership Transfer Decision',
    $statusText,
    $content,
    ['footer_text' => 'This notification was sent because a transfer request decision was made for your registered vehicle.']
);
?>
