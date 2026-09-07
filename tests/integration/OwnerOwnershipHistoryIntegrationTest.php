<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration test for Owner::getOwnershipHistory()'s happy path (#1618).
 *
 * OwnerReadMethodsDatabaseFailureTest.php already covers the DB-error branch
 * (throws OwnerDatabaseException, added in #1505 PR B) and the no-data-loaded
 * fast path, both via a fully stubbed DatabaseInterface — deliberately not
 * IntegrationTestCase, per that file's own docblock. The happy path needs a
 * real `cars_hist` table with joined `cars` data (Owner.php:395-402's
 * `LEFT JOIN cars`), so it belongs in its own IntegrationTestCase-based file
 * rather than being forced into that stub-only file.
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
                // Ignore cleanup errors — matches AdminOwnerManagementTest's convention
            }
        }
        $this->createdHistoryIds = [];
        parent::tearDown();
    }

    /**
     * Insert a cars_hist row tied to a given car/user, mirroring the field
     * shape Owner::syncOwnerFieldsToCars() itself builds (Owner.php) —
     * `operation`, `car_id`, `model`, `series`, `variant`, `type`, `chassis`
     * are NOT NULL with no default in the real schema (confirmed via
     * DESCRIBE cars_hist), so all must be supplied explicitly.
     */
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
        // The car's own chassis/model/year deliberately differ from the
        // cars_hist rows' values below (rather than matching, as an earlier
        // version of this test did) — Owner::getOwnershipHistory()'s SELECT
        // ch.*, c.chassis, c.model, c.year ... LEFT JOIN cars pulls these
        // three columns from the *joined* cars row, and PDO's duplicate-
        // column overwrite means c.chassis/c.model win over cars_hist's own
        // chassis/model of the same name. If the values matched, a
        // regression that accidentally read cars_hist's own columns instead
        // of the joined ones would still pass — divergent values make each
        // assertion below actually discriminate which table it read from.
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
        // Owner.php:400: ORDER BY ch.ctime DESC — most recent first.
        $this->assertSame('TRANSFER', $history[0]->operation);
        $this->assertSame('CREATE', $history[1]->operation);

        // LEFT JOIN cars c ON ch.car_id = c.id (Owner.php:398) — joined fields
        // present. All three joined columns (chassis, model, year) checked
        // individually against the *car's* values (not the history row's own
        // same-named columns, which deliberately differ above) so each
        // assertion actually discriminates a broken join, not just checks a
        // value that happens to be present on both tables.
        $this->assertSame('CARROW01', $history[0]->chassis, 'Joined cars.chassis must be present, not cars_hist.chassis');
        $this->assertSame('Elan Plus 2', $history[0]->model, 'Joined cars.model must be present, not cars_hist.model');
        $this->assertSame(1971, (int) $history[0]->year, 'Joined cars.year must be present');
    }

    public function testGetOwnershipHistoryReturnsEmptyArrayWhenNoHistoryExists(): void
    {
        $userId = $this->createTestUser();
        // No cars_hist rows inserted for this user.

        $owner = new Owner($userId);
        $this->assertNotNull($owner->data(), 'Owner must load successfully even with no history');

        $this->assertSame([], $owner->getOwnershipHistory());
    }
}
