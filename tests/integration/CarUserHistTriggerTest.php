<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for the car_user_hist triggers and indexes introduced in #592.
 *
 * Verifies that the three AFTER triggers on `car_user` (INSERT, UPDATE, DELETE)
 * correctly write audit rows to `car_user_hist`, that the UPDATE trigger respects
 * the `@disable_triggers` session variable bypass, and that the two covering
 * indexes are present in the database.
 *
 * Only the UPDATE trigger implements the `@disable_triggers` guard — INSERT and
 * DELETE triggers always fire unconditionally, matching the existing cars_insert
 * and cars_delete pattern.
 *
 * Requires the fix script 06-Add-Car-User-Hist-Triggers.php to have been run
 * against the target database. Tests are skipped if the triggers are absent.
 */
#[Group('integration')]
final class CarUserHistTriggerTest extends IntegrationTestCase
{
    private int $testUserId;
    private int $testCarId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $triggerCheck = $this->db->query("SHOW TRIGGERS WHERE `Trigger` = 'car_user_insert'");
        if ($triggerCheck->count() === 0) {
            $this->markTestSkipped(
                'car_user triggers not yet installed — run fix script 06-Add-Car-User-Hist-Triggers.php first'
            );
        }

        $this->testUserId = 1;

        try {
            $this->testCarId = $this->createTestCar($this->testUserId, [
                'chassis' => 'CUH' . uniqid(),
            ]);
        } catch (RuntimeException $e) {
            $this->markTestSkipped('Could not create test car: ' . $e->getMessage());
        }
    }

    /**
     * Verifies the AFTER INSERT trigger fires and writes one INSERT row to
     * car_user_hist when a car_user record is created.
     */
    #[Group('fast')]
    public function testInsertTriggerFiresOnCarUserInsert(): void
    {
        $histRows = $this->db->query(
            "SELECT * FROM car_user_hist WHERE car_id = ? AND operation = 'INSERT'",
            [$this->testCarId]
        );

        $this->assertSame(
            1,
            $histRows->count(),
            'Expected exactly one INSERT row in car_user_hist after car_user insert'
        );
    }

    /**
     * Verifies the AFTER UPDATE trigger fires and writes one UPDATE row to
     * car_user_hist when a car_user record is updated.
     */
    #[Group('fast')]
    public function testUpdateTriggerFiresOnCarUserUpdate(): void
    {
        $this->db->query(
            "UPDATE car_user SET mtime = NOW() WHERE car_id = ?",
            [$this->testCarId]
        );

        $histRows = $this->db->query(
            "SELECT * FROM car_user_hist WHERE car_id = ? AND operation = 'UPDATE'",
            [$this->testCarId]
        );

        $this->assertSame(
            1,
            $histRows->count(),
            'Expected exactly one UPDATE row in car_user_hist after car_user update'
        );
    }

    /**
     * Verifies the AFTER UPDATE trigger is bypassed when the session variable
     * @disable_triggers is set, producing zero UPDATE rows in car_user_hist.
     */
    #[Group('fast')]
    public function testUpdateTriggerBypassedWhenDisableTriggersSet(): void
    {
        $this->db->query("SET @disable_triggers = 1");
        try {
            $this->db->query(
                "UPDATE car_user SET mtime = NOW() WHERE car_id = ?",
                [$this->testCarId]
            );
        } finally {
            $this->db->query("SET @disable_triggers = NULL");
        }

        $histRows = $this->db->query(
            "SELECT * FROM car_user_hist WHERE car_id = ? AND operation = 'UPDATE'",
            [$this->testCarId]
        );

        $this->assertSame(
            0,
            $histRows->count(),
            'Expected zero UPDATE rows in car_user_hist when @disable_triggers is set'
        );
    }

    /**
     * Verifies the AFTER DELETE trigger fires and writes one DELETE row to
     * car_user_hist when a car_user record is deleted.
     */
    #[Group('fast')]
    public function testDeleteTriggerFiresOnCarUserDelete(): void
    {
        $this->db->query(
            "DELETE FROM car_user WHERE car_id = ?",
            [$this->testCarId]
        );

        $histRows = $this->db->query(
            "SELECT * FROM car_user_hist WHERE car_id = ? AND operation = 'DELETE'",
            [$this->testCarId]
        );

        $this->assertSame(
            1,
            $histRows->count(),
            'Expected exactly one DELETE row in car_user_hist after car_user delete'
        );
    }

    /**
     * Verifies the INSERT trigger captures the correct car_id and userid.
     * Each test gets its own car via uniqid() in setUp, so this hist row is
     * unique to this test invocation regardless of execution order.
     */
    #[Group('fast')]
    public function testCarUserHistColumnsMatchCarUser(): void
    {
        $histRow = $this->db->query(
            "SELECT car_id, userid FROM car_user_hist WHERE car_id = ? AND operation = 'INSERT'",
            [$this->testCarId]
        )->first();

        $this->assertNotEmpty($histRow, 'INSERT hist row must exist in car_user_hist');
        $this->assertSame($this->testCarId, (int) $histRow->car_id, 'car_id in hist must match the test car');
        $this->assertSame($this->testUserId, (int) $histRow->userid, 'userid in hist must match the test user');
    }

    /**
     * Verifies that both covering indexes exist on car_user_hist, guarding
     * against the fix script being skipped in a deployment environment.
     */
    #[Group('fast')]
    public function testIndexesExistOnCarUserHist(): void
    {
        $carIdIndex = $this->db->query(
            "SHOW INDEX FROM car_user_hist WHERE Key_name = 'idx_car_user_hist_car_id'"
        );
        $this->assertSame(
            1,
            $carIdIndex->count(),
            'Index idx_car_user_hist_car_id must exist on car_user_hist'
        );

        $useridIndex = $this->db->query(
            "SHOW INDEX FROM car_user_hist WHERE Key_name = 'idx_car_user_hist_userid'"
        );
        $this->assertSame(
            1,
            $useridIndex->count(),
            'Index idx_car_user_hist_userid must exist on car_user_hist'
        );
    }
}
