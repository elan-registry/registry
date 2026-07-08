<?php

declare(strict_types=1);

use ElanRegistry\EmailTemplate;

/**
 * Forgot Password Email Template (Branded Override)
 *
 * Overrides the default UserSpice forgot password email
 * (users/views/_email_template_forgot_password.php) with full registry branding
 * via the EmailTemplate class.
 *
 * Variables available (from $email_field_whitelist):
 *   $fname            — user's first name (raw)
 *   $email            — already rawurlencode'd by the caller; do NOT re-encode in this template
 *   $vericode         — raw plaintext token (rawurlencode applied in this template)
 *   $user_id          — user ID integer
 *   $reset_vericode_expiry — reset link expiry in minutes
 */

$emailTemplate = new EmailTemplate();

$resetUrl = getBaseUrl() . '/users/forgot_password_reset.php?email=' . $email . '&vericode=' . rawurlencode($vericode) . '&user_id=' . (int)$user_id . '&reset=1';

$content = "
    <p>Hello <strong>" . htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') . "</strong>,</p>

    <p>We received a request to reset the password for your Lotus Elan Registry account. Click the button below to choose a new password.</p>

    " . $emailTemplate->createButton('Reset My Password', $resetUrl, 'primary') . "

    <p>Or copy and paste this link into your browser:</p>
    <p style=\"word-break:break-all;font-size:13px;color:#6c757d;\">" . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . "</p>

    <p>This reset link expires in <strong>" . htmlspecialchars((string)$reset_vericode_expiry, ENT_QUOTES, 'UTF-8') . " minutes</strong>.</p>

    <p>If you did not request a password reset, you can safely ignore this email. Your password will not be changed.</p>
";

echo $emailTemplate->render(
    'Reset Your Password',
    'Password Reset Request',
    $content,
    ['footer_text' => 'You received this email because a password reset was requested for your account. If this was not you, please ignore this message.']
);
