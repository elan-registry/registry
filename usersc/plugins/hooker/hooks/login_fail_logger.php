<?php
if (count(get_included_files()) == 1) die(); //Direct Access Not Permitted Leave this line in place

// Mirrors the commented-out logger call in users/login.php:364.
// UserSpice ships with all login-event logging commented out;
// this hook restores it with the project security log category.
// Variables must be declared global — hooks are include'd inside includeHook().
global $username, $userId;

logger(
    (int) ($userId ?? 0),
    \ElanRegistry\LogCategories::LOG_CATEGORY_SECURITY,
    'Failed login attempt for username: ' . ($username ?? 'unknown')
);
