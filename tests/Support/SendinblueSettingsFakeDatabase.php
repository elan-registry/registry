<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * SendinblueSettingsFakeDatabase - FakeDatabase double for the
 * `SELECT * FROM plg_sendinblue` read in
 * {@see \ElanRegistry\Cron\BrevoEventReconciliationClient::apiKey()}
 *
 * Covers that read's three outcomes: a usable key, a row with no key (or no
 * row at all), and a failed query — the last split by SQLSTATE, since 42S02
 * (table absent, plugin never installed) is an expected steady state logged
 * as a skip while any other error is a genuine fault.
 *
 * Deliberately a *named* class rather than `new class extends FakeDatabase { ... }`,
 * per the `impureMethod.pure` rationale in CronJobGuardFakeDatabase's docblock.
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1889
 */
class SendinblueSettingsFakeDatabase extends FakeDatabase
{
    /**
     * @param string|null $key      Value of the row's `key` column; null means
     *                              no usable row came back at all.
     * @param string|null $sqlState When set, query() is reported as failed
     *                              with this SQLSTATE ('42S02' for a missing
     *                              table).
     */
    public function __construct(
        private readonly ?string $key = 'test-api-key',
        private readonly ?string $sqlState = null,
    ) {
    }

    public function query(string $sql, array $params = []): self
    {
        return $this;
    }

    public function error(): bool
    {
        return $this->sqlState !== null;
    }

    public function errorString(): string
    {
        return $this->sqlState === null ? '' : 'simulated DB error';
    }

    public function errorInfo(): array
    {
        return $this->sqlState === null ? [0, null, null] : [$this->sqlState, 1146, 'simulated DB error'];
    }

    public function first(bool $assoc = false): array|object
    {
        if ($this->key === null) {
            return [];
        }

        return (object) ['key' => $this->key];
    }
}
