<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * SqlRecordingFakeDatabase - FakeDatabase double that records the SQL it was given
 *
 * Returns a caller-supplied row set from `results()` and captures the SQL string and
 * bind parameters of the most recent `query()` call, so a test can assert on what the
 * code under test actually sent to the database.
 *
 * Shared by the findUnverifiedOwnerlessAccounts() and findVerifiedOwnerlessAccounts()
 * unit tests, which need exactly this double.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 *
 * @package Tests\Support
 * @since v2.29.1
 * @see https://github.com/elan-registry/registry/issues/1585
 */
class SqlRecordingFakeDatabase extends FakeDatabase
{
    /** @var array<mixed> Bind parameters from the most recent query() call */
    private array $lastParams = [];

    /** SQL string from the most recent query() call */
    private string $lastSql = '';

    /**
     * @param array<int, object|array<string, mixed>> $rows Rows handed back by results()
     */
    public function __construct(private readonly array $rows)
    {
    }

    /**
     * @param string $sql SQL with `?` placeholders
     * @param array<mixed> $params Values bound to the placeholders, in order
     * @return self This instance, for chaining
     */
    public function query(string $sql, array $params = []): self
    {
        $this->lastSql = $sql;
        $this->lastParams = $params;
        return $this;
    }

    /**
     * @param bool $assoc True for associative arrays, false for objects
     * @return array<int, object|array<string, mixed>> The rows supplied to the constructor
     */
    public function results(bool $assoc = false): array
    {
        return $this->rows;
    }

    /**
     * @return array<mixed> Bind parameters from the most recent query() call
     */
    public function getLastParams(): array
    {
        return $this->lastParams;
    }

    /**
     * @return string SQL string from the most recent query() call
     */
    public function getLastSql(): string
    {
        return $this->lastSql;
    }
}
