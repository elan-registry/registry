<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/../../app/admin/includes/account-cleanup-helpers.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for findVerifiedOwnerlessAccounts().
 *
 * Each test creates real database fixtures (users, cars, car_user rows) and
 * asserts that the function's SQL filters include or exclude those fixtures
 * correctly. All fixtures are cleaned up automatically in tearDown().
 *
 * Tests assert presence/absence of a specific user ID in results; they never
 * assert row counts because the live database contains other accounts.
 *
 * @see FindVerifiedOwnerlessAccountsTest  (unit tests — SQL-agnostic)
 */
#[Group('integration')]
#[Group('admin')]
final class FindVerifiedOwnerlessAccountsIntegrationTest extends IntegrationTestCase
{
    /** @var int[] car_user row IDs inserted directly by tests — cleaned in tearDown */
    private array $createdCarUserIds = [];

    /** @var int[] car_transfer_requests row IDs inserted directly by tests — cleaned in tearDown */
    private array $createdTransferRequestIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdCarUserIds as $carUserId) {
            try {
                $this->db->query("DELETE FROM car_user WHERE id = ?", [$carUserId]);
            } catch (\Throwable $e) {
                // Safety net — parent tearDown also cleans these up via car_id cascade.
            }
        }
        $this->createdCarUserIds = [];

        foreach ($this->createdTransferRequestIds as $requestId) {
            try {
                $this->db->query("DELETE FROM car_transfer_requests WHERE id = ?", [$requestId]);
            } catch (\Throwable $e) {
                // Safety net — parent tearDown also cleans these up via DELETE WHERE existing_car_id.
            }
        }
        $this->createdTransferRequestIds = [];

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
     * A verified user who has never logged in (last_login IS NULL) and has no
     * car associations must be included — null last_login satisfies the OR branch.
     */
    public function testFindsVerifiedOwnerlessAccountWithNullLastLogin(): void
    {
        $userId = $this->createTestUser(['email_verified' => 1]);

        $results = findVerifiedOwnerlessAccounts($this->db, 365);
        $ids     = $this->idsFrom($results);

        $this->assertContains((string) $userId, $ids, 'Verified user with NULL last_login should appear in results');
    }

    /**
     * A verified user whose last_login is the zero-date sentinel must be
     * included — '0000-00-00 00:00:00' satisfies the second OR branch.
     */
    public function testFindsVerifiedOwnerlessAccountWithZeroLastLogin(): void
    {
        $userId = $this->createTestUser([
            'email_verified' => 1,
            'last_login'     => '0000-00-00 00:00:00',
        ]);

        $results = findVerifiedOwnerlessAccounts($this->db, 365);
        $ids     = $this->idsFrom($results);

        $this->assertContains((string) $userId, $ids, "Verified user with '0000-00-00' last_login should appear in results");
    }

    // -------------------------------------------------------------------------
    // Exclusion tests — the user must NOT appear in results
    // -------------------------------------------------------------------------

    /**
     * An unverified user (email_verified = 0) must be excluded — this function
     * is specifically for the verified-but-inactive cohort.
     */
    public function testExcludesUnverifiedAccount(): void
    {
        $userId = $this->createTestUser(['email_verified' => 0]);

        $results = findVerifiedOwnerlessAccounts($this->db, 365);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, 'Unverified user must be excluded from the verified-account query');
    }

    /**
     * A verified user who logged in recently must be excluded.
     */
    public function testExcludesUserWithRecentLogin(): void
    {
        $userId = $this->createTestUser([
            'email_verified' => 1,
            'last_login'     => $this->daysAgo(1),
        ]);

        $results = findVerifiedOwnerlessAccounts($this->db, 365);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, 'Verified user with a recent last_login must be excluded');
    }

    /**
     * A verified user who owns a car (row in the `cars` table via user_id) must
     * be excluded even though they have not logged in recently.
     */
    public function testExcludesAccountWithCarsRow(): void
    {
        $userId = $this->createTestUser(['email_verified' => 1]);
        $this->createTestCar($userId);

        $results = findVerifiedOwnerlessAccounts($this->db, 365);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, 'Verified user with a cars row must be excluded');
    }

    /**
     * A verified user who appears in the car_user junction table (but has no row
     * in the cars table as owner) must be excluded via the second NOT EXISTS clause.
     *
     * Setup:
     *   1. Create the verified user under test (no cars of their own).
     *   2. Create a separate dummy user and a car for them — this gives us a
     *      real car_id to satisfy any FK constraints on car_user.car_id.
     *   3. Insert a car_user row linking the test user to that car.
     *   4. Track the inserted row ID for cleanup in tearDown().
     */
    public function testExcludesAccountWithCarUserRow(): void
    {
        $testUserId  = $this->createTestUser(['email_verified' => 1]);
        $dummyUserId = $this->createTestUser();
        $carId       = $this->createTestCar($dummyUserId);

        $this->db->insert('car_user', ['userid' => $testUserId, 'car_id' => $carId]);
        $row = $this->db->query(
            "SELECT id FROM car_user WHERE userid = ? AND car_id = ? ORDER BY id DESC LIMIT 1",
            [$testUserId, $carId]
        )->first();
        if ($row) {
            $this->createdCarUserIds[] = (int) $row->id;
        }

        $results = findVerifiedOwnerlessAccounts($this->db, 365);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $testUserId, $ids, 'Verified user with a car_user row must be excluded');
    }

    /**
     * A protected account (protected = 1) must be excluded regardless of
     * verification status or login history.
     */
    public function testExcludesProtectedAccount(): void
    {
        $userId = $this->createTestUser([
            'email_verified' => 1,
            'protected'      => 1,
        ]);

        $results = findVerifiedOwnerlessAccounts($this->db, 365);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, 'Protected verified user must be excluded');
    }

    /**
     * An account with username 'noowner' must be excluded because the query
     * uses it as the "unassigned cars" sentinel user.
     */
    public function testExcludesNoownerUsername(): void
    {
        $userId = $this->createTestUser([
            'username'       => 'noowner',
            'email_verified' => 1,
        ]);

        $results = findVerifiedOwnerlessAccounts($this->db, 365);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, "Verified user with username 'noowner' must be excluded");
    }

    /**
     * A verified user who has a pending car transfer request must be excluded
     * even if they have no car ownership records.
     *
     * Setup:
     *   1. Create the test user (verified, never logged in, no cars).
     *   2. Create a dummy user and a car for them to satisfy the FK on existing_car_id.
     *   3. Insert a car_transfer_requests row with the test user as requested_by_user_id
     *      and status = 'pending'.
     *   4. Assert the test user is absent from results.
     *
     * Cleanup: the child tearDown() deletes the request row by ID; parent tearDown()
     * also removes any remaining transfer requests via DELETE WHERE existing_car_id.
     */
    public function testExcludesUserWithPendingTransferRequest(): void
    {
        $testUserId  = $this->createTestUser(['email_verified' => 1]);
        $dummyUserId = $this->createTestUser();
        $carId       = $this->createTestCar($dummyUserId);

        $this->db->insert('car_transfer_requests', [
            'existing_car_id'      => $carId,
            'requested_by_user_id' => $testUserId,
            'created_by'           => $testUserId,
            'security_token'       => bin2hex(random_bytes(16)),
            'status'               => 'pending',
            'expires_at'           => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        $row = $this->db->query(
            "SELECT id FROM car_transfer_requests WHERE requested_by_user_id = ? ORDER BY id DESC LIMIT 1",
            [$testUserId]
        )->first();
        if ($row) {
            $this->createdTransferRequestIds[] = (int) $row->id;
        }

        $results = findVerifiedOwnerlessAccounts($this->db, 365);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $testUserId, $ids, 'Verified user with a pending transfer request must be excluded from cleanup eligibility');
    }

    /**
     * A verified user whose only transfer request is in a terminal state (denied)
     * must still be included in results — the NOT EXISTS guard applies only to
     * 'pending' requests.
     *
     * This prevents a regression where dropping the status condition from the
     * NOT EXISTS clause would permanently shield any user who ever submitted a
     * transfer request.
     */
    public function testIncludesUserWithDeniedTransferRequest(): void
    {
        $testUserId  = $this->createTestUser(['email_verified' => 1]);
        $dummyUserId = $this->createTestUser();
        $carId       = $this->createTestCar($dummyUserId);

        $this->db->insert('car_transfer_requests', [
            'existing_car_id'      => $carId,
            'requested_by_user_id' => $testUserId,
            'created_by'           => $testUserId,
            'security_token'       => bin2hex(random_bytes(16)),
            'status'               => 'denied',
            'expires_at'           => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        $row = $this->db->query(
            "SELECT id FROM car_transfer_requests WHERE requested_by_user_id = ? ORDER BY id DESC LIMIT 1",
            [$testUserId]
        )->first();
        if ($row) {
            $this->createdTransferRequestIds[] = (int) $row->id;
        }

        $results = findVerifiedOwnerlessAccounts($this->db, 365);
        $ids     = $this->idsFrom($results);

        $this->assertContains((string) $testUserId, $ids, 'Verified user with only a denied transfer request must still be eligible for cleanup');
    }

    // -------------------------------------------------------------------------
    // Threshold boundary tests
    // -------------------------------------------------------------------------

    /**
     * A verified user whose last_login equals the threshold exactly
     * (DATEDIFF = 365 >= 365) must be included — the boundary is inclusive.
     *
     * last_login set to midnight exactly 365 days ago so DATEDIFF is stable
     * regardless of test run time.
     */
    public function testThresholdBoundaryExactMatch(): void
    {
        $userId = $this->createTestUser([
            'email_verified' => 1,
            'last_login'     => $this->daysAgo(365),
        ]);

        $results = findVerifiedOwnerlessAccounts($this->db, 365);
        $ids     = $this->idsFrom($results);

        $this->assertContains((string) $userId, $ids, 'Verified user whose inactivity equals the threshold (365 == 365) must be included');
    }

    /**
     * A verified user whose last_login is one day short of the threshold
     * (DATEDIFF = 364 < 365) must NOT be included.
     *
     * last_login set to midnight exactly 364 days ago so DATEDIFF is stable
     * regardless of test run time.
     */
    public function testThresholdBoundaryOneDayShort(): void
    {
        $userId = $this->createTestUser([
            'email_verified' => 1,
            'last_login'     => $this->daysAgo(364),
        ]);

        $results = findVerifiedOwnerlessAccounts($this->db, 365);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, 'Verified user whose inactivity is one day below the threshold (364 < 365) must be excluded');
    }
}
