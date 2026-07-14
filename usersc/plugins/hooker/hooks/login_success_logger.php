<?php
if (count(get_included_files()) == 1) die(); //Direct Access Not Permitted Leave this line in place

// Mirrors commented-out logger calls in users/login.php (lines 138, 149, 284, 318).
// UserSpice ships with all login-event logging commented out;
// this hook restores it with the project security log category.
// Variables must be declared global — hooks are include'd inside includeHook().
//
// loginSuccess fires from three paths, and the user id is available via a
// different variable in each: $tempUser (login.php:278, :335) or $tempUserId
// (login.php:179). Prefer $tempUser when present, fall back to $tempUserId.
global $tempUser, $tempUserId;

$logUserId = 0;
if (isset($tempUser) && $tempUser->data()) {
    $logUserId = (int) $tempUser->data()->id;
} elseif (isset($tempUserId) && $tempUserId) {
    $logUserId = (int) $tempUserId;
}

logger($logUserId, \ElanRegistry\LogCategories::LOG_CATEGORY_SECURITY, 'Successful login');
