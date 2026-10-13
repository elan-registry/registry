<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

#[Group('fast')]
final class CarRepositoryTest extends TestCase
{
    /**
     * first() returns [], not null, to match the real \DB.
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

    /** The real \DB::get() returns false on a failed query. */
    public function testFindByIdThrowsCarDatabaseExceptionWhenGetFails(): void
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('get')->willReturn(false);

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $repo->findById(1);
    }

    /**
     * Unreachable on a real connection; the is_object() guard fails closed
     * instead of returning an array.
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

    // Transaction nesting (#1175): inside an outer transaction, begin/commit/rollback are no-ops.

    public function testStandaloneTransactionOwnsCommit(): void
    {
        $db = $this->createMock(DatabaseInterface::class);

        // State via a callback, so the test does not depend on the inTransaction() call count.
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

    /** The process-transfer-approve.php case: Car::transfer() runs inside an outer transaction. */
    public function testNestedTransactionDoesNotCommit(): void
    {
        $db = $this->createMock(DatabaseInterface::class);

        $db->method('inTransaction')->willReturn(true);
        $db->expects($this->never())->method('beginTransaction');
        $db->expects($this->never())->method('commit');

        $repo = new CarRepository($db);
        $repo->beginTransaction(); // no-op: inTransaction() = true
        $repo->commit();           // no-op: $transactionOwner was never set to true
    }

    public function testStandaloneTransactionOwnsRollback(): void
    {
        $db = $this->createMock(DatabaseInterface::class);

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
        // This SELECT backs the public history.php endpoint (#1501). vericode and
        // last_verified are not checked: cars_hist has no such columns.
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
        // Word boundary: 'lat'/'lon' would match 'plate' or 'longitude'.
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

    // reassignCarsByUser() (#1148)

    /** @return \PHPUnit\Framework\MockObject\MockObject&DatabaseInterface */
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

    // updateCarForOwner() (#1873)

    /** count() is rows changed, not matched (no MYSQL_ATTR_FOUND_ROWS). */
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

    /** The id + user_id scope stops a write onto a car reassigned mid-loop (#1873). */
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

    /** Throws so an enclosing transaction rolls back. */
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

    /** The caller (Owner::carBelongsToOwner()) tells the two zero cases apart. */
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

    /** Column names cannot be bound, so a bad key must throw before any query. */
    public function testUpdateCarForOwnerThrowsOnInvalidColumnName(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->never())->method('query');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/Invalid column name/');

        $repo->updateCarForOwner(101, 42, ['city; DROP TABLE cars' => 'x']);
    }

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
     * A 0 return would pass the caller's ownership check and report a sync
     * that wrote nothing.
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

    // updateImage() CAS (#1311)

    /** 0 rows means a concurrent change to the image column. */
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

    public function testUpdateImageThrowsCarDatabaseExceptionOnQueryError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $repo->updateImage(1, '["new.jpg"]', '["old.jpg"]');
    }

    // updateImage() owner_last_updated (#1929)

    /** One UPDATE, so the cars_update trigger writes one cars_hist row. */
    public function testUpdateImageWithTimestampIncludesOwnerLastUpdatedInSql(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE cars SET image = ?, owner_last_updated = ? WHERE id = ? AND image <=> ?',
                ['["new.jpg"]', '2026-09-29 10:00:00', 1, '["old.jpg"]']
            )
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);

        $repo = new CarRepository($db);

        $this->assertTrue($repo->updateImage(1, '["new.jpg"]', '["old.jpg"]', '2026-09-29 10:00:00'));
    }

    public function testUpdateImageWithoutTimestampSqlUnchanged(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE cars SET image = ? WHERE id = ? AND image <=> ?',
                ['["new.jpg"]', 1, '["old.jpg"]']
            )
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);

        $repo = new CarRepository($db);

        $this->assertTrue($repo->updateImage(1, '["new.jpg"]', '["old.jpg"]'));
    }

    public function testUpdateImageCasFailureReturnsFalseWithTimestampArgSet(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $repo = new CarRepository($db);

        $this->assertFalse($repo->updateImage(1, '["new.jpg"]', '["old.jpg"]', '2026-09-29 10:00:00'));
    }

    // deleteCar() rows-affected guard (#1311)

    /** 0 rows means a concurrent request already deleted the car. */
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

    public function testDeleteCarReturnsFalseOnQueryError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);

        $repo = new CarRepository($db);
        $this->assertFalse($repo->deleteCar(42));
    }

    // findByIdForUpdate() (#1311)

    public function testFindByIdForUpdateReturnsNullWhenNotFound(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $repo = new CarRepository($db);

        $this->assertNull($repo->findByIdForUpdate(1));
    }

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

    public function testFindByIdForUpdateThrowsCarDatabaseExceptionOnQueryError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $repo->findByIdForUpdate(1);
    }

    // getAllForSitemap() (#1373)

    /** An outage must give sitemap.php a 500, not a sitemap with no cars. */
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

    // findByChassisKey() (#1764)

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

    public function testFindByChassisKeyReturnsNullWhenNotFound(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('first')->willReturn([]);

        $repo = new CarRepository($db);

        $this->assertNull($repo->findByChassisKey('1973', '36', 'NOMATCH'));
    }

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

    // Verification backend (#1155)

    public function testUpdateVerificationSentAtReturnsTrue(): void
    {
        $repo   = new CarRepository($this->makeEmptyResultDb());
        $result = $repo->updateVerificationSentAt(1, '2026-07-05 12:00:00');
        $this->assertTrue($result);
    }

    // incrementVerificationAttempts() (#1884)

    public function testIncrementVerificationAttemptsReturnsTrueWhenRowMatched(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE cars'),
                [7]
            )
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);

        $repo = new CarRepository($db);
        $result = $repo->incrementVerificationAttempts(7);

        $this->assertTrue($result);
    }

    public function testIncrementVerificationAttemptsSendsExpectedCaseStructure(): void
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
        $db->method('count')->willReturn(1);

        $repo = new CarRepository($db);
        $repo->incrementVerificationAttempts(7);

        $this->assertNotNull($capturedSql);
        $this->assertStringContainsString('verification_attempts_since IS NULL', $capturedSql);
        $this->assertStringContainsString('INTERVAL 1 YEAR', $capturedSql);
        $this->assertStringContainsString('verification_attempts + 1', $capturedSql);
        $this->assertStringContainsString('WHERE id = ?', $capturedSql);
    }

    /**
     * Zero rows is unambiguous here: the CASE always changes a matched row.
     * Not thrown: the only caller runs after the email is sent.
     */
    public function testIncrementVerificationAttemptsReturnsFalseAndLogsWhenNoRowMatched(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $repo = new CarRepository($db);
        $result = $repo->incrementVerificationAttempts(999);

        $this->assertFalse($result);
    }

    public function testIncrementVerificationAttemptsThrowsOnDatabaseError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Connection lost');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/incrementVerificationAttempts failed for car=7/');
        $repo->incrementVerificationAttempts(7);
    }

    // restoreVerificationCodeState() (#1884)

    /**
     * A swapped bind order would write a vericode into vericode_sent_at.
     * CarVerificationSendServiceTest only mocks this method.
     */
    public function testRestoreVerificationCodeStateBindsParametersInDeclaredOrder(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE cars SET vericode = ?, vericode_sent_at = ? WHERE id = ?',
                ['abc123hash', '2026-09-01 12:00:00', 7]
            )
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);

        $repo = new CarRepository($db);
        $result = $repo->restoreVerificationCodeState(7, 'abc123hash', '2026-09-01 12:00:00');

        $this->assertTrue($result);
    }

    public function testRestoreVerificationCodeStateAcceptsNullVericodeAndSentAt(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE cars SET vericode = ?, vericode_sent_at = ? WHERE id = ?',
                [null, null, 7]
            )
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);

        $repo = new CarRepository($db);
        $result = $repo->restoreVerificationCodeState(7, null, null);

        $this->assertTrue($result);
    }

    /**
     * Restoring unchanged values (often NULL/NULL) gives count() === 0 on a
     * present row. That once logged a false CRITICAL in sendOne().
     */
    public function testRestoreVerificationCodeStateReturnsTrueWhenWriteChangedNothingButRowExists(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->exactly(2))->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);
        $db->method('first')->willReturn((object) ['id' => 7]);

        $repo = new CarRepository($db);

        $this->assertTrue($repo->restoreVerificationCodeState(7, null, null));
    }

    public function testRestoreVerificationCodeStateConfirmReadQueriesTheTargetCarId(): void
    {
        $db = $this->makeDbMock();
        $queries = [];
        $db->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$queries, $db) {
                $queries[] = [$sql, $params];
                return $db;
            });
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);
        $db->method('first')->willReturn((object) ['id' => 7]);

        $repo = new CarRepository($db);
        $repo->restoreVerificationCodeState(7, 'code', '2026-09-01 12:00:00');

        $this->assertSame(
            ['SELECT id FROM cars WHERE id = ?', [7]],
            $queries[1]
        );
    }

    public function testRestoreVerificationCodeStateReturnsFalseWhenCarDoesNotExist(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->exactly(2))->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);
        $db->method('first')->willReturn([]);

        $repo = new CarRepository($db);
        $result = $repo->restoreVerificationCodeState(999, 'code', '2026-09-01 12:00:00');

        $this->assertFalse($result);
    }

    public function testRestoreVerificationCodeStateSkipsConfirmReadWhenRowWasChanged(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->expects($this->never())->method('first');

        $repo = new CarRepository($db);

        $this->assertTrue($repo->restoreVerificationCodeState(7, 'code', '2026-09-01 12:00:00'));
    }

    public function testRestoreVerificationCodeStateThrowsWhenConfirmReadFails(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->exactly(2))->method('query')->willReturnSelf();
        $db->method('error')->willReturnOnConsecutiveCalls(false, true);
        $db->method('errorString')->willReturn('Connection lost');
        $db->method('count')->willReturn(0);

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/confirm-read failed for car=7/');
        $repo->restoreVerificationCodeState(7, 'code', '2026-09-01 12:00:00');
    }

    public function testRestoreVerificationCodeStateThrowsOnDatabaseError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Connection lost');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/restoreVerificationCodeState failed for car=7/');
        $repo->restoreVerificationCodeState(7, 'code', '2026-09-01 12:00:00');
    }

    // Profile flag methods

    /**
     * A DatabaseInterface mock that answers findProfileEmailState() with $row
     * (null for "no profiles row") and records every other query.
     *
     * @param array<string, mixed>|null $row Profile row, or null for no row
     * @param list<array{sql: string, params: array<mixed>}> $writes Out: queries other than the profile SELECT
     * @param list<string> $selects Out: SQL of each profile SELECT
     */
    private function makeProfileDb(?array $row, array &$writes, array &$selects = [], bool $writeFails = false): object
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use ($db, &$writes, &$selects) {
                if (str_starts_with(ltrim($sql), 'SELECT id, user_id, email_opted_out')) {
                    $selects[] = $sql;
                } else {
                    $writes[] = ['sql' => $sql, 'params' => $params];
                }
                return $db;
            }
        );
        $db->method('error')->willReturnCallback(
            static function () use ($writeFails, &$writes): bool {
                return $writeFails && $writes !== [];
            }
        );
        $db->method('errorString')->willReturn('Deadlock found');
        $db->method('count')->willReturn($row === null ? 0 : 1);
        $db->method('first')->willReturn($row === null ? [] : (object) $row);

        return $db;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function profileRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 5,
            'user_id' => 42,
            'email_opted_out' => 0,
            'email_opted_out_at' => null,
            'email_blocked' => 0,
            'email_blocked_address' => null,
            'email_blocked_at' => null,
            'email_blocked_cause' => null,
            'email_bounced' => 0,
            'email_bounced_address' => null,
            'email_bounced_at' => null,
            'email_bounce_cleared_at' => null,
        ], $overrides);
    }

    public function testFindProfileEmailStateReturnsTheRowAsAnObject(): void
    {
        $writes = [];
        $selects = [];
        $repo = new CarRepository($this->makeProfileDb(self::profileRow(['email_blocked' => 1]), $writes, $selects));

        $state = $repo->findProfileEmailState(42);

        $this->assertNotNull($state);
        $this->assertSame(1, $state->email_blocked);
        $this->assertCount(1, $selects);
        $this->assertStringContainsString('FROM profiles', $selects[0]);
        $this->assertStringNotContainsString('FOR UPDATE', $selects[0], 'The default read takes no lock');
    }

    public function testFindProfileEmailStateReturnsNullWhenNoProfileRow(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(null, $writes));

        $this->assertNull($repo->findProfileEmailState(42));
    }

    public function testFindProfileEmailStateForUpdateAddsTheRowLock(): void
    {
        $writes = [];
        $selects = [];
        $repo = new CarRepository($this->makeProfileDb(self::profileRow(), $writes, $selects));

        $repo->findProfileEmailState(42, true);

        $this->assertCount(1, $selects);
        $this->assertStringEndsWith('FOR UPDATE', $selects[0]);
    }

    public function testFindProfileEmailStateThrowsOnDatabaseError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Connection lost');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/findProfileEmailState failed for user=42/');
        $repo->findProfileEmailState(42);
    }

    public function testSetProfileOptedOutWritesOneProfileRowAndReturnsOldValue(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(self::profileRow(), $writes));

        $old = $repo->setProfileOptedOut(42, true);

        $this->assertSame(0, $old);
        $this->assertCount(1, $writes, 'One profile UPDATE, nothing else');
        $this->assertSame('UPDATE profiles SET email_opted_out = ?, email_opted_out_at = ? WHERE user_id = ?', $writes[0]['sql']);
        $this->assertSame(1, $writes[0]['params'][0]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $writes[0]['params'][1]);
        $this->assertSame(42, $writes[0]['params'][2]);
    }

    /** A repeat opt-out keeps the first time. */
    public function testSetProfileOptedOutWritesNothingWhenAlreadyOptedOut(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(
            self::profileRow(['email_opted_out' => 1, 'email_opted_out_at' => '2026-01-01 00:00:00']),
            $writes
        ));

        $this->assertSame(1, $repo->setProfileOptedOut(42, true));
        $this->assertSame([], $writes);
    }

    /** The opt-out stands when a block is also set. */
    public function testSetProfileOptedOutIgnoresBlockedAndBouncedFlags(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(
            self::profileRow(['email_blocked' => 1, 'email_bounced' => 1]),
            $writes
        ));

        $this->assertSame(0, $repo->setProfileOptedOut(42, true));
        $this->assertCount(1, $writes);
        $this->assertStringNotContainsString('email_blocked', $writes[0]['sql']);
        $this->assertStringNotContainsString('email_bounced', $writes[0]['sql']);
    }

    public function testSetProfileOptedOutClearsBothColumnsOnResume(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(
            self::profileRow(['email_opted_out' => 1, 'email_opted_out_at' => '2026-01-01 00:00:00']),
            $writes
        ));

        $this->assertSame(1, $repo->setProfileOptedOut(42, false));
        $this->assertCount(1, $writes);
        $this->assertSame([0, null, 42], $writes[0]['params']);
    }

    public function testSetProfileOptedOutReturnsNullAndWritesNothingWithoutAProfileRow(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(null, $writes));

        $this->assertNull($repo->setProfileOptedOut(42, true));
        $this->assertSame([], $writes);
    }

    public function testSetProfileBlockedStoresNormalizedAddressTimeAndCause(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(self::profileRow(), $writes));

        $old = $repo->setProfileBlocked(42, '  Owner@Example.COM ', 'spam', '2026-09-01 10:00:00');

        $this->assertSame(0, $old);
        $this->assertCount(1, $writes);
        $this->assertStringContainsString('email_blocked = 1', $writes[0]['sql']);
        $this->assertSame(['owner@example.com', '2026-09-01 10:00:00', 'spam', 42], $writes[0]['params']);
    }

    /** A repeat block of the same address keeps the first time and cause. */
    public function testSetProfileBlockedWritesNothingForTheSameAddressAgain(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(
            self::profileRow([
                'email_blocked' => 1,
                'email_blocked_address' => 'owner@example.com',
                'email_blocked_at' => '2026-01-01 00:00:00',
                'email_blocked_cause' => 'spam',
            ]),
            $writes
        ));

        $this->assertSame(1, $repo->setProfileBlocked(42, 'OWNER@example.com', 'unsubscribed', '2026-09-01 10:00:00'));
        $this->assertSame([], $writes);
    }

    public function testSetProfileBlockedRewritesAllThreeFieldsForADifferentAddress(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(
            self::profileRow([
                'email_blocked' => 1,
                'email_blocked_address' => 'old@example.com',
                'email_blocked_at' => '2026-01-01 00:00:00',
                'email_blocked_cause' => 'spam',
            ]),
            $writes
        ));

        $repo->setProfileBlocked(42, 'new@example.com', 'unsubscribed', '2026-09-01 10:00:00');

        $this->assertCount(1, $writes);
        $this->assertSame(['new@example.com', '2026-09-01 10:00:00', 'unsubscribed', 42], $writes[0]['params']);
    }

    /** A block with an empty stored address is rewritten, so it gains an address. */
    public function testSetProfileBlockedRewritesAFlagThatHasNoStoredAddress(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(
            self::profileRow(['email_blocked' => 1, 'email_blocked_address' => '']),
            $writes
        ));

        $repo->setProfileBlocked(42, 'owner@example.com', null, '2026-09-01 10:00:00');

        $this->assertCount(1, $writes);
        $this->assertSame(['owner@example.com', '2026-09-01 10:00:00', null, 42], $writes[0]['params']);
    }

    /**
     * @return array<string, array{string, ?string, string}>
     */
    public static function invalidBlockInputProvider(): array
    {
        return [
            'empty address' => ['', 'spam', '2026-09-01 10:00:00'],
            'whitespace address' => ['   ', 'spam', '2026-09-01 10:00:00'],
            'unknown cause' => ['owner@example.com', 'not-a-cause', '2026-09-01 10:00:00'],
            'malformed time' => ['owner@example.com', 'spam', 'yesterday'],
            'rolled-over date' => ['owner@example.com', 'spam', '2026-02-30 10:00:00'],
        ];
    }

    #[DataProvider('invalidBlockInputProvider')]
    public function testSetProfileBlockedRejectsInvalidInputBeforeAnyQuery(string $address, ?string $cause, string $at): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->never())->method('query');
        $repo = new CarRepository($db);

        $this->expectException(CarValidationException::class);
        $repo->setProfileBlocked(42, $address, $cause, $at);
    }

    public function testSetProfileBlockedReturnsNullWithoutAProfileRow(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(null, $writes));

        $this->assertNull($repo->setProfileBlocked(42, 'owner@example.com', 'spam', '2026-09-01 10:00:00'));
        $this->assertSame([], $writes);
    }

    public function testClearProfileBlockedClearsAllFourColumnsOnly(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(
            self::profileRow(['email_blocked' => 1, 'email_blocked_address' => 'o@example.com', 'email_opted_out' => 1]),
            $writes
        ));

        $this->assertSame(1, $repo->clearProfileBlocked(42));
        $this->assertCount(1, $writes);
        $this->assertSame(
            'UPDATE profiles SET email_blocked = 0, email_blocked_address = NULL, email_blocked_at = NULL, '
            . 'email_blocked_cause = NULL WHERE user_id = ?',
            $writes[0]['sql']
        );
        $this->assertStringNotContainsString('email_opted_out', $writes[0]['sql'], 'Clearing a block must leave the opt-out alone');
    }

    public function testClearProfileBlockedWritesNothingWhenNotBlocked(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(self::profileRow(), $writes));

        $this->assertSame(0, $repo->clearProfileBlocked(42));
        $this->assertSame([], $writes);
    }

    public function testSetProfileBouncedStoresNormalizedAddressAndTime(): void
    {
        $writes = [];
        $selects = [];
        $repo = new CarRepository($this->makeProfileDb(self::profileRow(), $writes, $selects));

        $old = $repo->setProfileBounced(42, 'Owner@Example.com', '2026-09-01 10:00:00');

        $this->assertSame(0, $old);
        $this->assertStringEndsWith('FOR UPDATE', $selects[0], 'The profile row is locked before the write');
        $this->assertCount(1, $writes);
        $this->assertSame(
            'UPDATE profiles SET email_bounced = 1, email_bounced_address = ?, email_bounced_at = ? WHERE user_id = ?',
            $writes[0]['sql']
        );
        $this->assertSame(['owner@example.com', '2026-09-01 10:00:00', 42], $writes[0]['params']);
    }

    /** Every new address gets a new email_bounced_at (#2320). */
    public function testSetProfileBouncedRewritesTheTimeForADifferentAddress(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(
            self::profileRow([
                'email_bounced' => 1,
                'email_bounced_address' => 'old@example.com',
                'email_bounced_at' => '2026-01-01 00:00:00',
            ]),
            $writes
        ));

        $repo->setProfileBounced(42, 'new@example.com', '2026-09-01 10:00:00');

        $this->assertCount(1, $writes);
        $this->assertSame(['new@example.com', '2026-09-01 10:00:00', 42], $writes[0]['params']);
    }

    public function testSetProfileBouncedWritesNothingForTheSameAddressAgain(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(
            self::profileRow(['email_bounced' => 1, 'email_bounced_address' => 'owner@example.com']),
            $writes
        ));

        $this->assertSame(1, $repo->setProfileBounced(42, 'owner@example.com', '2026-09-01 10:00:00'));
        $this->assertSame([], $writes);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidBounceInputProvider(): array
    {
        return [
            'empty address' => ['', '2026-09-01 10:00:00'],
            'whitespace address' => ['   ', '2026-09-01 10:00:00'],
            'malformed time' => ['owner@example.com', 'yesterday'],
            'rolled-over date' => ['owner@example.com', '2026-02-30 10:00:00'],
        ];
    }

    #[DataProvider('invalidBounceInputProvider')]
    public function testSetProfileBouncedRejectsEmptyAddressOrBadTimeBeforeAnyQuery(string $address, string $at): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->never())->method('query');
        $repo = new CarRepository($db);

        $this->expectException(CarValidationException::class);
        $repo->setProfileBounced(42, $address, $at);
    }

    public function testClearProfileBouncedSetsClearedAtSoOldSoftBouncesDoNotCount(): void
    {
        $writes = [];
        $selects = [];
        $repo = new CarRepository($this->makeProfileDb(
            self::profileRow(['email_bounced' => 1, 'email_bounced_address' => 'owner@example.com']),
            $writes,
            $selects
        ));

        $this->assertSame(1, $repo->clearProfileBounced(42));

        $this->assertStringEndsWith('FOR UPDATE', $selects[0]);
        $this->assertCount(1, $writes);
        $this->assertStringContainsString('email_bounce_cleared_at = ?', $writes[0]['sql']);
        $this->assertStringContainsString('email_bounced = 0', $writes[0]['sql']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $writes[0]['params'][0]);
        $this->assertSame(42, $writes[0]['params'][1]);
    }

    public function testClearProfileBouncedWritesNothingWhenNotBounced(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(self::profileRow(), $writes));

        $this->assertSame(0, $repo->clearProfileBounced(42));
        $this->assertSame([], $writes);
    }

    public function testProfileWriteFailureThrowsCarDatabaseExceptionNamingTheMethod(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(self::profileRow(), $writes, writeFails: true));

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/setProfileBounced failed for user=42/');
        $repo->setProfileBounced(42, 'owner@example.com', '2026-09-01 10:00:00');
    }

    /** Ownerless cars (no user, deleted user, `noowner`) are excluded through an INNER JOIN. */
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
        $this->assertStringContainsString('cars.solddate IS NULL', $capturedSql);
        $this->assertStringNotContainsString(
            "solddate = ''",
            $capturedSql,
            "solddate is a DATE column; comparing it to '' is a hard SQL error under STRICT_TRANS_TABLES"
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![\w.])email_(?:bounced|blocked|opted_out)\b/',
            $capturedSql,
            'The car carries no deliverability flag (#2101). Every flag must be read through the p alias'
        );
        $this->assertStringNotContainsString('suppressed', $capturedSql);
        $this->assertStringContainsString("cars.email IS NOT NULL AND cars.email != ''", $capturedSql);
        $this->assertStringContainsString(
            'SELECT cars.*',
            $capturedSql,
            'The JOIN against users makes a bare SELECT * ambiguous — only cars columns may be selected'
        );
        $this->assertStringContainsString(
            'INNER JOIN users',
            $capturedSql,
            'Ownership must require a live users row via INNER JOIN — a LEFT JOIN can\'t tell a car '
                . 'whose user_id points at a deleted user (no FK enforces this — see DATABASE.md\'s '
                . '"No Enforced Foreign Key Constraints") apart from a deliberately ownerless one, so it '
                . 'would slip through as eligible'
        );
        $this->assertStringContainsString(
            'cars.user_id IS NOT NULL',
            $capturedSql,
            'A car with no owner at all must be excluded explicitly, not via three-valued logic'
        );
        // The whole clause, not fragments: an OR here would still match the
        // fragments but admit an orphaned user_id.
        $this->assertStringContainsString(
            "AND users.username != 'noowner'",
            $capturedSql,
            'Cars owned by the noowner system account must be excluded, resolved by username not by ID, '
                . 'via an unconditional AND — not an OR that could admit an orphaned owner reference'
        );
        $this->assertStringContainsString(
            'NOT ((cars.last_verified IS NOT NULL AND cars.last_verified >= NOW() - INTERVAL 1 YEAR)'
                . ' OR cars.owner_last_updated >= NOW() - INTERVAL 1 YEAR)',
            $capturedSql,
            'findVerificationEligible() must filter on stalenessSql(), the exact negation of freshnessSql()'
        );
        // The owner's profile is the only source of the three flags. The join
        // is a plain LEFT JOIN because profiles.user_id is unique, so it cannot
        // duplicate a car row. An owner with no profile is excluded.
        $this->assertStringContainsString('LEFT JOIN profiles p ON p.user_id = cars.user_id', $capturedSql);
        $this->assertStringContainsString('AND p.id IS NOT NULL', $capturedSql, 'No profiles row must exclude the car');
        $this->assertStringContainsString('AND p.email_opted_out = 0', $capturedSql);
        $this->assertStringContainsString('AND p.email_blocked = 0', $capturedSql);
        $this->assertStringContainsString('AND p.email_bounced = 0', $capturedSql);
        $this->assertStringNotContainsString(
            'MAX(',
            $capturedSql,
            'profiles.user_id is unique since #2101, so no MAX() over duplicate rows is needed'
        );
        $this->assertStringNotContainsString(
            'COALESCE(p.',
            $capturedSql,
            'A flag with a NULL address must still exclude, and a missing row must not default to eligible'
        );
        // Pins the removed #1953 mtime fallback, not every COALESCE.
        $this->assertStringNotContainsString(
            'COALESCE(cars.owner_last_updated',
            $capturedSql,
            'The COALESCE(owner_last_updated, mtime) fallback was removed by #1953 — owner_last_updated '
                . 'is NOT NULL by schema, so no fallback to mtime is needed or wanted'
        );
        $this->assertStringNotContainsString(
            'cars.mtime',
            $capturedSql,
            'mtime is ON UPDATE CURRENT_TIMESTAMP, so any unrelated write bumps it — #1953 removed it '
                . 'from the freshness expression entirely and it must not return by any route'
        );
        $this->assertStringNotContainsString(
            'INTERVAL 2 YEAR',
            $capturedSql,
            'Freshness moved from a 2-year to a 1-year window'
        );
        $this->assertStringContainsString('ORDER BY cars.last_verified ASC', $capturedSql);
        $this->assertStringContainsString(
            'cars.verification_attempts_since IS NULL',
            $capturedSql,
            'The attempt cap (#1884): a car with no attempt window yet is always eligible on this clause alone'
        );
        $this->assertStringContainsString(
            'cars.verification_attempts_since < NOW() - INTERVAL 1 YEAR',
            $capturedSql,
            'The attempt cap resets once the rolling 12-month window has fully elapsed'
        );
        $this->assertStringContainsString(
            'cars.verification_attempts < 2',
            $capturedSql,
            'The attempt cap: at most 2 sends per rolling 12-month window, matching the FRD and '
                . 'incrementVerificationAttempts()\'s own reset logic'
        );
    }

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

    // clearBouncedForUser() / carIdsWithBouncedFlagButNoAddress() (#1890)

    /**
     * @return array<string, array{array<string, mixed>, string, int, bool}>
     */
    public static function clearBouncedCaseProvider(): array
    {
        return [
            'different address' => [['email_bounced' => 1, 'email_bounced_address' => 'old@example.com'], 'new-address@example.com', 1, true],
            'NULL address' => [['email_bounced' => 1, 'email_bounced_address' => null], 'new-address@example.com', 1, true],
            'empty address' => [['email_bounced' => 1, 'email_bounced_address' => ''], 'new-address@example.com', 1, true],
            'same address, other case and spaces' => [['email_bounced' => 1, 'email_bounced_address' => 'New-Address@Example.com'], ' new-address@example.com ', 0, false],
            'not bounced' => [['email_bounced' => 0], 'new-address@example.com', 0, false],
        ];
    }

    /**
     * The matching rule is the whole method: a bounce on another address, or
     * with no address, clears. A bounce on the confirmed address stays.
     *
     * @param array<string, mixed> $profile
     */
    #[DataProvider('clearBouncedCaseProvider')]
    public function testClearBouncedForUserClearsOnlyWhenTheBounceIsNotOnTheConfirmedAddress(
        array $profile,
        string $currentEmail,
        int $expectedReturn,
        bool $expectWrite
    ): void {
        $writes = [];
        $selects = [];
        $repo = new CarRepository($this->makeProfileDb(self::profileRow($profile), $writes, $selects));

        $result = $repo->clearBouncedForUser(42, $currentEmail);

        $this->assertSame($expectedReturn, $result);
        $this->assertCount(1, $selects);
        $this->assertStringEndsWith('FOR UPDATE', $selects[0], 'The profile row must be locked before the clear');
        if ($expectWrite) {
            $this->assertCount(1, $writes);
            $this->assertStringContainsString('UPDATE profiles SET email_bounced = 0', $writes[0]['sql']);
            $this->assertStringContainsString('email_bounce_cleared_at = ?', $writes[0]['sql']);
        } else {
            $this->assertSame([], $writes, 'No write when the bounce stays');
        }
    }

    public function testClearBouncedForUserReturnsZeroWithoutAProfileRow(): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb(null, $writes));

        $this->assertSame(0, $repo->clearBouncedForUser(42, 'new-address@example.com'));
        $this->assertSame([], $writes);
    }

    public function testClearBouncedForUserThrowsOnDatabaseError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Deadlock found');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/findProfileEmailState failed for user=42: Deadlock found/');
        $repo->clearBouncedForUser(42, 'new-address@example.com');
    }

    /** Anomaly: a bounce with no address returns the owner's car IDs, so the hook can log them. */
    public function testCarIdsWithBouncedFlagButNoAddressReturnsTheOwnersCarIdsAsInts(): void
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn((object) self::profileRow(['email_bounced' => 1, 'email_bounced_address' => '']));
        $db->method('get')->willReturnSelf();
        $db->method('results')->willReturn([(object) ['id' => 100], (object) ['id' => '101']]);

        $repo = new CarRepository($db);
        $result = $repo->carIdsWithBouncedFlagButNoAddress(42);

        $this->assertSame([100, 101], $result);
    }

    /**
     * @return array<string, array{array<string, mixed>|null}>
     */
    public static function noAnomalyProfileProvider(): array
    {
        return [
            'no profile row' => [null],
            'not bounced' => [['email_bounced' => 0]],
            'bounced with an address' => [['email_bounced' => 1, 'email_bounced_address' => 'owner@example.com']],
        ];
    }

    /**
     * @param array<string, mixed>|null $profile
     */
    #[DataProvider('noAnomalyProfileProvider')]
    public function testCarIdsWithBouncedFlagButNoAddressReturnsEmptyArrayWhenThereIsNoAnomaly(?array $profile): void
    {
        $writes = [];
        $repo = new CarRepository($this->makeProfileDb($profile === null ? null : self::profileRow($profile), $writes));

        $this->assertSame([], $repo->carIdsWithBouncedFlagButNoAddress(42));
    }

    /** No log here: the hook's catch blocks log this failure. */
    public function testCarIdsWithBouncedFlagButNoAddressThrowsOnDatabaseError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Connection lost');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/findProfileEmailState failed for user=42/');
        $repo->carIdsWithBouncedFlagButNoAddress(42);
    }

    // countSoftBouncesSinceLastDelivered()

    /**
     * Soft bounces before the owner's Clear Bounced must not count. The clear
     * time comes from the profile of the car's owner, found through the car.
     */
    public function testCountSoftBouncesSinceLastDeliveredUsesTheOwnersClearTimeAndTheCarId(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'p.email_bounce_cleared_at')
                    && str_contains($sql, 'INNER JOIN profiles p ON p.user_id = c.user_id')
                    && str_contains($sql, 'WHERE c.id = ?')),
                ['owner@example.com', 'owner@example.com', 17]
            )
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('first')->willReturn((object) ['cnt' => '2']);

        $repo = new CarRepository($db);

        $this->assertSame(2, $repo->countSoftBouncesSinceLastDelivered('owner@example.com', 17));
    }

    // findVerificationStateByOwner() / findLatestEmailEventsByCarIds() (#1924)

    /** Ordered `model, year` to match the car-button list in user_form_hook.php. */
    public function testFindVerificationStateByOwnerReadsTheFlagsFromTheProfileJoin(): void
    {
        $rows = [(object) ['id' => 1, 'model' => 'Elan', 'has_profile' => 1, 'email_blocked' => 1]];
        $capturedSql = null;

        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(
                function (string $sql, array $params = []) use (&$capturedSql, $db): DatabaseInterface {
                    $capturedSql = $sql;
                    $this->assertSame([42], $params);
                    return $db;
                }
            );
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn($rows);

        $repo = new CarRepository($db);
        $result = $repo->findVerificationStateByOwner(42);

        $this->assertSame($rows, $result);
        $this->assertStringContainsString('LEFT JOIN profiles p ON p.user_id = cars.user_id', $capturedSql);
        $this->assertStringContainsString('(p.id IS NOT NULL) AS has_profile', $capturedSql);
        foreach (['email_opted_out', 'email_blocked', 'email_blocked_address', 'email_blocked_cause', 'email_bounced', 'email_bounced_address'] as $column) {
            $this->assertStringContainsString("p.{$column}", $capturedSql);
        }
        $this->assertDoesNotMatchRegularExpression('/(?<![\w.])email_(?:bounced|blocked|opted_out)\b/', $capturedSql);
        $this->assertStringNotContainsString('suppressed', $capturedSql);
        $this->assertStringContainsString('ORDER BY cars.model, cars.year', $capturedSql);
    }

    /** The result is the driver's rows: the call site casts the flags (PDO may return strings). */
    public function testFindVerificationStateByOwnerReturnsRowsUnchangedIncludingNullProfileColumns(): void
    {
        $row = (object) [
            'id' => 7,
            'model' => 'Elan',
            'has_profile' => 0,
            'email_opted_out' => null,
            'email_blocked' => null,
            'email_bounced' => null,
        ];

        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn([$row]);

        $repo = new CarRepository($db);
        $result = $repo->findVerificationStateByOwner(42);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]->has_profile);
        $this->assertNull($result[0]->email_opted_out, 'No profile row: the flags are NULL, not 0');
    }

    public function testFindVerificationStateByOwnerReturnsEmptyArrayWhenNoCars(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn([]);

        $repo = new CarRepository($db);
        $result = $repo->findVerificationStateByOwner(999);

        $this->assertSame([], $result);
    }

    public function testFindVerificationStateByOwnerThrowsOnDatabaseError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Connection lost');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/findVerificationStateByOwner failed/');
        $repo->findVerificationStateByOwner(42);
    }

    /** The self-join is SQL; this checks only the PHP keying by (int) car_id. */
    public function testFindLatestEmailEventsByCarIdsKeysResultByCarId(): void
    {
        $rows = [
            (object) ['car_id' => 10, 'event' => 'delivered', 'occurred_at' => '2026-01-01 00:00:00', 'reason' => null],
            (object) ['car_id' => 20, 'event' => 'hard_bounce', 'occurred_at' => '2026-02-01 00:00:00', 'reason' => 'mailbox full'],
        ];

        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn($rows);

        $repo = new CarRepository($db);
        $result = $repo->findLatestEmailEventsByCarIds([10, 20]);

        $this->assertArrayHasKey(10, $result);
        $this->assertArrayHasKey(20, $result);
        $this->assertSame('delivered', $result[10]->event);
        $this->assertSame('2026-01-01 00:00:00', $result[10]->occurred_at);
        $this->assertSame('hard_bounce', $result[20]->event);
        $this->assertSame('mailbox full', $result[20]->reason);
    }

    /** On a tie, the later row in the result set wins. */
    public function testFindLatestEmailEventsByCarIdsReturnsMaxOccurredAtRowWhenMultipleEventsExist(): void
    {
        $rows = [
            (object) ['car_id' => 10, 'event' => 'soft_bounce', 'occurred_at' => '2026-03-01 00:00:00', 'reason' => 'first'],
            (object) ['car_id' => 10, 'event' => 'hard_bounce', 'occurred_at' => '2026-03-01 00:00:00', 'reason' => 'second'],
        ];

        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn($rows);

        $repo = new CarRepository($db);
        $result = $repo->findLatestEmailEventsByCarIds([10]);

        $this->assertCount(1, $result);
        $this->assertSame('hard_bounce', $result[10]->event, 'The later-joined row must win on a tie');
        $this->assertSame('second', $result[10]->reason);
    }

    /** A missing key means "no events", not an error. */
    public function testFindLatestEmailEventsByCarIdsOmitsCarWithNoEvents(): void
    {
        $rows = [
            (object) ['car_id' => 10, 'event' => 'delivered', 'occurred_at' => '2026-01-01 00:00:00', 'reason' => null],
        ];

        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn($rows);

        $repo = new CarRepository($db);
        $result = $repo->findLatestEmailEventsByCarIds([10, 30]);

        $this->assertArrayHasKey(10, $result);
        $this->assertArrayNotHasKey(30, $result);
    }

    public function testFindLatestEmailEventsByCarIdsReturnsEmptyArrayAndIssuesNoQueryOnEmptyInput(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->never())->method('query');

        $repo = new CarRepository($db);
        $result = $repo->findLatestEmailEventsByCarIds([]);

        $this->assertSame([], $result);
    }

    public function testFindLatestEmailEventsByCarIdsThrowsOnDatabaseError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Connection lost');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/findLatestEmailEventsByCarIds failed/');
        $repo->findLatestEmailEventsByCarIds([10, 20]);
    }
}
