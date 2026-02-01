<?php
/**
 * Pre-footer hook - loaded by users/includes/page_footer.php before
 * system_messages_footer.php.
 *
 * Includes the toast container so UserSpice toast JS can find it.
 *
 * @todo Issue #234 (BS5 migration): This file is still needed after BS5
 *       migration — it wires the toast container into the page. Only the
 *       system_messages_header.php override can be removed; this hook
 *       will then load the upstream users/ version instead.
 */

// Load toast container (must come before system_messages_footer.php JS)
if (file_exists($abs_us_root . $us_url_root . 'usersc/includes/system_messages_header.php')) {
    require_once $abs_us_root . $us_url_root . 'usersc/includes/system_messages_header.php';
} elseif (file_exists($abs_us_root . $us_url_root . 'users/includes/system_messages_header.php')) {
    require_once $abs_us_root . $us_url_root . 'users/includes/system_messages_header.php';
}
