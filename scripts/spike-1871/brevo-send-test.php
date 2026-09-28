<?php

declare(strict_types=1);

// Namespaced so helper names (parseArgs, usage, …) cannot collide with other
// scripts/ files under PHPStan's whole-project analysis.
namespace ElanRegistry\Spike1871;

/**
 * Brevo transactional send / webhook inspection helper (spike #1871).
 *
 * Sends tagged test emails through Brevo so that delivery events fire at a
 * capture endpoint, lists the account's transactional webhooks so the real
 * `batched` and `auth` settings can be observed, and lists the transactional
 * suppression list (blocked contacts) with each entry's reason.
 *
 * Examples:
 *   php scripts/spike-1871/brevo-send-test.php --to=you@example.com
 *   php scripts/spike-1871/brevo-send-test.php \
 *       --to='bounce+550+no+such+user+here@inbox.mailtrap.io' \
 *       --tag=verification-spike --tag=hard
 *   php scripts/spike-1871/brevo-send-test.php --list-webhooks
 *   php scripts/spike-1871/brevo-send-test.php --list-blocked
 *   php scripts/spike-1871/brevo-send-test.php --env=/home/<cpanel-account>/test.elanregistry.org
 *
 * Run this script inside the app container, where `.env`'s DB_HOST already
 * points at the `db` service:
 *   docker compose exec -u www-data app php scripts/spike-1871/brevo-send-test.php --to=you@example.com
 *
 * Requirements: PHP with ext-curl, and this file must stay two levels below a
 * directory containing `vendor/autoload.php` (for Dotenv); `.env` is read from
 * that directory too unless --env points elsewhere.
 * It deliberately calls Brevo's REST API over curl rather than using the
 * Brevo SDK: that SDK and its Guzzle dependency live in
 * usersc/plugins/sendinblue/vendor/, which is gitignored and absent on CI, so
 * an SDK-based script could not pass static analysis there.
 *
 * Not production code. Retained with brevo-webhook-capture.php until #1887
 * ships its own fixtures — see README.md in this directory.
 *
 * Security: the Brevo API key is read at runtime from the `plg_sendinblue`
 * table and is never printed, logged, or written to disk by this script. Only
 * its length is reported (on STDERR) so a misconfigured row can be diagnosed.
 * Request headers are never echoed, since they carry the key.
 *
 * @author Elan Registry Development Team
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This script requires the PHP curl extension (ext-curl), which is not loaded.\n");
    exit(1);
}

const REPO_ROOT = __DIR__ . '/../..';
const MAX_COUNT = 10;
const BREVO_API_BASE = 'https://api.brevo.com/v3';

/**
 * Read a single-valued option, taking the first if getopt collected repeats.
 *
 * @param array<string, mixed> $opts
 */
function optValue(array $opts, string $name, ?string $default): ?string
{
    if (!isset($opts[$name])) {
        return $default;
    }

    $value = $opts[$name];

    return (string) (is_array($value) ? $value[0] : $value);
}

/**
 * Parsed command-line options.
 *
 * @return array{
 *     envDir: string,
 *     host: ?string,
 *     to: ?string,
 *     subject: string,
 *     tags: array<int, string>,
 *     count: int,
 *     listWebhooks: bool,
 *     listBlocked: bool,
 *     help: bool
 * }
 */
function parseArgs(): array
{
    $opts = getopt('', [
        'env:',
        'host:',
        'to:',
        'subject:',
        'tag:',
        'count:',
        'list-webhooks',
        'list-blocked',
        'help',
    ]);

    if ($opts === false) {
        $opts = [];
    }

    $tags = [];
    if (isset($opts['tag'])) {
        $tags = is_array($opts['tag']) ? $opts['tag'] : [$opts['tag']];
    }
    if ($tags === []) {
        $tags = ['verification-spike'];
    }

    $countRaw = optValue($opts, 'count', '1');
    $count = filter_var($countRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => MAX_COUNT]]);
    if ($count === false) {
        fwrite(STDERR, "--count must be an integer between 1 and " . MAX_COUNT . ", got '{$countRaw}'.\n");
        exit(2);
    }

    return [
        'envDir' => (string) optValue($opts, 'env', dirname(__DIR__, 2)),
        'host' => optValue($opts, 'host', null),
        'to' => optValue($opts, 'to', null),
        'subject' => (string) optValue($opts, 'subject', 'Spike 1871 — ' . date('c')),
        'tags' => array_map('strval', $tags),
        'count' => $count,
        'listWebhooks' => isset($opts['list-webhooks']),
        'listBlocked' => isset($opts['list-blocked']),
        'help' => isset($opts['help']),
    ];
}

function usage(): void
{
    fwrite(STDOUT, <<<TXT
    Brevo send / webhook helper (spike #1871)

    Usage:
      php scripts/spike-1871/brevo-send-test.php --to=<address> [options]
      php scripts/spike-1871/brevo-send-test.php --list-webhooks [--env=<dir>]
      php scripts/spike-1871/brevo-send-test.php --list-blocked [--env=<dir>]

    Options:
      --to=<address>     Recipient of the test email (required for send mode)
      --subject=<text>   Subject line (default: "Spike 1871 — <timestamp>")
      --tag=<tag>        Brevo tag; repeatable (default: verification-spike)
      --count=<n>        Number of emails to send, 1-10 (default: 1)
      --list-webhooks    List the account's transactional webhooks and exit
      --list-blocked     List Brevo's transactional blocked-contacts (suppression list) and exit
      --env=<dir>        Directory containing the .env file (default: repo root)
      --host=<host>      Override DB_HOST (run this script in the app container instead: see file docblock)
      --help             Show this message

    TXT);
}

/**
 * Load Brevo credentials from the plg_sendinblue table.
 *
 * @param ?string $host Overrides DB_HOST when set (see --host in the header).
 *
 * @return array{key: string, from: string, from_name: string, reply: string}
 */
function loadConfig(string $envDir, ?string $host = null): array
{
    if (!is_file($envDir . '/.env')) {
        fwrite(STDERR, "No .env file found in {$envDir}\n");
        exit(1);
    }

    \Dotenv\Dotenv::createImmutable($envDir)->load();

    $required = ['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASS', 'DB_NAME'];
    foreach ($required as $var) {
        if (!isset($_ENV[$var]) || $_ENV[$var] === '') {
            fwrite(STDERR, "Missing required environment variable: {$var}\n");
            exit(1);
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $host ?? (string) $_ENV['DB_HOST'],
        (string) $_ENV['DB_PORT'],
        (string) $_ENV['DB_NAME']
    );

    try {
        $pdo = new \PDO($dsn, (string) $_ENV['DB_USER'], (string) $_ENV['DB_PASS'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $stmt = $pdo->query('SELECT `key`, `from`, `from_name`, `reply` FROM plg_sendinblue LIMIT 1');
        $row = $stmt === false ? false : $stmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        fwrite(STDERR, 'Database error: ' . $e->getMessage() . "\n");
        exit(1);
    }

    if (!is_array($row)) {
        fwrite(STDERR, "No row found in plg_sendinblue — is the Brevo plugin configured?\n");
        exit(1);
    }

    $key = (string) ($row['key'] ?? '');
    if ($key === '') {
        fwrite(STDERR, "plg_sendinblue.key is empty — configure the Brevo plugin first.\n");
        exit(1);
    }

    fwrite(STDERR, 'key length: ' . strlen($key) . "\n");

    return [
        'key' => $key,
        'from' => (string) ($row['from'] ?? ''),
        'from_name' => (string) ($row['from_name'] ?? ''),
        'reply' => (string) ($row['reply'] ?? ''),
    ];
}

/**
 * Perform a Brevo REST API call.
 *
 * The api-key header is set here and never echoed anywhere.
 *
 * @param string                $path Path below the API base, e.g. "/smtp/email".
 * @param ?array<string, mixed> $json Request body; null sends no body.
 *
 * @return array{status: int, body: string}
 */
function brevoRequest(string $method, string $path, string $apiKey, ?array $json = null): array
{
    $headers = [
        'api-key: ' . $apiKey,
        'accept: application/json',
        'content-type: application/json',
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        // Defaults, stated explicitly: the request carries the live API key.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FAILONERROR => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($json !== null) {
        $payload = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            fwrite(STDERR, 'Request body is not JSON-encodable: ' . json_last_error_msg() . "\n");
            exit(1);
        }
        $options[CURLOPT_POSTFIELDS] = $payload;
    }

    $handle = curl_init(BREVO_API_BASE . $path);
    if ($handle === false) {
        fwrite(STDERR, "curl error: could not initialise a curl handle\n");
        exit(1);
    }

    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);

    if ($body === false) {
        fwrite(STDERR, 'curl error: ' . curl_error($handle) . "\n");
        exit(1);
    }

    // No curl_close(): a no-op since PHP 8.0 (and deprecated from 8.5); the
    // handle is freed when it goes out of scope.
    return [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'body' => is_string($body) ? $body : '',
    ];
}

/**
 * Abort with the response body when a Brevo call did not answer 2xx.
 *
 * @param array{status: int, body: string} $response
 */
function abortUnlessOk(array $response): void
{
    if ($response['status'] < 200 || $response['status'] >= 300) {
        fwrite(STDERR, 'HTTP ' . $response['status'] . ': ' . $response['body'] . "\n");
        exit(1);
    }
}

/**
 * Send the tagged test emails.
 *
 * @param array{key: string, from: string, from_name: string, reply: string} $config
 * @param array<int, string>                                                 $tags
 */
function sendTests(array $config, string $to, string $subject, array $tags, int $count): void
{
    $tagList = implode(',', $tags);

    for ($i = 1; $i <= $count; $i++) {
        $html = sprintf(
            '<p>Elan Registry webhook verification test (spike #1871).</p>'
            . '<p>Iteration %d of %d. Tags: %s.</p>'
            . '<p>This message exists only to make Brevo fire transactional webhook events. '
            . 'No action is required.</p>',
            $i,
            $count,
            htmlspecialchars($tagList, ENT_QUOTES, 'UTF-8')
        );
        $text = sprintf(
            "Elan Registry webhook verification test (spike #1871).\n"
            . "Iteration %d of %d. Tags: %s.\n"
            . "This message exists only to make Brevo fire transactional webhook events. "
            . "No action is required.\n",
            $i,
            $count,
            $tagList
        );

        $response = brevoRequest('POST', '/smtp/email', $config['key'], [
            'sender' => ['name' => $config['from_name'], 'email' => $config['from']],
            'to' => [['email' => $to]],
            'replyTo' => ['email' => $config['reply'] !== '' ? $config['reply'] : $config['from']],
            'subject' => $subject,
            'htmlContent' => $html,
            'textContent' => $text,
            'tags' => $tags,
            'headers' => ['X-Spike' => '1871'],
        ]);

        abortUnlessOk($response);

        $decoded = json_decode($response['body'], true);
        $messageId = is_array($decoded) && isset($decoded['messageId']) ? (string) $decoded['messageId'] : '';

        fwrite(STDOUT, sprintf(
            "sent #%d to=%s messageId=%s tags=%s\n",
            $i,
            $to,
            $messageId,
            $tagList
        ));
    }
}

/**
 * Redact any auth token in a webhook payload before display.
 *
 * @param array<string, mixed> $webhook
 *
 * @return array<string, mixed>
 */
function redactWebhook(array $webhook): array
{
    if (isset($webhook['auth']) && is_array($webhook['auth']) && isset($webhook['auth']['token'])) {
        $token = (string) $webhook['auth']['token'];
        $webhook['auth']['token'] = substr($token, 0, 4) . '…redacted';
    }

    if (isset($webhook['url'])) {
        $webhook['url'] = redactUrlSecret((string) $webhook['url']);
    }

    return $webhook;
}

/**
 * Mask the ?k= capture secret in a webhook URL so it is not echoed to the terminal.
 */
function redactUrlSecret(string $url): string
{
    return (string) preg_replace_callback(
        '/([?&]k=)([^&#]+)/',
        static fn (array $m): string => $m[1] . substr($m[2], 0, 4) . '…(len=' . strlen($m[2]) . ')',
        $url
    );
}

/**
 * List the account's transactional webhooks.
 *
 * `batched` and `auth` are the two fields this spike exists to observe.
 *
 * @param array{key: string, from: string, from_name: string, reply: string} $config
 */
function listWebhooks(array $config): void
{
    $response = brevoRequest('GET', '/webhooks?type=transactional', $config['key']);

    // Brevo answers an empty webhook list with 400 {"code":"document_not_found"}
    // rather than 200 {"webhooks":[]} (observed 2026-09-03).
    if ($response['status'] === 400 && str_contains($response['body'], '"document_not_found"')) {
        fwrite(STDOUT, "no transactional webhooks\n");
        return;
    }

    abortUnlessOk($response);

    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded) || !isset($decoded['webhooks']) || !is_array($decoded['webhooks'])) {
        fwrite(STDERR, 'Unexpected response shape (no `webhooks` list): ' . $response['body'] . "\n");
        exit(1);
    }
    $webhooks = $decoded['webhooks'];

    if ($webhooks === []) {
        fwrite(STDOUT, "no transactional webhooks\n");
        return;
    }

    foreach ($webhooks as $webhook) {
        if (!is_array($webhook)) {
            continue;
        }

        $batched = array_key_exists('batched', $webhook)
            ? ($webhook['batched'] ? 'true' : 'false')
            : 'n/a';
        $auth = isset($webhook['auth']) && is_array($webhook['auth']) && isset($webhook['auth']['type'])
            ? (string) $webhook['auth']['type']
            : 'none';
        $events = isset($webhook['events']) && is_array($webhook['events'])
            ? implode(',', array_map('strval', $webhook['events']))
            : '';

        fwrite(STDOUT, sprintf(
            "id=%s url=%s batched=%s auth=%s events=%s\n",
            (string) ($webhook['id'] ?? ''),
            redactUrlSecret((string) ($webhook['url'] ?? '')),
            $batched,
            $auth,
            $events
        ));
        fwrite(STDOUT, 'raw: ' . json_encode(
            redactWebhook($webhook),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n");
    }
}

/**
 * List the account's transactional suppression list (GET /smtp/blockedContacts).
 *
 * Answers "is a hard-bounced address permanently blocked, and can it be
 * released?" — Brevo documents DELETE /smtp/blockedContacts/{email} as the
 * unblock call; this script only reads.
 *
 * @param array{key: string, from: string, from_name: string, reply: string} $config
 */
function listBlocked(array $config): void
{
    $response = brevoRequest('GET', '/smtp/blockedContacts?limit=50&sort=desc', $config['key']);

    abortUnlessOk($response);

    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded) || !isset($decoded['contacts']) || !is_array($decoded['contacts'])) {
        fwrite(STDERR, 'Unexpected response shape (no `contacts` list): ' . $response['body'] . "\n");
        exit(1);
    }
    $contacts = $decoded['contacts'];

    fwrite(STDOUT, 'count=' . (string) ($decoded['count'] ?? count($contacts)) . "\n");

    foreach ($contacts as $contact) {
        if (!is_array($contact)) {
            continue;
        }

        fwrite(STDOUT, sprintf(
            "email=%s blockedAt=%s senderEmail=%s reason=%s\n",
            (string) ($contact['email'] ?? ''),
            (string) ($contact['blockedAt'] ?? ''),
            (string) ($contact['senderEmail'] ?? ''),
            json_encode($contact['reason'] ?? null, JSON_UNESCAPED_SLASHES)
        ));
    }
}

$args = parseArgs();

if ($args['help'] || ($args['to'] === null && !$args['listWebhooks'] && !$args['listBlocked'])) {
    usage();
    exit(2);
}

$autoloader = REPO_ROOT . '/vendor/autoload.php';
if (!is_file($autoloader)) {
    fwrite(STDERR, "Missing autoloader: {$autoloader}\nRun composer install first.\n");
    exit(1);
}
require_once $autoloader;

$config = loadConfig($args['envDir'], $args['host']);

if ($args['listWebhooks']) {
    listWebhooks($config);
    exit(0);
}

if ($args['listBlocked']) {
    listBlocked($config);
    exit(0);
}

sendTests($config, (string) $args['to'], $args['subject'], $args['tags'], $args['count']);
