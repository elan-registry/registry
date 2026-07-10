<?php

declare(strict_types=1);

use ElanRegistry\Transfer\CarTransferRepository;
use ElanRegistry\Transfer\TransferStatus;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Smoke tests for CarTransferRepository using the mock DB from bootstrap-unit.php.
 *
 * These tests run against a mock where query() always returns count=0, insert()
 * returns true, and lastId() returns 1 — SQL correctness (column names, WHERE
 * clauses, JOINs) is NOT verified here. Real-DB behavioral coverage lives in
 * tests/integration/transfer/CarTransferRepositoryIntegrationTest.php.
 */
#[Group('fast')]
#[Group('transfer')]
final class CarTransferRepositoryTest extends TestCase
{
    private CarTransferRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new CarTransferRepository(DB::getInstance());
    }

    public function testFindPendingByIdReturnsNullForMissingId(): void
    {
        $result = $this->repo->findPendingById(PHP_INT_MAX);
        $this->assertNull($result);
    }

    public function testHasPendingForCarReturnsFalseForMissingCar(): void
    {
        $result = $this->repo->hasPendingForCar(PHP_INT_MAX, PHP_INT_MAX);
        $this->assertFalse($result);
    }

    public function testFindByIdReturnsNullForMissingId(): void
    {
        $result = $this->repo->findById(PHP_INT_MAX);
        $this->assertNull($result);
    }

    public function testCreateReturnsPositiveInt(): void
    {
        $result = $this->repo->create([
            'existing_car_id'     => 1,
            'requested_by_user_id' => 2,
            'security_token'      => 'TESTTOKEN12345678901234567890123456789012345678',
            'expires_at'          => '2026-08-01 00:00:00',
            'submitted_model'     => 'Elan',
            'submitted_series'    => 'S4',
            'submitted_variant'   => 'SE',
            'submitted_year'      => '1973',
            'submitted_type'      => '26R',
            'submitted_chassis'   => 'TEST_CTR_001',
            'created_by'          => 2,
        ]);
        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
    }

    public function testUpdateStatusReturnsBool(): void
    {
        $id = $this->repo->create([
            'existing_car_id'     => 1,
            'requested_by_user_id' => 2,
            'security_token'      => 'TESTTOKEN_UPDATE_STATUS_1234567890123456789012',
            'expires_at'          => '2026-08-01 00:00:00',
            'submitted_model'     => 'Elan',
            'submitted_series'    => 'S4',
            'submitted_variant'   => 'SE',
            'submitted_year'      => '1973',
            'submitted_type'      => '26R',
            'submitted_chassis'   => 'TEST_CTR_002',
            'created_by'          => 2,
        ]);
        $this->assertGreaterThan(0, $id, 'Precondition: create must succeed');

        // Mock query() always returns empty results, so rows-affected = 0.
        // That rows-affected=0 → false path is the correct behavior (no row matched).
        // True/False with real rows is verified by the integration tests.
        $result = $this->repo->updateStatus($id, TransferStatus::Denied, 'Test denial');
        $this->assertIsBool($result);
    }

    public function testCountPendingReturnsInt(): void
    {
        $result = $this->repo->countPending();
        $this->assertIsInt($result);
    }

    public function testGetPendingWithCarAndUsersReturnsArray(): void
    {
        $result = $this->repo->getPendingWithCarAndUsers();
        $this->assertIsArray($result);
    }
}
