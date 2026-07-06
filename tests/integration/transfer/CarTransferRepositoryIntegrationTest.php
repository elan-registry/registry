<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Transfer\CarTransferRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for CarTransferRepository against the real database.
 *
 * Verifies that repository methods produce correct SQL results — column names,
 * WHERE clauses, JOINs, status filters, and round-trip reads. Mock-backed unit
 * smoke tests live in tests/unit/transfer/CarTransferRepositoryTest.php.
 */
#[Group('integration')]
#[Group('transfer')]
final class CarTransferRepositoryIntegrationTest extends IntegrationTestCase
{
    private CarTransferRepository $repo;

    /** @var int[] Transfer request IDs to delete in tearDown */
    private array $createdTransferIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->repo = new CarTransferRepository($this->db);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdTransferIds as $id) {
            try {
                $this->db->query("DELETE FROM car_transfer_requests WHERE id = ?", [$id]);
            } catch (\Throwable) {
                // Ignore cleanup errors
            }
        }
        $this->createdTransferIds = [];
        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createTransferRequest(int $carId, int $requesterId, array $overrides = []): int
    {
        $defaults = [
            'existing_car_id'      => $carId,
            'requested_by_user_id' => $requesterId,
            'security_token'       => bin2hex(random_bytes(32)),
            'expires_at'           => date('Y-m-d H:i:s', strtotime('+30 days')),
            'submitted_model'      => 'S4|SE|FHC',
            'submitted_series'     => 'S4',
            'submitted_variant'    => 'SE',
            'submitted_year'       => '1973',
            'submitted_type'       => 'FHC',
            'submitted_chassis'    => 'INTTEST001',
            'submitted_color'      => 'Red',
            'submitted_engine'     => 'ENG001',
            'submitted_comments'   => 'Integration test request',
            'submitted_email'      => 'requester@example.com',
            'submitted_fname'      => 'Test',
            'submitted_lname'      => 'Requester',
            'submitted_city'       => 'Portland',
            'submitted_state'      => 'Oregon',
            'submitted_country'    => 'United States',
            'created_by'           => $requesterId,
        ];

        $id = $this->repo->create(array_merge($defaults, $overrides));
        $this->assertGreaterThan(0, $id, 'Precondition: CarTransferRepository::create() must return a positive ID');
        $this->createdTransferIds[] = $id;
        return $id;
    }

    // =========================================================================
    // Tests
    // =========================================================================

    /**
     * create() + findById() round-trip: the full row is persisted and retrieved.
     */
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

    /**
     * findById() returns null for a non-existent ID.
     */
    public function testFindByIdReturnsNullForMissingId(): void
    {
        $this->assertNull($this->repo->findById(PHP_INT_MAX));
    }

    /**
     * hasPendingForCar() returns true once a pending request exists.
     */
    public function testHasPendingForCarReturnsTrueWhenPendingExists(): void
    {
        $ownerId     = $this->createTestUser();
        $requesterId = $this->createTestUser();
        $carId       = $this->createTestCar($ownerId);

        $this->createTransferRequest($carId, $requesterId);

        $this->assertTrue($this->repo->hasPendingForCar($carId, $requesterId));
    }

    /**
     * hasPendingForCar() returns false when no pending request exists.
     */
    public function testHasPendingForCarReturnsFalseWhenNoneExists(): void
    {
        $this->assertFalse($this->repo->hasPendingForCar(PHP_INT_MAX, PHP_INT_MAX));
    }

    /**
     * findPendingById() returns the row while status is pending, and returns
     * null after updateStatus() changes it to denied.
     */
    public function testFindPendingByIdBeforeAndAfterStatusChange(): void
    {
        $ownerId     = $this->createTestUser();
        $requesterId = $this->createTestUser();
        $carId       = $this->createTestCar($ownerId);

        $id = $this->createTransferRequest($carId, $requesterId);

        $pending = $this->repo->findPendingById($id);
        $this->assertNotNull($pending, 'findPendingById() must find a pending row');
        $this->assertSame((string) $id, (string) $pending->id);

        $this->assertTrue($this->repo->updateStatus($id, 'denied', 'Test denial'));

        $this->assertNull(
            $this->repo->findPendingById($id),
            'findPendingById() must return null after status is changed away from pending'
        );
    }

    /**
     * updateStatus() persists the new status: read-back via raw query confirms the write.
     */
    public function testUpdateStatusPersistsChange(): void
    {
        $ownerId     = $this->createTestUser();
        $requesterId = $this->createTestUser();
        $carId       = $this->createTestCar($ownerId);

        $id = $this->createTransferRequest($carId, $requesterId);
        $this->assertTrue($this->repo->updateStatus($id, 'denied', 'Audit note'));

        $row = $this->db->query(
            "SELECT status, admin_notes FROM car_transfer_requests WHERE id = ?",
            [$id]
        )->first();

        $this->assertSame('denied', $row->status);
        $this->assertSame('Audit note', $row->admin_notes);
    }

    /**
     * findPendingWithCarById() returns joined car_id and current_owner_id fields.
     */
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

    /**
     * countPending() increments when a new pending request is created.
     */
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
     * hasPendingForCar() returns false after the pending request is resolved —
     * verifying the re-submission guard lifts once a request is no longer pending.
     */
    public function testHasPendingForCarReturnsFalseAfterStatusChange(): void
    {
        $ownerId     = $this->createTestUser();
        $requesterId = $this->createTestUser();
        $carId       = $this->createTestCar($ownerId);

        $id = $this->createTransferRequest($carId, $requesterId);
        $this->assertTrue($this->repo->hasPendingForCar($carId, $requesterId), 'Precondition: must be pending before status change');

        $this->repo->updateStatus($id, 'denied', 'Test');

        $this->assertFalse(
            $this->repo->hasPendingForCar($carId, $requesterId),
            'hasPendingForCar() must return false after the request is no longer pending'
        );
    }

    /**
     * getTodayStatusCounts() returns a row with the correct count after a denial.
     */
    public function testGetTodayStatusCountsReturnsCountAfterDenial(): void
    {
        $ownerId     = $this->createTestUser();
        $requesterId = $this->createTestUser();
        $carId       = $this->createTestCar($ownerId);

        $id = $this->createTransferRequest($carId, $requesterId);
        $this->repo->updateStatus($id, 'denied', 'Test denial');

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
