<?php

declare(strict_types=1);

// Namespaced so helper names (parseArgs, usage, …) cannot collide with other
// scripts/ files under PHPStan's whole-project analysis.
namespace ElanRegistry\Spike1888;

/**
 * Brevo transactional webhook registration helper (spike/issue #1888).
 *
 * #1888's acceptance criterion is that webhook registration is done via the
 * API, not manual UI clicks, and that the request body is checked into the
 * repo — this script is that checked-in, scriptable, reproducible mechanism.
 * It creates, lists, and deletes the account's transactional webhooks over
 * Brevo's REST API so a test registration can be set up and torn down
 * without a trip through the Brevo dashboard.
 *
 * This is test-environment-only by design: --create refuses any --url that
 * does not start with "https://test." (no bypass flag). Production webhook
 * registration is explicitly out of scope for #1888.
 *
 * Examples:
 *   php scripts/spike-1888/brevo-register-webhook.php \
 *       --create --url='https://test.elanregistry.org/spike-1888/capture.php' \
 *       --token=<same value as that server's BREVO_WEBHOOK_TOKEN>
 *   php scripts/spike-1888/brevo-register-webhook.php --list-webhooks
 *   php scripts/spike-1888/brevo-register-webhook.php --delete --id=123
 *   php scripts/spike-1888/brevo-register-webhook.php --env=/home/<cpanel-account>/test.elanregistry.org
 *
 * --host overrides DB_HOST so this script can reach test's plg_sendinblue
 * row from elsewhere (e.g. an SSH tunnel to test's DB) without editing
 * --env's .env file. It is NOT a way to run this against MAMP's own local
 * config: --create still calls Brevo's live production API with whichever
 * account plg_sendinblue.key points at, so pairing --host with a local MAMP
 * database would register a real webhook against test's Brevo account using
 * local dev's (usually absent or unrelated) Brevo credentials — not a safe
 * local rehearsal. On MAMP specifically, PDO also treats a DB_HOST of
 * "localhost" as a Unix socket and ignores DB_PORT, so --host=127.0.0.1 is
 * needed just to reach MAMP's instance on 8889 rather than system MySQL.
 *
 * Requirements: PHP with ext-curl, and this file must stay two levels below a
 * directory containing `vendor/autoload.php` (for Dotenv); `.env` is read from
 * that directory too unless --env points elsewhere.
 * It deliberately calls Brevo's REST API over curl rather than using the
 * Brevo SDK: that SDK and its Guzzle dependency live in
 * usersc/plugins/sendinblue/vendor/, which is gitignored and absent on CI, so
 * an SDK-based script could not pass static analysis there.
 *
 * This script shares helper patterns (config loading, the curl wrapper,
 * redaction helpers, CLI conventions) with
 * scripts/spike-1871/brevo-send-test.php rather than importing from it —
 * there is no shared module system between these standalone scripts. See
 * that file's header for the underlying security rationale (the Brevo API
 * key is never printed, logged, or written to disk) rather than re-deriving
 * it here.
 *
 * Security: the Brevo API key is read at runtime from the `plg_sendinblue`
 * table and is never printed, logged, or written to disk by this script. Only
 * its length is reported (on STDERR) so a misconfigured row can be diagnosed.
 * Request headers are never echoed, since they carry the key. The webhook
 * auth --token value is likewise never printed anywhere, including in error
 * output.
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
const BREVO_API_BASE = 'https://api.brevo.com/v3';

/**
 * The transactional events subscribed on --create. Matches what #1887's
 * bounce-detection endpoint needs to observe.
 *
 * @var array<int, string>
 */
const CREATE_EVENTS = ['delivered', 'opened', 'softBounce', 'hardBounce', 'blocked', 'invalid', 'spam'];

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
 *     create: bool,
 *     listWebhooks: bool,
 *     delete: bool,
 *     url: ?string,
 *     token: ?string,
 *     description: string,
 *     id: ?string,
 *     help: bool
 * }
 */
function parseArgs(): array
{
    $opts = getopt('', [
        'env:',
        'host:',
        'create',
        'list-webhooks',
        'delete',
        'url:',
        'token:',
        'description:',
        'id:',
        'help',
    ]);

    if ($opts === false) {
        $opts = [];
    }

    return [
        'envDir' => (string) optValue($opts, 'env', dirname(__DIR__, 2)),
        'host' => optValue($opts, 'host', null),
        'create' => isset($opts['create']),
        'listWebhooks' => isset($opts['list-webhooks']),
        'delete' => isset($opts['delete']),
        'url' => optValue($opts, 'url', null),
        'token' => optValue($opts, 'token', null),
        'description' => (string) optValue($opts, 'description', '1888 — test env'),
        'id' => optValue($opts, 'id', null),
        'help' => isset($opts['help']),
    ];
}

function usage(): void
{
    fwrite(STDOUT, <<<TXT
    Brevo webhook registration helper (spike/issue #1888)

    Usage:
      php scripts/spike-1888/brevo-register-webhook.php --create --url=<url> --token=<token> [options]
      php scripts/spike-1888/brevo-register-webhook.php --list-webhooks [--env=<dir>]
      php scripts/spike-1888/brevo-register-webhook.php --delete --id=<webhook-id> [--env=<dir>]

    Options:
      --create             Register a new transactional webhook and exit
      --url=<url>          Webhook URL (required for --create); must start with https://test.
      --token=<token>      Bearer token for the webhook's Token auth (required for --create; never printed)
      --description=<text> Webhook description (default: "1888 — test env")
      --list-webhooks      List the account's transactional webhooks and exit
      --delete             Delete a webhook (requires --id) and exit
      --id=<id>            Webhook id to delete
      --env=<dir>          Directory containing the .env file (default: repo root)
      --host=<host>        Override DB_HOST (e.g. reaching test's DB over an
                            SSH tunnel). NOT a MAMP rehearsal mode — --create
                            always calls Brevo's live API; see file docblock.
      --help               Show this message

    Examples:
      php scripts/spike-1888/brevo-register-webhook.php --create \\
          --url='https://test.elanregistry.org/spike-1888/capture.php' \\
          --token=<same value as that server's BREVO_WEBHOOK_TOKEN> \\
          --description='1888 — test env'
      php scripts/spike-1888/brevo-register-webhook.php --list-webhooks
      php scripts/spike-1888/brevo-register-webhook.php --delete --id=123

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
 * @param string                $path Path below the API base, e.g. "/webhooks".
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
 * Mask any `?k=`-style query-string secret in a webhook URL before it is
 * echoed to the terminal. #1888's own URLs carry no such param (auth is the
 * BREVO_WEBHOOK_TOKEN header, not a query secret) — kept as a safety net in
 * case a URL from elsewhere (e.g. a legacy spike-1871 registration) is ever
 * passed through this same code path.
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
 * Identical in shape and behaviour to brevo-send-test.php's listWebhooks():
 * duplicated here rather than shared, since these are standalone scripts
 * with no shared module system.
 *
 * @param array{key: string, from: string, from_name: string, reply: string} $config
 */
function listWebhooks(array $config): void
{
    $response = brevoRequest('GET', '/webhooks?type=transactional', $config['key']);

    // Brevo answers an empty webhook list with 400 {"code":"document_not_found"}
    // rather than 200 {"webhooks":[]} (observed 2026-09-03, see spike-1871/README.md).
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
 * Create a new transactional webhook.
 *
 * @param array{key: string, from: string, from_name: string, reply: string} $config
 */
function createWebhook(array $config, string $url, string $token, string $description): void
{
    if (!str_starts_with($url, 'https://test.')) {
        fwrite(STDERR, "--url must start with https://test. — this script only registers test-environment webhooks (no bypass; production registration is out of scope for #1888).\n");
        exit(2);
    }

    $response = brevoRequest('POST', '/webhooks', $config['key'], [
        'url' => $url,
        'type' => 'transactional',
        'events' => CREATE_EVENTS,
        'auth' => ['type' => 'token', 'token' => $token],
        'description' => $description,
    ]);

    abortUnlessOk($response);

    $decoded = json_decode($response['body'], true);
    $id = is_array($decoded) && isset($decoded['id']) ? (string) $decoded['id'] : '';

    fwrite(STDOUT, sprintf(
        "created webhook id=%s url=%s\n",
        $id,
        redactUrlSecret($url)
    ));
}

/**
 * Delete a transactional webhook by id.
 *
 * @param array{key: string, from: string, from_name: string, reply: string} $config
 */
function deleteWebhook(array $config, string $id): void
{
    $response = brevoRequest('DELETE', '/webhooks/' . rawurlencode($id), $config['key']);

    // Brevo's delete typically answers 204 No Content on success.
    abortUnlessOk($response);

    fwrite(STDOUT, "deleted webhook id={$id}\n");
}

$args = parseArgs();

if ($args['help'] || (!$args['create'] && !$args['listWebhooks'] && !$args['delete'])) {
    usage();
    exit(2);
}

if ($args['create'] && ($args['url'] === null || $args['token'] === null)) {
    fwrite(STDERR, "--create requires --url and --token.\n");
    usage();
    exit(2);
}

if ($args['delete'] && $args['id'] === null) {
    fwrite(STDERR, "--delete requires --id.\n");
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

if ($args['create']) {
    createWebhook($config, (string) $args['url'], (string) $args['token'], $args['description']);
    exit(0);
}

deleteWebhook($config, (string) $args['id']);
