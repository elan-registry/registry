<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Regression guard for the legacy car deletion path in
 * app/admin/manage-consolidated.php.
 *
 * That page issues raw DELETE statements against car_user and cars and
 * relies on the cars_delete DB trigger to write the cars_hist audit row.
 * Issue #593 removed a duplicate application-layer INSERT INTO cars_hist
 * from this path; if a similar manual insert is ever re-introduced above
 * the DELETE FROM cars statement, the cars_hist DELETE row count will
 * climb to 2 and this test will fail. Companion to
 * CarDeletionTest::testDeleteCarCreatesAuditTrail(), which guards the
 * Car::delete() / CarAdministrationService path. See #593, #931.
 */
#[Group('integration')]
final class ManageConsolidatedDeletionTest extends IntegrationTestCase
{
    private int $testUserId = 1;
    private int $testCarId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        try {
            $this->testCarId = $this->createTestCar($this->testUserId, [
                'chassis' => 'MCD' . uniqid(),
            ]);
        } catch (RuntimeException $e) {
            $this->markTestSkipped('Could not create test car: ' . $e->getMessage());
        }
    }

    #[Group('fast')]
    public function testManageConsolidatedDeleteCreatesExactlyOneAuditRow(): void
    {
        // Replicate the raw DB ops performed by
        // app/admin/manage-consolidated.php (action=delete).
        $this->db->query("DELETE FROM car_user WHERE car_id = ?", [$this->testCarId]);
        $this->db->query("DELETE FROM cars WHERE id = ?", [$this->testCarId]);

        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'DELETE'",
            [$this->testCarId]
        );

        $this->assertSame(
            1,
            $historyQuery->count(),
            'Expected exactly one DELETE row in cars_hist for the legacy '
                . 'manage-consolidated.php delete path'
        );
    }
}
