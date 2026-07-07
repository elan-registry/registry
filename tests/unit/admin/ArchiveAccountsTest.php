<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/admin/includes/account-cleanup-helpers.php';

/**
 * Unit tests for archiveAccounts() in account-cleanup-helpers.php.
 *
 * Uses an inline anonymous DB mock so the strict `DB $db` type hint is satisfied.
 * DB-level SQL correctness and constraint enforcement are left to integration tests.
 *
 * @see ArchiveAndRestoreIntegrationTest
 */
#[Group('fast')]
#[Group('unit')]
#[Group('admin')]
final class ArchiveAccountsTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Build a DB mock. $insertOk controls insert() return value; $queryRows drives query().results(). */
    private function makeDb(bool $insertOk = true, array $queryRows = []): object
    {
        return new class($insertOk, $queryRows) extends DB {
            public int $commitCalls   = 0;
            public int $rollBackCalls = 0;
            public ?array $lastInsertData = null;

            private bool $errorFlag = false;

            public function __construct(
                private readonly bool $insertOk,
                private readonly array $queryRows
            ) {}

            public function query(string $sql, array $params = []): QueryResult
            {
                return new QueryResult($this->queryRows);
            }

            public function insert(string $table, array $data): bool
            {
                $this->lastInsertData = $data;
                return $this->insertOk;
            }

            public function error(): bool   { return $this->errorFlag; }
            public function errorString(): string { return ''; }

            public function setError(): void { $this->errorFlag = true; }

            public function beginTransaction(): void {}
            public function commit(): void   { $this->commitCalls++; }
            public function rollBack(): void { $this->rollBackCalls++; }
        };
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    #[Group('fast')] #[Group('unit')] #[Group('admin')]
    public function testEmptyUserIdsReturnsImmediately(): void
    {
        $db = $this->makeDb();
        archiveAccounts($db, [], 1, 'unverified');

        $this->assertSame(0, $db->commitCalls, 'No commit expected for empty input');
    }

    #[Group('fast')] #[Group('unit')] #[Group('admin')]
    public function testZeroDateLastLoginNormalizedToNull(): void
    {
        $row = (object)[
            'id' => 5, 'email' => 'a@x.com', 'username' => 'a', 'fname' => 'A', 'lname' => 'B',
            'join_date' => '2024-01-01 00:00:00', 'last_login' => '0000-00-00 00:00:00',
            'logins' => 0, 'email_verified' => 0,
            'city' => null, 'state' => null, 'country' => null, 'bio' => null, 'website' => null,
        ];
        $db = $this->makeDb(true, [$row]);

        archiveAccounts($db, [5], 1, 'unverified');

        $this->assertNull($db->lastInsertData['last_login'], 'Zero-date must be normalised to null');
    }

    #[Group('fast')] #[Group('unit')] #[Group('admin')]
    public function testNullLastLoginStoredAsNull(): void
    {
        $row = (object)[
            'id' => 5, 'email' => 'a@x.com', 'username' => 'a', 'fname' => 'A', 'lname' => 'B',
            'join_date' => '2024-01-01 00:00:00', 'last_login' => null,
            'logins' => 0, 'email_verified' => 0,
            'city' => null, 'state' => null, 'country' => null, 'bio' => null, 'website' => null,
        ];
        $db = $this->makeDb(true, [$row]);

        archiveAccounts($db, [5], 1, 'unverified');

        $this->assertNull($db->lastInsertData['last_login']);
    }

    #[Group('fast')] #[Group('unit')] #[Group('admin')]
    public function testRealLastLoginPreserved(): void
    {
        $row = (object)[
            'id' => 7, 'email' => 'b@x.com', 'username' => 'b', 'fname' => 'C', 'lname' => 'D',
            'join_date' => '2024-01-01 00:00:00', 'last_login' => '2025-06-01 12:00:00',
            'logins' => 3, 'email_verified' => 0,
            'city' => null, 'state' => null, 'country' => null, 'bio' => null, 'website' => null,
        ];
        $db = $this->makeDb(true, [$row]);

        archiveAccounts($db, [7], 1, 'unverified');

        $this->assertSame('2025-06-01 12:00:00', $db->lastInsertData['last_login']);
    }

    #[Group('fast')] #[Group('unit')] #[Group('admin')]
    public function testThrowsOnInsertFailure(): void
    {
        $row = (object)[
            'id' => 5, 'email' => 'a@x.com', 'username' => 'a', 'fname' => 'A', 'lname' => 'B',
            'join_date' => '2024-01-01 00:00:00', 'last_login' => null,
            'logins' => 0, 'email_verified' => 0,
            'city' => null, 'state' => null, 'country' => null, 'bio' => null, 'website' => null,
        ];
        $db = $this->makeDb(false, [$row]);

        $this->expectException(RuntimeException::class);
        archiveAccounts($db, [5], 1, 'unverified');

        $this->assertSame(1, $db->rollBackCalls, 'Transaction must roll back on insert failure');
    }

    #[Group('fast')] #[Group('unit')] #[Group('admin')]
    public function testThrowsOnQueryError(): void
    {
        $db = $this->makeDb(true, []);
        $db->setError();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/query failed/');
        archiveAccounts($db, [5], 1, 'unverified');
    }

    #[Group('fast')] #[Group('unit')] #[Group('admin')]
    public function testCommitCalledOnSuccess(): void
    {
        $row = (object)[
            'id' => 5, 'email' => 'a@x.com', 'username' => 'a', 'fname' => 'A', 'lname' => 'B',
            'join_date' => '2024-01-01 00:00:00', 'last_login' => null,
            'logins' => 0, 'email_verified' => 0,
            'city' => null, 'state' => null, 'country' => null, 'bio' => null, 'website' => null,
        ];
        $db = $this->makeDb(true, [$row]);

        archiveAccounts($db, [5], 1, 'unverified');

        $this->assertSame(1, $db->commitCalls, 'commit() must be called exactly once on success');
        $this->assertSame(0, $db->rollBackCalls, 'rollBack() must not be called on success');
    }
}
