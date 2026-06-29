<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarShowcaseService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for CarShowcaseService::getNewCarIds()
 *
 * Verifies that:
 * - The return value is always a list of ints (even on an empty registry)
 * - Cars added within 90 days are always flagged
 * - Cars outside the 90-day window but in the top-5 most-recently-added are flagged
 * - Cars outside the 90-day window AND outside the top-5 are NOT flagged
 * - When there are fewer than 5 cars total, all are flagged
 */
#[Group('integration')]
final class CarShowcaseServiceGetNewCarIdsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /**
     * Must always return an array — never null or false.
     */
    public function testGetNewCarIdsReturnsArray(): void
    {
        $ids = CarShowcaseService::getNewCarIds($this->db);

        $this->assertIsArray($ids);
    }

    /**
     * Every element in the returned array must be a PHP int.
     */
    public function testGetNewCarIdsReturnsIntegers(): void
    {
        $userId = $this->createTestUser();
        $this->createTestCar($userId);

        $ids = CarShowcaseService::getNewCarIds($this->db);

        foreach ($ids as $id) {
            $this->assertIsInt($id);
        }
    }

    /**
     * A car with ctime = NOW() (within 90 days) must be included.
     */
    public function testRecentCarIsFlagged(): void
    {
        $userId = $this->createTestUser();
        $carId  = $this->createTestCar($userId);

        $ids = CarShowcaseService::getNewCarIds($this->db);

        $this->assertContains($carId, $ids);
    }

    /**
     * Skip when the database already has enough cars that backdated test cars
     * can never be in the global top-5 most-recently-added.
     */
    private function skipIfDatabaseIsPopulated(int $minRequired = 1): void
    {
        $this->db->query("SELECT COUNT(*) AS cnt FROM cars");
        $count = (int) ($this->db->results()[0]->cnt ?? 0);
        if ($count >= 5) {
            $this->markTestSkipped(
                'Top-5 floor tests require fewer than 5 pre-existing cars '
                . "(found {$count}). Run against an empty test database."
            );
        }
    }

    /**
     * A car added more than 90 days ago that is not in the top-5 must NOT be flagged.
     *
     * Creates 6 cars and sets the oldest one's ctime to 91 days ago, then verifies
     * it is absent from getNewCarIds() (since newer cars push it out of top-5).
     */
    public function testOldCarOutsideTopFiveIsNotFlagged(): void
    {
        $userId = $this->createTestUser();

        // Create the old car first so it gets the smallest id
        $oldCarId = $this->createTestCar($userId);
        $this->db->query(
            "UPDATE cars SET ctime = DATE_SUB(NOW(), INTERVAL 91 DAY) WHERE id = ?",
            [$oldCarId]
        );

        // Create 5 more recent cars so the old car falls outside top-5
        for ($i = 0; $i < 5; $i++) {
            $this->createTestCar($userId);
        }

        $ids = CarShowcaseService::getNewCarIds($this->db);

        $this->assertNotContains($oldCarId, $ids, 'Car added 91 days ago and outside top-5 must not be flagged');
    }

    /**
     * A car outside the 90-day window but at position 5 of 6 (inside the top-5 floor) must be flagged.
     *
     * Creates 6 cars with identical ctime 91 days ago. The 5 cars with the highest IDs
     * are in the top-NEW_FLOOR floor and must be included even though they are old.
     * This tests the floor guarantee when 6 total cars exist (not just fewer-than-5).
     */
    public function testOldCarInTopFiveIsFlagged(): void
    {
        $this->skipIfDatabaseIsPopulated();

        $userId = $this->createTestUser();
        $carIds = [];

        for ($i = 0; $i < 6; $i++) {
            $carIds[] = $this->createTestCar($userId);
        }

        // Set all to 91 days ago — none qualify via the date rule
        $this->db->query(
            "UPDATE cars SET ctime = DATE_SUB(NOW(), INTERVAL 91 DAY) WHERE id IN (" .
            implode(',', array_map('intval', $carIds)) . ")"
        );

        sort($carIds);
        // With equal ctimes, top-5 by (ctime DESC, id DESC) = the 5 highest IDs (indices 1-5)
        $fifthNewestId = $carIds[1]; // second-lowest ID = position 5 of 6 = inside top-5

        $ids = CarShowcaseService::getNewCarIds($this->db);

        $this->assertContains($fifthNewestId, $ids, 'Car at position 5 of 6 (top-5 floor) must be flagged even when old');
    }

    /**
     * When fewer than 5 cars exist in total, all must be flagged.
     */
    public function testFewerThanFiveCarsAllFlagged(): void
    {
        $this->skipIfDatabaseIsPopulated();

        $userId = $this->createTestUser();
        $carIds = [];

        // Create 3 old cars
        for ($i = 0; $i < 3; $i++) {
            $carId    = $this->createTestCar($userId);
            $carIds[] = $carId;
            $this->db->query(
                "UPDATE cars SET ctime = DATE_SUB(NOW(), INTERVAL 200 DAY) WHERE id = ?",
                [$carId]
            );
        }

        $ids = CarShowcaseService::getNewCarIds($this->db);

        foreach ($carIds as $carId) {
            $this->assertContains($carId, $ids, "With only 3 cars total, car {$carId} must be flagged as NEW via top-5");
        }
    }

    /**
     * Top-5 ordering uses ctime DESC, id DESC for deterministic tie-breaking.
     *
     * Creates 6 cars all with the same ctime; the 5 highest IDs must be in the
     * top-5 and therefore flagged even after setting all ctimes to > 90 days ago.
     */
    public function testTopFiveTieBrokenById(): void
    {
        $this->skipIfDatabaseIsPopulated();

        $userId = $this->createTestUser();
        $carIds = [];

        for ($i = 0; $i < 6; $i++) {
            $carIds[] = $this->createTestCar($userId);
        }

        // Set all to exactly 91 days ago with the same timestamp so tie-breaking
        // falls to id DESC — the car with the smallest id must be excluded
        $this->db->query(
            "UPDATE cars SET ctime = DATE_SUB(NOW(), INTERVAL 91 DAY) WHERE id IN (" .
            implode(',', array_map('intval', $carIds)) . ")"
        );

        sort($carIds);
        $lowestId     = $carIds[0];
        $topFiveIds   = array_slice($carIds, 1); // ids 2-6 (higher ids)

        $ids = CarShowcaseService::getNewCarIds($this->db);

        $this->assertNotContains($lowestId, $ids, 'Car with lowest id must be excluded when all ctimes tie and 6 cars exist');
        foreach ($topFiveIds as $id) {
            $this->assertContains($id, $ids, "Car {$id} (top-5 by id) must be flagged");
        }
    }
}
