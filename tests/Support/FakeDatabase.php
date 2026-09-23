<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\DatabaseInterface;

/**
 * FakeDatabase - Hand-written `DatabaseInterface` double for unit tests
 *
 * A concrete, fully benign implementation of every `DatabaseInterface` method:
 * queries succeed and return no rows, writes succeed, transactions succeed, and
 * `error()` is always false. It is meant to be *extended* per test — usually as an
 * anonymous class — overriding only the handful of methods that test needs:
 *
 * ```php
 * $db = new class extends FakeDatabase {
 *     public int $commitCalls = 0;
 *     public function commit(): bool { $this->commitCalls++; return true; }
 * };
 * ```
 *
 * Prefer PHPUnit's `createMock(DatabaseInterface::class)` / `createStub()` for the
 * common case of canned return values and call expectations. Reach for this class
 * instead when a test needs mutable state carried across calls (a fake that returns
 * different rows on successive `query()` calls), hand-tracked call history
 * (`$commitCalls`, `$lastInsertData`), or extra assertion helpers on the double
 * itself (`getLastSql()`) — all of which are awkward to express with a mock object.
 *
 * Defaults mirror the *real* `\DB` behaviour documented on `DatabaseInterface`:
 * `first()` and `results()` return `[]` when there are no rows (never null), `get()`
 * returns the instance rather than a separate result object, and `query()` returns
 * the instance for chaining without ever throwing.
 *
 * For integration tests that need a real connection, see PassThroughDatabase.
 *
 * @package Tests\Support
 * @since v2.29.1
 * @see https://github.com/elan-registry/registry/issues/1585
 */
class FakeDatabase implements DatabaseInterface
{
    /**
     * Row handed back by `first()`. Defaults to `[]` — the real `\DB` empty-result
     * value. Assign a row here instead of overriding `first()` when a subclass only
     * needs to change what the first row is.
     *
     * @var array<string, mixed>|object
     */
    protected array|object $firstRow = [];

    /**
     * Whether `get()` succeeds. Set false in a subclass to exercise the caller's
     * `false` (query failed / invalid WHERE clause) branch.
     */
    protected bool $getSucceeds = true;

    /**
     * Whether `delete()` succeeds. Set false in a subclass to exercise the caller's
     * `false` (delete failed) branch.
     */
    protected bool $deleteSucceeds = true;

    /**
     * @param string $sql SQL with `?` placeholders
     * @param array<mixed> $params Values bound to the placeholders, in order
     * @return self This instance, for chaining
     */
    public function query(string $sql, array $params = []): self
    {
        return $this;
    }

    /**
     * @param string $table Table name
     * @param array<mixed> $where UserSpice WHERE array, e.g. `['id', '=', 5]`
     * @return self|false This instance on success, false when $getSucceeds is false
     */
    public function get(string $table, array $where): self|false
    {
        return $this->getSucceeds ? $this : false;
    }

    /**
     * @param string $table Table name
     * @param array<string, mixed> $fields Column => value pairs
     * @param bool $update Append `ON DUPLICATE KEY UPDATE` for an upsert
     * @return bool Always true
     */
    public function insert(string $table, array $fields = [], bool $update = false): bool
    {
        return true;
    }

    /**
     * @param string $table Table name
     * @param array<mixed>|int $id Primary key value, or a UserSpice WHERE array
     * @param array<string, mixed> $fields Column => value pairs to set
     * @return bool Always true
     */
    public function update(string $table, array|int $id, array $fields): bool
    {
        return true;
    }

    /**
     * @param string $table Table name
     * @param array<mixed>|int $where UserSpice WHERE array, e.g. `['id', '=', 5]`, or an id
     * @return self|false This instance on success, false when $deleteSucceeds is false
     */
    public function delete(string $table, array|int $where): self|false
    {
        return $this->deleteSucceeds ? $this : false;
    }

    /**
     * @return bool Always false — no query ever fails unless a subclass says so
     */
    public function error(): bool
    {
        return false;
    }

    /**
     * @return string Always empty — no error to describe
     */
    public function errorString(): string
    {
        return '';
    }

    /**
     * @return array<int, mixed> Always the real \DB "no error" triple — the literal
     *                           integer 0, not the PDO '00000' SQLSTATE string. \DB::query()
     *                           resets $_errorInfo to [0, null, null] at the start of every
     *                           call and only overwrites it with the real PDO triple on
     *                           failure, so '00000' is never actually observed (see
     *                           tests/integration/DbAdapterContractTest.php).
     */
    public function errorInfo(): array
    {
        return [0, null, null];
    }

    /**
     * @return int Always 0 — no rows
     */
    public function count(): int
    {
        return 0;
    }

    /**
     * @param bool $assoc True for an associative array, false for an object
     * @return array<string, mixed>|object $firstRow — `[]` by default, the real `\DB` empty-row value
     */
    public function first(bool $assoc = false): array|object
    {
        return $this->firstRow;
    }

    /**
     * @param bool $assoc True for associative arrays, false for objects
     * @return array<int, object|array<string, mixed>> Always `[]` — no rows
     */
    public function results(bool $assoc = false): array
    {
        return [];
    }

    /**
     * @return int Always 0 — no insert has generated an id
     */
    public function lastId(): int
    {
        return 0;
    }

    /**
     * @return bool Always true
     */
    public function beginTransaction(): bool
    {
        return true;
    }

    /**
     * @return bool Always true
     */
    public function commit(): bool
    {
        return true;
    }

    /**
     * @return bool Always true
     */
    public function rollBack(): bool
    {
        return true;
    }

    /**
     * @return bool Always false — no transaction is active
     */
    public function inTransaction(): bool
    {
        return false;
    }
}
