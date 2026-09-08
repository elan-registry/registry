<?php

declare(strict_types=1);

namespace ElanRegistry\Spike1888;

/**
 * Brevo Webhook Capture Endpoint (spike #1888)
 *
 * Throwaway capture endpoint used to verify that Brevo's webhook
 * configuration for the bounce-detection endpoint (#1887) is correct BEFORE
 * `app/api/webhooks/brevo.php` is deployed to test.elanregistry.org: is the
 * URL reachable from Brevo, does the `Authorization: Bearer <token>` header
 * arrive intact using the *real* `BREVO_WEBHOOK_TOKEN` (not a placeholder
 * shared secret), and do the expected events fire and get subscribed.
 *
 * This does NOT need to re-derive payload shapes, event names, or field
 * layouts — that groundwork is already done. See
 * docs/development/EMAIL_SYSTEM.md § "Brevo Webhooks — Verified Behaviour
 * (#1871)" for the verified event/field shapes, and
 * scripts/spike-1871/README.md for the redaction and JSONL-logging security
 * rationale reused here unchanged.
 *
 * Not production code. Deleted from the server once the webhook
 * configuration is confirmed working — it is not part of ongoing deploy
 * infrastructure. The deploy hook removes scripts/ on the servers, so this
 * file only ever reaches a server via a manual scp and must be deleted there
 * after each run.
 *
 * This script never touches, rotates, or generates `.env`'s
 * BREVO_WEBHOOK_TOKEN. It only reads the value that must already be set on
 * the server as part of #1887's post-deployment step, and verifies Brevo can
 * present it back correctly.
 *
 * Security posture:
 * - Deliberately no UserSpice bootstrap, no session, no CSRF — Brevo cannot
 *   present any of those.
 * - Auth is the real `Authorization: Bearer <token>` mechanism, validated
 *   with hash_equals() against `$_ENV['BREVO_WEBHOOK_TOKEN']` — mirroring
 *   app/api/webhooks/brevo.php's own comparison exactly. Fails closed: an
 *   empty/missing configured token, or a missing/mismatched header, rejects
 *   every request rather than accepting everything.
 * - Auth failure answers 404, not 401. This deliberately differs from the
 *   real production endpoint (#1887), which answers 401 on auth failure —
 *   this capture script instead follows its #1871 predecessor's posture of
 *   not disclosing to an unauthorized caller that any endpoint lives at this
 *   URL during verification.
 * - Authorization/token-shaped header values are redacted to a 4-character
 *   prefix plus length before being written (same redact() logic as #1871).
 * - PHP warnings are never displayed (they would flush a 200 before the
 *   intended status and leak the capture path); a failed or partial write, or
 *   an unencodable record, answers 500 so the loss is visible.
 * - The capture file lives outside the web root so captured payloads (which
 *   contain recipient email addresses) are never served over HTTP.
 *
 * @author Elan Registry Development Team
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

const REPO_ROOT = __DIR__ . '/../..';

// --- edit before scp -------------------------------------------------------
// Absolute path to the capture file. MUST be outside the web root.
// Replace <cpanel-account> with the real cPanel account name before use.
const CAPTURE_FILE = '/home/<cpanel-account>/spike-1888/capture.jsonl';
// --- end edit --------------------------------------------------------------

/**
 * Send a response and stop.
 *
 * @param string|null $body JSON body, or null for an empty response
 */
function respond(int $status, ?string $body): never
{
    if (headers_sent()) {
        error_log('spike-1888 capture: headers already sent, cannot set status ' . $status);
    }

    http_response_code($status);

    if ($body !== null) {
        header('Content-Type: application/json');
        echo $body;
    }

    exit;
}

/**
 * Redact authorization- and token-shaped header values.
 *
 * Matches on the key name (normalising `-` to `_` so getallheaders() and
 * $_SERVER spellings both hit) or on an auth-scheme prefix in the value, so a
 * token arriving under an unexpected header name is still redacted.
 *
 * @param string $key Header or $_SERVER key name
 * @param string $value Raw value
 * @return string Redacted value, or the original when nothing matched
 */
function redact(string $key, string $value): string
{
    $normalisedKey = str_replace('-', '_', strtoupper($key));
    $redacted = substr($value, 0, 4) . '…(len=' . strlen($value) . ')';

    $sensitive = [
        'AUTHORIZATION',
        'TOKEN',
        'SECRET',
        'API_KEY',
        'APIKEY',
        'PHP_AUTH_PW',
        // Only REDIRECT_* keys pass the collection filter below, but they can
        // echo query-string/URI values; substring matching catches
        // REDIRECT_QUERY_STRING, REDIRECT_REQUEST_URI and REDIRECT_URL.
        // X-Mailin-* is deliberately NOT listed: it is Brevo's custom-metadata
        // header, and the value check below still redacts a token that
        // happens to arrive under it.
        'QUERY_STRING',
        'REQUEST_URI',
        'REDIRECT_URL',
    ];

    foreach ($sensitive as $needle) {
        if (str_contains($normalisedKey, $needle)) {
            return $redacted;
        }
    }

    if (preg_match('/^(Bearer|Basic|Token)\s+/i', $value) === 1) {
        return $redacted;
    }

    return $value;
}

$autoloader = REPO_ROOT . '/vendor/autoload.php';
if (!is_file($autoloader)) {
    error_log('spike-1888 capture: missing autoloader at ' . $autoloader);
    respond(404, null);
}
require_once $autoloader;

if (is_file(REPO_ROOT . '/.env')) {
    \Dotenv\Dotenv::createImmutable(REPO_ROOT)->load();
}

$authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$providedToken = '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $providedToken = substr($authHeader, 7);
}

$expectedToken = (string) ($_ENV['BREVO_WEBHOOK_TOKEN'] ?? '');

// Auth is checked before the method check, deliberately: this endpoint must
// not disclose to an unauthorized caller (via a distinguishable 405 vs 404)
// that anything lives at this URL at all. Fails closed: an empty configured
// token rejects every request rather than accepting everything (hash_equals
// ('', '') would otherwise return true). Auth failure answers 404 rather
// than 401 — see file docblock.
if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    respond(404, null);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, '{"ok":false}');
}

$serverKeys = [];
foreach ($_SERVER as $key => $value) {
    if (!is_string($key) || !is_scalar($value)) {
        continue;
    }

    if (str_starts_with($key, 'HTTP_')
        || str_starts_with($key, 'REDIRECT_')
        || str_starts_with($key, 'CONTENT_')
        || str_starts_with($key, 'PHP_AUTH_')
    ) {
        $serverKeys[$key] = redact($key, (string) $value);
    }
}

$headers = null;
if (function_exists('getallheaders')) {
    $headers = [];
    foreach (getallheaders() as $name => $value) {
        $headers[$name] = redact((string) $name, (string) $value);
    }
}

$rawBody = (string) file_get_contents('php://input');

// json_validate() is PHP 8.3; the test server is 8.2.
$decoded = json_decode($rawBody, true);
$jsonValid = json_last_error() === JSON_ERROR_NONE;

$isJsonArray = $jsonValid && is_array($decoded);
$bodyIsList = $isJsonArray && array_is_list($decoded);

$eventCount = 0;
if ($bodyIsList) {
    /** @var array<int, mixed> $decoded */
    $eventCount = count($decoded);
} elseif ($isJsonArray && isset($decoded['event'])) {
    $eventCount = 1;
}

$record = [
    'received_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'),
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
    'sapi' => PHP_SAPI,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
    'server_keys' => $serverKeys,
    'headers' => $headers,
    'raw_body' => $rawBody,
    'json_valid' => $jsonValid,
    'body_is_list' => $bodyIsList,
    'event_count' => $eventCount,
];

// Invalid UTF-8 anywhere in the request would otherwise make json_encode()
// return false and silently drop the event; substitute and record the error.
$encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($encoded === false) {
    $record['raw_body'] = base64_encode($rawBody);
    $record['json_encode_error'] = json_last_error_msg();
    $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

if ($encoded === false) {
    error_log('spike-1888 capture: record not encodable: ' . json_last_error_msg());
    respond(500, '{"ok":false}');
}

$line = $encoded . "\n";
$written = file_put_contents(CAPTURE_FILE, $line, FILE_APPEND | LOCK_EX);

if ($written !== strlen($line)) {
    error_log('spike-1888 capture: write to ' . CAPTURE_FILE . ' failed or was partial');
    respond(500, '{"ok":false}');
}

// Always 200 on a successful capture so Brevo never retries mid-spike.
respond(200, '{"ok":true}');
