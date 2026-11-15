<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqlRecordingFakeDatabase;

require_once __DIR__ . '/../../../app/admin/includes/account-cleanup-helpers.php';

/**
 * Unit tests for findVerifiedOwnerlessAccounts() and findUnverifiedOwnerlessAccounts()
 * in account-cleanup-helpers.php.
 *
 * These tests use SqlRecordingFakeDatabase (a FakeDatabase subclass) so the strict
 * `DatabaseInterface $db` type hint in the functions under test is satisfied at runtime.
 * query() returns the double itself, so the ->results() chain works without any
 * special plumbing.
 *
 * What is NOT tested here (delegated to integration tests):
 *   - Actual SQL filter correctness (email_verified, active, protected)
 *   - Database-level correctness of NOT EXISTS clauses for cars and cars_hist
 *   - last_login/DATEDIFF threshold and boundary behaviour
 *
 * The car_transfer_requests NOT EXISTS guard is verified at the unit level via
 * testSqlExcludesUsersWithPendingTransferRequests().
 *
 * @see FindVerifiedOwnerlessAccountsIntegrationTest
 * @see FindUnverifiedOwnerlessAccountsIntegrationTest
 */
#[Group('fast')]
#[Group('unit')]
#[Group('admin')]
final class OwnerlessAccountsFinderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Factory helpers
    // -------------------------------------------------------------------------

    /**
     * Build a DB double that returns $rows from ->results() and records the last
     * SQL query string and parameters for inspection.
     *
     * @param array<int, object|array<string, mixed>> $rows
     */
    private function makeDb(array $rows): SqlRecordingFakeDatabase
    {
        return new SqlRecordingFakeDatabase($rows);
    }

    /**
     * @return array<string, mixed> ['functionName' => callable(DatabaseInterface, int): array,
     *                                'defaultThreshold' => int, 'altThreshold' => int]
     */
    public static function finderProvider(): array
    {
        return [
            'findVerifiedOwnerlessAccounts' => ['findVerifiedOwnerlessAccounts', 365, 730],
            'findUnverifiedOwnerlessAccounts' => ['findUnverifiedOwnerlessAccounts', 30, 90],
        ];
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * When the database returns two rows the function must return exactly
     * those two rows with their values intact.
     */
    #[DataProvider('finderProvider')]
    public function testReturnsRowsProvidedByDatabase(
        string $functionName,
        int $defaultThreshold,
        int $altThreshold
    ): void {
        $row1 = (object) ['id' => 42, 'email' => 'alice@example.com', 'fname' => 'Alice', 'lname' => 'Smith'];
        $row2 = (object) ['id' => 99, 'email' => 'bob@example.com',   'fname' => 'Bob',   'lname' => 'Jones'];

        $db     = $this->makeDb([$row1, $row2]);
        $result = $functionName($db, $defaultThreshold);

        $this->assertCount(2, $result);
        $this->assertSame($row1, $result[0]);
        $this->assertSame($row2, $result[1]);
    }

    /**
     * When the database returns no rows the function must return an empty array,
     * not null or false.
     */
    #[DataProvider('finderProvider')]
    public function testReturnsEmptyArrayWhenNoRows(
        string $functionName,
        int $defaultThreshold,
        int $altThreshold
    ): void {
        $db     = $this->makeDb([]);
        $result = $functionName($db, $defaultThreshold);

        $this->assertSame([], $result);
    }

    /**
     * The days threshold must be forwarded as the first (and only) positional
     * parameter to DB::query() so the SQL placeholder is bound correctly.
     */
    #[DataProvider('finderProvider')]
    public function testPassesDaysParameterToQuery(
        string $functionName,
        int $defaultThreshold,
        int $altThreshold
    ): void {
        $db = $this->makeDb([]);
        $functionName($db, $defaultThreshold);

        $params = $db->getLastParams();

        $this->assertCount(1, $params, 'Exactly one bind parameter expected');
        $this->assertSame($defaultThreshold, $params[0]);
    }

    /**
     * Verifies that a different threshold value is forwarded unchanged — ruling
     * out any accidental hard-coding of the default value inside the function.
     */
    #[DataProvider('finderProvider')]
    public function testThresholdVariationPassedCorrectly(
        string $functionName,
        int $defaultThreshold,
        int $altThreshold
    ): void {
        $db = $this->makeDb([]);
        $functionName($db, $altThreshold);

        $params = $db->getLastParams();

        $this->assertSame($altThreshold, $params[0]);
    }

    /**
     * The generated SQL must contain the NOT EXISTS guard for car_transfer_requests
     * so that users with a pending transfer request are excluded from eligibility.
     */
    #[DataProvider('finderProvider')]
    public function testSqlExcludesUsersWithPendingTransferRequests(
        string $functionName,
        int $defaultThreshold,
        int $altThreshold
    ): void {
        $db = $this->makeDb([]);
        $functionName($db, $defaultThreshold);

        $sql = $db->getLastSql();
        $this->assertStringContainsString('car_transfer_requests', $sql);
        $this->assertStringContainsString("status = 'pending'", $sql);
    }
}
