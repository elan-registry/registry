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

$hasQualityIssue = !empty($qualityIssue);
$hasCarContext   = !empty($carContext['id']);

$adminDetails =
    $emailTemplate->createDetailRow('Name', $from) .
    $emailTemplate->createDetailRow('Role', 'Registry Administrator');

$carContextBox = '';
if ($hasCarContext) {
    $carDetails = $emailTemplate->createDetailRow('Car ID', $carContext['id']);
    if (isset($carContext['year']) && isset($carContext['model'])) {
        $carDetails .= $emailTemplate->createDetailRow('Vehicle', $carContext['year'] . ' ' . $carContext['model']);
    }
    if (isset($carContext['chassis'])) {
        $carDetails .= $emailTemplate->createDetailRow('Chassis', $carContext['chassis']);
    }
    if ($hasQualityIssue) {
        $carDetails .= $emailTemplate->createDetailRow('Issue', $qualityIssue);
    }
    $carContextBox = $emailTemplate->createMessageBox('Related to Your Car', $carDetails, 'default');
}

$registryUrl = htmlspecialchars(getBaseUrl(), ENT_QUOTES, 'UTF-8');

$content = "
    <p>Hello <strong>" . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . "</strong>,</p>

    " . ($hasQualityIssue
        ? '<p>A Registry Administrator has flagged a data issue with one of your car registrations and needs your help to correct it.</p>'
        : '<p>A Registry Administrator has sent you a message regarding your car registration.</p>') . "

    " . $emailTemplate->createMessageBox('From Registry Administrator', $adminDetails, 'default') . "

    " . $carContextBox . "

    " . $emailTemplate->createMessageBox('Administrator Message',
        $emailTemplate->createMessageContent($message), 'message') . "

    <p>To respond, simply reply to this email.</p>
";

if ($hasCarContext) {
    $baseUrl = getBaseUrl();
    if ($hasQualityIssue) {
        $content .= $emailTemplate->createButton('Update Your Car Record', $baseUrl . '/app/cars/form.php?car_id=' . (int)$carContext['id'], 'primary');
    } else {
        $content .= $emailTemplate->createButton('View Your Car', $baseUrl . '/app/cars/details.php?car_id=' . (int)$carContext['id'], 'primary');
    }
} else {
    $content .= "
    <p>Visit the registry at: <a href=\"{$registryUrl}\">{$registryUrl}</a></p>
";
}

echo $emailTemplate->render(
    'Lotus Elan Registry - Administrator Message',
    'Administrator Message',
    $content,
    ['footer_text' => 'This message was sent by a Registry Administrator through the data quality management system.']
);
?>
