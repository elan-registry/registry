<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/admin/includes/account-cleanup-helpers.php';

/**
 * Unit tests for restoreArchivedAccount() in account-cleanup-helpers.php.
 *
 * Uses an inline anonymous DB mock; SQL correctness delegated to integration tests.
 *
 * @see ArchiveAndRestoreIntegrationTest
 */
#[Group('fast')]
#[Group('unit')]
#[Group('admin')]
final class RestoreArchivedAccountTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a mock DB for restoreArchivedAccount().
     *
     * @param object|null $archiveRow  Row returned by the initial SELECT, or null to simulate "not found"
     * @param bool        $insertOk   Whether insert() should succeed
     * @param int         $lastId     Value returned by lastId()
     * @param bool        $queryError Whether the first query() should set an error flag
     */
    private function makeDb(
        ?object $archiveRow,
        bool $insertOk = true,
        int $lastId = 42,
        bool $queryError = false
    ): object {
        return new class($archiveRow, $insertOk, $lastId, $queryError) extends DB {
            public int $commitCalls   = 0;
            public int $rollBackCalls = 0;

            private bool $errorFlag     = false;
            private bool $firstQueryDone = false;

            public function __construct(
                private readonly ?object $archiveRow,
                private readonly bool    $insertOk,
                private readonly int     $lastIdValue,
                private readonly bool    $queryError
            ) {}

            public function query(string $sql, array $params = []): QueryResult
            {
                if (!$this->firstQueryDone) {
                    $this->firstQueryDone = true;
                    if ($this->queryError) {
                        $this->errorFlag = true;
                        return new QueryResult([]);
                    }
                    return new QueryResult($this->archiveRow ? [$this->archiveRow] : []);
                }
                // Subsequent queries (UPDATE, etc.) succeed silently
                $this->errorFlag = false;
                return new QueryResult([]);
            }

            public function insert(string $table, array $data): bool
            {
                return $this->insertOk;
            }

            public function lastId(): int    { return $this->lastIdValue; }
            public function error(): bool    { return $this->errorFlag; }
            public function errorString(): string { return 'mock error'; }

            public function beginTransaction(): void {}
            public function commit(): void   { $this->commitCalls++; }
            public function rollBack(): void { $this->rollBackCalls++; }
        };
    }

    private function archiveRow(): object
    {
        return (object)[
            'id'             => 10,
            'email'          => 'test@example.com',
            'username'       => 'testuser',
            'fname'          => 'Test',
            'lname'          => 'User',
            'join_date'      => '2024-01-01 00:00:00',
            'last_login'     => null,
            'logins'         => 0,
            'email_verified' => 0,
            'city'           => null,
            'state'          => null,
            'country'        => null,
            'bio'            => null,
            'website'        => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    #[Group('fast')] #[Group('unit')] #[Group('admin')]
    public function testThrowsWhenDbErrorOnLookup(): void
    {
        $db = $this->makeDb(null, true, 42, queryError: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DB error reading archive row/');
        restoreArchivedAccount($db, 10, 1);
    }

    #[Group('fast')] #[Group('unit')] #[Group('admin')]
    public function testThrowsWhenArchiveRowNotFound(): void
    {
        $db = $this->makeDb(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found or already restored/');
        restoreArchivedAccount($db, 99, 1);
    }

    #[Group('fast')] #[Group('unit')] #[Group('admin')]
    public function testThrowsAndRollsBackOnInsertFailure(): void
    {
        $db = $this->makeDb($this->archiveRow(), insertOk: false);

        try {
            restoreArchivedAccount($db, 10, 1);
            $this->fail('Expected RuntimeException on insert failure');
        } catch (RuntimeException) {
            $this->assertSame(1, $db->rollBackCalls);
            $this->assertSame(0, $db->commitCalls);
        }
    }

    #[Group('fast')] #[Group('unit')] #[Group('admin')]
    public function testThrowsWhenLastIdIsZero(): void
    {
        $db = $this->makeDb($this->archiveRow(), insertOk: true, lastId: 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/returned no ID/');
        restoreArchivedAccount($db, 10, 1);
    }

    #[Group('fast')] #[Group('unit')] #[Group('admin')]
    public function testReturnsNewUserIdOnSuccess(): void
    {
        $db = $this->makeDb($this->archiveRow(), insertOk: true, lastId: 42);

        $result = restoreArchivedAccount($db, 10, 1);

        $this->assertSame(42, $result, 'Must return the new user ID from lastId()');
        $this->assertSame(1, $db->commitCalls);
        $this->assertSame(0, $db->rollBackCalls);
    }
}
