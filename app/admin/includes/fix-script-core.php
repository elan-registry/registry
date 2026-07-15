<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;

/**
 * Shared infrastructure for admin fix and maintenance scripts.
 *
 * Include after require_once '../../../../users/init.php' and before
 * require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php'.
 *
 * Provides: POST + CSRF + admin-role gate, start-form HTML, close-button HTML, logProgress().
 */

define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');

/**
 * Returns true only when the current request is a POST with a valid CSRF token
 * from an admin-role user. Blocks editor accounts from triggering destructive
 * execute paths independent of whatever roles securePage() allows at the page level.
 *
 * Result is cached statically — Token::generate() (called by admin_script_start_form())
 * overwrites the session token, so calling Token::check() more than once per request
 * would return false on subsequent calls even for a valid initial POST.
 */
function admin_script_exec_requested(): bool
{
    static $result = null;
    if ($result !== null) {
        return $result;
    }
    global $method;
    $result = $method === 'POST' && Token::check($_POST['csrf'] ?? '') && isAdmin();
    return $result;
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
 * If the window has no opener (e.g. direct URL access), navigates to $fallbackUrl instead.
 *
 * @param string $extraClass  Additional Bootstrap/custom classes to append
 * @param string $fallbackUrl URL to navigate to when window.opener is absent (e.g. '../../maintenance.php?tab=maintenance').
 *                            Must be a trusted static string — never pass user-supplied input. The URL is embedded
 *                            directly inside a JS string literal; user-controlled values would require JS-context
 *                            encoding, which this function does not perform.
 */
function admin_script_close_button(string $extraClass = '', string $fallbackUrl = ''): string
{
    $cls = trim('btn btn-primary btn-lg ' . $extraClass);
    if ($fallbackUrl !== '') {
        $safeUrl = htmlspecialchars($fallbackUrl, ENT_QUOTES, 'UTF-8');
        $onclick  = "if(window.opener){window.opener.location.reload();window.close();}else{window.location.href='{$safeUrl}';}";
    } else {
        $onclick = 'if(window.opener){window.opener.location.reload();} window.close();';
    }
    return '<button type="button" onclick="' . $onclick . '" class="'
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
