<?php
/**
 * Transfer Request Response Email Template
 *
 * Notifies requester about approved or denied transfer request
 * Variables available: $requester, $carInfo, $transferRequest, $isApproved, $adminNotes, $carUrl
 */

require_once $abs_us_root . $us_url_root . 'usersc/classes/EmailTemplate.php';

$emailTemplate = new EmailTemplate();

// Build car information display
$carDetails =
    $emailTemplate->createDetailRow('Year', $carInfo->year) .
    $emailTemplate->createDetailRow('Model', $carInfo->series . ' ' . $carInfo->variant) .
    $emailTemplate->createDetailRow('Chassis', $carInfo->chassis) .
    $emailTemplate->createDetailRow('Color', $carInfo->color ?: 'Not specified');

// Build request details
$requestDetails =
    $emailTemplate->createDetailRow('Request ID', $transferRequest->id) .
    $emailTemplate->createDetailRow('Submitted', date('M j, Y g:i A', strtotime($transferRequest->request_date))) .
    $emailTemplate->createDetailRow('Reviewed', date('M j, Y g:i A', strtotime($transferRequest->completed_date))) .
    $emailTemplate->createDetailRow('Status', $isApproved ? 'APPROVED' : 'DENIED');

// Determine response style and content based on approval status
if ($isApproved) {
    $statusStyle = 'success';
    $statusTitle = 'Transfer Request Approved';
    $statusContent = $requestDetails;
    $statusMessage = "
        <p><strong>Congratulations!</strong> Your car ownership transfer request has been approved by the registry administrators.</p>

        <p><strong>What this means:</strong></p>
        <ul>
            <li>You are now the registered owner of this Lotus Elan</li>
            <li>The car record has been updated with your information</li>
            <li>You can now edit and manage this car in your registry account</li>
            <li>The car will appear in your \"My Cars\" section</li>
        </ul>

        " . $emailTemplate->createButton('View Your Car', $carUrl, 'success') . "

        <p>Welcome to the ownership of this beautiful Lotus Elan! We encourage you to keep the registry updated with any changes or improvements to your car.</p>

        <h3>📚 Helpful Resources</h3>
        <p>Get familiar with managing your car in the registry:</p>
        <ul>
            <li><a href=\"{$us_url_root}docs/view.php?doc=CAR_TRANSFER_USER_GUIDE.md\">Car Transfer User Guide</a> - Complete guide for ownership transfers</li>
            <li><a href=\"{$us_url_root}docs/view.php?doc=CAR_TRANSFER_FAQ.md\">Transfer FAQ</a> - Frequently asked questions about transfers</li>
            <li><a href=\"{$us_url_root}docs/faq/index.php\">Registry Documentation</a> - All user guides and help resources</li>
        </ul>
    ";
} else {
    $statusStyle = 'alert';
    $statusTitle = 'Transfer Request Denied';
    $statusContent = $requestDetails;
    $statusMessage = "
        <p>After review, your car ownership transfer request has been denied by the registry administrators.</p>

        <p><strong>What this means:</strong></p>
        <ul>
            <li>The current owner remains the registered owner</li>
            <li>No changes have been made to the car record</li>
            <li>You may submit a new request with additional documentation if needed</li>
        </ul>

        <p><strong>Questions about this decision?</strong> Please contact the registry administrators at
        <a href=\"mailto:registrar@elanregistry.org\">registrar@elanregistry.org</a> if you have additional information
        or documentation that supports your ownership claim.</p>

        <h3>📚 Next Steps & Resources</h3>
        <p>Learn more about the transfer process and requirements:</p>
        <ul>
            <li><a href=\"{$us_url_root}docs/view.php?doc=CAR_TRANSFER_FAQ.md\">Transfer FAQ</a> - Common questions about transfer requirements</li>
            <li><a href=\"{$us_url_root}docs/view.php?doc=CAR_TRANSFER_USER_GUIDE.md\">Transfer User Guide</a> - Step-by-step transfer process</li>
            <li><a href=\"{$us_url_root}docs/faq/index.php\">Registry Help</a> - Complete documentation and support</li>
        </ul>
        <p>These resources can help you understand transfer requirements and prepare a stronger case for a future request.</p>
    ";
}

// Build main content
$content = "
    <p>Hello <strong>" . htmlspecialchars($requester->fname) . "</strong>,</p>

    <p>Your car ownership transfer request has been reviewed by the registry administrators.</p>

    " . $emailTemplate->createMessageBox($statusTitle, $statusContent, $statusStyle) . "

    " . $emailTemplate->createMessageBox('Car Information', $carDetails) . "

    {$statusMessage}
";

// Add admin notes if provided
if (!empty($adminNotes)) {
    $content .= $emailTemplate->createMessageBox('Administrator Notes',
        '<div class="message-content">' . htmlspecialchars($adminNotes) . '</div>', 'message');
}

$content .= "
    <p>Thank you for your participation in the Lotus Elan Registry. Our goal is to maintain accurate ownership records while ensuring all transfers are legitimate and properly documented.</p>
";

// Generate the complete email
echo $emailTemplate->render(
    'Transfer Request ' . ($isApproved ? 'Approved' : 'Denied'),
    'Transfer Request Response',
    $content,
    ['footer_text' => 'This notification was sent in response to your car ownership transfer request.']
);
?>
