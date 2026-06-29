<?php

declare(strict_types=1);

/**
 * Owner to Owner Contact Email Template
 *
 * Sent when one registry member contacts a car owner through the registry.
 * Variables available: $to, $from, $message, $car_year, $car_series,
 * $car_variant, $car_type, $car_chassis, $carUrl
 */

require_once $abs_us_root . $us_url_root . 'usersc/classes/EmailTemplate.php';

$emailTemplate = new EmailTemplate();

// Build a human-readable car description, e.g. "1968 Elan +2 FHC (Type 50)"
$carLabel = '';
if (!empty($car_year)) {
    $carLabel .= htmlspecialchars((string)$car_year, ENT_QUOTES, 'UTF-8');
}
if (!empty($car_series)) {
    $carLabel .= ' Elan ' . htmlspecialchars($car_series, ENT_QUOTES, 'UTF-8');
}
if (!empty($car_variant)) {
    $carLabel .= ' ' . htmlspecialchars($car_variant, ENT_QUOTES, 'UTF-8');
}
if (!empty($car_type)) {
    $carLabel .= ' (Type ' . htmlspecialchars($car_type, ENT_QUOTES, 'UTF-8') . ')';
}

// Build car details rows
$carDetailsContent = '';
if (!empty($car_year)) {
    $carDetailsContent .= $emailTemplate->createDetailRow('Year', (string)$car_year);
}
if (!empty($car_series)) {
    $carDetailsContent .= $emailTemplate->createDetailRow('Series', $car_series);
}
if (!empty($car_variant)) {
    $carDetailsContent .= $emailTemplate->createDetailRow('Variant', $car_variant);
}
if (!empty($car_type)) {
    $carDetailsContent .= $emailTemplate->createDetailRow('Type', $car_type);
}
if (!empty($car_chassis)) {
    $carDetailsContent .= $emailTemplate->createDetailRow('Chassis', $car_chassis);
}
if (!empty($carUrl)) {
    $carDetailsContent .= $emailTemplate->createButton('View Car Details', $carUrl);
}

$introLine = !empty($carLabel)
    ? 'A fellow Lotus Elan Registry member has sent you a message regarding your <strong>' . $carLabel . '</strong>.'
    : 'A fellow Lotus Elan Registry member has sent you a message about one of your cars.';

$content = "
    <p>Hello <strong>" . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . "</strong>,</p>

    <p>{$introLine}</p>

    " . (!empty($carDetailsContent) ? $emailTemplate->createMessageBox('Your Car', $carDetailsContent) : '') . "

    " . $emailTemplate->createMessageBox('Message From', $emailTemplate->createDetailRow('Name', $from)) . "

    " . $emailTemplate->createMessageBox('Message', $emailTemplate->createMessageContent($message), 'message') . "

    <p><strong>To respond to " . htmlspecialchars($from, ENT_QUOTES, 'UTF-8') . ", simply reply to this email — your reply will be sent directly to them.</strong></p>
";

echo $emailTemplate->render(
    'A fellow Elan owner sent you a message',
    'Owner to Owner Message',
    $content,
    ['footer_text' => 'This message was sent through the registry contact system. Reply to this email to respond directly to the sender.']
);
?>
