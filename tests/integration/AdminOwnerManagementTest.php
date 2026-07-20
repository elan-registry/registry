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
 * cannot be included in unit/integration tests directly. This class uses:
 *
 * - Source-inspection for auth-guard and CSRF-guard contracts (pins that
 *   the guard code is present in each file).
 * - Behavioral integration tests for the happy-path logic via the underlying
 *   Owner class and real database fixtures.
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

    /**
     * Seed a valid CSRF token into the session and return it.
     * The token is a 64-char hex string matching Token::check() format requirements.
     */
    private function seedCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['token'] = $token;
        return $token;
    }

    // =========================================================================
    // Auth-guard and CSRF-guard source-inspection tests
    // =========================================================================

    /**
     * requireAdminAjax() in custom_functions.php must contain both the admin
     * check and CSRF validation so the chain of trust is intact.
     */
    public function testRequireAdminAjaxHelperContainsSecurityChecks(): void
    {
        $content = file_get_contents(__DIR__ . '/../../usersc/includes/custom_functions.php');
        $this->assertNotFalse($content, 'Could not read custom_functions.php');
        $this->assertStringContainsString(
            'isRegistryAdmin',
            $content,
            'requireAdminAjax() in custom_functions.php must call isRegistryAdmin()'
        );
        $this->assertStringContainsString(
            'Token::check(',
            $content,
            'requireAdminAjax() in custom_functions.php must call Token::check() for CSRF validation'
        );
    }

    /**
     * process-owner-search.php must delegate auth+CSRF guard to requireAdminAjax().
     */
    public function testOwnerSearchEndpointRequiresRegistryAdmin(): void
    {
        $content = file_get_contents(__DIR__ . '/../../app/admin/includes/process-owner-search.php');
        $this->assertNotFalse($content, 'Could not read process-owner-search.php');
        $this->assertStringContainsString(
            'requireAdminAjax(',
            $content,
            'process-owner-search.php must call requireAdminAjax() for auth+CSRF guard'
        );
    }

    /**
     * process-owner-update.php must delegate auth+CSRF guard to requireAdminAjax().
     */
    public function testOwnerUpdateEndpointRequiresRegistryAdmin(): void
    {
        $content = file_get_contents(__DIR__ . '/../../app/admin/includes/process-owner-update.php');
        $this->assertNotFalse($content, 'Could not read process-owner-update.php');
        $this->assertStringContainsString(
            'requireAdminAjax(',
            $content,
            'process-owner-update.php must call requireAdminAjax() for auth+CSRF guard'
        );
    }

    /**
     * process-owner-sync-location.php must delegate auth+CSRF guard to requireAdminAjax().
     */
    public function testOwnerSyncLocationEndpointRequiresRegistryAdmin(): void
    {
        $content = file_get_contents(__DIR__ . '/../../app/admin/includes/process-owner-sync-location.php');
        $this->assertNotFalse($content, 'Could not read process-owner-sync-location.php');
        $this->assertStringContainsString(
            'requireAdminAjax(',
            $content,
            'process-owner-sync-location.php must call requireAdminAjax() for auth+CSRF guard'
        );
    }

    /**
     * load-owner-info.php must delegate auth+CSRF guard to requireAdminAjax().
     */
    public function testLoadOwnerInfoEndpointHasAdminGuard(): void
    {
        $content = file_get_contents(__DIR__ . '/../../app/admin/includes/load-owner-info.php');
        $this->assertNotFalse($content, 'Could not read load-owner-info.php');
        $this->assertStringContainsString(
            'requireAdminAjax(',
            $content,
            'load-owner-info.php must call requireAdminAjax() for auth+CSRF guard'
        );
    }

    /**
     * load-owner-profile.php must delegate auth+CSRF guard to requireAdminAjax().
     */
    public function testLoadOwnerProfileEndpointHasAdminGuard(): void
    {
        $content = file_get_contents(__DIR__ . '/../../app/admin/includes/load-owner-profile.php');
        $this->assertNotFalse($content, 'Could not read load-owner-profile.php');
        $this->assertStringContainsString(
            'requireAdminAjax(',
            $content,
            'load-owner-profile.php must call requireAdminAjax() for auth+CSRF guard'
        );
    }

    /**
     * process-car-details.php must delegate auth+CSRF guard to requireAdminAjax().
     */
    public function testProcessCarDetailsEndpointHasAdminGuard(): void
    {
        $content = file_get_contents(__DIR__ . '/../../app/admin/includes/process-car-details.php');
        $this->assertNotFalse($content, 'Could not read process-car-details.php');
        $this->assertStringContainsString(
            'requireAdminAjax(',
            $content,
            'process-car-details.php must call requireAdminAjax() for auth+CSRF guard'
        );
    }

    /**
     * process-transfer-approve.php must delegate auth+CSRF guard to requireAdminAjax().
     */
    public function testProcessTransferApproveEndpointHasAdminGuard(): void
    {
        $content = file_get_contents(__DIR__ . '/../../app/admin/includes/process-transfer-approve.php');
        $this->assertNotFalse($content, 'Could not read process-transfer-approve.php');
        $this->assertStringContainsString(
            'requireAdminAjax(',
            $content,
            'process-transfer-approve.php must call requireAdminAjax() for auth+CSRF guard'
        );
    }

    /**
     * process-transfer-deny.php must delegate auth+CSRF guard to requireAdminAjax().
     */
    public function testProcessTransferDenyEndpointHasAdminGuard(): void
    {
        $content = file_get_contents(__DIR__ . '/../../app/admin/includes/process-transfer-deny.php');
        $this->assertNotFalse($content, 'Could not read process-transfer-deny.php');
        $this->assertStringContainsString(
            'requireAdminAjax(',
            $content,
            'process-transfer-deny.php must call requireAdminAjax() for auth+CSRF guard'
        );
    }

    /**
     * process-user-details.php must delegate auth+CSRF guard to requireAdminAjax().
     */
    public function testProcessUserDetailsEndpointHasAdminGuard(): void
    {
        $content = file_get_contents(__DIR__ . '/../../app/admin/includes/process-user-details.php');
        $this->assertNotFalse($content, 'Could not read process-user-details.php');
        $this->assertStringContainsString(
            'requireAdminAjax(',
            $content,
            'process-user-details.php must call requireAdminAjax() for auth+CSRF guard'
        );
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

    /**
     * Happy path for owner-sync-location: Owner::syncLocationToCars()
     * copies the owner's lat/lon to all owned cars and returns the count of
     * cars updated.
     *
     * Validates the sync logic used by process-owner-sync-location.php.
     */
    public function testSyncLocationToCarsCopiesCoordinatesToOwnedCar(): void
    {
        $userId = $this->createTestUser();
        $this->createTestProfile($userId, [
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 45.5231,
            'lon'     => -122.6765,
        ]);
        $carId = $this->createTestCar($userId);

        // Load owner with full profile data (lat/lon present)
        $owner = new Owner($userId);
        $this->assertNotNull($owner->data(), 'Owner must load successfully after profile creation');

        $carsUpdated = $owner->syncLocationToCars();

        $this->assertSame(1, $carsUpdated, 'syncLocationToCars() must update the one owned car');

        $car = $this->db->query("SELECT lat, lon FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertEqualsWithDelta(45.5231, (float) $car->lat, 0.001, 'Car lat must match owner lat');
        $this->assertEqualsWithDelta(-122.6765, (float) $car->lon, 0.001, 'Car lon must match owner lon');
    }
}
