<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;
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

        $this->testUserId = $this->createTestUser([], true);
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
            'email_address_since' => date('Y-m-d H:i:s'),
            'email_address_seen'  => 'target-owner@example.com',
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
     * The flags belong to the owner's profile (#2101). A transfer writes none
     * of them. The previous owner keeps their own flags, and the new owner
     * does not get them: the old complaint stays with the person who made it.
     */
    #[Group('fast')]
    public function testTransferLeavesBothOwnersProfileFlagsUnchangedAndNewOwnerGetsNone(): void
    {
        $this->setOwnerBounced($this->testUserId, 'previous-owner@example.com', '2026-03-04 05:06:07');
        $this->setOwnerBlocked($this->testUserId, 'previous-owner@example.com', '2026-02-03 04:05:06', 'spam');
        $this->setOwnerOptedOut($this->testUserId, true, '2026-01-02 03:04:05');
        $flaggedCarId = $this->createTestCar($this->testUserId, ['chassis' => 'TR' . uniqid()]);
        $previousBefore = $this->getOwnerEmailFlags($this->testUserId);
        $targetBefore = $this->getOwnerEmailFlags($this->targetUserId);
        $this->assertSame('0', (string) $targetBefore['email_blocked'], 'Test sanity: the new owner starts clean');

        $car = new Car($flaggedCarId);
        $car->transfer($this->targetUserId, 'Test transfer flagged car', 'NEWOWNER', $this->testUserId);

        $this->assertSame(
            $previousBefore,
            $this->getOwnerEmailFlags($this->testUserId),
            'The previous owner\'s profile flags must be unchanged by the transfer'
        );
        $after = $this->getOwnerEmailFlags($this->targetUserId);
        $this->assertSame($targetBefore, $after, 'The new owner\'s profile must be unchanged by the transfer');
        $this->assertSame('0', (string) $after['email_blocked'], 'The old complaint must not pass to the new owner');
        $this->assertSame('0', (string) $after['email_bounced']);
        $this->assertSame('0', (string) $after['email_opted_out']);
    }

    /**
     * A flagged previous owner no longer keeps the transferred car out of
     * verification: eligibility reads the new owner's profile.
     */
    #[Group('fast')]
    public function testTransferredCarOfFlaggedPreviousOwnerIsEligibleUnderTheNewOwner(): void
    {
        $this->setOwnerBounced($this->testUserId, 'previous-owner@example.com');
        $flaggedCarId = $this->createTestCar($this->testUserId, [
            'chassis' => 'TR' . uniqid(),
            'email'   => 'previous-owner@example.com',
        ]);

        $car = new Car($flaggedCarId);
        $car->transfer($this->targetUserId, 'Test transfer flagged car', 'NEWOWNER', $this->testUserId);

        $this->db->query(
            'UPDATE cars SET email = ?, last_verified = NULL, vericode_sent_at = NULL WHERE id = ?',
            ['new-owner-' . uniqid() . '@example.com', $flaggedCarId]
        );
        $this->seedOwnerLastUpdated($flaggedCarId, date('Y-m-d H:i:s', strtotime('-3 years')));

        // This class shadows $db with the raw DB singleton, which the
        // repository does not accept, so the repository gets dbi().
        $eligibleIds = array_map(
            static fn (object $row): int => (int) $row->id,
            (new CarRepository(dbi()))->findVerificationEligible(100000, 0)
        );
        $this->assertContains(
            $flaggedCarId,
            $eligibleIds,
            'Under the clean new owner the car is eligible, although the previous owner is bounced'
        );
    }

    /**
     * Reassignment to "noowner" is not a change of owner (#1878). It is the
     * GDPR erasure path (after_user_deletion.php). The transfer writes no
     * profile flag, and the audit row exists.
     */
    #[Group('fast')]
    public function testSystemAccountReassignmentWritesNoProfileFlag(): void
    {
        $this->setOwnerBounced($this->testUserId, 'still-bounced@example.com');
        $flaggedCarId = $this->createTestCar($this->testUserId, ['chassis' => 'TR' . uniqid()]);
        $previousBefore = $this->getOwnerEmailFlags($this->testUserId);

        $noOwner = $this->db->query("SELECT id FROM users WHERE username = 'noowner'")->first();
        $this->assertNotNull($noOwner, 'noowner system account must exist for this test');
        $noOwnerProfilesBefore = (int) $this->db->query(
            'SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?',
            [(int) $noOwner->id]
        )->first()->cnt;

        $car = new Car($flaggedCarId);
        $car->transfer((int) $noOwner->id, 'Test reassignment to noowner', 'NEWOWNER', $this->testUserId);

        $this->assertSame($previousBefore, $this->getOwnerEmailFlags($this->testUserId));
        $this->assertSame(
            $noOwnerProfilesBefore,
            (int) $this->db->query(
                'SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?',
                [(int) $noOwner->id]
            )->first()->cnt,
            'The reassignment must not create or delete a noowner profile'
        );

        $histRow = $this->db->query(
            "SELECT car_id FROM cars_hist WHERE car_id = ? AND operation = 'NEWOWNER' ORDER BY timestamp DESC LIMIT 1",
            [$flaggedCarId]
        )->first();
        $this->assertIsObject($histRow, 'Expected a NEWOWNER row in cars_hist');
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
            . 'credential that would let the previous owner act on the car'
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
