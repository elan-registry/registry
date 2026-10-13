<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDatabase;

require_once __DIR__ . '/../../../app/admin/includes/account-cleanup-helpers.php';

/**
 * Unit tests for archiveAccounts() in account-cleanup-helpers.php.
 *
 * Uses ArchiveAccountsFakeDatabase (declared at the foot of this file) so the strict
 * `DatabaseInterface $db` type hint is satisfied.
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

    /**
     * Build a DB double. $insertOk controls insert() return value; $queryRows drives query().results().
     *
     * @param array<int, object|array<string, mixed>> $queryRows
     */
    private function makeDb(bool $insertOk = true, array $queryRows = []): ArchiveAccountsFakeDatabase
    {
        return new ArchiveAccountsFakeDatabase($insertOk, $queryRows);
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

    /** The snapshot keeps the opt-out, so a restore does not enable mail to an owner who opted out. */
    #[Group('fast')] #[Group('unit')] #[Group('admin')]
    public function testOptedOutOwnerIsArchivedWithTheOptOutAndItsTime(): void
    {
        $row = (object)[
            'id' => 5, 'email' => 'a@x.com', 'username' => 'a', 'fname' => 'A', 'lname' => 'B',
            'join_date' => '2024-01-01 00:00:00', 'last_login' => null,
            'logins' => 0, 'email_verified' => 0,
            'city' => null, 'state' => null, 'country' => null, 'bio' => null, 'website' => null,
            'email_opted_out' => '1', 'email_opted_out_at' => '2026-02-03 04:05:06',
        ];
        $db = $this->makeDb(true, [$row]);

        archiveAccounts($db, [5], 1, 'unverified');

        $this->assertNotNull($db->lastInsertData);
        $this->assertSame(1, $db->lastInsertData['email_opted_out'], 'A string flag from PDO must be cast to int');
        $this->assertSame('2026-02-03 04:05:06', $db->lastInsertData['email_opted_out_at']);
    }

    /** An owner with no profile row (LEFT JOIN gives no columns) is archived as not opted out. */
    #[Group('fast')] #[Group('unit')] #[Group('admin')]
    public function testOwnerWithNoProfileRowIsArchivedAsNotOptedOut(): void
    {
        $row = (object)[
            'id' => 5, 'email' => 'a@x.com', 'username' => 'a', 'fname' => 'A', 'lname' => 'B',
            'join_date' => '2024-01-01 00:00:00', 'last_login' => null,
            'logins' => 0, 'email_verified' => 0,
        ];
        $db = $this->makeDb(true, [$row]);

        archiveAccounts($db, [5], 1, 'unverified');

        $this->assertNotNull($db->lastInsertData);
        $this->assertSame(0, $db->lastInsertData['email_opted_out']);
        $this->assertNull($db->lastInsertData['email_opted_out_at']);
    }

    /** Only the opt-out is archived. A block or bounce is not the owner's choice and is not restored. */
    #[Group('fast')] #[Group('unit')] #[Group('admin')]
    public function testArchiveCarriesNoBlockOrBounceColumns(): void
    {
        $row = (object)[
            'id' => 5, 'email' => 'a@x.com', 'username' => 'a', 'fname' => 'A', 'lname' => 'B',
            'join_date' => '2024-01-01 00:00:00', 'last_login' => null,
            'logins' => 0, 'email_verified' => 0,
            'email_blocked' => 1, 'email_bounced' => 1,
        ];
        $db = $this->makeDb(true, [$row]);

        archiveAccounts($db, [5], 1, 'unverified');

        $this->assertNotNull($db->lastInsertData);
        foreach (array_keys($db->lastInsertData) as $column) {
            $this->assertStringNotContainsString('blocked', $column);
            $this->assertStringNotContainsString('bounce', $column);
        }
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

        try {
            archiveAccounts($db, [5], 1, 'unverified');
            $this->fail('Expected RuntimeException on insert failure');
        } catch (RuntimeException) {
            $this->assertSame(1, $db->rollBackCalls, 'Transaction must roll back on insert failure');
            $this->assertSame(0, $db->commitCalls);
        }
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

/**
 * DB double for archiveAccounts(): canned SELECT rows, a switchable insert() result,
 * and call counters for commit()/rollBack() plus the last insert payload.
 *
 * Deliberately a *named* class rather than the anonymous `new class extends
 * FakeDatabase` it replaces: PHPStan reports `impureMethod.pure` when an anonymous
 * class overrides one of DatabaseInterface's `@phpstan-impure` methods (`results()`,
 * `error()`, `errorString()` here) with a side-effect-free body, because an anonymous
 * class can never be extended to add the side effect later. A named class is exempt.
 */
class ArchiveAccountsFakeDatabase extends FakeDatabase
{
    public int $commitCalls = 0;
    public int $rollBackCalls = 0;

    /** @var array<string, mixed>|null Fields passed to the most recent insert() call */
    public ?array $lastInsertData = null;

    private bool $errorFlag = false;

    /**
     * @param bool $insertOk Value returned by insert()
     * @param array<int, object|array<string, mixed>> $queryRows Rows handed back by results()
     */
    public function __construct(
        private readonly bool $insertOk,
        private readonly array $queryRows
    ) {
    }

    /**
     * @param array<mixed> $params
     */
    public function query(string $sql, array $params = []): self
    {
        return $this;
    }

    /**
     * @return array<int, object|array<string, mixed>>
     */
    public function results(bool $assoc = false): array
    {
        return $this->queryRows;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function insert(string $table, array $fields = [], bool $update = false): bool
    {
        $this->lastInsertData = $fields;
        return $this->insertOk;
    }

    /**
     * @return bool True once setError() has been called
     */
    public function error(): bool
    {
        return $this->errorFlag;
    }

    /**
     * @return string Always empty — the tests assert on the thrown message, not this
     */
    public function errorString(): string
    {
        return '';
    }

    /**
     * Make every subsequent error() check report a failed query.
     */
    public function setError(): void
    {
        $this->errorFlag = true;
    }

    /**
     * @return bool Always true
     */
    public function beginTransaction(): bool
    {
        return true;
    }

    /**
     * @return bool Always true; increments $commitCalls
     */
    public function commit(): bool
    {
        $this->commitCalls++;
        return true;
    }

    /**
     * @return bool Always true; increments $rollBackCalls
     */
    public function rollBack(): bool
    {
        $this->rollBackCalls++;
        return true;
    }
}
