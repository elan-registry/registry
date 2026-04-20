<?php

declare(strict_types=1);

/**
 * Registration Verification Email Template (Branded Override)
 *
 * Overrides the default UserSpice registration verification email
 * (users/views/_email_template_verify.php) with full registry branding
 * via the EmailTemplate class.
 *
 * Variables available (from $email_field_whitelist):
 *   $fname, $email, $vericode, $user_id, $join_vericode_expiry
 */

require_once $abs_us_root . $us_url_root . 'usersc/classes/EmailTemplate.php';

$emailTemplate = new EmailTemplate();

// Build verification URL
$verifyUrl = getBaseUrl() . '/users/verify.php?email=' . rawurlencode($email) . '&vericode=' . rawurlencode($vericode) . '&user_id=' . (int)$user_id;

// Build main content
$content = "
    <p>Hello <strong>" . htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') . "</strong>,</p>

    <p>Thank you for registering with the Lotus Elan Registry! To complete your registration, please verify your email address by clicking the button below.</p>

    " . $emailTemplate->createButton('Verify My Email Address', $verifyUrl, 'primary') . "

    <p>Or copy and paste this link into your browser:</p>
    <p style=\"word-break:break-all;font-size:13px;color:#6c757d;\">" . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . "</p>

    <p><small>This verification link expires in <strong>" . htmlspecialchars((string)$join_vericode_expiry, ENT_QUOTES, 'UTF-8') . " hours</strong>. If you did not register an account, you can safely ignore this email.</small></p>

    <h3>Welcome to the Registry</h3>
    <ul>
        <li>Register your Lotus Elan(s) in the worldwide database</li>
        <li>Connect with other Elan owners around the globe</li>
        <li>Access the complete registry history and documentation</li>
        <li>Manage ownership records and car details</li>
    </ul>
";

// Generate the complete email
echo $emailTemplate->render(
    'Verify Your Email Address',
    'Welcome — Please Verify Your Email',
    $content,
    ['footer_text' => 'You received this email because someone registered an account using this email address. If this was not you, please ignore this message.']
);
