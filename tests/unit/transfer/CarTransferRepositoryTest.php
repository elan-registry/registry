<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Transfer\CarTransferRepository;
use ElanRegistry\Transfer\TransferStatus;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // Database error paths: each method must fail closed (#1441).

    /** @return \PHPUnit\Framework\MockObject\MockObject&DatabaseInterface */
    private function makeDbMock(): object
    {
        return $this->createMock(DatabaseInterface::class);
    }

    /**
     * @return array<string, array{0: \Closure(CarTransferRepository): mixed}>
     */
    public static function databaseErrorProvider(): array
    {
        return [
            'findById'                  => [fn (CarTransferRepository $repo) => $repo->findById(1)],
            'findPendingById'           => [fn (CarTransferRepository $repo) => $repo->findPendingById(1)],
            'findPendingWithCarById'    => [fn (CarTransferRepository $repo) => $repo->findPendingWithCarById(1)],
            'hasPendingForCar'          => [fn (CarTransferRepository $repo) => $repo->hasPendingForCar(1, 2)],
            'getPendingWithCarAndUsers' => [fn (CarTransferRepository $repo) => $repo->getPendingWithCarAndUsers()],
            'getTodayStatusCounts'      => [fn (CarTransferRepository $repo) => $repo->getTodayStatusCounts()],
            'countPending'              => [fn (CarTransferRepository $repo) => $repo->countPending()],
            'updateStatus'              => [fn (CarTransferRepository $repo) => $repo->updateStatus(1, TransferStatus::Denied, 'Test denial')],
        ];
    }

    /**
     * @param \Closure(CarTransferRepository): mixed $action
     */
    #[DataProvider('databaseErrorProvider')]
    public function testMethodThrowsOnDatabaseError(\Closure $action): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Connection lost');

        $repo = new CarTransferRepository($db);

        $this->expectException(CarDatabaseException::class);
        $action($repo);
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
