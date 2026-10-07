<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Owner;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;

use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class CarTransferTest extends IntegrationTestCase
{
    private $testCarId;
    private $testUserId;
    private $targetUserId;
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->testUserId = $this->createTestUser();
        $this->targetUserId = $this->createTestUser();

        $this->loginAsTestUser($this->testUserId);

        $this->db = DB::getInstance();

        // Location lives on `profiles`; without it the location test compares
        // '' === ''. Checked so a failed insert cannot hide that.
        $profileInserted = $this->db->insert('profiles', [
            'user_id' => $this->targetUserId,
            'city'    => 'Hethel',
            'state'   => 'Norfolk',
            'country' => 'United Kingdom',
        ]);
        $this->assertTrue((bool) $profileInserted, 'Test fixture: profiles insert must succeed');

        try {
            $this->testCarId = $this->createTestCar($this->testUserId, [
                'chassis' => 'TR' . uniqid()
            ]);
        } catch (RuntimeException $e) {
            $this->markTestSkipped('Could not create test car: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->databaseConnected && $this->targetUserId) {
                $this->db->query("DELETE FROM profiles WHERE user_id = ?", [$this->targetUserId]);
            }
        } finally {
            // Run even if the cleanup above throws, so restoreGlobalUser() is never skipped.
            parent::tearDown();
        }
    }

    #[Group('fast')]
    public function testTransferCarSuccessWithValidUser(): void
    {
        $car = new Car($this->testCarId);

        $car->transfer($this->targetUserId, 'Test transfer', 'NEWOWNER', $this->testUserId);

        // Reload car data from database to verify transfer
        $transferredCar = new Car($this->testCarId);
        $this->assertEquals($this->targetUserId, $transferredCar->data()->user_id);
    }

    #[Group('fast')]
    public function testTransferCarFailsWithInvalidUser(): void
    {
        $this->expectException(CarValidationException::class);

        $car = new Car($this->testCarId);
        $car->transfer(99999, 'Test transfer', 'NEWOWNER', $this->testUserId);
    }

    #[Group('fast')]
    public function testTransferCarFailsWhenCarNotExists(): void
    {
        $this->expectException(CarNotFoundException::class);

        $car = new Car(99999);
        $car->transfer($this->targetUserId, 'Test transfer', 'NEWOWNER', $this->testUserId);
    }

    #[Group('fast')]
    public function testTransferUpdatesCarsUserId(): void
    {
        $car = new Car($this->testCarId);
        $carId = $car->data()->id;

        $before = $this->db->query(
            "SELECT user_id FROM cars WHERE id = ?",
            [$carId]
        )->first();
        $this->assertSame($this->testUserId, (int) $before->user_id);

        $car->transfer($this->targetUserId, 'Test transfer', 'NEWOWNER', $this->testUserId);

        $after = $this->db->query(
            "SELECT user_id FROM cars WHERE id = ?",
            [$carId]
        )->first();
        $this->assertSame($this->targetUserId, (int) $after->user_id);
    }

    #[Group('fast')]
    public function testTransferCreatesHistoryRecord(): void
    {
        $car = new Car($this->testCarId);
        $carId = $car->data()->id;

        $car->transfer($this->targetUserId, 'Test transfer history', 'NEWOWNER', $this->testUserId);

        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'NEWOWNER'",
            [$carId]
        );
        $this->assertTrue($historyQuery->count() > 0);
    }

    #[Group('fast')]
    public function testTransferCopiesUserProfileData(): void
    {
        $car = new Car($this->testCarId);

        $targetUser = (new Owner($this->targetUserId))->data();
        $this->assertNotNull($targetUser);

        $car->transfer($this->targetUserId, 'Test transfer profile', 'NEWOWNER', $this->testUserId);

        $updatedCar = new Car((int) $car->data()->id);
        $this->assertEquals($targetUser->fname ?? '', $updatedCar->data()->fname);
        $this->assertEquals($targetUser->lname ?? '', $updatedCar->data()->lname);
        $this->assertEquals($targetUser->email ?? '', $updatedCar->data()->email);
    }

    #[Group('fast')]
    public function testTransferTransactionRollbackOnFailure(): void
    {
        $this->expectException(CarValidationException::class);

        $car = new Car($this->testCarId);
        $originalUserId = $car->data()->user_id;

        try {
            $car->transfer(99999, 'Test transfer', 'NEWOWNER', $this->testUserId);
        } catch (Exception $e) {
            $carReloaded = new Car((int) $car->data()->id);
            $this->assertEquals($originalUserId, $carReloaded->data()->user_id);
            throw $e;
        }
    }

    #[Group('fast')]
    public function testTransferCarWithTransferOperationType(): void
    {
        $car = new Car($this->testCarId);
        $carId = $car->data()->id;

        $car->transfer($this->targetUserId, 'Test transfer', 'TRANSFER', $this->testUserId);

        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'TRANSFER'",
            [$carId]
        );
        $this->assertTrue($historyQuery->count() > 0);
    }

    /**
     * #1929 (decided on #1878): a transfer is not a re-attestation, so it
     * must leave owner_last_updated exactly as it was.
     */
    #[Group('fast')]
    public function testTransferDoesNotChangeOwnerLastUpdated(): void
    {
        $old = date('Y-m-d H:i:s', strtotime('-2 years'));
        $this->seedOwnerLastUpdated($this->testCarId, $old);

        $before = $this->getOwnerLastUpdated($this->testCarId);
        $this->assertSame($old, $before, 'Precondition: the seeded value must be stored as written');

        (new Car($this->testCarId))->transfer($this->targetUserId, 'Test transfer freshness', 'NEWOWNER', $this->testUserId);

        $after = $this->db->query('SELECT user_id, owner_last_updated FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertSame($this->targetUserId, (int) $after->user_id, 'Precondition: the transfer must have happened');
        $this->assertSame($before, (string) $after->owner_last_updated, 'transfer() must not change owner_last_updated');
    }

    #[Group('fast')]
    public function testTransferUpdatesLocationData(): void
    {
        $car = new Car($this->testCarId);

        $car->transfer($this->targetUserId, 'Test transfer location', 'NEWOWNER', $this->testUserId);

        $targetUser = (new Owner($this->targetUserId))->data();
        $updatedCar = new Car((int) $car->data()->id);

        $this->assertEquals($targetUser->city ?? '', $updatedCar->data()->city);
        $this->assertEquals($targetUser->state ?? '', $updatedCar->data()->state);
        $this->assertEquals($targetUser->country ?? '', $updatedCar->data()->country);
    }

    /**
     * Issue #1878: car transfer clears solddate on a previously-sold car, both
     * on the cars row and on the app-written NEWOWNER cars_hist row.
     */
    #[Group('fast')]
    public function testTransferClearsSoldDateOnPreviouslySoldCar(): void
    {
        $soldCarId = $this->createTestCar($this->testUserId, [
            'chassis'  => 'TR' . uniqid(),
            'solddate' => '2020-01-01',
        ]);

        $car = new Car($soldCarId);
        $car->transfer($this->targetUserId, 'Test transfer sold car', 'NEWOWNER', $this->testUserId);

        $carRow = $this->db->query(
            "SELECT solddate FROM cars WHERE id = ?",
            [$soldCarId]
        )->first();
        $this->assertNull($carRow->solddate);

        $histQuery = $this->db->query(
            "SELECT solddate FROM cars_hist WHERE car_id = ? AND operation = 'NEWOWNER'",
            [$soldCarId]
        );
        $this->assertSame(1, $histQuery->count());
        $this->assertNull($histQuery->first()->solddate);
    }

    /** #1878: the clear must write SQL NULL, not a sentinel such as '' or '0000-00-00'. */
    #[Group('fast')]
    public function testTransferLeavesNeverSoldCarSoldDateNull(): void
    {
        $car = new Car($this->testCarId);
        $carId = $car->data()->id;

        $car->transfer($this->targetUserId, 'Test transfer never sold', 'NEWOWNER', $this->testUserId);

        $carRow = $this->db->query(
            "SELECT solddate FROM cars WHERE id = ?",
            [$carId]
        )->first();
        $this->assertNull($carRow->solddate);
    }

    /**
     * The bounce belongs to the previous owner's address; carrying it forward
     * would exclude the car from findVerificationEligible() forever.
     */
    #[Group('fast')]
    public function testTransferClearsEmailBouncedOnPreviouslyBouncedCar(): void
    {
        $bouncedCarId = $this->createTestCar($this->testUserId, [
            'chassis'       => 'TR' . uniqid(),
            'email_bounced' => 1,
        ]);

        $car = new Car($bouncedCarId);
        $car->transfer($this->targetUserId, 'Test transfer bounced car', 'NEWOWNER', $this->testUserId);

        $carRow = $this->db->query(
            "SELECT email_bounced FROM cars WHERE id = ?",
            [$bouncedCarId]
        )->first();
        $this->assertSame(0, (int) $carRow->email_bounced);
    }

    /** #1887: both belong to the previous owner's address. */
    #[Group('fast')]
    public function testTransferClearsBounceAddressAndSuppressedOnPreviouslyFlaggedCar(): void
    {
        $flaggedCarId = $this->createTestCar($this->testUserId, [
            'chassis'               => 'TR' . uniqid(),
            'email_bounced'         => 1,
            'email_bounced_address' => 'previous-owner@example.com',
            'email_suppressed'      => 1,
        ]);

        $car = new Car($flaggedCarId);
        $car->transfer($this->targetUserId, 'Test transfer flagged car', 'NEWOWNER', $this->testUserId);

        $carRow = $this->db->query(
            "SELECT email_bounced_address, email_suppressed FROM cars WHERE id = ?",
            [$flaggedCarId]
        )->first();
        $this->assertNull($carRow->email_bounced_address);
        $this->assertSame(0, (int) $carRow->email_suppressed);
    }

    /**
     * Reassignment to "noowner" is not a change of owner (#1878), so the boolean
     * flags survive. email_bounced_address is PII and this is the GDPR erasure
     * path (after_user_deletion.php), so it is always cleared.
     */
    #[Group('fast')]
    public function testSystemAccountReassignmentPreservesFlagsButClearsBounceAddress(): void
    {
        $flaggedCarId = $this->createTestCar($this->testUserId, [
            'chassis'               => 'TR' . uniqid(),
            'email_bounced'         => 1,
            'email_bounced_address' => 'still-bounced@example.com',
            'email_suppressed'      => 1,
        ]);

        $noOwner = $this->db->query("SELECT id FROM users WHERE username = 'noowner'")->first();
        $this->assertNotNull($noOwner, 'noowner system account must exist for this test');

        $car = new Car($flaggedCarId);
        $car->transfer((int) $noOwner->id, 'Test reassignment to noowner', 'NEWOWNER', $this->testUserId);

        $carRow = $this->db->query(
            "SELECT email_bounced, email_bounced_address, email_suppressed FROM cars WHERE id = ?",
            [$flaggedCarId]
        )->first();
        $this->assertSame(1, (int) $carRow->email_bounced, 'The boolean bounced flag carries no PII and must survive');
        $this->assertNull($carRow->email_bounced_address, 'The bounced address is PII and must be cleared even on GDPR-erasure reassignment');
        $this->assertSame(1, (int) $carRow->email_suppressed, 'The boolean suppressed flag carries no PII and must survive');

        $histRow = $this->db->query(
            "SELECT email_bounced_address FROM cars_hist WHERE car_id = ? AND operation = 'NEWOWNER' ORDER BY timestamp DESC LIMIT 1",
            [$flaggedCarId]
        )->first();
        $this->assertIsObject($histRow, 'Expected a NEWOWNER row in cars_hist');
        $this->assertNull($histRow->email_bounced_address, 'The audit trail must not retain the bounced address either');
    }

    /**
     * vericode is a bearer token: a live link would let the previous owner
     * verify or mark sold a car they no longer own.
     */
    #[Group('fast')]
    public function testTransferClearsVericodeOnPreviouslyVerifiedCar(): void
    {
        $verifiedCarId = $this->createTestCar($this->testUserId, [
            'chassis'  => 'TR' . uniqid(),
            'vericode' => hashVericode('SOME-PLAINTEXT-CODE-' . uniqid()),
        ]);

        $car = new Car($verifiedCarId);
        $car->transfer($this->targetUserId, 'Test transfer verified car', 'NEWOWNER', $this->testUserId);

        $carRow = $this->db->query(
            "SELECT vericode FROM cars WHERE id = ?",
            [$verifiedCarId]
        )->first();
        $this->assertNull($carRow->vericode, 'vericode must be cleared on transfer to a real owner');
    }

    /** A live credential: cleared on any ownership change, including noowner. */
    #[Group('fast')]
    public function testSystemAccountReassignmentAlsoClearsVericode(): void
    {
        $verifiedCarId = $this->createTestCar($this->testUserId, [
            'chassis'  => 'TR' . uniqid(),
            'vericode' => hashVericode('SOME-PLAINTEXT-CODE-' . uniqid()),
        ]);

        $noOwner = $this->db->query("SELECT id FROM users WHERE username = 'noowner'")->first();
        $this->assertNotNull($noOwner, 'noowner system account must exist for this test');

        $car = new Car($verifiedCarId);
        $car->transfer((int) $noOwner->id, 'Test reassignment to noowner', 'NEWOWNER', $this->testUserId);

        $carRow = $this->db->query(
            "SELECT vericode FROM cars WHERE id = ?",
            [$verifiedCarId]
        )->first();
        $this->assertNull(
            $carRow->vericode,
            'vericode must be cleared even on system-account reassignment — it is a live '
            . 'credential, not a boolean signal, so it does not get the same-as-email_bounced '
            . 'preservation treatment'
        );
    }

    /** transfer() must not fall back to a global $user. */
    #[Group('fast')]
    public function testTransferHonorsExplicitActingUserIdWithoutGlobalUser(): void
    {
        // Car::__construct() needs global $user (getSettings()), so construct first.
        $car = new Car($this->testCarId);

        $savedUser = $GLOBALS['user'] ?? null;
        unset($GLOBALS['user']);

        try {
            $car->transfer($this->targetUserId, 'Explicit actingUserId test', 'NEWOWNER', $this->testUserId);
        } finally {
            if ($savedUser !== null) {
                $GLOBALS['user'] = $savedUser;
            }
        }

        $transferredCar = new Car($this->testCarId);
        $this->assertEquals(
            $this->targetUserId,
            $transferredCar->data()->user_id,
            'transfer() must not depend on global $user'
        );
    }
}
