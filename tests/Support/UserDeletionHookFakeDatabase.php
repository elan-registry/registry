<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * UserDeletionHookFakeDatabase - FakeDatabase double for the account-deletion hook
 *
 * Records every statement and transaction call in one ordered list, so a test
 * can check the order of steps in `usersc/scripts/after_user_deletion.php`.
 * It tracks whether a transaction is open. A test can make one statement
 * fail (with an SQLSTATE and driver code), and can report the sendinblue
 * plugin table as absent. get(), insert(), update() and delete() throw,
 * so a statement cannot bypass the recorded list.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 *
 * @package Tests\Support
 * @since v2.30.8
 * @see https://github.com/elan-registry/registry/issues/2358
 */
class UserDeletionHookFakeDatabase extends FakeDatabase
{
    /** @var list<string> Statements as 'SQL: ...', transaction calls as 'BEGIN', 'COMMIT', 'ROLLBACK' */
    public array $events = [];

    private bool $open = false;

    private string $currentSql = '';

    /** Whether the current statement is the one told to fail. */
    private bool $currentFails = false;

    /**
     * @param bool $pluginTableExists What the information_schema check reports
     * @param string|null $failOnSql A statement that contains this text fails
     * @param array{0: string, 1: int, 2: string} $failInfo PDO errorInfo() triple of the failure
     */
    public function __construct(
        private readonly bool $pluginTableExists = true,
        private readonly ?string $failOnSql = null,
        private readonly array $failInfo = ['HY000', 1234, 'simulated failure'],
    ) {
    }

    public function query(string $sql, array $params = []): self
    {
        $this->events[] = 'SQL: ' . preg_replace('/\s+/', ' ', trim($sql));
        $this->currentSql = $sql;
        $this->currentFails = $this->failOnSql !== null && str_contains($sql, $this->failOnSql);

        return $this;
    }

    /**
     * The hook must send each statement through query(), so the order tests
     * see it. A call here would hide from them, so it fails the test.
     */
    public function get(string $table, array $where): self|false
    {
        throw new \LogicException('unrecorded call');
    }

    public function insert(string $table, array $fields = [], bool $update = false): bool
    {
        throw new \LogicException('unrecorded call');
    }

    public function update(string $table, array|int $id, array $fields): bool
    {
        throw new \LogicException('unrecorded call');
    }

    public function delete(string $table, array|int $where): self|false
    {
        throw new \LogicException('unrecorded call');
    }

    public function error(): bool
    {
        return $this->currentFails;
    }

    public function errorString(): string
    {
        return $this->currentFails ? $this->failInfo[2] : '';
    }

    public function errorInfo(): array
    {
        return $this->currentFails ? $this->failInfo : [0, null, null];
    }

    public function first(bool $assoc = false): array|object
    {
        if (str_contains($this->currentSql, 'information_schema.tables')) {
            $row = ['cnt' => $this->pluginTableExists ? 1 : 0];

            return $assoc ? $row : (object) $row;
        }

        return [];
    }

    public function beginTransaction(): bool
    {
        $this->events[] = 'BEGIN';
        $this->open = true;

        return true;
    }

    public function commit(): bool
    {
        $this->events[] = 'COMMIT';
        $this->open = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->events[] = 'ROLLBACK';
        $this->open = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->open;
    }

    /**
     * Position of the first event that contains $needle, or null.
     */
    public function indexOf(string $needle): ?int
    {
        foreach ($this->events as $index => $event) {
            if (str_contains($event, $needle)) {
                return $index;
            }
        }

        return null;
    }
}
