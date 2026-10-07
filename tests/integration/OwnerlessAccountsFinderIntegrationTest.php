<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/../../app/admin/includes/account-cleanup-helpers.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for findVerifiedOwnerlessAccounts() and
 * findUnverifiedOwnerlessAccounts(). Shared rules run once per finder via
 * finderProvider.
 *
 * Assert presence or absence of a user ID, never row counts: the database
 * holds other accounts.
 *
 * @see OwnerlessAccountsFinderTest  (unit tests — SQL-agnostic)
 */
#[Group('integration')]
#[Group('admin')]
final class OwnerlessAccountsFinderIntegrationTest extends IntegrationTestCase
{
    /** @var int[] car_transfer_requests row IDs inserted directly by tests — cleaned in tearDown */
    private array $createdTransferRequestIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
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

    /** Midnight keeps DATEDIFF stable whatever time the test runs. */
    private function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days")) . ' 00:00:00';
    }

    /**
     * DB::query() returns IDs as strings and assertContains is strict, so cast.
     *
     * @param array<object> $results
     * @return string[]
     */
    private function idsFrom(array $results): array
    {
        return array_map('strval', array_column($results, 'id'));
    }

    // -------------------------------------------------------------------------
    // Data provider
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: int, 2: callable(string): array<string, mixed>}>
     */
    public static function finderProvider(): array
    {
        return [
            'verified' => [
                'findVerifiedOwnerlessAccounts',
                365,
                fn (string $daysAgo) => ['email_verified' => 1, 'last_login' => $daysAgo],
            ],
            'unverified' => [
                'findUnverifiedOwnerlessAccounts',
                30,
                fn (string $daysAgo) => ['join_date' => $daysAgo],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Shared exclusion tests — the user must NOT appear in results
    // -------------------------------------------------------------------------

    #[DataProvider('finderProvider')]
    public function testExcludesAccountWithCarsRow(string $functionName, int $threshold, callable $fixtureBuilder): void
    {
        $userId = $this->createTestUser($fixtureBuilder($this->daysAgo($threshold + 10)));
        $this->createTestCar($userId);

        $results = $functionName($this->db, $threshold);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, 'User with a cars row must be excluded');
    }

    #[DataProvider('finderProvider')]
    public function testExcludesProtectedAccount(string $functionName, int $threshold, callable $fixtureBuilder): void
    {
        $overrides = $fixtureBuilder($this->daysAgo($threshold + 10));
        $overrides['protected'] = 1;
        $userId = $this->createTestUser($overrides);

        $results = $functionName($this->db, $threshold);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, 'Protected user must be excluded');
    }

    /** 'noowner' is the unassigned-cars sentinel user. */
    #[DataProvider('finderProvider')]
    public function testExcludesNoownerUsername(string $functionName, int $threshold, callable $fixtureBuilder): void
    {
        $overrides = $fixtureBuilder($this->daysAgo($threshold + 10));
        $overrides['username'] = 'noowner';
        $userId = $this->createTestUser($overrides);

        $results = $functionName($this->db, $threshold);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, "User with username 'noowner' must be excluded");
    }

    #[DataProvider('finderProvider')]
    public function testExcludesUserWithPendingTransferRequest(string $functionName, int $threshold, callable $fixtureBuilder): void
    {
        $testUserId  = $this->createTestUser($fixtureBuilder($this->daysAgo($threshold + 10)));
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

        $results = $functionName($this->db, $threshold);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $testUserId, $ids, 'User with a pending transfer request must be excluded from cleanup eligibility');
    }

    // -------------------------------------------------------------------------
    // Shared inclusion tests
    // -------------------------------------------------------------------------

    /** The NOT EXISTS guard applies only to 'pending' requests. */
    #[DataProvider('finderProvider')]
    public function testIncludesUserWithDeniedTransferRequest(string $functionName, int $threshold, callable $fixtureBuilder): void
    {
        $testUserId  = $this->createTestUser($fixtureBuilder($this->daysAgo($threshold + 10)));
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

        $results = $functionName($this->db, $threshold);
        $ids     = $this->idsFrom($results);

        $this->assertContains((string) $testUserId, $ids, 'User with only a denied transfer request must still be eligible for cleanup');
    }

    // -------------------------------------------------------------------------
    // Shared threshold boundary tests
    // -------------------------------------------------------------------------

    /** Inclusive boundary: DATEDIFF = threshold is included. */
    #[DataProvider('finderProvider')]
    public function testThresholdBoundaryExactMatch(string $functionName, int $threshold, callable $fixtureBuilder): void
    {
        $userId = $this->createTestUser($fixtureBuilder($this->daysAgo($threshold)));

        $results = $functionName($this->db, $threshold);
        $ids     = $this->idsFrom($results);

        $this->assertContains((string) $userId, $ids, 'User whose inactivity equals the threshold must be included');
    }

    #[DataProvider('finderProvider')]
    public function testThresholdBoundaryOneDayShort(string $functionName, int $threshold, callable $fixtureBuilder): void
    {
        $userId = $this->createTestUser($fixtureBuilder($this->daysAgo($threshold - 1)));

        $results = $functionName($this->db, $threshold);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, 'User whose inactivity is one day below the threshold must be excluded');
    }

    // -------------------------------------------------------------------------
    // findVerifiedOwnerlessAccounts()-only tests
    // -------------------------------------------------------------------------

    public function testFindsVerifiedOwnerlessAccountWithNullLastLogin(): void
    {
        $userId = $this->createTestUser(['email_verified' => 1]);

        $results = findVerifiedOwnerlessAccounts($this->db, 365);
        $ids     = $this->idsFrom($results);

        $this->assertContains((string) $userId, $ids, 'Verified user with NULL last_login should appear in results');
    }

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

    public function testExcludesUnverifiedAccount(): void
    {
        $userId = $this->createTestUser(['email_verified' => 0]);

        $results = findVerifiedOwnerlessAccounts($this->db, 365);
        $ids     = $this->idsFrom($results);

        $this->assertNotContains((string) $userId, $ids, 'Unverified user must be excluded from the verified-account query');
    }

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

    // -------------------------------------------------------------------------
    // findUnverifiedOwnerlessAccounts()-only tests
    // -------------------------------------------------------------------------

    public function testFindsUnverifiedOwnerlessAccount(): void
    {
        $userId = $this->createTestUser(['join_date' => $this->daysAgo(31)]);

        $results = findUnverifiedOwnerlessAccounts($this->db, 30);
        $ids     = $this->idsFrom($results);

        $this->assertContains((string) $userId, $ids, 'User with no cars and old enough join date should appear in results');
    }

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
}
