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
 * A class rather than a trait on IntegrationTestCase so the 80 classes
 * extending that base are not widened for a helper only a few tests use.
 *
 * @package Tests\Support
 * @see https://github.com/elan-registry/registry/issues/2161
 */
class PassThroughDatabase implements DatabaseInterface
{
    public function __construct(protected DatabaseInterface $real)
    {
    }

    public function query(string $sql, array $params = []): static
    {
        $this->real->query($sql, $params);
        return $this;
    }

    public function get(string $table, array $where): static|false
    {
        return $this->real->get($table, $where) === false ? false : $this;
    }

    public function insert(string $table, array $fields = [], bool $update = false): bool
    {
        return $this->real->insert($table, $fields, $update);
    }

    public function update(string $table, array|int $id, array $fields): bool
    {
        return $this->real->update($table, $id, $fields);
    }

    public function delete(string $table, array|int $where): static|false
    {
        return $this->real->delete($table, $where) === false ? false : $this;
    }

    public function error(): bool
    {
        return $this->real->error();
    }

    public function errorString(): string
    {
        return $this->real->errorString();
    }

    public function errorInfo(): array
    {
        return $this->real->errorInfo();
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
