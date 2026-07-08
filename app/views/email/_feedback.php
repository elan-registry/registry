<?php

declare(strict_types=1);

use ElanRegistry\EmailTemplate;

/**
 * Feedback Submission Email Template
 *
 * Sent to registry administrators when a user submits feedback.
 * Variables available: $name, $email, $accountId, $comments
 */

$emailTemplate = new EmailTemplate();

$ownerDetails =
    $emailTemplate->createDetailRow('Name', $name) .
    $emailTemplate->createDetailRow('Email', $email) .
    $emailTemplate->createDetailRow('Account ID', $accountId);

$messageHtml = $emailTemplate->createMessageContent($comments, true);

$content = "
    <p>A registry member has submitted feedback.</p>
" .
    $emailTemplate->createMessageBox('Owner Details', $ownerDetails, 'default') .
    $emailTemplate->createMessageBox('Feedback Message', $messageHtml, 'message');

echo $emailTemplate->render(
    '[ELANREGISTRY] User Feedback',
    'Feedback from ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
    $content,
    ['footer_text' => 'This is an automated message from the registry feedback system.']
);
