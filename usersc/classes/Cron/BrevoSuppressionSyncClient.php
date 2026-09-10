<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;

/**
 * Thin read-only wrapper around the vendored Brevo SDK's
 * `TransactionalEmailsApi::getTransacBlockedContacts()`
 * (`GET /smtp/blockedContacts`).
 *
 * Used by the suppression list import (#1923) to poll Brevo for blocked and
 * unsubscribed transactional contacts, so addresses Brevo has already stopped
 * delivering to are reflected locally instead of being retried forever. It is
 * the exact analogue of {@see BrevoEventReconciliationClient}, which wraps
 * `getEmailEventReport()` for the nightly event backfill; the two are
 * deliberately separate classes rather than one client with a mode flag,
 * because they wrap different endpoints with different parameters, different
 * return shapes, and different failure semantics (see below), and share
 * nothing beyond credential lookup and SDK bootstrapping — a handful of lines
 * whose duplication is cheaper than the coupling of a shared base class
 * between two independently-scheduled jobs.
 *
 * This is also a new class rather than a reuse of the sendinblue plugin's
 * procedural `sendinblue()` function, for the same two reasons its sibling
 * gives. First, `sendinblue()` is the *send* path — it posts a message and
 * returns a send result; this is a *read* path against a different endpoint,
 * so there is nothing to reuse but the credential lookup. Second, and more
 * importantly, `sendinblue()` constructs its transport as a bare
 * `new GuzzleHttp\Client()` with no timeout options set at all. Guzzle's own
 * defaults leave `timeout` and `connect_timeout` at 0 (no limit), so a hung
 * Brevo connection there blocks until PHP's own `max_execution_time` kills the
 * request. That is survivable for an interactive send; it is not survivable
 * for a cron job, where a stalled socket would hold the run open
 * indefinitely — and a full suppression backfill pages through the endpoint
 * many times, multiplying the exposure. This client therefore sets both
 * timeouts explicitly, matching the in-repo precedent set by
 * `LocationService::makeHttpRequest()`'s `CURLOPT_TIMEOUT` — every outbound
 * HTTP call gets an explicit deadline.
 *
 * All failure modes (Brevo unconfigured, missing SDK, DB error, HTTP error)
 * return `null` rather than throwing: a suppression poll that cannot reach
 * Brevo should log and skip, not abort the whole cron run. `null` — rather
 * than the empty result its sibling returns — is what makes the contract
 * usable here: callers page through the suppression list by comparing rows
 * consumed so far against `GetTransacBlockedContacts::getCount()`, so they
 * must be able to tell "the poll failed" from "the poll succeeded and this
 * page is empty". A returned object always means a real answer from Brevo,
 * including a legitimate `getCount()` of 0; `null` always means the answer is
 * unknown and paging must stop rather than conclude the list has ended.
 *
 * Not `final`, matching {@see BrevoEventReconciliationClient},
 * {@see \ElanRegistry\Car\CarRepository} and
 * {@see \ElanRegistry\Car\CarVerificationManager}: this class is an injected
 * collaborator of the suppression sync job, and the job's unit tests must
 * substitute it (its only real behavior is an outbound HTTP call against an
 * SDK that isn't even on the unit suite's autoloader). Subclassing outside of
 * test doubles is not intended.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1923
 */
class BrevoSuppressionSyncClient
{
    /**
     * Seconds to wait for the whole request, and for the connection alone.
     *
     * Explicit values, not Guzzle's unlimited defaults — see the class
     * docblock. Generous enough for a large suppression page over a slow link,
     * short enough that a stalled Brevo endpoint cannot hold a cron run open.
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

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Fetch one page of blocked/unsubscribed transactional contacts.
     *
     * Both dates are optional and are all-or-nothing as far as Brevo is
     * concerned: passing null for both fetches the whole suppression list
     * without a date window, which is what a first-time backfill wants;
     * passing both narrows to contacts suppressed within that window, which is
     * what a nightly incremental run wants. The window is inclusive of both
     * dates at day granularity — Brevo's endpoint takes `YYYY-MM-DD` strings,
     * not timestamps — so any time-of-day component of the arguments is
     * discarded, and a sub-day window is not expressible here.
     *
     * @param \DateTimeImmutable|null $startDate Start of the window (date part
     *                                           only), or null for no window
     * @param \DateTimeImmutable|null $endDate   End of the window (date part
     *                                           only), or null for no window
     * @param int                     $limit     Page size
     * @param int                     $offset    Zero-based offset into the
     *                                           result set
     * @return \Brevo\Client\Model\GetTransacBlockedContacts|null The page, or
     *         null if Brevo is not configured/ready or the API call fails —
     *         never throws. Null means "poll failed"; it is distinct from a
     *         returned page whose `getCount()` is 0, which means Brevo really
     *         has nothing to report. Callers paging on `getCount()` must stop
     *         on null rather than treat it as the end of the list.
     */
    public function fetchBlockedContacts(
        ?\DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        int $limit,
        int $offset
    ): ?\Brevo\Client\Model\GetTransacBlockedContacts {
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return null;
        }

        if (!$this->loadSdk()) {
            return null;
        }

        try {
            $credentials = \Brevo\Client\Configuration::getDefaultConfiguration()
                ->setApiKey('api-key', $apiKey);

            $apiInstance = new \Brevo\Client\Api\TransactionalEmailsApi(
                new \GuzzleHttp\Client([
                    'timeout' => self::TIMEOUT_SECONDS,
                    'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                ]),
                $credentials
            );

            // Positional arguments, per the generated SDK signature:
            //   getTransacBlockedContacts($startDate, $endDate, $limit, $offset,
            //       $senders, $sort)
            // $limit/$offset are passed as ints, not strings: the generated
            // defaults are the strings '50'/'0', but the SDK documents both
            // parameters as int and serializes either to the same query
            // string, so int matches the declared contract.
            // $senders is passed explicitly as null rather than omitted because
            // $sort's default ('desc') sits after it and cannot be reached
            // otherwise; 'desc' is kept, so paging walks newest-first
            // consistently across calls. Not scoping by sender is deliberate:
            // the downstream reason-code mapping already distinguishes an
            // all-sender hardBounce from the sender-scoped codes, so filtering
            // at the API level would only discard rows it needs.
            return $apiInstance->getTransacBlockedContacts(
                $startDate?->format('Y-m-d'),
                $endDate?->format('Y-m-d'),
                $limit,
                $offset,
                null,
                'desc'
            );
        } catch (\Throwable $e) {
            // Guzzle/the SDK raise on connection failure, timeout, and any
            // non-2xx response (\Brevo\Client\ApiException). Caught as
            // \Throwable rather than a narrower type because the generated SDK
            // also raises \InvalidArgumentException on malformed arguments and
            // \RuntimeException during response deserialization — none of which
            // should be allowed to abort a cron run.
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                'Brevo suppression sync poll FAILED (%s to %s, limit %d, offset %d): %s: %s. '
                . 'No suppressions imported this cycle.',
                $startDate?->format('Y-m-d') ?? 'no start date',
                $endDate?->format('Y-m-d') ?? 'no end date',
                $limit,
                $offset,
                get_class($e),
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Read the Brevo API key from the sendinblue plugin's settings row.
     *
     * Mirrors the defensive read in
     * {@see \ElanRegistry\Car\VerificationSettings::brevoReady()} rather than
     * the looser style of the plugin's own `sendinblue()` function, which
     * dereferences the row without checking that the query succeeded.
     *
     * @return string|null The key, or null if Brevo is not configured or the
     *                     row could not be read
     */
    private function apiKey(): ?string
    {
        $this->db->query('SELECT * FROM plg_sendinblue');

        if ($this->db->error()) {
            // SQLSTATE 42S02 = table missing, i.e. the sendinblue plugin was
            // never installed — an expected, non-alarming "not configured".
            // Any other error (connection lost, grants revoked, lock timeout)
            // means the key may well exist and the poll is being skipped for
            // an unrelated infrastructure fault; logged distinctly so an
            // operator investigating a stale suppression list is not sent to
            // check a Brevo configuration that is in fact correct.
            $sqlState = (string) ($this->db->errorInfo()[0] ?? '');

            // Category differs by SQLSTATE, not just wording: a missing table
            // means Brevo was never installed here, an expected steady state
            // on any environment without it (dev, a fresh test box), so it is
            // a SKIPPED, not a FAILURE. Any other error genuinely is a fault.
            // Without this split, an unconfigured environment would log a
            // "cron job failure" on every claimed run forever, drowning the
            // one category an operator filters on to find real problems.
            logger(
                0,
                $sqlState === '42S02'
                    ? LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED
                    : LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
                sprintf(
                    $sqlState === '42S02'
                        ? 'Brevo suppression sync: plg_sendinblue table absent (sendinblue plugin not installed) — skipping poll. %s'
                        : 'Brevo suppression sync FAILED querying plg_sendinblue — skipping poll, but Brevo may in fact be configured. Check DB connectivity/grants on plg_sendinblue. %s',
                    $this->db->errorString() ?: 'unknown'
                )
            );
            return null;
        }

        $settings = $this->db->first();
        if (!is_object($settings) || empty($settings->key)) {
            // SKIPPED, not FAILURE: an environment with no Brevo key is
            // correctly configured for what it is, and would otherwise log a
            // "failure" every claimed run in perpetuity.
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED, 'Brevo suppression sync: no Brevo API key configured in plg_sendinblue — skipping poll.');
            return null;
        }

        return (string) $settings->key;
    }

    /**
     * Load the vendored Brevo SDK's autoloader.
     *
     * Idempotent (`require_once`) and safe to call when the SDK is already
     * loaded — for instance when `sendinblue()` ran earlier in the same
     * request. Guarded by a class_exists() short-circuit and a file_exists()
     * check so that a missing plugin is a logged skip rather than a fatal,
     * which also keeps this class constructible in unit tests without the
     * plugin present.
     *
     * @return bool True if the SDK is available
     */
    private function loadSdk(): bool
    {
        if (class_exists('\Brevo\Client\Api\TransactionalEmailsApi')) {
            return true;
        }

        global $abs_us_root, $us_url_root;
        $autoload = ($abs_us_root ?? '') . ($us_url_root ?? '') . self::SDK_AUTOLOAD_RELATIVE_PATH;

        if (!file_exists($autoload)) {
            // SKIPPED, not FAILURE: no plugin installed is an expected steady
            // state (see apiKey()'s 42S02 branch for the same reasoning).
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED, sprintf(
                'Brevo suppression sync: SDK autoloader not found at %s (sendinblue plugin not installed) — skipping poll.',
                $autoload
            ));
            return false;
        }

        try {
            require_once $autoload;
        } catch (\Throwable $e) {
            // A \ParseError or \Error from a corrupt/truncated autoloader is
            // not an \Exception, and would otherwise escape both this method
            // and fetchBlockedContacts() — breaking that method's documented
            // never-throws contract and surfacing in AbstractCronJob::run()'s
            // catch-all as a generic job failure, with nothing pointing at the
            // SDK. Caught and identified here instead.
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                'Brevo suppression sync: SDK autoloader at %s failed to load (%s: %s) —'
                . ' the vendored Brevo SDK looks corrupt. Skipping poll.',
                $autoload,
                get_class($e),
                $e->getMessage()
            ));
            return false;
        }

        if (!class_exists('\Brevo\Client\Api\TransactionalEmailsApi')) {
            // Never silent: without this, a vendor tree that loads but does
            // not define the API class makes every run a no-op that still
            // reports success.
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                'Brevo suppression sync: SDK autoloader at %s loaded, but'
                . ' \\Brevo\\Client\\Api\\TransactionalEmailsApi is still undefined —'
                . ' the vendored SDK tree looks incomplete. Skipping poll.',
                $autoload
            ));
            return false;
        }

        return true;
    }
}
