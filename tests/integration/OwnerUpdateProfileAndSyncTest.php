<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1891: one live-DB call to Owner::updateProfileAndSync() persists the
 * users/profiles write and cascades it to every car. Wiring is unit-tested.
 */
#[Group('integration')]
#[Group('owner')]
final class OwnerUpdateProfileAndSyncTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    public function testUpdateProfileAndSyncPersistsFieldsAndCascadesToAllOwnedCars(): void
    {
        $userId = $this->createTestUser([
            'fname' => 'Original',
            'lname' => 'Name',
        ], withProfile: true);
        $carId1 = $this->createTestCar($userId, ['city' => 'StaleCity']);
        $carId2 = $this->createTestCar($userId, ['city' => 'StaleCity']);

        $owner = new Owner($userId);
        $this->assertNotNull($owner->data(), 'Owner must load successfully before calling updateProfileAndSync()');

        $result = $owner->updateProfileAndSync([
            'fname'   => 'Synced',
            'lname'   => 'Owner',
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'website' => 'https://example.com',
            'lat'     => '45.5231',
            'lon'     => '-122.6765',
        ]);

        $this->assertTrue($result->isCompleteSuccess(), 'A clean two-car sync must report complete success');
        $this->assertSame([$carId1, $carId2], $result->updated);

        // users/profiles: the update() half of the chain committed.
        $userRow = $this->db->query('SELECT fname, lname FROM users WHERE id = ?', [$userId])->first();
        $this->assertSame('Synced', $userRow->fname);
        $this->assertSame('Owner', $userRow->lname);

        $profileRow = $this->db->query(
            'SELECT city, state, country, website, lat, lon FROM profiles WHERE user_id = ?',
            [$userId]
        )->first();
        $this->assertSame('Portland', $profileRow->city);
        $this->assertSame('Oregon', $profileRow->state);
        $this->assertSame('United States', $profileRow->country);
        $this->assertSame('https://example.com', $profileRow->website);
        $this->assertEqualsWithDelta(45.5231, (float) $profileRow->lat, 0.001);
        $this->assertEqualsWithDelta(-122.6765, (float) $profileRow->lon, 0.001);

        // cars: the syncOwnerFieldsToCars() half cascaded to every car.
        foreach ([$carId1, $carId2] as $carId) {
            $carRow = $this->db->query(
                'SELECT fname, lname, city, state, country, website, lat, lon FROM cars WHERE id = ?',
                [$carId]
            )->first();
            $this->assertNotNull($carRow, "Car {$carId} must exist");
            $this->assertSame('Synced', $carRow->fname, "Car {$carId} fname must reflect the just-written owner profile");
            $this->assertSame('Owner', $carRow->lname);
            $this->assertSame('Portland', $carRow->city, "Car {$carId} must no longer hold the stale pre-sync city");
            $this->assertSame('Oregon', $carRow->state);
            $this->assertSame('United States', $carRow->country);
            $this->assertSame('https://example.com', $carRow->website);
            $this->assertEqualsWithDelta(45.5231, (float) $carRow->lat, 0.001);
            $this->assertEqualsWithDelta(-122.6765, (float) $carRow->lon, 0.001);
        }
    }

    /**
     * #1891: Owner::update() drops empty values, so user_settings.php clears
     * the website with a direct write plus syncOwnerFieldsToCars(). This
     * runs the same two calls.
     */
    public function testClearedWebsiteReachesCarsViaDirectWriteThenSync(): void
    {
        $userId = $this->createTestUser([], withProfile: true);
        $carId = $this->createTestCar($userId, ['website' => 'https://stale.example.com']);

        // Mirrors the page's own direct write for the empty-website case.
        $this->db->update('profiles', ['user_id' => $userId], ['website' => '']);

        $owner = new Owner($userId);
        $this->assertNotNull($owner->data(), 'Owner must load successfully before calling syncOwnerFieldsToCars()');

        $result = $owner->syncOwnerFieldsToCars();

        $this->assertTrue($result->isCompleteSuccess());
        $this->assertSame([$carId], $result->updated);

        $carRow = $this->db->query('SELECT website FROM cars WHERE id = ?', [$carId])->first();
        $this->assertSame('', (string) $carRow->website, 'The cleared website must reach the car, not stay stale');
    }
}
