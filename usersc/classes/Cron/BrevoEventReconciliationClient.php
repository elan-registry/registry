<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;

/**
 * Thin read-only wrapper around the vendored Brevo SDK's
 * `TransactionalEmailsApi::getEmailEventReport()` (`GET /smtp/statistics/events`).
 *
 * Used by the nightly reconciliation job (#1889) to poll Brevo for delivery
 * events, so any event the webhook missed can be backfilled.
 *
 * This exists as a new class rather than reusing the sendinblue plugin's
 * procedural `sendinblue()` function for two reasons. First, `sendinblue()`
 * is the *send* path — it posts a message and returns a send result; this is
 * a *read* path against a different endpoint, with different parameters and
 * a different return shape, so there is nothing to reuse but the credential
 * lookup. Second, and more importantly, `sendinblue()` constructs its
 * transport as a bare `new GuzzleHttp\Client()` with no timeout options set
 * at all. Guzzle's own defaults leave `timeout` and `connect_timeout` at 0
 * (no limit), so a hung Brevo connection there blocks until PHP's own
 * `max_execution_time` kills the request. That is survivable for an
 * interactive send; it is not survivable for a cron job, where a stalled
 * socket would hold the run open indefinitely. This client therefore sets
 * both timeouts explicitly, matching the in-repo precedent set by
 * `LocationService::makeHttpRequest()`'s `CURLOPT_TIMEOUT` — every outbound
 * HTTP call gets an explicit deadline.
 *
 * All failure modes (Brevo unconfigured, missing SDK, DB error, HTTP error)
 * return `null` rather than throwing: a reconciliation poll that cannot reach
 * Brevo should log and skip this cycle, not abort the whole cron run. `null`
 * means the poll itself failed (SDK unconfigured/missing, HTTP/API
 * exception); `[]` means the poll succeeded and Brevo genuinely returned zero
 * events for the window — same *intent* as
 * {@see BrevoSuppressionSyncClient::fetchBlockedContacts()}'s null-on-failure
 * signal (so a caller can tell "nothing happened" apart from "nothing to
 * report"), though that method's own success shape differs: it returns a
 * single page object (never `[]`), not an array.
 *
 * Not `final`, matching {@see \ElanRegistry\Car\CarRepository} and
 * {@see \ElanRegistry\Car\CarVerificationManager}: this class is an injected
 * collaborator of {@see BrevoEventReconciliationJob}, and the job's unit tests
 * must substitute it (its only real behavior is an outbound HTTP call against
 * an SDK that isn't even on the unit suite's autoloader). Subclassing outside
 * of test doubles is not intended.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1889
 */
class BrevoEventReconciliationClient
{
    /**
     * Seconds to wait for the whole request, and for the connection alone.
     *
     * Explicit values, not Guzzle's unlimited defaults — see the class
     * docblock. Generous enough for a large event page over a slow link,
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
     * Fetch one page of transactional delivery events for the given window.
     *
     * The window is inclusive of both dates at day granularity: Brevo's
     * statistics endpoint takes `YYYY-MM-DD` strings, not timestamps, so any
     * time-of-day component of the arguments is discarded. Narrowing to a
     * sub-day window is not possible here and must be done by filtering the
     * returned events on their own `date` field.
     *
     * @param \DateTimeImmutable $startDate Start of the window (date part only)
     * @param \DateTimeImmutable $endDate   End of the window (date part only)
     * @param int                $limit     Page size (Brevo caps this at 2500)
     * @param int                $offset    Zero-based offset into the result set
     * @return \Brevo\Client\Model\GetEmailEventReportEvents[]|null Null if
     *         Brevo is not configured/ready or the API call fails — never
     *         throws (a reconciliation job's polling failure should be
     *         logged and skipped this cycle, not crash the whole run). An
     *         empty array means the poll succeeded and Brevo returned no
     *         events for the window.
     */
    public function fetchEvents(
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        int $limit,
        int $offset
    ): ?array {
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
            //   getEmailEventReport($limit, $offset, $startDate, $endDate, $days,
            //       $email, $event, $tags, $messageId, $templateId, $sort)
            // Everything past $endDate is an optional server-side filter this
            // job does not use. They are passed explicitly as null rather than
            // omitted because $sort's default ('desc') sits at the end of the
            // list and cannot be reached otherwise; 'desc' is kept, so paging
            // walks newest-first consistently across calls.
            $report = $apiInstance->getEmailEventReport(
                $limit,
                $offset,
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d'),
                null,
                null,
                null,
                null,
                null,
                null,
                'desc'
            );

            return $report->getEvents() ?? [];
        } catch (\Throwable $e) {
            // Guzzle/the SDK raise on connection failure, timeout, and any
            // non-2xx response (\Brevo\Client\ApiException). Caught as
            // \Throwable rather than a narrower type because the generated SDK
            // also raises \InvalidArgumentException on malformed arguments and
            // \RuntimeException during response deserialization — none of which
            // should be allowed to abort a cron run.
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                'Brevo event reconciliation poll FAILED (%s to %s, limit %d, offset %d): %s: %s. '
                . 'No events backfilled this cycle.',
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d'),
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
            // operator investigating a gap in reconciled events is not sent to
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
                        ? 'Brevo event reconciliation: plg_sendinblue table absent (sendinblue plugin not installed) — skipping poll. %s'
                        : 'Brevo event reconciliation FAILED querying plg_sendinblue — skipping poll, but Brevo may in fact be configured. Check DB connectivity/grants on plg_sendinblue. %s',
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
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED, 'Brevo event reconciliation: no Brevo API key configured in plg_sendinblue — skipping poll.');
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
                'Brevo event reconciliation: SDK autoloader not found at %s (sendinblue plugin not installed) — skipping poll.',
                $autoload
            ));
            return false;
        }

        try {
            require_once $autoload;
        } catch (\Throwable $e) {
            // A \ParseError or \Error from a corrupt/truncated autoloader is
            // not an \Exception, and would otherwise escape both this method
            // and fetchEvents() — breaking fetchEvents()'s documented
            // never-throws contract and surfacing in AbstractCronJob::run()'s
            // catch-all as a generic job failure, with nothing pointing at the
            // SDK. Caught and identified here instead.
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                'Brevo event reconciliation: SDK autoloader at %s failed to load (%s: %s) —'
                . ' the vendored Brevo SDK looks corrupt. Skipping poll.',
                $autoload,
                get_class($e),
                $e->getMessage()
            ));
            return false;
        }

        if (!class_exists('\Brevo\Client\Api\TransactionalEmailsApi')) {
            // Never silent: without this, a vendor tree that loads but does
            // not define the API class makes every nightly run a no-op that
            // still reports success.
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                'Brevo event reconciliation: SDK autoloader at %s loaded, but'
                . ' \\Brevo\\Client\\Api\\TransactionalEmailsApi is still undefined —'
                . ' the vendored SDK tree looks incomplete. Skipping poll.',
                $autoload
            ));
            return false;
        }

        return true;
    }
}
