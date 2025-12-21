<?php
/**
 * Transfer Decision Notification Email Template for Previous Owner
 *
 * Notifies the previous car owner about the final decision on a transfer request
 * Variables available: $previousOwner, $requester, $carInfo, $transferRequest, $isApproved, $adminNotes
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

// Build transfer decision details
$decisionDetails =
    $emailTemplate->createDetailRow('Request ID', $transferRequest->id) .
    $emailTemplate->createDetailRow('Decision Date', date('M j, Y g:i A', strtotime($transferRequest->completed_date))) .
    $emailTemplate->createDetailRow('Status', $isApproved ? 'APPROVED' : 'DENIED');

// Build content based on approval status
if ($isApproved) {
    // Approved transfer content
    $statusMessage = '<p><strong>The ownership transfer request for your car has been APPROVED by registry administrators.</strong></p>';

    $newOwnerDetails =
        $emailTemplate->createDetailRow('Name', $requester->fname . ' ' . $requester->lname) .
        $emailTemplate->createDetailRow('Email', $requester->email) .
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
    // Denied transfer content
    $statusMessage = '<p><strong>The ownership transfer request for your car has been DENIED by registry administrators.</strong></p>';

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

// Build main content
$content = "
    <p>Hello <strong>" . htmlspecialchars($previousOwner->fname) . "</strong>,</p>

    $statusMessage

    <p>As promised in our initial notification, we're writing to inform you of the final decision regarding the ownership transfer request for your registered Lotus Elan.</p>

    " . $emailTemplate->createMessageBox('Your Car Information', $carDetails) . "

    " . $emailTemplate->createMessageBox('Transfer Decision', $decisionDetails, $isApproved ? 'success' : 'alert') . "

    $nextSteps

    " . (!empty($adminNotes) ?
        $emailTemplate->createMessageBox('Administrator Notes',
            '<div class="message-content">' . htmlspecialchars($adminNotes) . '</div>', 'message') : '') . "

    <p><strong>Questions or concerns?</strong> Please contact the registry administrators at
    <a href=\"mailto:<?= htmlspecialchars(getAdminEmails()) ?>\"><?= htmlspecialchars(getAdminEmails()) ?></a> if you have any questions about this decision.</p>

    <h3>📚 Registry Resources</h3>
    <p>Learn more about the transfer system and registry features:</p>
    <ul>
        <li><a href=\"{$us_url_root}docs/view.php?doc=CAR_TRANSFER_FAQ.md\">Transfer FAQ</a> - How the transfer system works</li>
        <li><a href=\"{$us_url_root}docs/view.php?doc=CAR_TRANSFER_USER_GUIDE.md\">Transfer Guide</a> - Complete transfer process documentation</li>
        <li><a href=\"{$us_url_root}docs/faq/index.php\">Registry Help</a> - All documentation and user guides</li>
    </ul>

    <p>Thank you for using the Elan Registry transfer system.</p>
";

// Generate the complete email
$statusText = $isApproved ? 'Transfer Approved' : 'Transfer Denied';
echo $emailTemplate->render(
    'Car Ownership Transfer Decision',
    $statusText,
    $content,
    ['footer_text' => 'This notification was sent because a transfer request decision was made for your registered vehicle.']
);
?>
