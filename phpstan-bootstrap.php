<?php

/**
 * PHPStan bootstrap — stubs UserSpice framework globals that config.php needs
 * at analysis time. These are set by z_us_root.php in the normal page-load flow,
 * but PHPStan bootstraps in isolation so they must be pre-declared here.
 */
$abs_us_root = '';
$us_url_root = '';

require_once __DIR__ . '/usersc/includes/config.php';
