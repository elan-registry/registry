<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1505: Owner::create()/update() must roll back the real transaction when a
 * PHP \Error (not an \Exception) is thrown mid-transaction. The old
 * `catch (Exception $e)` around raw SQL missed it.
 */
#[Group('integration')]
#[Group('owner')]
final class OwnerCreateUpdateTransactionRollbackTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /**
     * Proxies the real connection, but insert()/update() throw \TypeError.
     * DatabaseInterface has no base class, so every method is proxied.
     */
    private function dbThrowingTypeErrorOnInsert(): DatabaseInterface
    {
        return new class ($this->db) implements DatabaseInterface {
            public function __construct(private DatabaseInterface $real)
            {
            }
            public function query(string $sql, array $params = []): DatabaseInterface
            {
                $this->real->query($sql, $params);
                return $this;
            }
            public function get(string $table, array $where): self|false
            {
                $result = $this->real->get($table, $where);
                return $result === false ? false : $this;
            }
            public function insert(string $table, array $fields = [], bool $update = false): bool
            {
                throw new \TypeError('Simulated mid-transaction PHP error during insert()');
            }
            public function update(string $table, array|int $id, array $fields): bool
            {
                throw new \TypeError('Simulated mid-transaction PHP error during update()');
            }
            public function delete(string $table, array|int $where): self|false
            {
                $result = $this->real->delete($table, $where);
                return $result === false ? false : $this;
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
        };
    }

    /** A \TypeError during create()'s user insert must roll back the transaction. */
    public function testCreateRollsBackOnMidTransactionTypeError(): void
    {
        $db = $this->dbThrowingTypeErrorOnInsert();
        $owner = new Owner(null, $db);

        $this->expectException(\TypeError::class);

        try {
            $owner->create([
                'fname' => 'Rollback',
                'lname' => 'Test',
                'email' => 'rollback-create-' . uniqid() . '@example.com',
            ]);
        } finally {
            $this->assertFalse(
                $db->inTransaction(),
                'A mid-transaction \TypeError during create() must trigger rollback, leaving no open transaction'
            );
        }
    }

    /** A \TypeError during update()'s user update must roll back the transaction. */
    public function testUpdateRollsBackOnMidTransactionTypeError(): void
    {
        $userId = $this->createTestUser();

        $db = $this->dbThrowingTypeErrorOnInsert();
        $owner = new Owner(null, $db);

        $this->expectException(\TypeError::class);

        try {
            $owner->update([
                'id'    => $userId,
                'fname' => 'RollbackUpdate',
            ]);
        } finally {
            $this->assertFalse(
                $db->inTransaction(),
                'A mid-transaction \TypeError during update() must trigger rollback, leaving no open transaction'
            );
        }
    }
}
