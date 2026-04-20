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

$carDetails =
    $emailTemplate->createDetailRow('Car ID', $carInfo->id) .
    $emailTemplate->createDetailRow('Year', $carInfo->year) .
    $emailTemplate->createDetailRow('Model', $carInfo->series . ' ' . $carInfo->variant) .
    $emailTemplate->createDetailRow('Chassis', $carInfo->chassis) .
    $emailTemplate->createDetailRow('Color', $carInfo->color ?: 'Not specified');

$currentOwnerDetails =
    $emailTemplate->createDetailRow('Name', $currentOwner->fname . ' ' . $currentOwner->lname) .
    $emailTemplate->createDetailRow('Email', $currentOwner->email) .
    $emailTemplate->createDetailRow('User ID', $currentOwner->id) .
    $emailTemplate->createDetailRow('Location', trim($currentOwner->city . ', ' . $currentOwner->state . ', ' . $currentOwner->country, ', ') ?: 'Not specified');

$requesterDetails =
    $emailTemplate->createDetailRow('Name', $requester->fname . ' ' . $requester->lname) .
    $emailTemplate->createDetailRow('Email', $requester->email) .
    $emailTemplate->createDetailRow('User ID', $requester->id) .
    $emailTemplate->createDetailRow('Location', trim($requester->city . ', ' . $requester->state . ', ' . $requester->country, ', ') ?: 'Not specified');

$expiresAt     = strtotime($transferRequest->expires_at);
$requestDetails =
    $emailTemplate->createDetailRow('Request ID', $transferRequest->id) .
    $emailTemplate->createDetailRow('Submitted', date('M j, Y g:i A', strtotime($transferRequest->request_date))) .
    $emailTemplate->createDetailRow('Expires', date('M j, Y g:i A', $expiresAt));

$content = "
    <p><strong>A new car ownership transfer request requires administrative review.</strong></p>

    " . $emailTemplate->createMessageBox('Transfer Request Details', $requestDetails, 'alert') . "

    " . $emailTemplate->createButton('Review Transfer Request', $reviewUrl, 'primary') . "

    " . $emailTemplate->createMessageBox('Car Information', $carDetails) . "

    " . $emailTemplate->createMessageBox('Current Owner', $currentOwnerDetails) . "

    " . $emailTemplate->createMessageBox('Requested By', $requesterDetails) . "

    " . (!empty($transferRequest->submitted_comments) ?
        $emailTemplate->createMessageBox('Requester\'s Comments',
            $emailTemplate->createMessageContent($transferRequest->submitted_comments), 'message') : '') . "
";

echo $emailTemplate->render(
    'Transfer Request - Admin Review Required',
    'Administrative Review Required',
    $content,
    ['footer_text' => 'This is an automated administrative alert from the registry transfer system.']
);
?>
