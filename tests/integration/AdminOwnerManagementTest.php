<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests for the three admin owner-management AJAX endpoints.
 *
 * The endpoint files (process-owner-search.php, process-owner-update.php,
 * process-owner-sync-location.php) call `send()` (which exits) so they
 * cannot be included in unit/integration tests directly. This class tests the
 * happy-path logic via the underlying Owner class and real database fixtures;
 * the endpoints' requireAdminAjax() auth/CSRF guards are not exercised here.
 *
 * The guard pins (every app/admin/includes/{process,load}-*.php endpoint calls
 * requireAdminAjax() or securePage(), and requireAdminAjax() performs the
 * admin-role and CSRF checks) live in tests/unit/security/AdminAjaxGuardTest.php.
 * HTTP-level rejection is tested only for process-user-details.php
 * (tests/playwright/ajax-endpoints.spec.js:814 unauthenticated,
 * tests/playwright/e2e/ajax-endpoints-non-admin.spec.js:34 non-admin); the
 * three owner-management endpoints have no HTTP-level guard test.
 */
#[Group('integration')]
#[Group('admin')]
final class AdminOwnerManagementTest extends IntegrationTestCase
{
    /** @var int[] Profile IDs to clean up in tearDown */
    private array $createdProfileIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdProfileIds as $profileId) {
            try {
                $this->db->query("DELETE FROM profiles WHERE id = ?", [$profileId]);
            } catch (\Throwable $e) {
                // Ignore cleanup errors
            }
        }
        $this->createdProfileIds = [];
        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a profile row for a test user with optional lat/lon coordinates.
     * Tracked for cleanup in tearDown().
     */
    private function createTestProfile(int $userId, array $overrides = []): void
    {
        $defaults = [
            'user_id' => $userId,
            'bio'     => '',
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => null,
            'lon'     => null,
        ];

        $this->db->insert('profiles', array_merge($defaults, $overrides));

        $row = $this->db->query("SELECT id FROM profiles WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$userId])->first();
        if (!$row) {
            throw new \RuntimeException("createTestProfile: insert failed for user_id={$userId}");
        }
        $this->createdProfileIds[] = (int) $row->id;
    }

    // =========================================================================
    // Happy-path behavioral tests via Owner
    // =========================================================================

    /**
     * Happy path for owner-search: a test user created in the DB is returned
     * by $owner->searchOwners() when searched by first name.
     *
     * Validates that the search logic used by process-owner-search.php finds
     * real owners from the database.
     */
    public function testSearchOwnersReturnsMatchingOwner(): void
    {
        $userId = $this->createTestUser(['fname' => 'SearchHappy', 'lname' => 'PathTest']);

        $results = (new Owner())->searchOwners('SearchHappy', 25);

        $this->assertIsArray($results);
        $this->assertNotEmpty($results, 'searchOwners() must return the newly created test user');

        $ids = array_column((array) $results, 'id');
        $this->assertContains((string) $userId, array_map('strval', $ids),
            'searchOwners() must include the test user in results'
        );
    }

    /**
     * Multi-word UNION path: a two-word search term ('Greg Surcouf') resolves
     * via the exact-name UNION branch and returns the matching owner.
     *
     * Validates the three-UNION SQL path in searchOwners() and guards against
     * parameter-ordering regressions in the 14-placeholder prepared statement.
     */
    public function testSearchOwnersReturnsMatchingOwnerForMultiWordQuery(): void
    {
        $userId = $this->createTestUser(['fname' => 'Greg', 'lname' => 'Surcouf']);

        $results = (new Owner())->searchOwners('Greg Surcouf', 25);

        $this->assertIsArray($results);
        $this->assertNotEmpty($results, 'Multi-word searchOwners() must return the test user');

        $ids = array_column((array) $results, 'id');
        $this->assertContains((string) $userId, array_map('strval', $ids),
            'Multi-word search must find the user by exact first+last name'
        );
    }

    /**
     * Happy path for owner-update: Owner::update() persists a changed city to
     * the profiles table. CSRF is validated by the caller (HTTP layer) before
     * update() is called.
     *
     * Validates the DB write path used by process-owner-update.php.
     */
    public function testUpdateOwnerProfilePersistsToDatabase(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, ['city' => 'Salem']);

        $owner = new Owner($userId);
        $result = $owner->update([
            'id'   => $userId,
            'city' => 'Eugene',
        ]);

        $this->assertTrue($result, 'Owner::update() must return true on success');

        $row = $this->db->query(
            "SELECT city FROM profiles WHERE user_id = ?",
            [$userId]
        )->first();

        $this->assertNotNull($row, 'profiles row must exist after update');
        $this->assertSame('Eugene', $row->city, 'City must be persisted to the profiles table');
    }

    // Happy path for owner-sync (formerly owner-sync-location) is now covered
    // by the nine-field suite in OwnerSyncOwnerFieldsToCarsTest.php (#1873),
    // which subsumes this test's single lat/lon assertion.

    /**
     * process-owner-update.php's actual sequence — Owner::update() followed by
     * syncOwnerFieldsToCars() — must propagate the change to the owner's cars,
     * not just the profiles table.
     *
     * process-owner-update.php cannot be exercised directly (every path ends
     * in exit via ApiResponse::send()), so this test drives the same two
     * calls the endpoint makes, in the same order, against a real car. Before
     * this fix, the endpoint called only Owner::update() — an admin editing a
     * member's profile here left every car stale until the owner separately
     * triggered a sync, the one owner-contact-field write path this milestone
     * otherwise missed (user_settings.php, the email-verification hook, car
     * edit, and the standalone admin sync endpoint all already synced).
     *
     * Note: this re-implements the endpoint's call sequence rather than
     * invoking process-owner-update.php, so it runs the real Owner::update()
     * and syncOwnerFieldsToCars() but would not notice the endpoint itself
     * dropping or reordering either call.
     */
    public function testAdminOwnerUpdateSequenceSyncsToOwnedCars(): void
    {
        $userId = $this->createTestUser(['fname' => 'Original']);
        $this->createTestProfile($userId, ['city' => 'Salem']);
        $carId = $this->createTestCar($userId, ['city' => 'Salem']);

        // Mirrors process-owner-update.php: Owner::update() first, then
        // syncOwnerFieldsToCars() on a freshly-reloaded Owner.
        $owner = new Owner($userId);
        $owner->update(['id' => $userId, 'fname' => 'AdminEdited', 'city' => 'Eugene']);
        $owner = new Owner($userId);
        $syncResult = $owner->syncOwnerFieldsToCars();

        $this->assertTrue($syncResult->isCompleteSuccess());
        $this->assertContains($carId, $syncResult->updated);

        $car = $this->db->query("SELECT fname, city FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame(
            'AdminEdited',
            $car->fname,
            "An admin editing a member's profile must propagate to the member's cars, "
                . 'the same as every other owner-contact-field write path'
        );
        $this->assertSame('Eugene', $car->city);
    }
}
