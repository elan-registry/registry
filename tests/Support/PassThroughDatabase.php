<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\DatabaseInterface;

/**
 * PassThroughDatabase - `DatabaseInterface` decorator for integration tests
 *
 * A DatabaseInterface decorator that forwards every call to a real
 * connection unchanged. It is meant to be *extended* per test, usually as an
 * anonymous class, overriding only the method(s) that inject the failure or
 * stale read the test needs:
 *
 * ```php
 * $db = new class ($this->db) extends PassThroughDatabase {
 *     public function insert(string $table, array $fields = [], bool $update = false): bool
 *     {
 *         return false;
 *     }
 * };
 * ```
 *
 * This is the integration-test counterpart of FakeDatabase (same directory):
 * FakeDatabase answers every call itself with no rows; this class lets the
 * real UPDATEs, transactions and reads hit MySQL, so a test can sabotage one
 * write while the rest of the code path behaves exactly as in production.
 * The chainable methods (query(), get(), delete()) return $this rather than
 * the real connection, so the caller keeps talking to the proxy (and any
 * overridden count()/results()); terminal reads return the real values.
 *
 * To make a call fail without reaching MySQL, return simulateFailure() from
 * the override instead of calling parent. error(), errorString() and
 * errorInfo() then all report that failure until the next query(), get(),
 * insert(), update() or delete() call, as they would after a genuine error.
 * count(), first() and results() are not reset: they still return whatever
 * the last real query left, so do not read them after a simulated failure.
 * For example:
 *
 * ```php
 * public function query(string $sql, array $params = []): static
 * {
 *     return str_starts_with($sql, 'UPDATE cars SET')
 *         ? $this->simulateFailure('simulated deadlock')
 *         : parent::query($sql, $params);
 * }
 * ```
 *
 * A class rather than a trait on IntegrationTestCase so every class
 * extending that base is not widened for a helper only a few tests use.
 *
 * @package Tests\Support
 * @see https://github.com/elan-registry/registry/issues/2161
 */
class PassThroughDatabase implements DatabaseInterface
{
    /** Message of the failure simulated on the most recent call, or null if that call reached $real. */
    private ?string $simulatedFailure = null;

    public function __construct(protected readonly DatabaseInterface $real)
    {
    }

    /**
     * Record a simulated failure for the current call, in place of forwarding it.
     */
    protected function simulateFailure(string $message): static
    {
        $this->simulatedFailure = $message;
        return $this;
    }

    public function query(string $sql, array $params = []): static
    {
        $this->simulatedFailure = null;
        $this->real->query($sql, $params);
        return $this;
    }

    public function get(string $table, array $where): static|false
    {
        $this->simulatedFailure = null;
        return $this->real->get($table, $where) === false ? false : $this;
    }

    public function insert(string $table, array $fields = [], bool $update = false): bool
    {
        $this->simulatedFailure = null;
        return $this->real->insert($table, $fields, $update);
    }

    public function update(string $table, array|int $id, array $fields): bool
    {
        $this->simulatedFailure = null;
        return $this->real->update($table, $id, $fields);
    }

    public function delete(string $table, array|int $where): static|false
    {
        $this->simulatedFailure = null;
        return $this->real->delete($table, $where) === false ? false : $this;
    }

    public function error(): bool
    {
        return $this->simulatedFailure !== null || $this->real->error();
    }

    public function errorString(): string
    {
        return $this->simulatedFailure ?? $this->real->errorString();
    }

    public function errorInfo(): array
    {
        return $this->simulatedFailure !== null
            ? ['HY000', null, $this->simulatedFailure]
            : $this->real->errorInfo();
    }

    public function count(): int
    {
        return $this->real->count();
    }

    public function first(bool $assoc = false): array|object
    {
        return $this->real->first($assoc);
    }

    public function results(bool $assoc = false): array
    {
        return $this->real->results($assoc);
    }

    public function lastId(): int
    {
        return $this->real->lastId();
    }

    public function beginTransaction(): bool
    {
        return $this->real->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->real->commit();
    }

    public function rollBack(): bool
    {
        return $this->real->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->real->inTransaction();
    }
}
