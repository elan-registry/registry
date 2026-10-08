<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Transfer\CarTransferRepository;
use ElanRegistry\Transfer\TransferStatus;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The stubs do not check SQL. integration/transfer/CarTransferRepositoryIntegrationTest
 * covers the queries against a real database.
 */
#[Group('fast')]
#[Group('transfer')]
final class CarTransferRepositoryTest extends TestCase
{
    private CarTransferRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new CarTransferRepository($this->makeEmptyResultDb());
    }

    /**
     * first() returns [], not null, to match the real \DB::first().
     *
     * @return \PHPUnit\Framework\MockObject\Stub&DatabaseInterface
     */
    private function makeEmptyResultDb(): object
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);
        $db->method('first')->willReturn([]);
        $db->method('results')->willReturn([]);
        $db->method('insert')->willReturn(true);
        $db->method('lastId')->willReturn(1);

        return $db;
    }

    public function testFindPendingByIdReturnsNullForMissingId(): void
    {
        $result = $this->repo->findPendingById(PHP_INT_MAX);
        $this->assertNull($result);
    }

    public function testFindPendingWithCarByIdReturnsNullForMissingId(): void
    {
        $result = $this->repo->findPendingWithCarById(PHP_INT_MAX);
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

    // Database error paths: each method must fail closed (#1441).

    /** @return \PHPUnit\Framework\MockObject\MockObject&DatabaseInterface */
    private function makeDbMock(): object
    {
        return $this->createMock(DatabaseInterface::class);
    }

    private function assertThrowsOnDatabaseError(callable $action): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Connection lost');

        $repo = new CarTransferRepository($db);

        $this->expectException(CarDatabaseException::class);
        $action($repo);
    }

    public function testFindByIdThrowsOnDatabaseError(): void
    {
        $this->assertThrowsOnDatabaseError(fn (CarTransferRepository $repo) => $repo->findById(1));
    }

    public function testFindPendingByIdThrowsOnDatabaseError(): void
    {
        $this->assertThrowsOnDatabaseError(fn (CarTransferRepository $repo) => $repo->findPendingById(1));
    }

    public function testFindPendingWithCarByIdThrowsOnDatabaseError(): void
    {
        $this->assertThrowsOnDatabaseError(fn (CarTransferRepository $repo) => $repo->findPendingWithCarById(1));
    }

    public function testHasPendingForCarThrowsOnDatabaseError(): void
    {
        $this->assertThrowsOnDatabaseError(fn (CarTransferRepository $repo) => $repo->hasPendingForCar(1, 2));
    }

    public function testGetPendingWithCarAndUsersThrowsOnDatabaseError(): void
    {
        $this->assertThrowsOnDatabaseError(fn (CarTransferRepository $repo) => $repo->getPendingWithCarAndUsers());
    }

    public function testGetTodayStatusCountsThrowsOnDatabaseError(): void
    {
        $this->assertThrowsOnDatabaseError(fn (CarTransferRepository $repo) => $repo->getTodayStatusCounts());
    }

    public function testCountPendingThrowsOnDatabaseError(): void
    {
        $this->assertThrowsOnDatabaseError(fn (CarTransferRepository $repo) => $repo->countPending());
    }

    public function testUpdateStatusThrowsOnDatabaseError(): void
    {
        $this->assertThrowsOnDatabaseError(
            fn (CarTransferRepository $repo) => $repo->updateStatus(1, TransferStatus::Denied, 'Test denial')
        );
    }

    // create() uses insert()/lastId(), not query()/error().

    public function testCreateThrowsOnInsertFailure(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('insert')->willReturn(false);
        $db->method('errorString')->willReturn('Duplicate entry');

        $repo = new CarTransferRepository($db);

        $this->expectException(CarDatabaseException::class);
        $repo->create(['existing_car_id' => 1]);
    }

    public function testCreateThrowsWhenNoIdReturned(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('insert')->willReturn(true);
        $db->expects($this->once())->method('lastId')->willReturn(0);

        $repo = new CarTransferRepository($db);

        $this->expectException(CarDatabaseException::class);
        $repo->create(['existing_car_id' => 1]);
    }
}
