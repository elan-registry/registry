<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1562: the "no_owner" reassign path in app/admin/index.php resolves the
 * noowner ID with User::find('noowner'), not a hardcoded 83. The page is
 * securePage()-gated, so the test runs the same resolution logic.
 */
#[Group('integration')]
final class AdminCarReassignmentTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    public function testNoOwnerResolutionTransfersCarToSeededNoownerAccount(): void
    {
        $noOwnerRow = $this->db->query("SELECT id FROM users WHERE username = ?", ['noowner'])->first();
        $this->assertNotEmpty($noOwnerRow, 'noowner system account missing — run composer migrate (RegisterNoownerAccount)');
        $noOwnerId = (int) $noOwnerRow->id;

        $ownerId = $this->createTestUser();
        $carId = $this->createTestCar($ownerId);

        // Same resolution as app/admin/index.php: never trust a client-supplied ID.
        $noOwnerUser = new User();
        $found = $noOwnerUser->find('noowner');
        $this->assertTrue($found, 'User::find(\'noowner\') must resolve the seeded account');

        $noOwnerData = $noOwnerUser->data();
        $this->assertNotEmpty($noOwnerData, 'User::data() must return the resolved noowner account');
        $this->assertSame($noOwnerId, (int) $noOwnerData->id, 'Resolved ID must match the seeded noowner account');

        $car = new Car($carId);
        $car->transfer((int) $noOwnerData->id, 'Integration test: no_owner reassignment', 'NEWOWNER', $ownerId);

        $after = $this->db->query('SELECT user_id FROM cars WHERE id = ?', [$carId])->first();
        $this->assertSame(
            $noOwnerId,
            (int) $after->user_id,
            'Car must be reassigned to the dynamically-resolved noowner ID, not a hardcoded value'
        );
    }

    /** find()'s return value, not data(), is the not-found signal. */
    public function testFindReturnsFalseForNonexistentUsername(): void
    {
        $missingUser = new User();
        $found = $missingUser->find('this-username-does-not-exist-1562');
        $this->assertFalse($found, 'User::find() must return false for a nonexistent username');
        $this->assertEmpty($missingUser->data(), 'User::data() must be empty/null after a failed find()');
    }

    /** #1878: reassignment to noowner is not a change of owner, so solddate stays. */
    public function testReassignToNoownerPreservesSoldDate(): void
    {
        $noOwnerRow = $this->db->query("SELECT id FROM users WHERE username = ?", ['noowner'])->first();
        $this->assertNotEmpty($noOwnerRow, 'noowner system account missing — run composer migrate (RegisterNoownerAccount)');

        $ownerId = $this->createTestUser();
        $carId = $this->createTestCar($ownerId, ['solddate' => '2020-01-01']);

        $noOwnerUser = new User();
        $found = $noOwnerUser->find('noowner');
        $this->assertTrue($found, 'User::find(\'noowner\') must resolve the seeded account');

        $noOwnerData = $noOwnerUser->data();
        $this->assertNotEmpty($noOwnerData, 'User::data() must return the resolved noowner account');

        $car = new Car($carId);
        $car->transfer((int) $noOwnerData->id, 'Integration test: noowner reassignment preserves solddate', 'NEWOWNER', $ownerId);

        $after = $this->db->query('SELECT solddate FROM cars WHERE id = ?', [$carId])->first();
        $this->assertSame(
            '2020-01-01',
            $after->solddate,
            'Reassigning to the seeded noowner account must not clear solddate — it is not a change of owner'
        );

        $hist = $this->db->query(
            "SELECT solddate FROM cars_hist WHERE car_id = ? AND operation = 'NEWOWNER'",
            [$carId]
        );
        $this->assertSame(1, $hist->count());
        $this->assertSame(
            '2020-01-01',
            $hist->first()->solddate,
            'The NEWOWNER history row must carry the preserved solddate for a noowner reassignment'
        );
    }
}
