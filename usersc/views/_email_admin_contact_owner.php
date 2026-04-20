<?php

declare(strict_types=1);

/**
 * Admin Contact Owner Email Template
 *
 * Notifies a car owner about a message from a Registry Administrator.
 * Variables available: $to, $from, $message, $carContext (array), $qualityIssue
 */

require_once $abs_us_root . $us_url_root . 'usersc/classes/EmailTemplate.php';

$emailTemplate = new EmailTemplate();

// Build administrator details
$adminDetails =
    $emailTemplate->createDetailRow('Name', $from) .
    $emailTemplate->createDetailRow('Role', 'Registry Administrator');

// Build car context details (conditional)
$carContextBox = '';
if (isset($carContext) && $carContext) {
    $carDetails = $emailTemplate->createDetailRow('Car ID', $carContext['id']);
    if (isset($carContext['year']) && isset($carContext['model'])) {
        $carDetails .= $emailTemplate->createDetailRow('Vehicle', $carContext['year'] . ' ' . $carContext['model']);
    }
    if (isset($carContext['chassis'])) {
        $carDetails .= $emailTemplate->createDetailRow('Chassis', $carContext['chassis']);
    }
    if (isset($qualityIssue)) {
        $carDetails .= $emailTemplate->createDetailRow('Issue', $qualityIssue);
    }
    $carContextBox = $emailTemplate->createMessageBox('Related to Your Car', $carDetails, 'default');
}

$registryUrl = htmlspecialchars(getBaseUrl(), ENT_QUOTES, 'UTF-8');

// Build main content
$content = "
    <p>Hello <strong>" . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . "</strong>,</p>

    <p>A Registry Administrator has sent you a message regarding your car registration in the Lotus Elan Registry.</p>

    " . $emailTemplate->createMessageBox('From Registry Administrator', $adminDetails, 'default') . "

    " . $carContextBox . "

    " . $emailTemplate->createMessageBox('Administrator Message',
        $emailTemplate->createMessageContent($message), 'message') . "

    <p>If this message is regarding data quality in your car registration, please consider updating your car details at: <a href=\"{$registryUrl}\">{$registryUrl}</a></p>
";

// Generate the complete email
echo $emailTemplate->render(
    'Lotus Elan Registry - Administrator Message',
    'Administrator Message',
    $content,
    ['footer_text' => 'This message was sent by a Registry Administrator through the data quality management system.']
);
?>
