<?php
/**
 * Owner to Owner Contact Email Template
 *
 * Notification email sent when one owner contacts another through the registry.
 * Variables available: $to, $from, $message
 */

require_once $abs_us_root . $us_url_root . 'usersc/classes/EmailTemplate.php';

$emailTemplate = new EmailTemplate();

// Build main content
$content = "
    <p>Hello <strong>" . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . "</strong>,</p>

    <p>Another Elan owner has sent you a message through the Lotus Elan Registry.</p>

    " . $emailTemplate->createMessageBox('From Owner', $emailTemplate->createDetailRow('Name', $from)) . "

    " . $emailTemplate->createMessageBox('Message', $emailTemplate->createMessageContent($message), 'message') . "

    <p><strong>To respond to " . htmlspecialchars($from, ENT_QUOTES, 'UTF-8') . ", simply reply to this email — your reply will be sent directly to them.</strong></p>
";

// Generate the complete email
echo $emailTemplate->render(
    '[ELANREGISTRY] Owner to Owner Message',
    'Owner to Owner Message',
    $content,
    ['footer_text' => 'This message was sent through the registry contact system. Reply to this email to respond directly to the sender.']
);
?>
