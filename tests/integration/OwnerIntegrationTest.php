<?php
declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Owner;

/**
 * Integration tests for Owner class
 *
 * These tests require the full application bootstrap and real database connection.
 * They test Owner functionality with actual database data and global functions.
 * Owner's pure field-validation tests (no DB) live in tests/unit/OwnerValidationTest.php.
 */
class OwnerIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /**
     * Test owner loading with valid ID
     */
    public function testFindWithValidUser(): void
    {
        $userId = $this->createTestUser();
        $owner = new Owner();
        $result = $owner->find($userId);

        $this->assertTrue($result);
        $this->assertNotNull($owner->data());
        $this->assertEquals($userId, $owner->data()->id);
    }

    /**
     * Test getting cars owned by owner
     */
    public function testGetCarsOwned(): void
    {
        $userId = $this->createTestUser();
        $this->createTestCar($userId);
        $owner = new Owner($userId);

        $ownedCars = $owner->getCarsOwned();
        $this->assertIsArray($ownedCars);
        $this->assertGreaterThan(0, count($ownedCars));

        // Check that all returned cars belong to this user
        foreach ($ownedCars as $carData) {
            $this->assertEquals($userId, $carData->user_id);
        }
    }

    public function testCompletenessAcceptsZeroCoordinates(): void
    {
        $userId = $this->createTestUser();
        $insertResult = $this->db->insert('profiles', [
            'user_id' => $userId,
            'city' => 'Equator', 'state' => 'Meridian', 'country' => 'Ocean',
            'lat' => 0, 'lon' => 0,
        ]);
        $this->assertTrue((bool) $insertResult, 'Test fixture: profiles insert must succeed');
        try {
            $owner = new Owner($userId);
            $missing = $owner->validateProfileCompleteness();
            $this->assertNotContains('Location Coordinates', $missing,
                'lat=0 and lon=0 are valid coordinates and must not be flagged as missing');
        } finally {
            $this->db->query("DELETE FROM profiles WHERE user_id = ?", [$userId]);
        }
    }

    public function testLatLonRoundTripThroughUpdate(): void
    {
        $userId = $this->createTestUser();
        $insertResult = $this->db->insert('profiles', [
            'user_id' => $userId,
            'city' => 'London', 'state' => 'England', 'country' => 'UK',
            'lat' => 1.0, 'lon' => 1.0,
        ]);
        $this->assertTrue((bool) $insertResult, 'Test fixture: profiles insert must succeed');
        try {
            $owner = new Owner($userId);
            $owner->update([
                'id' => $userId,
                'lat' => '0',
                'lon' => '0',
            ]);
            // update() calls find() internally — data is already reloaded from DB
            $this->assertSame(0.0, (float) $owner->data()->lat,
                'lat=0 must survive a MySQL write and read-back');
            $this->assertSame(0.0, (float) $owner->data()->lon,
                'lon=0 must survive a MySQL write and read-back');
        } finally {
            $this->db->query("DELETE FROM profiles WHERE user_id = ?", [$userId]);
        }
    }

    public function testActiveAndPermissionsDoNotReachDbViaUpdate(): void
    {
        $userId = $this->createTestUser();
        $this->db->insert('profiles', [
            'user_id' => $userId,
            'city' => 'BeforeCity', 'state' => '', 'country' => '',
            'lat' => 0.0, 'lon' => 0.0,
        ]);
        try {
            $before = $this->db->query("SELECT active, permissions FROM users WHERE id = ?", [$userId])
                ->first();
            $owner = new Owner($userId);
            $owner->update([
                'id'          => $userId,
                'active'      => '0',
                'permissions' => '3',
                'city'        => 'AfterCity',
            ]);
            $after = $this->db->query("SELECT active, permissions FROM users WHERE id = ?", [$userId])
                ->first();
            // Confirm the update ran (city changed) so the privilege assertions aren't vacuously true
            $this->assertSame('AfterCity', $owner->data()->city,
                'Owner::update() must persist legitimate fields');
            $this->assertSame((string) $before->active, (string) $after->active,
                'Owner::update() must not modify the active column');
            $this->assertSame((string) $before->permissions, (string) $after->permissions,
                'Owner::update() must not modify the permissions column');
        } finally {
            $this->db->query("DELETE FROM profiles WHERE user_id = ?", [$userId]);
        }
    }

    public function testActiveEscalationDoesNotReachDbViaUpdate(): void
    {
        // Deactivated-user escalation: attacker passes active=1 to re-activate their account
        $userId = $this->createTestUser();
        $this->db->query("UPDATE users SET active = 0 WHERE id = ?", [$userId]);
        $this->db->insert('profiles', [
            'user_id' => $userId,
            'city' => 'BeforeCity', 'state' => '', 'country' => '',
            'lat' => 0.0, 'lon' => 0.0,
        ]);
        try {
            $owner = new Owner($userId);
            $owner->update([
                'id'     => $userId,
                'active' => '1',
                'city'   => 'AfterCity',
            ]);
            $after = $this->db->query("SELECT active FROM users WHERE id = ?", [$userId])->first();
            $this->assertSame('AfterCity', $owner->data()->city,
                'Owner::update() must persist legitimate fields');
            $this->assertSame('0', (string) $after->active,
                'Owner::update() must not allow escalation of active from 0 to 1');
        } finally {
            $this->db->query("DELETE FROM profiles WHERE user_id = ?", [$userId]);
        }
    }
}
