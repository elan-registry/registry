<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarNotFoundException;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for CarRepository service class
 */
#[Group('fast')]
final class CarRepositoryTest extends TestCase
{
    private CarRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new CarRepository(DB::getInstance());
    }

    public function testFindByIdReturnsObjectForExistingCar(): void
    {
        $result = $this->repo->findById(1);
        $this->assertIsObject($result);
        $this->assertEquals(1, $result->id);
    }

    public function testInsertCarReturnsTrue(): void
    {
        $result = $this->repo->insertCar(['chassis' => 'TEST99999', 'model' => 'Elan']);
        $this->assertTrue($result);
    }

    public function testUpdateCarReturnsTrue(): void
    {
        $result = $this->repo->updateCar(1, ['color' => 'Blue']);
        $this->assertTrue($result);
    }

    public function testLastIdReturnsInt(): void
    {
        $this->repo->insertCar(['chassis' => 'TEST']);
        $lastId = $this->repo->lastId();
        $this->assertIsInt($lastId);
    }

    public function testGetDbReturnsDbInstance(): void
    {
        $db = $this->repo->getDb();
        $this->assertInstanceOf(DB::class, $db);
    }

    public function testTransactionMethodsDoNotThrow(): void
    {
        $this->repo->beginTransaction();
        $this->repo->commit();
        $this->assertTrue(true);
    }

    public function testRollbackDoesNotThrow(): void
    {
        $this->repo->beginTransaction();
        $this->repo->rollback();
        $this->assertTrue(true);
    }

    // =========================================================================
    // Transaction nesting tests (issue #1175)
    //
    // CarRepository tracks whether it started the transaction via $transactionOwner.
    // When beginTransaction() is called while a transaction is already active
    // (inTransaction() = true), it is a no-op and $transactionOwner stays false.
    // commit() and rollback() are then also no-ops, leaving the outer transaction
    // in control of commit/rollback.
    // =========================================================================

    /**
     * When no outer transaction exists, beginTransaction() calls through to the DB
     * and commit() calls through to the DB exactly once.
     */
    public function testStandaloneTransactionOwnsCommit(): void
    {
        $db = $this->createMock(DB::class);

        // No outer transaction — beginTransaction() should call through.
        // beginTransaction() checks inTransaction() = false → starts tx.
        // commit() checks inTransaction() = true → commits.
        $db->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->once())->method('commit');

        $repo = new CarRepository($db);
        $repo->beginTransaction();
        $repo->commit();
    }

    /**
     * When an outer transaction is already active, beginTransaction() is a no-op
     * and commit() is a no-op — the DB commit() is never called.
     *
     * This is the nested-transaction scenario in process-transfer-approve.php:
     * the outer $db->beginTransaction() is called first, then Car::transfer()
     * internally calls CarRepository::beginTransaction() which must not start
     * a second transaction or commit prematurely.
     */
    public function testNestedTransactionDoesNotCommit(): void
    {
        $db = $this->createMock(DB::class);

        // Outer transaction already active — every inTransaction() call returns true.
        $db->method('inTransaction')->willReturn(true);
        $db->expects($this->never())->method('beginTransaction');
        $db->expects($this->never())->method('commit');

        $repo = new CarRepository($db);
        $repo->beginTransaction(); // no-op: inTransaction() = true
        $repo->commit();           // no-op: $transactionOwner was never set to true
    }

    /**
     * When no outer transaction exists, rollback() calls through to the DB.
     *
     * This is the symmetric counterpart to testStandaloneTransactionOwnsCommit:
     * when the repository began the transaction itself, rollback() must fire and
     * commit() must never be called.
     */
    public function testStandaloneTransactionOwnsRollback(): void
    {
        $db = $this->createMock(DB::class);

        // beginTransaction() checks inTransaction() = false → starts tx.
        // rollback() checks inTransaction() = true → rolls back.
        $db->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->once())->method('rollBack');
        $db->expects($this->never())->method('commit');

        $repo = new CarRepository($db);
        $repo->beginTransaction();
        $repo->rollback();
    }

    /**
     * When an outer transaction is already active, rollback() is also a no-op.
     *
     * The outer caller is responsible for rolling back; CarRepository must not
     * interfere by issuing its own rollBack().
     */
    public function testRollbackIsNoOpWhenNotOwner(): void
    {
        $db = $this->createMock(DB::class);

        $db->method('inTransaction')->willReturn(true);
        $db->expects($this->never())->method('rollBack');

        $repo = new CarRepository($db);
        $repo->beginTransaction(); // no-op
        $repo->rollback();         // no-op: $transactionOwner = false
    }

    public function testGetHistoryReturnsEmptyArrayWhenNoneFound(): void
    {
        $result = $this->repo->getHistory(1);
        $this->assertIsArray($result);
        $this->assertSame([], $result);
    }

    public function testInsertHistoryReturnsTrue(): void
    {
        $result = $this->repo->insertHistory([
            'operation' => 'TEST',
            'car_id' => 1,
            'comments' => 'Test history'
        ]);
        $this->assertTrue($result);
    }

    public function testUpdateVerificationCodeReturnsTrue(): void
    {
        $result = $this->repo->updateVerificationCode(1, 'TESTCODE12345678');
        $this->assertTrue($result);
    }

    public function testUpdateLastVerifiedReturnsTrue(): void
    {
        $result = $this->repo->updateLastVerified(1, '2026-07-05 12:00:00');
        $this->assertTrue($result);
    }

    public function testUpdateSoldDateReturnsTrue(): void
    {
        $result = $this->repo->updateSoldDate(1, '2026-07-05');
        $this->assertTrue($result);
    }

    public function testUpdateImageReturnsTrue(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturn(new QueryResult([]));
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);

        $repo = new CarRepository($db);
        $result = $repo->updateImage(1, '["new.jpg"]', '["old.jpg"]');

        $this->assertTrue($result);
    }

    public function testGetFilterOptionsReturnsCorrectShape(): void
    {
        $result = $this->repo->getFilterOptions();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('series', $result);
        $this->assertArrayHasKey('types', $result);
        $this->assertArrayHasKey('variants', $result);
        $this->assertIsArray($result['series']);
        $this->assertIsArray($result['types']);
        $this->assertIsArray($result['variants']);
    }

    // =========================================================================
    // reassignCarsByUser tests (issue #1148)
    // =========================================================================

    /** @return \PHPUnit\Framework\MockObject\MockObject&DB */
    private function makeDbMock(): object
    {
        return $this->getMockBuilder(DB::class)
            ->onlyMethods(['query', 'error', 'errorString', 'count', 'first'])
            ->getMock();
    }

    public function testReassignCarsByUserReturnsRowCount(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturn(new QueryResult([]));
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(3);

        $repo = new CarRepository($db);
        $result = $repo->reassignCarsByUser(42, 7);

        $this->assertSame(3, $result);
    }

    public function testReassignCarsByUserWithNullTargetPassesNullToQuery(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE cars SET user_id = ? WHERE user_id = ?',
                [null, 42]
            )
            ->willReturn(new QueryResult([]));
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $repo = new CarRepository($db);
        $result = $repo->reassignCarsByUser(42, null);

        $this->assertIsInt($result);
    }

    public function testReassignCarsByUserThrowsOnDatabaseError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturn(new QueryResult([]));
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Deadlock found');

        $repo = new CarRepository($db);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/reassignCarsByUser failed/');

        $repo->reassignCarsByUser(42, 7);
    }

    // =========================================================================
    // updateImage() CAS semantics tests (issue #1311)
    // =========================================================================

    /**
     * updateImage() returns false when the UPDATE matches 0 rows, indicating that
     * the image column was modified concurrently after the caller read it.
     */
    public function testUpdateImageReturnsFalseOnConcurrentModification(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturn(new QueryResult([]));
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $repo = new CarRepository($db);
        $result = $repo->updateImage(1, '["new.jpg"]', '["old.jpg"]');

        $this->assertFalse($result);
    }

    /**
     * updateImage() throws CarDatabaseException when the DB query itself fails
     * (e.g. connection lost, constraint violation).
     */
    public function testUpdateImageThrowsCarDatabaseExceptionOnQueryError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturn(new QueryResult([]));
        $db->method('error')->willReturn(true);

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $repo->updateImage(1, '["new.jpg"]', '["old.jpg"]');
    }

    // =========================================================================
    // deleteCar() rows-affected guard tests (issue #1311)
    // =========================================================================

    /**
     * deleteCar() throws CarNotFoundException when the DELETE affects 0 rows,
     * meaning the car was already deleted by a concurrent request.
     */
    public function testDeleteCarThrowsCarNotFoundExceptionWhenNoRowsAffected(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturn(new QueryResult([]));
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $repo = new CarRepository($db);

        $this->expectException(CarNotFoundException::class);
        $repo->deleteCar(999);
    }

    // =========================================================================
    // findByIdForUpdate() tests (issue #1311)
    // =========================================================================

    /**
     * findByIdForUpdate() returns null when the SELECT FOR UPDATE finds no row.
     */
    public function testFindByIdForUpdateReturnsNullWhenNotFound(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturn(new QueryResult([]));
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $repo = new CarRepository($db);

        $this->assertNull($repo->findByIdForUpdate(1));
    }

    /**
     * findByIdForUpdate() returns the car stdClass object when a row is found.
     */
    public function testFindByIdForUpdateReturnsCarObjectWhenFound(): void
    {
        $car = (object) ['id' => 1, 'chassis' => 'TEST001'];

        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturn(new QueryResult([]));
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn($car);

        $repo = new CarRepository($db);
        $result = $repo->findByIdForUpdate(1);

        $this->assertIsObject($result);
        $this->assertSame(1, $result->id);
        $this->assertSame('TEST001', $result->chassis);
    }

    /**
     * findByIdForUpdate() throws CarDatabaseException when the query fails
     * (e.g. no active transaction, connection error).
     */
    public function testFindByIdForUpdateThrowsCarDatabaseExceptionOnQueryError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturn(new QueryResult([]));
        $db->method('error')->willReturn(true);

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $repo->findByIdForUpdate(1);
    }
}
