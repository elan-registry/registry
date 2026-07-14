<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;

/**
 * Shared infrastructure for admin fix and maintenance scripts.
 *
 * Include after require_once '../../../../users/init.php' and before
 * require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php'.
 *
 * Provides: POST+CSRF gate, start-form HTML, close-button HTML, logProgress().
 */

define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');

/** Returns true only when the current request is a POST with a valid CSRF token. */
function admin_script_exec_requested(): bool
{
    global $method;
    return $method === 'POST' && Token::check($_POST['csrf'] ?? '');
}

/**
 * Returns the HTML for a POST+CSRF "Start" form button.
 *
 * @param string $label   Button label text (HTML-escaped internally)
 * @param string $icon    Font Awesome icon class without "fa-" prefix, e.g. 'play'
 * @param string $btnClass Bootstrap button classes, e.g. 'btn-success btn-lg'
 */
function admin_script_start_form(
    string $label,
    string $icon = 'play',
    string $btnClass = 'btn-success btn-lg'
): string {
    return '<form method="POST"><input type="hidden" name="csrf" value="'
        . htmlspecialchars(Token::generate(), ENT_QUOTES, 'UTF-8')
        . '"><button type="submit" class="btn '
        . htmlspecialchars($btnClass, ENT_QUOTES, 'UTF-8')
        . '"><i class="fa fa-'
        . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8')
        . '"></i> '
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</button></form>';
}

/**
 * Returns the HTML for a "Close Window" button.
 * Closes the script window; if an opener window exists, reloads it first.
 *
 * @param string $extraClass Additional Bootstrap/custom classes to append
 */
function admin_script_close_button(string $extraClass = ''): string
{
    $cls = trim('btn btn-primary btn-lg ' . $extraClass);
    return '<button type="button" onclick="if(window.opener){window.opener.location.reload();} window.close();" class="'
        . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8')
        . '"><i class="fa fa-times"></i> Close Window</button>';
}

/**
 * Writes a timestamped progress line inside a <pre> block.
 * For simple (non-streaming) fix scripts only.
 * Streaming scripts (outputMessage() pattern) should not use this function.
 *
 * @param string $message Message to output
 * @param string $type    'info' | 'success' | 'error' | 'warning' | 'step'
 */
function logProgress(string $message, string $type = 'info'): void
{
    $icons = [
        'info'    => 'ℹ️',
        'success' => '✅',
        'error'   => '❌',
        'warning' => '⚠️',
        'step'    => '▶️',
    ];
    echo date('[H:i:s] ') . ($icons[$type] ?? '•') . ' ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "\n";
    flush();
}
