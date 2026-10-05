<?php

declare(strict_types=1);

use ElanRegistry\EmailTemplate;

/**
 * Registration Attempt Recovery Email Template
 *
 * Sent when someone attempts to register a new account using an email address
 * that already belongs to an existing account (issue #1406, account-enumeration
 * hardening on the registration form). It notifies the existing account holder
 * and offers a password reset, rather than revealing to the person attempting
 * registration that the email is already in use.
 *
 * Rendered via ElanRegistry\RegistrationRecoveryNotifier::notifyIfAccountExists().
 * The reset link intentionally targets the existing password-reset verification
 * flow (users/forgot_password_reset.php) — no new endpoint is introduced.
 *
 * Variables available (from $email_field_whitelist):
 *   $fname            — user's first name (raw)
 *   $email            — already rawurlencode'd by the caller; do NOT re-encode in this template
 *   $vericode         — raw plaintext token (rawurlencode applied in this template)
 *   $user_id          — user ID integer
 *   $reset_vericode_expiry — reset link expiry in minutes
 *
 * @var string $fname
 * @var string $email
 * @var string $vericode
 * @var int|string $user_id
 * @var int|string $reset_vericode_expiry
 */

$emailTemplate = new EmailTemplate();

$resetUrl = getBaseUrl() . '/users/forgot_password_reset.php?email=' . $email . '&vericode=' . rawurlencode($vericode) . '&user_id=' . (int)$user_id . '&reset=1';

// Brevo rewrites every http link through its tracking domain, so the fallback is a
// mailto contact, not a printed URL (#2147).
$safeContact = htmlspecialchars(getFeedbackEmail(), ENT_QUOTES, 'UTF-8');

$content = "
    <p>Hello <strong>" . htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') . "</strong>,</p>

    <p>Someone (possibly you) recently tried to register a new account using your email address on the Lotus Elan Registry. An account with this email already exists, so no new account was created.</p>

    <p>If this was you and you've forgotten your password, you can reset it below and sign in to your existing account.</p>

    " . $emailTemplate->createButton('Reset My Password', $resetUrl, 'primary') . "

    <p>If the button above does not work, contact <a href=\"mailto:" . $safeContact . "\">" . $safeContact . "</a>.</p>

    <p>This reset link expires in <strong>" . htmlspecialchars((string)$reset_vericode_expiry, ENT_QUOTES, 'UTF-8') . " minutes</strong>.</p>

    <p>If you don't recognize this activity, you can safely ignore this email — no changes have been made to your account.</p>
";

echo $emailTemplate->render(
    'Registration Attempt on Your Account',
    'Registration Attempt on Your Account',
    $content,
    ['footer_text' => 'You received this email because someone tried to register a new account using your email address, which already belongs to an existing account. If this was not you, no action is needed — your account is unchanged.']
);
