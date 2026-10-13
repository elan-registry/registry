<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\DatabaseInterface;

/**
 * RecordingPassThroughDatabase - a PassThroughDatabase that records the order of its calls
 *
 * Every query, update, insert and transaction call runs on the real
 * connection and adds one line to {@see self::$events}. A test reads the list
 * to check the order of statements, for example that a row lock comes before
 * the first write. A test can also make one `update()` or `insert()` on a
 * named table fail, to check a rollback.
 *
 * SQL text is stored with each run of white space reduced to one space.
 *
 * @package Tests\Support
 * @see https://github.com/elan-registry/registry/issues/2356
 */
final class RecordingPassThroughDatabase extends PassThroughDatabase
{
    /** @var list<string> One line per call: `query: <sql>`, `update: <table> [<columns>]`, `begin`, `commit`, `rollback` */
    public array $events = [];

    /**
     * @param DatabaseInterface $real        Connection that runs every call
     * @param string|null       $failTable   Table whose `update()` or `insert()` reports failure without a write
     * @param string|null       $failSqlPart Text in a `query()` that makes it report failure without running
     */
    public function __construct(
        DatabaseInterface $real,
        private readonly ?string $failTable = null,
        private readonly ?string $failSqlPart = null
    ) {
        parent::__construct($real);
    }

    /**
     * Forget the calls so far, for example those of a fixture load
     */
    public function resetEvents(): void
    {
        $this->events = [];
    }

    public function query(string $sql, array $params = []): static
    {
        $this->events[] = 'query: ' . trim((string) preg_replace('/\s+/', ' ', $sql));
        if ($this->failSqlPart !== null && str_contains($sql, $this->failSqlPart)) {
            return $this->simulateFailure('simulated failure of a query containing ' . $this->failSqlPart);
        }

        return parent::query($sql, $params);
    }

    public function update(string $table, array|int $id, array $fields): bool
    {
        $this->events[] = 'update: ' . $table . ' [' . implode(',', array_keys($fields)) . ']';
        if ($this->failTable === $table) {
            $this->simulateFailure('simulated failure of an update to ' . $table);

            return false;
        }

        return parent::update($table, $id, $fields);
    }

    public function insert(string $table, array $fields = [], bool $update = false): bool
    {
        $this->events[] = 'insert: ' . $table;
        if ($this->failTable === $table) {
            $this->simulateFailure('simulated failure of an insert into ' . $table);

            return false;
        }

        return parent::insert($table, $fields, $update);
    }

    public function beginTransaction(): bool
    {
        $this->events[] = 'begin';

        return parent::beginTransaction();
    }

    public function commit(): bool
    {
        $this->events[] = 'commit';

        return parent::commit();
    }

    public function rollBack(): bool
    {
        $this->events[] = 'rollback';

        return parent::rollBack();
    }
}
