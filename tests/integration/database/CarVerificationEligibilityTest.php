<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for CarRepository::findVerificationEligible() (issue #1155).
 *
 * The existing unit tests for this method only string-match the SQL against a
 * mocked DB — nothing runs it against real, populated data to confirm the
 * WHERE clause actually includes/excludes the correct rows. These tests
 * create one real `cars` row per condition and assert on that row's presence
 * (or absence) in the result set, rather than on total row counts, since the
 * shared integration DB may contain other rows that would make count-based
 * assertions flaky.
 *
 * Eligibility (per CarRepository::findVerificationEligible()):
 *   solddate IS NULL
 *   AND email_bounced = 0
 *   AND email IS NOT NULL AND email != ''
 *   AND NOT (
 *     (last_verified IS NOT NULL AND last_verified >= NOW() - INTERVAL 1 YEAR)
 *     OR owner_last_updated >= NOW() - INTERVAL 1 YEAR
 *   )
 *
 * owner_last_updated is NOT NULL by schema (issue #1953) — there is no
 * COALESCE(owner_last_updated, mtime) fallback. mtime is deliberately excluded
 * from the freshness expression entirely: it is ON UPDATE CURRENT_TIMESTAMP, so
 * MySQL bumps it on any UPDATE that changes a value, including an unrelated
 * owner-profile sync.
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarVerificationEligibilityTest extends IntegrationTestCase
{
    private int $testUserId;
    private CarRepository $repo;

    /** More than 1 year ago — satisfies both the last_verified and owner_last_updated staleness checks. */
    private const STALE_DATE = '-3 years';

    /** Within the last 1 year — fails the staleness checks. */
    private const RECENT_DATE = '-1 day';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        foreach (['owner_last_updated', 'email_bounced', 'last_verified'] as $column) {
            $this->assertColumnExists('cars', $column);
        }

        $this->testUserId = $this->createTestUser();
        $this->loginAsTestUser($this->testUserId);
        $this->repo = new CarRepository($this->db);
    }

    /**
     * Skips the test (rather than failing with a DB error) if the given
     * column is not yet present — mirrors CarVerificationColumnsHistTest's
     * pattern for a migration that may not have run yet.
     */
    private function assertColumnExists(string $table, string $column): void
    {
        $check = $this->db->query(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = ?
               AND COLUMN_NAME  = ?
             LIMIT 1",
            [$table, $column]
        );

        if (!$check || $check->count() === 0) {
            $this->markTestSkipped(
                "{$table}.{$column} not yet available — run `composer migrate`"
            );
        }
    }

    /** @return int[] */
    private function eligibleIds(): array
    {
        $results = $this->repo->findVerificationEligible(1000, 0);
        return array_map(static fn ($row) => (int) $row->id, $results);
    }

    private function staleDate(): string
    {
        return date('Y-m-d H:i:s', strtotime(self::STALE_DATE));
    }

    private function recentDate(): string
    {
        return date('Y-m-d H:i:s', strtotime(self::RECENT_DATE));
    }

    #[Group('fast')]
    public function testSoldCarIsExcluded(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'sold-owner@example.com',
            'email_bounced'      => 0,
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => date('Y-m-d', strtotime(self::STALE_DATE)),
        ]);

        $this->assertNotContains(
            $carId,
            $this->eligibleIds(),
            'A car with a non-null solddate must be excluded from verification eligibility'
        );
    }

    #[Group('fast')]
    public function testBouncedEmailCarIsExcluded(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'bounced-owner@example.com',
            'email_bounced'      => 1,
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ]);

        $this->assertNotContains(
            $carId,
            $this->eligibleIds(),
            'A car with email_bounced = 1 must be excluded from verification eligibility'
        );
    }

    #[Group('fast')]
    public function testEmptyEmailCarIsExcluded(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => '',
            'email_bounced'      => 0,
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ]);

        $this->assertNotContains(
            $carId,
            $this->eligibleIds(),
            'A car with an empty email must be excluded from verification eligibility'
        );
    }

    #[Group('fast')]
    public function testNullEmailCarIsExcluded(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => null,
            'email_bounced'      => 0,
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ]);

        $this->assertNotContains(
            $carId,
            $this->eligibleIds(),
            'A car with a NULL email must be excluded from verification eligibility'
        );
    }

    /**
     * The specific fix made during this issue's implementation: a car that has
     * never been verified (last_verified IS NULL) must still be eligible, as
     * long as it is otherwise stale — NULL must not silently exclude the row.
     */
    #[Group('fast')]
    public function testNeverVerifiedCarIsEligible(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'never-verified-owner@example.com',
            'email_bounced'      => 0,
            'last_verified'      => null,
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ]);

        $this->assertContains(
            $carId,
            $this->eligibleIds(),
            'A car with last_verified IS NULL and a stale owner_last_updated must be eligible for verification'
        );
    }

    #[Group('fast')]
    public function testRecentlyVerifiedCarIsExcluded(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'recently-verified-owner@example.com',
            'email_bounced'      => 0,
            'last_verified'      => $this->recentDate(),
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ]);

        $this->assertNotContains(
            $carId,
            $this->eligibleIds(),
            'A car verified within the last 1 year must be excluded from verification eligibility'
        );
    }

    /** The fully-eligible case: stale last_verified AND stale owner_last_updated. */
    #[Group('fast')]
    public function testStaleCarIsEligible(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'stale-owner@example.com',
            'email_bounced'      => 0,
            'last_verified'      => $this->staleDate(),
            'owner_last_updated' => $this->staleDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ]);

        $this->assertContains(
            $carId,
            $this->eligibleIds(),
            'A car with both last_verified and owner_last_updated more than 1 year old must be eligible'
        );
    }

    /**
     * Confirms the owner_last_updated freshness check gates eligibility
     * independent of last_verified — a car that was never verified but was
     * recently touched by its owner must NOT be considered eligible.
     */
    #[Group('fast')]
    public function testRecentOwnerUpdateExcludesEvenWhenNeverVerified(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'recent-owner-update@example.com',
            'email_bounced'      => 0,
            'last_verified'      => null,
            'owner_last_updated' => $this->recentDate(),
            'mtime'              => $this->staleDate(),
            'solddate'           => null,
        ]);

        $this->assertNotContains(
            $carId,
            $this->eligibleIds(),
            'A car with last_verified IS NULL but a recent owner_last_updated must be excluded'
        );
    }

    /**
     * #1953: cars.owner_last_updated is NOT NULL by schema, so a fixture
     * attempting to insert NULL must be rejected by the database rather than
     * silently falling back to mtime. This replaces the two COALESCE-fallback
     * tests this class used to carry
     * (testNullOwnerLastUpdatedFallsBackToStaleMtimeAndIsEligible and
     * testNullOwnerLastUpdatedFallsBackToRecentMtimeAndIsExcluded), which
     * asserted the COALESCE(owner_last_updated, mtime) fallback worked — i.e.
     * they pinned the #1953 defect as intended behavior. A green suite
     * therefore proved nothing about the actual bug: Owner::syncOwnerFieldsToCars()
     * bumping mtime on an unrelated profile edit could silently reset the
     * verification clock for any car with a NULL owner_last_updated.
     *
     * Skipped, not failed, when the #1953 migration hasn't run locally: the
     * column is still nullable pre-migration, so the INSERT this test expects
     * to fail would instead succeed. Mirrors CarVerificationTimestampMigrationTest's
     * skip-guard pattern rather than assertColumnExists() (existence, not
     * nullability, is what that helper checks).
     */
    #[Group('fast')]
    public function testColumnIsNotNullSoNullOwnerLastUpdatedFixtureIsRejected(): void
    {
        $row = $this->db->query(
            "SELECT IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cars'
               AND COLUMN_NAME  = 'owner_last_updated'
             LIMIT 1"
        )->first();

        if (!$row || $row->IS_NULLABLE !== 'NO') {
            $this->markTestSkipped(
                'Migration 20260905172137 has not been applied — cars.owner_last_updated is ' .
                'still nullable. Run: composer migrate'
            );
        }

        $chassis = 'NULLREJ' . substr((string) uniqid(), -8);

        $fields = [
            'user_id'            => $this->testUserId,
            'chassis'            => $chassis,
            'email'              => 'null-owner-updated-rejected@example.com',
            'email_bounced'      => 0,
            'last_verified'      => null,
            'solddate'           => null,
        ];

        // Control: the same row shape WITH a non-null owner_last_updated must
        // insert cleanly. Without this, the assertion below would pass just as
        // well if the insert failed for an unrelated reason (a missing required
        // column, a chassis collision, schema drift) — leaving the NOT NULL
        // constraint itself untested while still reporting green. createTestCar()
        // throws on failure and registers the row for teardown.
        $controlId = $this->createTestCar($this->testUserId, [
            'email'              => 'null-owner-updated-control@example.com',
            'email_bounced'      => 0,
            'last_verified'      => null,
            'owner_last_updated' => date('Y-m-d H:i:s'),
            'solddate'           => null,
        ]);

        $this->assertGreaterThan(
            0,
            $controlId,
            'Control insert must succeed — otherwise the NULL rejection below proves nothing '
            . 'about the NOT NULL constraint specifically'
        );

        $insertResult = $this->db->insert('cars', array_merge($fields, [
            'owner_last_updated' => null,
        ]));

        $this->assertFalse(
            $insertResult,
            'Inserting a NULL owner_last_updated must fail — the column is NOT NULL by schema'
        );

        // And it must fail without creating the row, confirming the constraint
        // rejected the write rather than the driver merely reporting an error.
        $orphan = $this->db->query('SELECT id FROM cars WHERE chassis = ?', [$chassis]);
        $this->assertSame(
            0,
            $orphan->count(),
            'A rejected NULL owner_last_updated insert must leave no row behind'
        );
    }
}
