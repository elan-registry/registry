<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

/**
 * CurlHealthcheckTransport - Sends a healthchecks.io ping with cURL
 *
 * The timeouts are short because cron.php runs the jobs one after another in
 * one process. They limit how long a slow or unreachable healthchecks.io can
 * delay the jobs after this one.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.9
 * @see https://github.com/elan-registry/registry/issues/1875
 */
class CurlHealthcheckTransport implements HealthcheckTransport
{
    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const TOTAL_TIMEOUT_SECONDS = 5;

    /**
     * {@inheritDoc}
     *
     * @throws \RuntimeException When cURL cannot start or the request fails.
     *         The message holds the cURL error number only, not the URL,
     *         because the URL holds the ping key. The code is the cURL error
     *         number, so a log-safe summary still tells one failure from
     *         another.
     */
    public function send(string $url): int
    {
        // Plain \RuntimeException, not an ElanRegistryException subtype:
        // HealthcheckPinger catches \Throwable, so the type does not matter.
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Healthcheck ping: cURL could not start.');
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            // Without this, curl_exec() echoes the response body into the cron output.
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT_SECONDS,
        ]);

        if (curl_exec($ch) === false) {
            $errno = curl_errno($ch);
            throw new \RuntimeException("Healthcheck ping: cURL error {$errno}.", $errno);
        }

        return (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    }
}
