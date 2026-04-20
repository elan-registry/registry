<?php
/**
 * Feedback Submission Email Template
 *
 * Sent to registry administrators when a user submits feedback.
 * Variables available: $name, $email, $accountId, $comments
 */

require_once $abs_us_root . $us_url_root . 'usersc/classes/EmailTemplate.php';
$emailTemplate = new EmailTemplate();

$ownerDetails =
    $emailTemplate->createDetailRow('Name', $name) .
    $emailTemplate->createDetailRow('Email', $email) .
    $emailTemplate->createDetailRow('Account ID', $accountId);

$messageHtml = $emailTemplate->createMessageContent($comments, true);

$content =
    $emailTemplate->createMessageBox('Owner Details', $ownerDetails, 'default') .
    $emailTemplate->createMessageBox('Feedback Message', $messageHtml, 'message');

echo $emailTemplate->render(
    '[ELANREGISTRY] User Feedback',
    'User Feedback Submission',
    $content,
    ['footer_text' => 'This is an automated message from the registry feedback system.']
);
