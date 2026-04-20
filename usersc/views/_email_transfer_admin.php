<?php

declare(strict_types=1);

/**
 * Transfer Request Admin Alert Email Template
 *
 * Notifies administrators about a new transfer request requiring review
 * Variables available: $currentOwner, $requester, $carInfo, $transferRequest, $reviewUrl
 */

require_once $abs_us_root . $us_url_root . 'usersc/classes/EmailTemplate.php';

$emailTemplate = new EmailTemplate();

// Build car information display
$carDetails =
    $emailTemplate->createDetailRow('Car ID', $carInfo->id) .
    $emailTemplate->createDetailRow('Year', $carInfo->year) .
    $emailTemplate->createDetailRow('Model', $carInfo->series . ' ' . $carInfo->variant) .
    $emailTemplate->createDetailRow('Chassis', $carInfo->chassis) .
    $emailTemplate->createDetailRow('Color', $carInfo->color ?: 'Not specified');

// Build current owner information
$currentOwnerDetails =
    $emailTemplate->createDetailRow('Name', $currentOwner->fname . ' ' . $currentOwner->lname) .
    $emailTemplate->createDetailRow('Email', $currentOwner->email) .
    $emailTemplate->createDetailRow('User ID', $currentOwner->id) .
    $emailTemplate->createDetailRow('Location', trim($currentOwner->city . ', ' . $currentOwner->state . ', ' . $currentOwner->country, ', ') ?: 'Not specified');

// Build requester information
$requesterDetails =
    $emailTemplate->createDetailRow('Name', $requester->fname . ' ' . $requester->lname) .
    $emailTemplate->createDetailRow('Email', $requester->email) .
    $emailTemplate->createDetailRow('User ID', $requester->id) .
    $emailTemplate->createDetailRow('Location', trim($requester->city . ', ' . $requester->state . ', ' . $requester->country, ', ') ?: 'Not specified');

// Build request details
$requestDetails =
    $emailTemplate->createDetailRow('Request ID', $transferRequest->id) .
    $emailTemplate->createDetailRow('Submitted', date('M j, Y g:i A', strtotime($transferRequest->request_date))) .
    $emailTemplate->createDetailRow('Expires', date('M j, Y g:i A', strtotime($transferRequest->expires_at)));

// Build main content
$content = "
    <p><strong>A new car ownership transfer request requires administrative review.</strong></p>

    " . $emailTemplate->createMessageBox('Transfer Request Details', $requestDetails, 'alert') . "

    " . $emailTemplate->createMessageBox('Car Information', $carDetails) . "

    " . $emailTemplate->createMessageBox('Current Owner', $currentOwnerDetails) . "

    " . $emailTemplate->createMessageBox('Requested By', $requesterDetails) . "

    " . (!empty($transferRequest->submitted_comments) ?
        $emailTemplate->createMessageBox('Requester\'s Comments',
            $emailTemplate->createMessageContent($transferRequest->submitted_comments), 'message') : '') . "

    " . $emailTemplate->createButton('Review Transfer Request', $reviewUrl, 'primary') . "

    <p><strong>Administrative Action Required:</strong></p>
    <ul>
        <li>Review the transfer request details above</li>
        <li>Verify the legitimacy of the ownership claim</li>
        <li>Contact the parties involved if additional verification is needed</li>
        <li>Approve or deny the request through the admin panel</li>
    </ul>

    <h3>📋 Administrative Resources</h3>
    <p>Reference guides for handling transfer requests:</p>
    <ul>
        <li><a href=\"{$us_url_root}docs/view.php?doc=CAR_TRANSFER_ADMIN_GUIDE.md\">Transfer Admin Guide</a> - Complete administrative procedures</li>
        <li><a href=\"{$us_url_root}docs/view.php?doc=CAR_TRANSFER_ADMIN_QUICK_REFERENCE.md\">Admin Quick Reference</a> - Daily tasks and quick fixes</li>
        <li><a href=\"{$us_url_root}docs/view.php?doc=CAR_TRANSFER_TROUBLESHOOTING.md\">Troubleshooting Guide</a> - Common issues and solutions</li>
        <li><a href=\"{$us_url_root}docs/faq/admin/index.php\">Admin Documentation</a> - All administrative guides</li>
    </ul>

    <p><small><strong>Note:</strong> This request will automatically expire on " . date('M j, Y', strtotime($transferRequest->expires_at)) . " if no action is taken.</small></p>
";

// Generate the complete email
echo $emailTemplate->render(
    'Transfer Request - Admin Review Required',
    'Administrative Review Required',
    $content,
    ['footer_text' => 'This is an automated administrative alert from the registry transfer system.']
);
?>