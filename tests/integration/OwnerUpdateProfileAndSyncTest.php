<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;

/**
 * Thin, single live-DB test for {@see Owner::updateProfileAndSync()} (#1891).
 *
 * The wrapper's own wiring (does it call update() before sync, propagate a
 * validation failure before sync ever runs, return a non-complete-success
 * result rather than swallowing it) is covered without a database by
 * tests/unit/classes/OwnerUpdateProfileAndSyncTest.php. The method itself
 * introduces no new query — it only chains two already-tested methods — so
 * this file proves exactly one thing a unit test cannot: that calling it
 * once against a real database really does persist the users/profiles write
 * AND cascade it to every car the owner has, in a single call. Per-field
 * validation rules and per-car sync failure modes are already covered,
 * respectively, by tests/unit/OwnerValidationTest.php and
 * tests/integration/OwnerSyncOwnerFieldsToCarsTest.php — not duplicated here.
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

        // cars: the syncOwnerFieldsToCars() half of the chain cascaded the
        // just-written values onto every car this owner has, in this one call.
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
     * Regression guard (#1891): usersc/user_settings.php cannot clear the
     * website through updateProfileAndSync($ownerFields), because
     * Owner::update() drops empty values — an empty website never survives
     * validateAndSanitizeFields(). The page instead writes `profiles.website`
     * directly, then calls syncOwnerFieldsToCars() on its own (see
     * $websiteCleared in usersc/user_settings.php). This test proves that
     * second half of the page's workaround actually clears the car, using
     * the same two calls the page makes, not updateProfileAndSync().
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
