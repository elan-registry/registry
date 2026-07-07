<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/../../app/admin/includes/account-cleanup-helpers.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for findUnverifiedOwnerlessAccounts().
 *
 * Each test creates real database fixtures (users, cars, car_user rows) and
 * asserts that the function's SQL filters include or exclude those fixtures
 * correctly. All fixtures are cleaned up automatically in tearDown().
 *
 * Tests assert presence/absence of a specific user ID in results; they never
 * assert row counts because the live database contains other accounts.
 *
 * @see FindUnverifiedOwnerlessAccountsTest  (unit tests — SQL-agnostic)
 */
#[Group('integration')]
#[Group('admin')]
final class FindUnverifiedOwnerlessAccountsIntegrationTest extends IntegrationTestCase
{
    /** @var int[] car_user row IDs inserted directly by tests — cleaned in tearDown */
    private array $createdCarUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        // Remove any car_user rows we inserted manually (before parent deletes the cars).
        foreach ($this->createdCarUserIds as $carUserId) {
            try {
                $this->db->query("DELETE FROM car_user WHERE id = ?", [$carUserId]);
            } catch (\Throwable $e) {
                // Ignore cleanup errors — parent tearDown may have already removed them
                // via the car_id cascade.
            }
        }
        $this->createdCarUserIds = [];

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return a date string exactly $days days ago at midnight (00:00:00).
     * Midnight keeps DATEDIFF deterministic regardless of the time the test runs.
     */
    private function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days")) . ' 00:00:00';
    }

    /**
     * Extract user IDs from results as strings.
     *
     * DB::query() returns integer columns as strings (PDO default behaviour).
     * PHPUnit assertContains/assertNotContains use strict (===) comparison, so
     * comparing an int $userId against a string '5003' would silently give the
     * wrong answer. Casting here keeps assertions correct regardless of the
     * underlying PDO fetch mode.
     *
     * @param array<object> $results
     * @return string[]
     */
    private function idsFrom(array $results): array
    {
        return array_map('strval', array_column($results, 'id'));
    }

    // -------------------------------------------------------------------------
    // Inclusion tests — the user SHOULD appear in results
    // -------------------------------------------------------------------------

    /**
     * A user who is active, unverified, unprotected, not named 'noowner',
     * has no cars, and joined more than 30 days ago must be included.
     */
    #[Group('integration')]
    #[Group('admin')]
    public function testFindsUnverifiedOwnerlessAccount(): void
    {
        $userId = $this->createTestUser(['join_date' => $this->daysAgo(31)]);

        $results = findUnverifiedOwnerlessAccounts($this->db, 30);
        $ids     = $this->idsFrom($results);

        $this->assertContains((string) $userId, $ids, 'User with no cars and old enough join date should appear in results');
    }

    // -------------------------------------------------------------------------
    // Exclusion tests — the user must NOT appear in results
    // -------------------------------------------------------------------------

    /**
     * A user whose email is already verified must be excluded even if they
     * have no cars and joined long ago.
     */
    #[Group('integration')]
    #[Group('admin')]
    public function testExcludesEmailVerifiedAccount(): void
    {
        $userId = $this->createTestUser([
            'email_verified' => 1,
            'join_date'      => $this->daysAgo(31),
        ]);

        $results = findUnverifiedOwnerlessAccounts($this->db, 30);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, 'Email-verified user must be excluded');
    }

    /**
     * A user who owns a car (row in the `cars` table via user_id) must be
     * excluded even though their email is unverified.
     *
     * createTestCar() inserts into both `cars` (user_id) and `car_user` (userid),
     * exercising both NOT EXISTS clauses simultaneously. The first clause
     * (NOT EXISTS cars WHERE user_id) alone is sufficient for exclusion here.
     */
    #[Group('integration')]
    #[Group('admin')]
    public function testExcludesAccountWithCarsRow(): void
    {
        $userId = $this->createTestUser(['join_date' => $this->daysAgo(31)]);
        $this->createTestCar($userId);

        $results = findUnverifiedOwnerlessAccounts($this->db, 30);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, 'User with a cars row must be excluded');
    }

    /**
     * A user who appears in the car_user junction table (but has no row in
     * the cars table as owner) must be excluded via the second NOT EXISTS clause.
     *
     * Setup:
     *   1. Create the user under test (no cars of their own).
     *   2. Create a separate dummy user and a car for them — this gives us a
     *      real car_id to satisfy any FK constraints on car_user.car_id.
     *   3. Insert a car_user row linking the test user to that car.
     *   4. Track the inserted row ID for cleanup in tearDown().
     */
    #[Group('integration')]
    #[Group('admin')]
    public function testExcludesAccountWithCarUserRow(): void
    {
        $testUserId  = $this->createTestUser(['join_date' => $this->daysAgo(31)]);
        $dummyUserId = $this->createTestUser();
        $carId       = $this->createTestCar($dummyUserId);

        // Manually link testUser → existing car via car_user.
        $this->db->insert('car_user', ['userid' => $testUserId, 'car_id' => $carId]);
        $row = $this->db->query(
            "SELECT id FROM car_user WHERE userid = ? AND car_id = ? ORDER BY id DESC LIMIT 1",
            [$testUserId, $carId]
        )->first();
        if ($row) {
            $this->createdCarUserIds[] = (int) $row->id;
        }

        $results = findUnverifiedOwnerlessAccounts($this->db, 30);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $testUserId, $ids, 'User with a car_user row must be excluded');
    }

    /**
     * A protected account (protected = 1) must be excluded regardless of
     * verification status or car ownership.
     */
    #[Group('integration')]
    #[Group('admin')]
    public function testExcludesProtectedAccount(): void
    {
        $userId = $this->createTestUser([
            'protected' => 1,
            'join_date' => $this->daysAgo(31),
        ]);

        $results = findUnverifiedOwnerlessAccounts($this->db, 30);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, 'Protected user must be excluded');
    }

    /**
     * An account with username 'noowner' must be excluded because the query
     * uses it as the "unassigned cars" sentinel user.
     */
    #[Group('integration')]
    #[Group('admin')]
    public function testExcludesNoownerUsername(): void
    {
        $userId = $this->createTestUser([
            'username'  => 'noowner',
            'join_date' => $this->daysAgo(31),
        ]);

        $results = findUnverifiedOwnerlessAccounts($this->db, 30);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, "User with username 'noowner' must be excluded");
    }

    // -------------------------------------------------------------------------
    // Threshold boundary tests
    // -------------------------------------------------------------------------

    /**
     * A user whose account age equals the threshold exactly (DATEDIFF = 30 >= 30)
     * must be included — the boundary is inclusive.
     *
     * join_date set to midnight exactly 30 days ago so DATEDIFF(NOW(), join_date)
     * is stable at 30 regardless of test run time.
     */
    #[Group('integration')]
    #[Group('admin')]
    public function testThresholdBoundaryExactMatch(): void
    {
        $userId = $this->createTestUser(['join_date' => $this->daysAgo(30)]);

        $results = findUnverifiedOwnerlessAccounts($this->db, 30);
        $ids     = $this->idsFrom($results);

        $this->assertContains((string) $userId, $ids, 'User whose account age equals the threshold (30 == 30) must be included');
    }

    /**
     * A user whose account age is one day short of the threshold (DATEDIFF = 29 < 30)
     * must NOT be included — the boundary is exclusive for younger accounts.
     *
     * join_date set to midnight exactly 29 days ago so DATEDIFF(NOW(), join_date)
     * is stable at 29 regardless of test run time.
     */
    #[Group('integration')]
    #[Group('admin')]
    public function testThresholdBoundaryOneDayShort(): void
    {
        $userId = $this->createTestUser(['join_date' => $this->daysAgo(29)]);

        $results = findUnverifiedOwnerlessAccounts($this->db, 30);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, 'User whose account age is one day below the threshold (29 < 30) must be excluded');
    }
}
