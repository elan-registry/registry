<?php

/**
 * PHPStan bootstrap — pre-declares UserSpice framework globals so the
 * `require_once config.php` at the bottom of this file can resolve
 * `$abs_us_root` and `$us_url_root` during analysis startup.
 *
 * These globals are set by z_us_root.php + users/init.php +
 * usersc/includes/server_globals.php at runtime, but PHPStan bootstraps in
 * isolation. The remaining stubs (server_globals globals, $user, $db) are
 * declared for consistency and IDE completeness.
 *
 * Note: these stubs do NOT suppress variable.undefined errors in page files —
 * bootstrap scope is isolated from the analysed files. That suppression is
 * handled by the ignoreErrors pattern in phpstan.neon.
 *
 * Page-level variables ($car, $transfer, $settings, etc.) are script-scope
 * locals set via includes and are handled by the generated baseline instead.
 */

// z_us_root.php path globals
$abs_us_root = '';
$us_url_root = '';

// usersc/includes/server_globals.php — validated $_SERVER wrappers
$php_self       = '';
$is_https       = false;
$host           = '';
$method         = '';
$request_uri    = '';
$current_url    = '';
$current_origin = '';
$remote_addr    = '';
$referer        = '';
$user_agent     = '';

// UserSpice auth/DB context set by users/init.php
/** @var mixed $user */
$user = null;
/** @var mixed $db */
$db   = null;

require_once __DIR__ . '/usersc/includes/config.php';
