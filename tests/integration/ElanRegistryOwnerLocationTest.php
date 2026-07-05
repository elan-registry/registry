<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Exceptions\OwnerValidationException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for ElanRegistryOwner location methods
 *
 * Covers updateLocation() with and without coordinates, verifying that
 * location saves succeed and that missing coordinates are handled correctly
 * rather than triggering a geocoding fallback.
 */
#[Group('Integration')]
class ElanRegistryOwnerLocationTest extends IntegrationTestCase
{
    private int $testUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->testUserId = $this->createTestUser();

        $this->db->insert('profiles', [
            'user_id' => $this->testUserId,
            'bio'     => 'test profile for location tests',
            'city'    => 'InitialCity',
            'state'   => 'InitialState',
            'country' => 'InitialCountry',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected && $this->testUserId > 0) {
            $this->db->query("DELETE FROM profiles WHERE user_id = ?", [$this->testUserId]);
        }
        parent::tearDown();
    }

    /**
     * updateLocation() with all fields including coordinates should persist lat/lon
     */
    public function testUpdateLocationWithCoordinates(): void
    {
        $owner = new ElanRegistryOwner($this->testUserId);

        $result = $owner->updateLocation([
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => 45.5231,
            'lon'     => -122.6765,
        ]);

        $this->assertTrue($result, 'updateLocation() should return true on success');
        $this->assertEquals('Portland', $owner->data()->city);
        $this->assertEquals('Oregon', $owner->data()->state);
        $this->assertEquals('United States', $owner->data()->country);
        $this->assertEqualsWithDelta(45.5231, (float)$owner->data()->lat, 0.001, 'lat must round-trip through MySQL float within 0.001');
        $this->assertEqualsWithDelta(-122.6765, (float)$owner->data()->lon, 0.001, 'lon must round-trip through MySQL float within 0.001');
    }

    /**
     * updateLocation() without lat/lon should still succeed and persist city/state/country
     */
    public function testUpdateLocationWithoutCoordinatesSucceeds(): void
    {
        $owner = new ElanRegistryOwner($this->testUserId);

        $result = $owner->updateLocation([
            'city'    => 'London',
            'state'   => 'England',
            'country' => 'United Kingdom',
        ]);

        $this->assertTrue($result, 'updateLocation() should succeed without coordinates');
        $this->assertEquals('London', $owner->data()->city);
        $this->assertEquals('England', $owner->data()->state);
        $this->assertEquals('United Kingdom', $owner->data()->country);
    }

    /**
     * updateLocation() with missing required field should throw OwnerValidationException
     */
    public function testUpdateLocationThrowsOnMissingCountry(): void
    {
        $owner = new ElanRegistryOwner($this->testUserId);

        $this->expectException(OwnerValidationException::class);
        $this->expectExceptionMessage("Required location field 'country' is missing");

        $owner->updateLocation([
            'city'  => 'Portland',
            'state' => 'Oregon',
        ]);
    }

    /**
     * updateLocation() on unloaded owner should throw OwnerValidationException
     */
    public function testUpdateLocationThrowsWhenOwnerNotLoaded(): void
    {
        $owner = new ElanRegistryOwner();

        $this->expectException(OwnerValidationException::class);
        $this->expectExceptionMessage('Owner data not loaded');

        $owner->updateLocation([
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
        ]);
    }
}
