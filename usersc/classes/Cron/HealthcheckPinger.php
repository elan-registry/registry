<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

use ElanRegistry\Exceptions\ExceptionLogSummary;
use ElanRegistry\LogCategories;

/**
 * HealthcheckPinger - Tells healthchecks.io that a cron job finished or failed
 *
 * Builds `https://hc-ping.com/<key>/<slug><suffix>` (plus `/fail` for a
 * failure) from two `.env` values:
 *
 *   - `HEALTHCHECKS_PING_KEY` — the project ping key
 *   - `HEALTHCHECKS_SLUG_SUFFIX` — the environment suffix, `-prod` or `-test`
 *
 * Both values are required. A server with a key but no suffix would ping a
 * check that does not exist, and the failure would show only as a log line.
 * So if either value is empty, no request is sent.
 *
 * A transport fault never throws out of this class: a monitoring fault must
 * not stop the job that it monitors. The logger() calls write to the
 * database and can throw, so the caller guards each call
 * (AbstractCronJob::pingHealthcheck()). Each problem (missing config,
 * transport error, non-2xx status) gives one log line under
 * LOG_CATEGORY_HEALTHCHECK_PING. The ping key lets anyone
 * suppress the alerts, so no log line holds the key or the URL.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.9
 * @see https://github.com/elan-registry/registry/issues/1875
 */
class HealthcheckPinger
{
    private const PING_BASE_URL = 'https://hc-ping.com/';

    private readonly HealthcheckTransport $transport;

    /**
     * @param HealthcheckTransport|null $transport Sends the request; defaults
     *        to {@see CurlHealthcheckTransport}
     */
    public function __construct(?HealthcheckTransport $transport = null)
    {
        $this->transport = $transport ?? new CurlHealthcheckTransport();
    }

    /**
     * Ping the check for a run that completed. Throws only if logger() throws.
     *
     * @param string $slug The check slug without the environment suffix
     */
    public function success(string $slug): void
    {
        $this->ping($slug, false);
    }

    /**
     * Ping the `/fail` endpoint of the check for a run that failed. Throws only if logger() throws.
     *
     * @param string $slug The check slug without the environment suffix
     */
    public function fail(string $slug): void
    {
        $this->ping($slug, true);
    }

    /**
     * @param string $slug The check slug without the environment suffix
     * @param bool $failed True for the `/fail` endpoint, false for success
     */
    private function ping(string $slug, bool $failed): void
    {
        $kind = $failed ? 'fail' : 'success';
        $key = self::env('HEALTHCHECKS_PING_KEY');
        $suffix = self::env('HEALTHCHECKS_SLUG_SUFFIX');

        if ($key === '' || $suffix === '') {
            // The names are not secret. Name only the empty ones, so the
            // operator knows which value to fix on the server.
            $missing = array_keys(array_filter(
                ['HEALTHCHECKS_PING_KEY' => $key, 'HEALTHCHECKS_SLUG_SUFFIX' => $suffix],
                static fn (string $value): bool => $value === ''
            ));
            logger(0, LogCategories::LOG_CATEGORY_HEALTHCHECK_PING, sprintf(
                "Healthcheck %s ping for '%s' not sent: %s not set.",
                $kind,
                $slug,
                implode(' and ', $missing)
            ));
            return;
        }

        try {
            $status = $this->transport->send(self::PING_BASE_URL . $key . '/' . $slug . $suffix . ($failed ? '/fail' : ''));
        } catch (\Throwable $e) {
            // The summary leaves out the message, which can hold the URL.
            logger(0, LogCategories::LOG_CATEGORY_HEALTHCHECK_PING, sprintf(
                "Healthcheck %s ping for '%s' failed: %s",
                $kind,
                $slug,
                ExceptionLogSummary::of($e)
            ));
            return;
        }

        if ($status < 200 || $status > 299) {
            logger(0, LogCategories::LOG_CATEGORY_HEALTHCHECK_PING, sprintf(
                "Healthcheck %s ping for '%s' got HTTP %d.",
                $kind,
                $slug,
                $status
            ));
        }
    }

    /**
     * Read an environment value from `$_ENV` or `getenv()`, as
     * {@see \ElanRegistry\Email\BrevoDevOverride::hostOverride()} does.
     *
     * @param string $name The variable name
     * @return string The value, or '' when it is not set
     */
    private static function env(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name) ?: '';

        return is_string($value) ? $value : '';
    }
}
