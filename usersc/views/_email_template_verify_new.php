<?php
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

// Build verification URL (UserSpice pre-builds $url as a relative path).
// Validate that the composed URL stays within the known base to prevent open-redirect
// injection if $url were ever influenced by attacker-controlled data.
$baseUrl   = getBaseUrl();
$relPath   = ltrim($url ?? '', '/');
$verifyUrl = $baseUrl . '/' . $relPath;
if (!str_starts_with($verifyUrl, $baseUrl)) {
    $verifyUrl = $baseUrl;
}

// Build email details display
$emailDetails = $emailTemplate->createDetailRow('New Email Address', $email);

// Build main content
$content = "
    <p>Hello <strong>" . htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') . "</strong>,</p>

    <p>A request was made to change the email address on your Lotus Elan Registry account. To confirm this change, please verify your new email address by clicking the button below.</p>

    " . $emailTemplate->createMessageBox('Email Change Request', $emailDetails, 'default') . "

    " . $emailTemplate->createButton('Verify New Email Address', $verifyUrl, 'primary') . "

    <p>Or copy and paste this link into your browser:</p>
    <p style=\"word-break:break-all;font-size:13px;color:#6c757d;\">" . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . "</p>

    <p><small>This verification link expires in <strong>" . htmlspecialchars((string)$join_vericode_expiry, ENT_QUOTES, 'UTF-8') . " hours</strong>. Until verified, your previous email address will remain active. If you did not request this change, please contact us at <a href=\"mailto:admin@elanregistry.org\">admin@elanregistry.org</a> immediately.</small></p>
";

// Generate the complete email
echo $emailTemplate->render(
    'Verify Your New Email Address',
    'Email Change Verification',
    $content,
    ['footer_text' => 'You received this email because a request was made to change the email address on your registry account.']
);
