<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for which photo removals reset owner_last_updated (#1929).
 *
 * Only an owner removal resets the freshness clock. An admin or editor
 * removal on another owner's car, and the removeImages() clean-up after a
 * failed upload, leave it unchanged.
 *
 * Car::removeImage() and Car::removeImages() change only the cars.image
 * column, not files on disk, so each test seeds the image JSON directly.
 * Each test also seeds an old owner_last_updated, so a reset is visible.
 */
#[Group('integration')]
#[Group('car')]
final class CarImageOwnerFreshnessTest extends IntegrationTestCase
{
    private const OLD_OWNER_LAST_UPDATED = '2024-09-29 12:00:00';
    private const IMAGE_JSON = '["a.jpg","b.jpg"]';

    private int $testCarId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $userId = $this->createTestUser();
        $this->testCarId = $this->createTestCar($userId, ['image' => self::IMAGE_JSON]);

        $this->seedOwnerLastUpdated($this->testCarId, self::OLD_OWNER_LAST_UPDATED);
    }

    /**
     * Read the stored image and owner_last_updated for the test car.
     *
     * @return object{image: ?string, owner_last_updated: string}
     */
    private function fetchRow(): object
    {
        $row = $this->db->query(
            'SELECT image, owner_last_updated FROM cars WHERE id = ?',
            [$this->testCarId]
        )->first();
        $this->assertIsObject($row, "Car {$this->testCarId} must exist");

        return $row;
    }

    public function testOwnerRemovalResetsOwnerLastUpdatedAndWritesOneHistoryRow(): void
    {
        $historyBefore = $this->countCarsHistRows($this->testCarId);

        $car = new Car($this->testCarId);
        $this->assertTrue($car->removeImage('a.jpg', true));

        $row = $this->fetchRow();
        $this->assertSame('["b.jpg"]', $row->image);
        $this->assertNotSame(self::OLD_OWNER_LAST_UPDATED, $row->owner_last_updated);
        $this->assertEqualsWithDelta(
            time(),
            strtotime($row->owner_last_updated),
            60,
            'An owner removal must set owner_last_updated to the current time'
        );
        $this->assertSame(
            $historyBefore + 1,
            $this->countCarsHistRows($this->testCarId),
            'The image and owner_last_updated must change in one UPDATE, so exactly one cars_hist row is written'
        );
        $this->assertSame(
            $row->owner_last_updated,
            $car->data()->owner_last_updated,
            'The cached car row must match the stored owner_last_updated'
        );
    }

    public function testNonOwnerRemovalDoesNotResetOwnerLastUpdated(): void
    {
        $car = new Car($this->testCarId);
        $this->assertTrue($car->removeImage('a.jpg', false));

        $row = $this->fetchRow();
        $this->assertSame('["b.jpg"]', $row->image);
        $this->assertSame(self::OLD_OWNER_LAST_UPDATED, $row->owner_last_updated);
    }

    public function testRemoveImagesCleanupDoesNotResetOwnerLastUpdated(): void
    {
        $car = new Car($this->testCarId);
        $result = $car->removeImages(['a.jpg']);
        $this->assertTrue($result['updated']);

        $row = $this->fetchRow();
        $this->assertSame('["b.jpg"]', $row->image);
        $this->assertSame(self::OLD_OWNER_LAST_UPDATED, $row->owner_last_updated);
    }

    public function testUpdateImageWithTimestampRunsAgainstRealSchema(): void
    {
        $repo = new CarRepository($this->db);

        $this->assertTrue(
            $repo->updateImage($this->testCarId, '["b.jpg"]', self::IMAGE_JSON, '2026-09-29 10:00:00')
        );

        $row = $this->fetchRow();
        $this->assertSame('["b.jpg"]', $row->image);
        $this->assertSame('2026-09-29 10:00:00', $row->owner_last_updated);
    }

    public function testUpdateImageWithTimestampReturnsFalseOnCasMismatch(): void
    {
        $repo = new CarRepository($this->db);

        $this->assertFalse(
            $repo->updateImage($this->testCarId, '["b.jpg"]', '["stale.jpg"]', '2026-09-29 10:00:00')
        );

        $row = $this->fetchRow();
        $this->assertSame(self::IMAGE_JSON, $row->image);
        $this->assertSame(self::OLD_OWNER_LAST_UPDATED, $row->owner_last_updated);
    }

    /**
     * #1929: an owner edit that changes only the image list, saved as save.php
     * does, resets owner_last_updated in the same UPDATE (one cars_hist row).
     */
    public function testOwnerEditThatChangesImagesResetsOwnerLastUpdated(): void
    {
        $historyBefore = $this->countCarsHistRows($this->testCarId);

        $car = new Car($this->testCarId);
        $this->assertTrue($car->update(['id' => $this->testCarId, 'image' => '["b.jpg","a.jpg"]'], true));

        $row = $this->fetchRow();
        $this->assertSame('["b.jpg","a.jpg"]', $row->image);
        $this->assertNotSame(self::OLD_OWNER_LAST_UPDATED, $row->owner_last_updated);
        $this->assertEqualsWithDelta(
            time(),
            strtotime($row->owner_last_updated),
            60,
            'An owner-initiated image-only edit must set owner_last_updated to the current time'
        );
        $this->assertSame(
            $historyBefore + 1,
            $this->countCarsHistRows($this->testCarId),
            'The image and owner_last_updated must change in one UPDATE, so exactly one cars_hist row is written'
        );
    }

    /** #1929: the same edit by an admin or editor leaves owner_last_updated unchanged. */
    public function testAdminEditThatChangesImagesDoesNotResetOwnerLastUpdated(): void
    {
        $car = new Car($this->testCarId);
        $this->assertTrue($car->update(['id' => $this->testCarId, 'image' => '["b.jpg","a.jpg"]'], false));

        $row = $this->fetchRow();
        $this->assertSame('["b.jpg","a.jpg"]', $row->image);
        $this->assertSame(self::OLD_OWNER_LAST_UPDATED, $row->owner_last_updated);
    }
}
