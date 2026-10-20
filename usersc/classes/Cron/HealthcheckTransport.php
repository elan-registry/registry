<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

/**
 * HealthcheckTransport - Sends one healthchecks.io ping request
 *
 * An interface so that {@see HealthcheckPinger} can be tested without a
 * network call. The production implementation is
 * {@see CurlHealthcheckTransport}.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.9
 * @see https://github.com/elan-registry/registry/issues/1875
 */
interface HealthcheckTransport
{
    /**
     * Send a GET request with no body to the ping URL.
     *
     * @param string $url The full ping URL. It holds the ping key, so an
     *        implementation must not put it in a log line or an exception
     *        message.
     * @return int The HTTP status code of the response
     * @throws \Throwable When the request cannot be sent or gets no response
     */
    public function send(string $url): int;
}
