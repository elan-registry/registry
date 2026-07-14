<?php
if (count(get_included_files()) == 1) die(); //Direct Access Not Permitted Leave this line in place

// Mirrors the commented-out logger call in users/login.php:364.
// UserSpice ships with all login-event logging commented out;
// this hook restores it with the project security log category.
// Variables must be declared global — hooks are include'd inside includeHook().
//
// Scope: credential failures only. TOTP failures (inline: login.php:286, step-2: login.php:196)
// are tracked for rate-limiting via handleAuthFailure('totp_verify') but do not fire loginFail,
// so they produce no log entry here — by design, as the loginFail hook point doesn't exist for them.
global $username, $userId;

logger(
    (int) ($userId ?? 0),
    \ElanRegistry\LogCategories::LOG_CATEGORY_SECURITY,
    'Failed login attempt for username: ' . ($username ?? 'unknown')
);
