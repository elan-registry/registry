<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
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
    /**
     * A database double standing in for a healthy connection with an empty
     * result set: query() and get() return the double itself (the real \DB
     * contract — both return $this for chaining), error() is false, the result
     * accessors report no rows (first() returns [], never null), writes
     * succeed, and no transaction is active.
     *
     * A stub rather than a mock: the tests using it assert on what the
     * repository returns, not on how it calls the database.
     *
     * @return \PHPUnit\Framework\MockObject\Stub&DatabaseInterface
     */
    private function makeEmptyResultDb(): object
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('get')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);
        $db->method('first')->willReturn([]);
        $db->method('results')->willReturn([]);
        $db->method('insert')->willReturn(true);
        $db->method('update')->willReturn(true);
        $db->method('lastId')->willReturn(1);
        $db->method('inTransaction')->willReturn(false);

        return $db;
    }

    public function testFindByIdReturnsObjectForExistingCar(): void
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('get')->willReturnSelf();
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn((object) ['id' => 1, 'chassis' => 'TEST123456']);

        $repo   = new CarRepository($db);
        $result = $repo->findById(1);

        $this->assertIsObject($result);
        $this->assertEquals(1, $result->id);
    }

    /**
     * findById() must throw CarDatabaseException — not fatal, and not silently
     * report "not found" — when get() itself fails (real \DB::get() returns the
     * literal false on a failed query, per DatabaseInterface's documented contract).
     */
    public function testFindByIdThrowsCarDatabaseExceptionWhenGetFails(): void
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('get')->willReturn(false);

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $repo->findById(1);
    }

    /**
     * findById() must return null — not the raw array — when count() reports a row
     * but first() yields the real \DB empty-row value ([]) rather than an object.
     * This should be unreachable against a real connection (count()>0 implies first()
     * is an object), but the is_object() guard exists to fail closed instead of
     * returning a caller-facing array where an object is documented.
     */
    public function testFindByIdReturnsNullWhenFirstYieldsNonObject(): void
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('get')->willReturnSelf();
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn([]);

        $repo = new CarRepository($db);

        $this->assertNull($repo->findById(1));
    }

    public function testInsertCarReturnsTrue(): void
    {
        $repo   = new CarRepository($this->makeEmptyResultDb());
        $result = $repo->insertCar(['chassis' => 'TEST99999', 'model' => 'Elan']);
        $this->assertTrue($result);
    }

    public function testUpdateCarReturnsTrue(): void
    {
        $repo   = new CarRepository($this->makeEmptyResultDb());
        $result = $repo->updateCar(1, ['color' => 'Blue']);
        $this->assertTrue($result);
    }

    public function testLastIdReturnsInt(): void
    {
        $repo = new CarRepository($this->makeEmptyResultDb());
        $repo->insertCar(['chassis' => 'TEST']);
        $lastId = $repo->lastId();
        $this->assertIsInt($lastId);
    }

    public function testTransactionMethodsDoNotThrow(): void
    {
        $repo = new CarRepository($this->makeEmptyResultDb());
        $repo->beginTransaction();
        $repo->commit();
        $this->expectNotToPerformAssertions();
    }

    public function testRollbackDoesNotThrow(): void
    {
        $repo = new CarRepository($this->makeEmptyResultDb());
        $repo->beginTransaction();
        $repo->rollback();
        $this->expectNotToPerformAssertions();
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
        $db = $this->createMock(DatabaseInterface::class);

        // No outer transaction — beginTransaction() should call through and flip the
        // state; commit() then sees inTransaction()=true and commits. Modeling actual
        // state via a callback (not willReturnOnConsecutiveCalls) means this doesn't
        // depend on inTransaction() being called exactly twice in exactly this order.
        $inTransaction = false;
        $db->method('inTransaction')->willReturnCallback(function () use (&$inTransaction): bool {
            return $inTransaction;
        });
        $db->expects($this->once())->method('beginTransaction')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = true;
                return true;
            });
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
        $db = $this->createMock(DatabaseInterface::class);

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
        $db = $this->createMock(DatabaseInterface::class);

        // beginTransaction() flips state to "in transaction"; rollback() then sees
        // inTransaction()=true and rolls back. State-modeled, not call-count-modeled —
        // see testStandaloneTransactionOwnsCommit's comment for why.
        $inTransaction = false;
        $db->method('inTransaction')->willReturnCallback(function () use (&$inTransaction): bool {
            return $inTransaction;
        });
        $db->expects($this->once())->method('beginTransaction')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = true;
                return true;
            });
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
        $db = $this->createMock(DatabaseInterface::class);

        $db->method('inTransaction')->willReturn(true);
        $db->expects($this->never())->method('rollBack');

        $repo = new CarRepository($db);
        $repo->beginTransaction(); // no-op
        $repo->rollback();         // no-op: $transactionOwner = false
    }

    public function testGetHistoryReturnsEmptyArrayWhenNoneFound(): void
    {
        $repo   = new CarRepository($this->makeEmptyResultDb());
        $result = $repo->getHistory(1);
        $this->assertIsArray($result);
        $this->assertSame([], $result);
    }

    public function testGetHistoryExcludesPII(): void
    {
        // Security contract: email, lname, user_id, lat, and lon must not appear in the
        // SELECT clause — this method backs the public, unauthenticated
        // app/api/cars/history.php endpoint (see #1501). vericode and last_verified are
        // not asserted here because cars_hist has no such columns — they exist only on
        // the cars table and can never leak from this path even under SELECT *.
        $capturedSql = null;
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(
                function (string $sql, array $params = []) use (&$capturedSql, $db): DatabaseInterface {
                    $capturedSql = $sql;
                    return $db;
                }
            );
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn([]);

        $repo = new CarRepository($db);
        $repo->getHistory(1);

        $this->assertNotNull($capturedSql, 'getHistory() must call DB::query()');
        $this->assertStringNotContainsStringIgnoringCase(
            'email', $capturedSql,
            'getHistory() SELECT must not include email column (PII)'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'lname', $capturedSql,
            'getHistory() SELECT must not include lname column (PII)'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'user_id', $capturedSql,
            'getHistory() SELECT must not include user_id column (#1501)'
        );
        // Word-boundary match, not substring: 'lat'/'lon' are short enough to
        // false-positive against a future column like 'plate' or 'longitude'.
        $this->assertDoesNotMatchRegularExpression(
            '/\blat\b/i', $capturedSql,
            'getHistory() SELECT must not include lat column (#1501)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\blon\b/i', $capturedSql,
            'getHistory() SELECT must not include lon column (#1501)'
        );
    }

    public function testInsertHistoryReturnsTrue(): void
    {
        $repo   = new CarRepository($this->makeEmptyResultDb());
        $result = $repo->insertHistory([
            'operation' => 'TEST',
            'car_id' => 1,
            'comments' => 'Test history'
        ]);
        $this->assertTrue($result);
    }

    public function testUpdateVerificationCodeReturnsTrue(): void
    {
        $repo   = new CarRepository($this->makeEmptyResultDb());
        $result = $repo->updateVerificationCode(1, 'TESTCODE12345678');
        $this->assertTrue($result);
    }

    public function testUpdateLastVerifiedReturnsTrue(): void
    {
        $repo   = new CarRepository($this->makeEmptyResultDb());
        $result = $repo->updateLastVerified(1, '2026-07-05 12:00:00');
        $this->assertTrue($result);
    }

    public function testUpdateSoldDateReturnsTrue(): void
    {
        $repo   = new CarRepository($this->makeEmptyResultDb());
        $result = $repo->updateSoldDate(1, '2026-07-05');
        $this->assertTrue($result);
    }

    public function testUpdateImageReturnsTrue(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);

        $repo = new CarRepository($db);
        $result = $repo->updateImage(1, '["new.jpg"]', '["old.jpg"]');

        $this->assertTrue($result);
    }

    public function testGetFilterOptionsReturnsCorrectShape(): void
    {
        $repo   = new CarRepository($this->makeEmptyResultDb());
        $result = $repo->getFilterOptions();
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

    /**
     * A bare database double for tests that shape the result themselves.
     *
     * query() must be stubbed with willReturnSelf() (or a callback returning
     * the double) wherever the repository chains off it — the real \DB::query()
     * always returns $this.
     *
     * @return \PHPUnit\Framework\MockObject\MockObject&DatabaseInterface
     */
    private function makeDbMock(): object
    {
        return $this->createMock(DatabaseInterface::class);
    }

    public function testReassignCarsByUserReturnsRowCount(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
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
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $repo = new CarRepository($db);
        $result = $repo->reassignCarsByUser(42, null);

        $this->assertIsInt($result);
    }

    public function testReassignCarsByUserThrowsOnDatabaseError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Deadlock found');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/reassignCarsByUser failed/');

        $repo->reassignCarsByUser(42, 7);
    }

    // =========================================================================
    // updateCarForOwner() tests (issue #1873)
    // =========================================================================

    /**
     * On success, updateCarForOwner() returns the row count reported by
     * DB::count() — rows *changed*, not matched (no MYSQL_ATTR_FOUND_ROWS).
     */
    public function testUpdateCarForOwnerReturnsRowCountOnSuccess(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);

        $repo = new CarRepository($db);
        $result = $repo->updateCarForOwner(101, 42, ['city' => 'Portland']);

        $this->assertSame(1, $result);
    }

    /**
     * The SQL must pin both id and user_id in the WHERE clause — that scoping
     * is the security fix (#1873): it prevents writing one owner's data onto a
     * car reassigned to someone else mid-loop. Column names are backtick-quoted
     * (matching DB::update()'s own quoting), values bound positionally in
     * fields-then-carId-then-userId order.
     */
    public function testUpdateCarForOwnerPinsIdAndUserIdInWhereClause(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE cars SET `city` = ?, `state` = ? WHERE id = ? AND user_id = ?',
                ['Portland', 'Oregon', 101, 42]
            )
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);

        $repo = new CarRepository($db);
        $repo->updateCarForOwner(101, 42, ['city' => 'Portland', 'state' => 'Oregon']);
    }

    /**
     * A query error must log and throw CarDatabaseException so an enclosing
     * transaction can roll back rather than commit over a partial state.
     */
    public function testUpdateCarForOwnerThrowsOnDatabaseError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Deadlock found');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/updateCarForOwner failed/');

        $repo->updateCarForOwner(101, 42, ['city' => 'Portland']);
    }

    /**
     * A 0-row change (either a no-op UPDATE that matched but changed nothing,
     * or a car no longer owned by this user) must return 0 without throwing —
     * disambiguating the two is the caller's job (Owner::carBelongsToOwner()),
     * not this method's.
     */
    public function testUpdateCarForOwnerReturnsZeroWithoutThrowingWhenNothingChanged(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $repo = new CarRepository($db);
        $result = $repo->updateCarForOwner(101, 42, ['city' => 'Portland']);

        $this->assertSame(0, $result);
    }

    /**
     * A malformed column name (not a valid SQL identifier) must throw
     * CarDatabaseException rather than being interpolated into the SET
     * clause — this is the injection-prevention check on the structured
     * $fields array, since column names (unlike values) cannot be bound as
     * placeholders. No query should be issued once a bad key is found.
     */
    public function testUpdateCarForOwnerThrowsOnInvalidColumnName(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->never())->method('query');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/Invalid column name/');

        $repo->updateCarForOwner(101, 42, ['city; DROP TABLE cars' => 'x']);
    }

    /**
     * A column name starting with a digit is also not a valid SQL identifier
     * and must be rejected the same way as an injection attempt — distinct
     * failure mode from the previous test (malformed-but-innocuous vs.
     * malformed-and-malicious), both must be caught by the same regex guard.
     */
    public function testUpdateCarForOwnerThrowsOnColumnNameStartingWithDigit(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->never())->method('query');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/Invalid column name/');

        $repo->updateCarForOwner(101, 42, ['0bad' => 'x']);
    }

    /**
     * Empty $fields must throw rather than return 0 without issuing SQL: a 0
     * return is already ambiguous between "matched but nothing changed" and "no
     * row matched", so an empty write would pass the caller's ownership check
     * and be reported as a successful sync having written nothing.
     */
    public function testUpdateCarForOwnerThrowsWhenFieldsEmpty(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->never())->method('query');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('called with no fields (carId=101 userId=42)');

        $repo->updateCarForOwner(101, 42, []);
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
        $db->expects($this->once())->method('query')->willReturnSelf();
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
        $db->expects($this->once())->method('query')->willReturnSelf();
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
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $repo = new CarRepository($db);

        $this->expectException(CarNotFoundException::class);
        $repo->deleteCar(999);
    }

    /**
     * deleteCar() returns false when the DB query itself errors out
     * (distinct from the 0-rows-affected CarNotFoundException path).
     */
    public function testDeleteCarReturnsFalseOnQueryError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);

        $repo = new CarRepository($db);
        $this->assertFalse($repo->deleteCar(42));
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
        $db->expects($this->once())->method('query')->willReturnSelf();
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
        $db->expects($this->once())->method('query')->willReturnSelf();
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
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $repo->findByIdForUpdate(1);
    }

    // =========================================================================
    // getAllForSitemap tests (issue #1373)
    // =========================================================================

    /**
     * getAllForSitemap() throws CarDatabaseException when the query fails, rather than
     * silently returning an empty array — a real DB outage must produce a visible 500 via
     * sitemap.php's catch block, not a healthy-looking sitemap missing every car.
     */
    public function testGetAllForSitemapThrowsCarDatabaseExceptionOnQueryError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Connection lost');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $repo->getAllForSitemap();
    }

    /**
     * getAllForSitemap() returns the rows from DB::results() on the happy path.
     *
     * Regression guard for #1441: the shared mock DB previously had no results()
     * method at all, so this call would have fatally errored ("Call to undefined
     * method DB::results()") the first time a unit test actually exercised it.
     */
    public function testGetAllForSitemapReturnsRows(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn([(object) ['id' => 1, 'mtime' => '2026-01-01 00:00:00']]);

        $repo = new CarRepository($db);
        $result = $repo->getAllForSitemap();

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]->id);
    }

    // =========================================================================
    // findByChassisKey() tests (issue #1764)
    // =========================================================================

    /**
     * findByChassisKey() returns the car object (id, user_id) when a matching
     * row is found.
     */
    public function testFindByChassisKeyReturnsObjectWhenFound(): void
    {
        $car = (object) ['id' => 1, 'user_id' => 42];

        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with(
                'SELECT id, user_id FROM cars WHERE year = ? AND type = ? AND chassis = ?',
                ['1973', '36', 'TEST001']
            )
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('first')->willReturn($car);

        $repo = new CarRepository($db);
        $result = $repo->findByChassisKey('1973', '36', 'TEST001');

        $this->assertIsObject($result);
        $this->assertSame(1, $result->id);
        $this->assertSame(42, $result->user_id);
    }

    /**
     * findByChassisKey() returns null when no matching row exists (real \DB
     * returns [] from first() rather than null when zero rows match).
     */
    public function testFindByChassisKeyReturnsNullWhenNotFound(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('first')->willReturn([]);

        $repo = new CarRepository($db);

        $this->assertNull($repo->findByChassisKey('1973', '36', 'NOMATCH'));
    }

    /**
     * findByChassisKey() throws CarDatabaseException when the query itself fails.
     */
    public function testFindByChassisKeyThrowsCarDatabaseExceptionOnQueryError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Connection lost');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/findByChassisKey failed/');
        $repo->findByChassisKey('1973', '36', 'TEST001');
    }

    // =========================================================================
    // Verification system backend tests (issue #1155)
    // =========================================================================

    public function testUpdateVerificationSentAtReturnsTrue(): void
    {
        $repo   = new CarRepository($this->makeEmptyResultDb());
        $result = $repo->updateVerificationSentAt(1, '2026-07-05 12:00:00');
        $this->assertTrue($result);
    }

    public function testUpdateEmailBouncedReturnsTrue(): void
    {
        $repo   = new CarRepository($this->makeEmptyResultDb());
        $result = $repo->updateEmailBounced(1, true);
        $this->assertTrue($result);
    }

    public function testUpdateOwnerLastUpdatedReturnsTrue(): void
    {
        $repo   = new CarRepository($this->makeEmptyResultDb());
        $result = $repo->updateOwnerLastUpdated(1, '2026-07-05 12:00:00');
        $this->assertTrue($result);
    }

    /**
     * findVerificationEligible() must build a WHERE clause covering every
     * eligibility condition: not sold, deliverable email, never-verified or
     * stale verification, and a stale owner-driven update, ordered oldest first.
     */
    public function testFindVerificationEligibleQueryContainsExpectedConditions(): void
    {
        $capturedSql = null;
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(
                function (string $sql, array $params = []) use (&$capturedSql, $db): DatabaseInterface {
                    $capturedSql = $sql;
                    return $db;
                }
            );
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn([]);

        $repo = new CarRepository($db);
        $repo->findVerificationEligible(10, 0);

        $this->assertNotNull($capturedSql, 'findVerificationEligible() must call DB::query()');
        $this->assertStringContainsString('solddate IS NULL', $capturedSql);
        $this->assertStringNotContainsString(
            "solddate = ''",
            $capturedSql,
            "solddate is a DATE column; comparing it to '' is a hard SQL error under STRICT_TRANS_TABLES"
        );
        $this->assertStringContainsString('email_bounced = 0', $capturedSql);
        $this->assertStringContainsString("email IS NOT NULL AND email != ''", $capturedSql);
        $this->assertStringContainsString(
            'NOT ((cars.last_verified IS NOT NULL AND cars.last_verified >= NOW() - INTERVAL 1 YEAR)'
                . ' OR cars.owner_last_updated >= NOW() - INTERVAL 1 YEAR)',
            $capturedSql,
            'findVerificationEligible() must filter on stalenessSql(), the exact negation of freshnessSql()'
        );
        $this->assertStringNotContainsString(
            'COALESCE',
            $capturedSql,
            'The COALESCE(owner_last_updated, mtime) fallback was removed by #1953 — owner_last_updated '
                . 'is NOT NULL by schema, so no fallback to mtime is needed or wanted'
        );
        $this->assertStringNotContainsString(
            'INTERVAL 2 YEAR',
            $capturedSql,
            'Freshness moved from a 2-year to a 1-year window'
        );
        $this->assertStringContainsString('ORDER BY last_verified ASC', $capturedSql);
    }

    /**
     * LIMIT/OFFSET are cast with max(0, ...) before interpolation — negative
     * inputs must render as 0 in the query, never as a negative number.
     */
    public function testFindVerificationEligibleClampsNegativeLimitAndOffset(): void
    {
        $capturedSql = null;
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(
                function (string $sql, array $params = []) use (&$capturedSql, $db): DatabaseInterface {
                    $capturedSql = $sql;
                    return $db;
                }
            );
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn([]);

        $repo = new CarRepository($db);
        $repo->findVerificationEligible(-5, -10);

        $this->assertNotNull($capturedSql);
        $this->assertStringContainsString('LIMIT 0 OFFSET 0', $capturedSql);
    }

    /**
     * findVerificationEligible() returns the rows from DB::results() on the happy path.
     */
    public function testFindVerificationEligibleReturnsRows(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn([(object) ['id' => 1, 'chassis' => 'TEST001']]);

        $repo = new CarRepository($db);
        $result = $repo->findVerificationEligible(10, 0);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]->id);
    }

    /**
     * findVerificationEligible() throws CarDatabaseException when the query fails.
     */
    public function testFindVerificationEligibleThrowsCarDatabaseExceptionOnQueryError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Connection lost');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/findVerificationEligible failed/');
        $repo->findVerificationEligible(10, 0);
    }
}
