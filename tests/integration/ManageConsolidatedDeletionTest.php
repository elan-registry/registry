<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Regression guard for the car deletion path in
 * app/admin/index.php after migration to Car::delete().
 *
 * Prior to #956 that page issued raw DELETE statements directly against
 * cars. Issue #956 routes deletion through Car::delete() /
 * CarAdministrationService::delete(), which handles the transaction,
 * cars cleanup, and audit trail. This test guards against regressions
 * to that path — specifically verifying that exactly one DELETE row appears
 * in cars_hist (written by the DB trigger). A second row would indicate an
 * accidental application-layer re-introduction of a pre-delete INSERT.
 *
 * Companion to CarDeletionTest::testDeleteCarCreatesAuditTrail(), which
 * guards the Car::delete() / CarAdministrationService path directly.
 *
 * @see CarDeletionTest
 * @see #593, #930, #931, #956
 */
#[Group('integration')]
final class ManageConsolidatedDeletionTest extends IntegrationTestCase
{
    private int $testUserId;
    private int $testCarId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Set up authenticated user context required by Car::delete()
        global $user;
        $user = new User();
        $user->find(1);

        // Bypass login() to set the private $_isLoggedIn flag directly via reflection.
        // setAccessible() is intentionally omitted — it is a no-op since PHP 8.1.
        $reflection = new ReflectionClass($user);
        $isLoggedInProperty = $reflection->getProperty('_isLoggedIn');
        $isLoggedInProperty->setValue($user, true);

        $GLOBALS['user'] = $user;
        $this->testUserId = 1;

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
        $car = new Car($this->testCarId);
        $car->delete('Integration test deletion', Token::generate(), $this->testUserId);

        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'DELETE'",
            [$this->testCarId]
        );

        $this->assertSame(
            1,
            $historyQuery->count(),
            'Expected exactly one DELETE row in cars_hist for the '
                . 'manage-consolidated.php Car::delete() path'
        );
    }
}
