<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;

/**
 * Admin owner-management endpoints (process-owner-search/update/sync-location).
 * They exit via send(), so tests drive the Owner calls they make. Guard pins:
 * tests/unit/security/AdminAjaxGuardTest.php. Gap: these three endpoints have
 * no HTTP-level guard test.
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
     * Two-word term uses the exact-name UNION branch; guards placeholder order
     * in the 14-placeholder statement.
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
     * process-owner-update.php must sync the change to the owner's cars, not
     * only the profile. Limit: replays the endpoint's call sequence, so it
     * would not see the endpoint drop or reorder a call.
     */
    public function testAdminOwnerUpdateSequenceSyncsToOwnedCars(): void
    {
        $userId = $this->createTestUser(['fname' => 'Original']);
        $this->createTestProfile($userId, ['city' => 'Salem']);
        $carId = $this->createTestCar($userId, ['city' => 'Salem']);

        // Same order as process-owner-update.php, on a freshly reloaded Owner.
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
