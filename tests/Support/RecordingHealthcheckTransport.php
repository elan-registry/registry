<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\Cron\HealthcheckTransport;

/**
 * RecordingHealthcheckTransport - HealthcheckTransport that records each ping
 * URL and sends nothing
 *
 * Tests set `$status` to script the HTTP response, or `$throw` to simulate a
 * transport error. The URL is recorded before the throw, so a test can still
 * assert which endpoint was asked for.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 *
 * @package Tests\Support
 * @since v2.30.9
 * @see https://github.com/elan-registry/registry/issues/1875
 */
class RecordingHealthcheckTransport implements HealthcheckTransport
{
    /** @var list<string> Every URL passed to send(), in call order. */
    public array $urls = [];

    /** The HTTP status that send() returns. */
    public int $status = 200;

    /** Thrown from send() when set. */
    public ?\Throwable $throw = null;

    public function send(string $url): int
    {
        $this->urls[] = $url;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->status;
    }
}
