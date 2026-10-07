<?php

declare(strict_types=1);

require_once __DIR__ . '/TransferIntegrationTestCase.php';

use ElanRegistry\Transfer\TransferStatus;
use PHPUnit\Framework\Attributes\Group;

/**
 * CarTransferRepository against the real database. Mock-backed unit tests:
 * tests/unit/transfer/CarTransferRepositoryTest.php.
 */
#[Group('integration')]
#[Group('transfer')]
final class CarTransferRepositoryIntegrationTest extends TransferIntegrationTestCase
{
    public function testCreateAndFindByIdRoundTrip(): void
    {
        $ownerId     = $this->createTestUser();
        $requesterId = $this->createTestUser();
        $carId       = $this->createTestCar($ownerId);

        $id  = $this->createTransferRequest($carId, $requesterId);
        $row = $this->repo->findById($id);

        $this->assertNotNull($row, 'findById() must return the created row');
        $this->assertSame((string) $carId, (string) $row->existing_car_id);
        $this->assertSame((string) $requesterId, (string) $row->requested_by_user_id);
        $this->assertSame('pending', $row->status, 'Schema DEFAULT must set status to pending');
    }

    public function testFindByIdReturnsNullForMissingId(): void
    {
        $this->assertNull($this->repo->findById(PHP_INT_MAX));
    }

    public function testHasPendingForCarReturnsTrueWhenPendingExists(): void
    {
        $ownerId     = $this->createTestUser();
        $requesterId = $this->createTestUser();
        $carId       = $this->createTestCar($ownerId);

        $this->createTransferRequest($carId, $requesterId);

        $this->assertTrue($this->repo->hasPendingForCar($carId, $requesterId));
    }

    public function testHasPendingForCarReturnsFalseWhenNoneExists(): void
    {
        $this->assertFalse($this->repo->hasPendingForCar(PHP_INT_MAX, PHP_INT_MAX));
    }

    public function testFindPendingByIdBeforeAndAfterStatusChange(): void
    {
        $ownerId     = $this->createTestUser();
        $requesterId = $this->createTestUser();
        $carId       = $this->createTestCar($ownerId);

        $id = $this->createTransferRequest($carId, $requesterId);

        $pending = $this->repo->findPendingById($id);
        $this->assertNotNull($pending, 'findPendingById() must find a pending row');
        $this->assertSame((string) $id, (string) $pending->id);

        $this->assertTrue($this->repo->updateStatus($id, TransferStatus::Denied, 'Test denial'));

        $this->assertNull(
            $this->repo->findPendingById($id),
            'findPendingById() must return null after status is changed away from pending'
        );
    }

    public function testUpdateStatusPersistsChange(): void
    {
        $ownerId     = $this->createTestUser();
        $requesterId = $this->createTestUser();
        $carId       = $this->createTestCar($ownerId);

        $id = $this->createTransferRequest($carId, $requesterId);
        $this->assertTrue($this->repo->updateStatus($id, TransferStatus::Denied, 'Audit note'));

        $row = $this->db->query(
            "SELECT status, admin_notes FROM car_transfer_requests WHERE id = ?",
            [$id]
        )->first();

        $this->assertSame('denied', $row->status);
        $this->assertSame('Audit note', $row->admin_notes);
    }

    public function testFindPendingWithCarByIdReturnsJoinedFields(): void
    {
        $ownerId     = $this->createTestUser();
        $requesterId = $this->createTestUser();
        $carId       = $this->createTestCar($ownerId);

        $id  = $this->createTransferRequest($carId, $requesterId);
        $row = $this->repo->findPendingWithCarById($id);

        $this->assertNotNull($row, 'findPendingWithCarById() must return the pending row with car join');
        $this->assertSame((string) $carId, (string) $row->car_id, 'car_id alias must match created car');
        $this->assertSame((string) $ownerId, (string) $row->current_owner_id, 'current_owner_id must match car owner');
    }

    public function testCountPendingIncreasesAfterCreate(): void
    {
        $before = $this->repo->countPending();

        $ownerId     = $this->createTestUser();
        $requesterId = $this->createTestUser();
        $carId       = $this->createTestCar($ownerId);
        $this->createTransferRequest($carId, $requesterId);

        $after = $this->repo->countPending();
        $this->assertGreaterThan($before, $after, 'countPending() must increase after a new pending request is inserted');
    }

    /**
     * The re-submission guard lifts once the request is no longer pending.
     */
    public function testHasPendingForCarReturnsFalseAfterStatusChange(): void
    {
        $ownerId     = $this->createTestUser();
        $requesterId = $this->createTestUser();
        $carId       = $this->createTestCar($ownerId);

        $id = $this->createTransferRequest($carId, $requesterId);
        $this->assertTrue($this->repo->hasPendingForCar($carId, $requesterId), 'Precondition: must be pending before status change');

        $this->repo->updateStatus($id, TransferStatus::Denied, 'Test');

        $this->assertFalse(
            $this->repo->hasPendingForCar($carId, $requesterId),
            'hasPendingForCar() must return false after the request is no longer pending'
        );
    }

    public function testGetTodayStatusCountsReturnsCountAfterDenial(): void
    {
        $ownerId     = $this->createTestUser();
        $requesterId = $this->createTestUser();
        $carId       = $this->createTestCar($ownerId);

        $id = $this->createTransferRequest($carId, $requesterId);
        $this->repo->updateStatus($id, TransferStatus::Denied, 'Test denial');

        $counts = $this->repo->getTodayStatusCounts();

        $deniedCount = 0;
        foreach ($counts as $row) {
            if ($row->status === 'denied') {
                $deniedCount = (int) $row->count;
            }
        }

        $this->assertGreaterThanOrEqual(1, $deniedCount, 'getTodayStatusCounts() must include the denied request created today');
    }
}
