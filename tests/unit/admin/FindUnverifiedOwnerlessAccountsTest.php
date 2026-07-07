<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/admin/includes/account-cleanup-helpers.php';

/**
 * Unit tests for findUnverifiedOwnerlessAccounts() in account-cleanup-helpers.php.
 *
 * These tests use an inline anonymous DB mock that extends the bootstrap's DB class
 * so the strict `DB $db` type hint in the function under test is satisfied at runtime.
 * The anonymous class returns a real QueryResult so the ->results() chain works without
 * any special plumbing.
 *
 * What is NOT tested here (delegated to integration tests):
 *   - Actual SQL filter correctness (email_verified, active, protected, NOT EXISTS)
 *   - DATEDIFF boundary behaviour
 *
 * @see FindUnverifiedOwnerlessAccountsIntegrationTest
 */
#[Group('fast')]
#[Group('unit')]
#[Group('admin')]
final class FindUnverifiedOwnerlessAccountsTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Factory helpers
    // -------------------------------------------------------------------------

    /**
     * Build a DB mock that returns $rows from ->results() and records the last
     * SQL parameters for inspection via getLastParams().
     *
     * The anonymous class extends the bootstrap mock DB so the `DB $db` type
     * hint in findUnverifiedOwnerlessAccounts() is satisfied at runtime.
     */
    private function makeDb(array $rows): object
    {
        return new class($rows) extends DB {
            private array $lastParams = [];

            public function __construct(private readonly array $rows) {}

            public function query(string $sql, array $params = []): QueryResult
            {
                $this->lastParams = $params;
                return new QueryResult($this->rows);
            }

            public function getLastParams(): array
            {
                return $this->lastParams;
            }
        };
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * When the database returns two rows the function must return exactly
     * those two rows with their values intact.
     */
    #[Group('fast')]
    #[Group('unit')]
    #[Group('admin')]
    public function testReturnsRowsProvidedByDatabase(): void
    {
        $row1 = (object) ['id' => 42, 'email' => 'alice@example.com', 'fname' => 'Alice', 'lname' => 'Smith'];
        $row2 = (object) ['id' => 99, 'email' => 'bob@example.com',   'fname' => 'Bob',   'lname' => 'Jones'];

        $db     = $this->makeDb([$row1, $row2]);
        $result = findUnverifiedOwnerlessAccounts($db, 30);

        $this->assertCount(2, $result);
        $this->assertSame($row1, $result[0]);
        $this->assertSame($row2, $result[1]);
    }

    /**
     * When the database returns no rows the function must return an empty array,
     * not null or false.
     */
    #[Group('fast')]
    #[Group('unit')]
    #[Group('admin')]
    public function testReturnsEmptyArrayWhenNoRows(): void
    {
        $db     = $this->makeDb([]);
        $result = findUnverifiedOwnerlessAccounts($db, 30);

        $this->assertSame([], $result);
    }

    /**
     * The days threshold must be forwarded as the first (and only) positional
     * parameter to DB::query() so the SQL placeholder is bound correctly.
     */
    #[Group('fast')]
    #[Group('unit')]
    #[Group('admin')]
    public function testPassesDaysParameterToQuery(): void
    {
        $db = $this->makeDb([]);
        findUnverifiedOwnerlessAccounts($db, 30);

        $params = $db->getLastParams();

        $this->assertCount(1, $params, 'Exactly one bind parameter expected');
        $this->assertSame(30, $params[0]);
    }

    /**
     * Verifies that a different threshold value (90) is forwarded unchanged —
     * ruling out any accidental hard-coding of the default value inside the
     * function.
     */
    #[Group('fast')]
    #[Group('unit')]
    #[Group('admin')]
    public function testThresholdVariationPassedCorrectly(): void
    {
        $db = $this->makeDb([]);
        findUnverifiedOwnerlessAccounts($db, 90);

        $params = $db->getLastParams();

        $this->assertSame(90, $params[0]);
    }

    /**
     * The array returned by the function must be the exact array returned by
     * QueryResult::results() — not a copy, subset, or re-keyed version.
     */
    #[Group('fast')]
    #[Group('unit')]
    #[Group('admin')]
    public function testResultsAreReturnedDirectly(): void
    {
        $expected = [
            (object) ['id' => 7,  'email' => 'x@example.com'],
            (object) ['id' => 13, 'email' => 'y@example.com'],
            (object) ['id' => 21, 'email' => 'z@example.com'],
        ];

        $db     = $this->makeDb($expected);
        $result = findUnverifiedOwnerlessAccounts($db, 30);

        $this->assertSame($expected, $result);
    }

    /**
     * Verifies that the function calls query() before accessing results().
     *
     * A shared-state sentinel object is passed into both the DB mock and the
     * QueryResult-like object it returns. If results() were somehow reached
     * without query() having run first, a RuntimeException would be thrown and
     * the test would fail. The function under test calls the chain in the
     * correct order, so the assertion on $state->queryWasCalled confirms the
     * expected call sequence.
     */
    #[Group('fast')]
    #[Group('unit')]
    #[Group('admin')]
    public function testQueryChainIsCalledCorrectly(): void
    {
        $state = (object) ['queryWasCalled' => false];

        $db = new class($state) extends DB {
            public function __construct(private readonly object $state) {}

            public function query(string $sql, array $params = []): QueryResult
            {
                $this->state->queryWasCalled = true;
                $s = $this->state;

                // Return a QueryResult subclass whose results() enforces ordering.
                return new class($s) extends QueryResult {
                    public function __construct(private readonly object $s)
                    {
                        // Intentionally skip parent::__construct() — we override results().
                    }

                    public function results(): array
                    {
                        if (!$this->s->queryWasCalled) {
                            throw new \RuntimeException('results() was reached before query() was called');
                        }
                        return [];
                    }
                };
            }
        };

        $result = findUnverifiedOwnerlessAccounts($db, 30);

        $this->assertIsArray($result);
        $this->assertTrue($state->queryWasCalled, 'query() must be called before results()');
    }
}
