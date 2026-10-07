<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1618: the happy path of Owner::getOwnershipHistory(). Failure paths are
 * in OwnerReadMethodsDatabaseFailureTest.php.
 *
 * @see usersc/classes/Owner.php Owner::getOwnershipHistory()
 */
#[Group('integration')]
#[Group('owner')]
final class OwnerOwnershipHistoryIntegrationTest extends IntegrationTestCase
{
    /** @var int[] cars_hist row IDs to clean up in tearDown */
    private array $createdHistoryIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdHistoryIds as $historyId) {
            try {
                $this->db->query("DELETE FROM cars_hist WHERE id = ?", [$historyId]);
            } catch (\Throwable $e) {
                // Ignore cleanup errors
            }
        }
        $this->createdHistoryIds = [];
        parent::tearDown();
    }

    /** cars_hist identity columns are NOT NULL with no default, so supply them all. */
    private function insertHistoryRow(int $carId, int $userId, array $overrides = []): void
    {
        $defaults = [
            'operation' => 'TEST_EVENT',
            'car_id'    => $carId,
            'user_id'   => $userId,
            'model'     => 'Elan S4',
            'series'    => 'S4',
            'variant'   => 'SE',
            'type'      => 'FHC',
            'chassis'   => 'TEST0001',
            'ctime'     => date('Y-m-d H:i:s'),
        ];

        $this->db->insert('cars_hist', array_merge($defaults, $overrides));

        $row = $this->db->query(
            "SELECT id FROM cars_hist WHERE car_id = ? AND operation = ? ORDER BY id DESC LIMIT 1",
            [$carId, $overrides['operation'] ?? $defaults['operation']]
        )->first();
        if (!$row) {
            throw new \RuntimeException("insertHistoryRow: insert failed for car_id={$carId}");
        }
        $this->createdHistoryIds[] = (int) $row->id;
    }

    public function testGetOwnershipHistoryReturnsMultipleRecordsOrderedByCtimeDesc(): void
    {
        $userId = $this->createTestUser();
        // Car values differ from the cars_hist values so each assertion shows which
        // table the joined columns came from.
        $carId = $this->createTestCar($userId, [
            'chassis' => 'CARROW01',
            'model'   => 'Elan Plus 2',
            'year'    => 1971,
        ]);

        $this->insertHistoryRow($carId, $userId, [
            'operation' => 'CREATE',
            'chassis'   => 'HISTROW1',
            'model'     => 'Elan Sprint',
            'ctime'     => '2020-01-01 10:00:00',
        ]);
        $this->insertHistoryRow($carId, $userId, [
            'operation' => 'TRANSFER',
            'chassis'   => 'HISTROW2',
            'model'     => 'Elan Sprint',
            'ctime'     => '2021-06-15 10:00:00',
        ]);

        $owner = new Owner($userId);
        $this->assertNotNull($owner->data(), 'Owner must load successfully');

        $history = $owner->getOwnershipHistory();

        $this->assertCount(2, $history, 'Both cars_hist rows for this owner must be returned');
        $this->assertSame('TRANSFER', $history[0]->operation);
        $this->assertSame('CREATE', $history[1]->operation);

        $this->assertSame('CARROW01', $history[0]->chassis, 'Joined cars.chassis must be present, not cars_hist.chassis');
        $this->assertSame('Elan Plus 2', $history[0]->model, 'Joined cars.model must be present, not cars_hist.model');
        $this->assertSame(1971, (int) $history[0]->year, 'Joined cars.year must be present');
    }

    public function testGetOwnershipHistoryReturnsEmptyArrayWhenNoHistoryExists(): void
    {
        $userId = $this->createTestUser();

        $owner = new Owner($userId);
        $this->assertNotNull($owner->data(), 'Owner must load successfully even with no history');

        $this->assertSame([], $owner->getOwnershipHistory());
    }
}
