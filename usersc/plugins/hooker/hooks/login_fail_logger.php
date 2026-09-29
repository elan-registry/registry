<?php
if (count(get_included_files()) == 1) die(); //Direct Access Not Permitted Leave this line in place

// Mirrors the commented-out logger call in users/login.php:364.
// UserSpice ships with all login-event logging commented out;
// this hook restores it with the project security log category.
// Variables must be declared global — hooks are include'd inside includeHook().
//
// Scope: credential failures only. TOTP failures (both the inline and the step-2
// handleAuthFailure('totp_verify', ...) calls in usersc/login.php) are tracked for
// rate-limiting but do not fire loginFail, so they produce no log entry here — by
// design, as the loginFail hook point doesn't exist for them.
global $username, $userId;

// An unmatched identifier is untrusted free text: a member who types their
// password into the username box would put that password in the log. Log
// the submitted value only when it matched a real account.
$knownUserId = (int) ($userId ?? 0);
$logMessage = $knownUserId > 0
    ? 'Failed login attempt for username: ' . $username
    : 'Failed login attempt for unrecognised username';

logger(
    $knownUserId,
    \ElanRegistry\LogCategories::LOG_CATEGORY_SECURITY,
    $logMessage
);
