<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarBadges;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;

/**
 * A sync of owner fields changes `cars.mtime` but must not make a stale car
 * show a Verified status (#1897).
 *
 * `mtime` has `ON UPDATE CURRENT_TIMESTAMP`, so any UPDATE moves it. The
 * freshness rule reads only `last_verified` and `owner_last_updated`. This
 * test runs the real Owner::syncOwnerFieldsToCars() on a stale car and
 * reloads the row from the database.
 */
#[Group('integration')]
#[Group('car')]
final class CarVerifiedRowSyncTest extends IntegrationTestCase
{
    private const OLD = '2023-01-15 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    public function testSyncChangesMtimeButStaleCarStaysStaleAndNotVerified(): void
    {
        $userId = $this->createTestUser(['fname' => 'Synced', 'lname' => 'Owner']);
        $carId = $this->createTestCar($userId, ['fname' => 'Different']);

        // Seed all three dates old. Setting mtime explicitly stops the
        // ON UPDATE clause from overriding the seed in this statement.
        $seed = $this->db->query(
            'UPDATE cars SET last_verified = ?, owner_last_updated = ?, mtime = ? WHERE id = ?',
            [self::OLD, self::OLD, self::OLD, $carId]
        );
        $this->assertFalse($seed->error(), 'Test setup: seed must succeed: ' . $seed->errorString());

        $before = $this->db->query(
            'SELECT mtime, owner_last_updated, last_verified FROM cars WHERE id = ?',
            [$carId]
        )->first();
        $this->assertIsObject($before);
        $this->assertSame(self::OLD, (string) $before->mtime, 'Test setup: mtime must read back as seeded');
        $this->assertSame(self::OLD, (string) $before->owner_last_updated);

        $result = (new Owner($userId))->syncOwnerFieldsToCars();
        $this->assertContains($carId, $result->updated, 'The sync must write the car');

        $after = $this->db->query('SELECT * FROM cars WHERE id = ?', [$carId])->first();
        $this->assertIsObject($after);
        $this->assertSame('Synced', $after->fname, 'The sync must have changed the row');
        $this->assertNotSame(self::OLD, (string) $after->mtime, 'The sync must move mtime');
        $this->assertSame(
            self::OLD,
            (string) $after->owner_last_updated,
            'The sync must not touch owner_last_updated'
        );
        $this->assertSame(self::OLD, (string) $after->last_verified);

        $this->assertFalse(
            CarRepository::isFresh($after->last_verified, (string) $after->owner_last_updated),
            'A car whose only change is a sync must stay stale'
        );
        $this->assertNull(CarBadges::verifiedStatus($after));
        $this->assertSame([], CarBadges::forCar($after));
    }

    /**
     * A new car reads as fresh through its creation-time default.
     *
     * The create path does not write `owner_last_updated` or `last_verified`.
     * The column default `CURRENT_TIMESTAMP` sets `owner_last_updated`. The
     * `cars` triggers are AFTER triggers, so they do not set it.
     */
    public function testNewCarIsCurrentThroughColumnDefault(): void
    {
        $default = $this->db->query(
            'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['cars', 'owner_last_updated']
        )->first();
        $this->assertIsObject($default);
        $this->assertSame('CURRENT_TIMESTAMP', $default->COLUMN_DEFAULT);

        $userId = $this->createTestUser();
        $carId = $this->createTestCar($userId);

        $car = $this->db->query('SELECT * FROM cars WHERE id = ?', [$carId])->first();
        $this->assertIsObject($car);
        $this->assertNull($car->last_verified, 'Test setup: a new car has no last_verified');
        $this->assertIsString($car->owner_last_updated, 'The column default must set owner_last_updated');

        $status = CarBadges::verifiedStatus($car);
        $this->assertNotNull($status);
        $this->assertSame('current', $status['source']);
        $this->assertSame((string) $car->owner_last_updated, $status['date']->format('Y-m-d H:i:s'));
    }
}
