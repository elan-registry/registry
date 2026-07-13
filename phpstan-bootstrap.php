<?php

/**
 * PHPStan bootstrap — stubs UserSpice framework globals so page-level files
 * don't generate false-positive variable.undefined errors for
 * framework-provided context.
 *
 * These are set by z_us_root.php + users/init.php + usersc/includes/server_globals.php
 * in the normal page-load flow, but PHPStan bootstraps in isolation so they
 * must be pre-declared here.
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
$user = null;
$db   = null;

require_once __DIR__ . '/usersc/includes/config.php';
