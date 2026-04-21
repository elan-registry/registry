<?php

declare(strict_types=1);

/**
 * Email Change Verification Email Template (Branded Override)
 *
 * Overrides the default UserSpice email-change verification email
 * (users/views/_email_template_verify_new.php) with full registry branding
 * via the EmailTemplate class.
 *
 * Variables available (from $email_field_whitelist):
 *   $fname, $email, $vericode, $user_id, $join_vericode_expiry, $url
 */

require_once $abs_us_root . $us_url_root . 'usersc/classes/EmailTemplate.php';

$emailTemplate = new EmailTemplate();

// Build verification URL from trusted server-side components.
// $email here is the current (old) email, rawurlencode()'d by user_settings.php — it is not used
// in the URL. The new address is not in scope; vericode + user_id are sufficient for verify.php.
$verifyUrl = getBaseUrl() . '/users/verify.php?new=1'
    . '&email=' . ($email ?? '')
    . '&vericode=' . rawurlencode($vericode ?? '')
    . '&user_id=' . (int)($user_id ?? 0);

$content = "
    <p>Hello <strong>" . htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') . "</strong>,</p>

    <p>A request was made to change the email address on your Lotus Elan Registry account. To confirm this change, please verify your new email address by clicking the button below.</p>

    " . $emailTemplate->createButton('Verify New Email Address', $verifyUrl, 'primary') . "

    <p>Or copy and paste this link into your browser:</p>
    <p style=\"word-break:break-all;font-size:13px;color:#6c757d;\">" . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . "</p>

    <p><strong>Didn't request this change?</strong> Contact <a href=\"mailto:admin@elanregistry.org\">admin@elanregistry.org</a> immediately — your account may be at risk.</p>

    <p>This verification link expires in <strong>" . htmlspecialchars((string)$join_vericode_expiry, ENT_QUOTES, 'UTF-8') . " hours</strong>. Until verified, your previous email address will remain active.</p>
";

echo $emailTemplate->render(
    'Confirm your Elan Registry email address change',
    'Email Change Verification',
    $content,
    ['footer_text' => 'You received this email because a request was made to change the email address on your registry account.']
);
