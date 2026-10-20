<?php

declare(strict_types=1);

namespace ElanRegistry\Email;

use Brevo\Client\Api\TransactionalEmailsApi;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\ExceptionLogSummary;

/**
 * BrevoApiFactory - builds the Brevo SDK's `TransactionalEmailsApi` for the
 * project's own Brevo clients
 *
 * Holds the three steps that each client needs before its call: read the API
 * key from the sendinblue plugin's `plg_sendinblue` row, load the vendored
 * SDK, and build the API object with explicit timeouts and the
 * {@see BrevoDevOverride} host.
 *
 * It never logs. A failed step returns a reason code (`no_api_key`,
 * `no_sdk`) and a cause, so each caller logs in its own category: the cron
 * suppression sync logs a skip or a cron failure, and an admin action
 * reports the result to the admin.
 *
 * Detail strings never hold an exception message. They hold a database error
 * string or an {@see ExceptionLogSummary}.
 *
 * @package ElanRegistry\Email
 * @since v2.30.9
 * @see https://github.com/elan-registry/registry/issues/2357
 */
final class BrevoApiFactory
{
    /** The API key could not be read, or no key is set. */
    public const REASON_NO_API_KEY = 'no_api_key';

    /** The vendored Brevo SDK did not load. */
    public const REASON_NO_SDK = 'no_sdk';

    /** `plg_sendinblue` does not exist (SQLSTATE 42S02): the plugin is not installed. */
    public const CAUSE_TABLE_ABSENT = 'table_absent';

    /** The `plg_sendinblue` query failed for another reason. */
    public const CAUSE_DB_ERROR = 'db_error';

    /** The `plg_sendinblue` row is missing or its key is empty. */
    public const CAUSE_KEY_NOT_SET = 'key_not_set';

    /** No SDK autoloader file at the expected path. */
    public const CAUSE_AUTOLOADER_ABSENT = 'autoloader_absent';

    /** The SDK autoloader threw while it loaded. */
    public const CAUSE_AUTOLOADER_FAILED = 'autoloader_failed';

    /** The autoloader loaded, but the API class is still undefined. */
    public const CAUSE_API_CLASS_MISSING = 'api_class_missing';

    /**
     * Seconds to wait for the whole request, and for the connection alone.
     *
     * Explicit values, not Guzzle's unlimited defaults: a stalled Brevo
     * endpoint must not hold a cron run or an admin request open.
     */
    private const TIMEOUT_SECONDS = 30;
    private const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * Vendored Brevo SDK autoloader, relative to the site root.
     *
     * The SDK ships inside the sendinblue plugin and is not wired into the
     * project's own Composer autoloader (see `composer.json`), so it must be
     * required explicitly — the same path `sendinblue()` uses.
     */
    private const SDK_AUTOLOAD_RELATIVE_PATH = 'usersc/plugins/sendinblue/vendor/autoload.php';

    private const SDK_API_CLASS = '\Brevo\Client\Api\TransactionalEmailsApi';

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Read the Brevo API key from the sendinblue plugin's settings row.
     *
     * Checks that the query succeeded before it reads the row, unlike the
     * plugin's own `sendinblue()` function.
     *
     * @return array{key: string|null, reason: string|null, cause: string|null, detail: string}
     *         On success, `key` holds the key and the other values are null
     *         and ''. On failure, `key` is null, `reason` is
     *         {@see self::REASON_NO_API_KEY}, `cause` is one of the
     *         `CAUSE_TABLE_ABSENT`, `CAUSE_DB_ERROR` or `CAUSE_KEY_NOT_SET`
     *         constants, and `detail` holds the database error string for a
     *         failed query ('unknown' when it is empty), or '' otherwise.
     */
    public function readApiKey(): array
    {
        $this->db->query('SELECT * FROM plg_sendinblue');

        if ($this->db->error()) {
            $sqlState = (string) ($this->db->errorInfo()[0] ?? '');

            return [
                'key' => null,
                'reason' => self::REASON_NO_API_KEY,
                'cause' => $sqlState === '42S02' ? self::CAUSE_TABLE_ABSENT : self::CAUSE_DB_ERROR,
                'detail' => $this->db->errorString() ?: 'unknown',
            ];
        }

        $settings = $this->db->first();
        if (!is_object($settings) || empty($settings->key)) {
            return [
                'key' => null,
                'reason' => self::REASON_NO_API_KEY,
                'cause' => self::CAUSE_KEY_NOT_SET,
                'detail' => '',
            ];
        }

        return ['key' => (string) $settings->key, 'reason' => null, 'cause' => null, 'detail' => ''];
    }

    /**
     * Load the vendored Brevo SDK's autoloader.
     *
     * Idempotent (`require_once`) and safe to call when the SDK is already
     * loaded, for instance when `sendinblue()` ran earlier in the same
     * request. A missing plugin is a returned reason, not a fatal, so the
     * callers stay constructible in unit tests without the plugin.
     *
     * @return array{reason: string|null, cause: string|null, autoload: string, detail: string}
     *         On success, `reason` and `cause` are null. On failure, `reason`
     *         is {@see self::REASON_NO_SDK} and `cause` is one of the
     *         `CAUSE_AUTOLOADER_ABSENT`, `CAUSE_AUTOLOADER_FAILED` or
     *         `CAUSE_API_CLASS_MISSING` constants. `autoload` is the path
     *         that was tried ('' when the SDK was already loaded). `detail`
     *         holds the {@see ExceptionLogSummary} for a failed load, or ''.
     */
    public function loadSdk(): array
    {
        if (class_exists(self::SDK_API_CLASS)) {
            return ['reason' => null, 'cause' => null, 'autoload' => '', 'detail' => ''];
        }

        global $abs_us_root, $us_url_root;
        $autoload = ($abs_us_root ?? '') . ($us_url_root ?? '') . self::SDK_AUTOLOAD_RELATIVE_PATH;

        if (!file_exists($autoload)) {
            return [
                'reason' => self::REASON_NO_SDK,
                'cause' => self::CAUSE_AUTOLOADER_ABSENT,
                'autoload' => $autoload,
                'detail' => '',
            ];
        }

        try {
            require_once $autoload;
        } catch (\Throwable $e) {
            // A \ParseError or \Error from a corrupt autoloader is not an
            // \Exception, and would otherwise escape the callers' never-throws
            // contracts. No message: all detail strings follow one rule.
            return [
                'reason' => self::REASON_NO_SDK,
                'cause' => self::CAUSE_AUTOLOADER_FAILED,
                'autoload' => $autoload,
                'detail' => ExceptionLogSummary::of($e),
            ];
        }

        if (!class_exists(self::SDK_API_CLASS)) {
            return [
                'reason' => self::REASON_NO_SDK,
                'cause' => self::CAUSE_API_CLASS_MISSING,
                'autoload' => $autoload,
                'detail' => '',
            ];
        }

        return ['reason' => null, 'cause' => null, 'autoload' => $autoload, 'detail' => ''];
    }

    /**
     * Build the Brevo transactional email API client.
     *
     * Call {@see self::loadSdk()} first; this method assumes the SDK is
     * loaded. In local development, {@see BrevoDevOverride::hostOverride()}
     * points the client at mock-brevo, so a local run makes no live call.
     *
     * @param string $apiKey The Brevo API key
     * @return TransactionalEmailsApi The client
     */
    public function createApi(string $apiKey): TransactionalEmailsApi
    {
        $credentials = \Brevo\Client\Configuration::getDefaultConfiguration()
            ->setApiKey('api-key', $apiKey);
        if ($hostOverride = BrevoDevOverride::hostOverride()) {
            $credentials->setHost($hostOverride);
        }

        return new \Brevo\Client\Api\TransactionalEmailsApi(
            new \GuzzleHttp\Client([
                'timeout' => self::TIMEOUT_SECONDS,
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            ]),
            $credentials
        );
    }
}
