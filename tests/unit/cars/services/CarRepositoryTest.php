<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\LogCategories;
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

    public function testUpdateEmailBouncedReturnsTrue(): void
    {
        $repo   = new CarRepository($this->makeEmptyResultDb());
        $result = $repo->updateEmailBounced(1, true, 'owner@example.com');
        $this->assertTrue($result);
    }

    public function testUpdateEmailBouncedThrowsWhenBouncedTrueWithNoAddress(): void
    {
        $repo = new CarRepository($this->makeEmptyResultDb());
        $this->expectException(\ElanRegistry\Exceptions\CarDatabaseException::class);
        $repo->updateEmailBounced(1, true);
    }

    public function testUpdateEmailBouncedThrowsWhenBouncedTrueWithEmptyAddress(): void
    {
        $repo = new CarRepository($this->makeEmptyResultDb());
        $this->expectException(\ElanRegistry\Exceptions\CarDatabaseException::class);
        $repo->updateEmailBounced(1, true, '');
    }

    public function testUpdateEmailBouncedClearsAddressWhenFalse(): void
    {
        $repo   = new CarRepository($this->makeEmptyResultDb());
        $result = $repo->updateEmailBounced(1, false);
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

    // updateProfileEmailBounced() (#1884)

    public function testUpdateProfileEmailBouncedThrowsWhenBouncedTrueWithNoAddress(): void
    {
        $repo = new CarRepository($this->makeEmptyResultDb());
        $this->expectException(CarDatabaseException::class);
        $repo->updateProfileEmailBounced(1, true, null);
    }

    public function testUpdateProfileEmailBouncedThrowsWhenBouncedTrueWithEmptyAddress(): void
    {
        $repo = new CarRepository($this->makeEmptyResultDb());
        $this->expectException(CarDatabaseException::class);
        $repo->updateProfileEmailBounced(1, true, '');
    }

    public function testUpdateProfileEmailBouncedGuardThrowsBeforeAnyQuery(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->never())->method('query');

        $repo = new CarRepository($db);
        try {
            $repo->updateProfileEmailBounced(1, true, '');
            $this->fail('Expected CarDatabaseException was not thrown');
        } catch (CarDatabaseException) {
        }
    }

    public function testUpdateProfileEmailBouncedSendsExpectedBoundParamsWhenSetting(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE profiles SET email_bounced = ?, email_bounced_address = ? WHERE user_id = ?',
                [1, 'owner@example.com', 42]
            )
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);

        $repo = new CarRepository($db);
        $result = $repo->updateProfileEmailBounced(42, true, 'owner@example.com');

        $this->assertTrue($result);
    }

    public function testUpdateProfileEmailBouncedSendsExpectedBoundParamsWhenClearing(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE profiles SET email_bounced = ?, email_bounced_address = ? WHERE user_id = ?',
                [0, null, 42]
            )
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);

        $repo = new CarRepository($db);
        $result = $repo->updateProfileEmailBounced(42, false);

        $this->assertTrue($result);
    }

    public function testUpdateProfileEmailBouncedReturnsTrueWhenRowsAffected(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);

        $repo = new CarRepository($db);
        $result = $repo->updateProfileEmailBounced(42, true, 'owner@example.com');

        $this->assertTrue($result);
    }

    /** 0 means "no row" or "unchanged"; bounceOwnerProfile() tells them apart. */
    public function testUpdateProfileEmailBouncedReturnsFalseWhenNoRowsAffected(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $repo = new CarRepository($db);
        $result = $repo->updateProfileEmailBounced(42, true, 'owner@example.com');

        $this->assertFalse($result);
    }

    public function testUpdateProfileEmailBouncedThrowsOnDatabaseError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Connection lost');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/updateProfileEmailBounced failed for user=42/');
        $repo->updateProfileEmailBounced(42, true, 'owner@example.com');
    }

    // findProfileEmailBounced() (#1884)

    public function testFindProfileEmailBouncedReturnsNullWhenNoRow(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with('SELECT email_bounced FROM profiles WHERE user_id = ?', [42])
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $repo = new CarRepository($db);
        $result = $repo->findProfileEmailBounced(42);

        $this->assertNull($result);
    }

    public function testFindProfileEmailBouncedReturnsIntCastOfColumnWhenRowFound(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn((object) ['email_bounced' => 1]);

        $repo = new CarRepository($db);
        $result = $repo->findProfileEmailBounced(42);

        $this->assertSame(1, $result);
    }

    public function testFindProfileEmailBouncedThrowsOnDatabaseError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Connection lost');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/findProfileEmailBounced failed for user=42/');
        $repo->findProfileEmailBounced(42);
    }

    // findProfileEmailBouncedAddress() (#1884)

    public function testFindProfileEmailBouncedAddressReturnsNullWhenNoRow(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with('SELECT email_bounced_address FROM profiles WHERE user_id = ?', [42])
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $repo = new CarRepository($db);
        $result = $repo->findProfileEmailBouncedAddress(42);

        $this->assertNull($result);
    }

    public function testFindProfileEmailBouncedAddressReturnsNullWhenColumnIsNull(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn((object) ['email_bounced_address' => null]);

        $repo = new CarRepository($db);
        $result = $repo->findProfileEmailBouncedAddress(42);

        $this->assertNull($result);
    }

    public function testFindProfileEmailBouncedAddressReturnsStringWhenFound(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(1);
        $db->method('first')->willReturn((object) ['email_bounced_address' => 'owner@example.com']);

        $repo = new CarRepository($db);
        $result = $repo->findProfileEmailBouncedAddress(42);

        $this->assertSame('owner@example.com', $result);
    }

    public function testFindProfileEmailBouncedAddressThrowsOnDatabaseError(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Connection lost');

        $repo = new CarRepository($db);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessageMatches('/findProfileEmailBouncedAddress failed for user=42/');
        $repo->findProfileEmailBouncedAddress(42);
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
        $this->assertStringContainsString('cars.email_bounced = 0', $capturedSql);
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
        $this->assertStringContainsString(
            'AND COALESCE((SELECT MAX(p.email_suppressed) FROM profiles p WHERE p.user_id = cars.user_id), 0) = 0',
            $capturedSql,
            'The owner-level opt-out must be read via a correlated subquery, not a LEFT JOIN — '
                . 'profiles.user_id has no UNIQUE index, so a join could duplicate the car row in a list '
                . 'result. An owner who opted out (profiles.email_suppressed = 1) must be excluded '
                . 'regardless of the per-car flag — setSuppressedForOwner() only fans out to the cars held '
                . 'at opt-out time, so a car acquired later reads cars.email_suppressed = 0 and would '
                . 'otherwise re-enter the eligible set (the gap named in '
                . '20260914093000_add_profile_email_suppressed.php). COALESCE supplies the column default '
                . 'for an owner with no profiles row at all, and MAX treats "suppressed in any profiles '
                . 'row" as suppressed if an owner somehow has more than one'
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

    /** The LOWER() match and the NULL/empty-address branch are the whole method. */
    public function testClearBouncedForUserSendsExpectedSqlAndParams(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with(
                'UPDATE cars
                SET email_bounced = 0, email_bounced_address = NULL
              WHERE user_id = ?
                AND email_bounced = 1
                AND (email_bounced_address IS NULL
                     OR email_bounced_address = \'\'
                     OR LOWER(email_bounced_address) <> LOWER(?))',
                [42, 'new-address@example.com']
            )
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(2);

        $repo = new CarRepository($db);
        $result = $repo->clearBouncedForUser(42, 'new-address@example.com');

        $this->assertSame(2, $result, 'Must return DB::count() — rows changed by the UPDATE');
    }

    /** A stale re-click matches nothing. */
    public function testClearBouncedForUserReturnsZeroWhenNothingMatches(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('count')->willReturn(0);

        $repo = new CarRepository($db);
        $result = $repo->clearBouncedForUser(42, 'new-address@example.com');

        $this->assertSame(0, $result);
    }

    public function testClearBouncedForUserThrowsAndLogsOnDatabaseError(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(true);
        $db->method('errorString')->willReturn('Deadlock found');

        $repo = new CarRepository($db);

        try {
            $repo->clearBouncedForUser(42, 'new-address@example.com');
            $this->fail('Expected CarDatabaseException was not thrown');
        } catch (CarDatabaseException $e) {
            $this->assertMatchesRegularExpression('/clearBouncedForUser failed \(userId=42\)/', $e->getMessage());
        }

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_DATABASE_ERROR, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('clearBouncedForUser failed (userId=42)', $mockLogEntries[0]['message']);
    }

    public function testCarIdsWithBouncedFlagButNoAddressReturnsListOfInts(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with(
                "SELECT id FROM cars WHERE user_id = ? AND email_bounced = 1
              AND (email_bounced_address IS NULL OR email_bounced_address = '')",
                [42]
            )
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn([
            (object) ['id' => 100],
            (object) ['id' => 101],
        ]);

        $repo = new CarRepository($db);
        $result = $repo->carIdsWithBouncedFlagButNoAddress(42);

        $this->assertSame([100, 101], $result);
        foreach ($result as $id) {
            $this->assertIsInt($id);
        }
    }

    /** The hook checks `!empty($integrityCarIds)`. */
    public function testCarIdsWithBouncedFlagButNoAddressReturnsEmptyArrayWhenNoneMatch(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn([]);

        $repo = new CarRepository($db);
        $result = $repo->carIdsWithBouncedFlagButNoAddress(42);

        $this->assertSame([], $result);
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
        $this->expectExceptionMessageMatches('/carIdsWithBouncedFlagButNoAddress failed \(userId=42\)/');
        $repo->carIdsWithBouncedFlagButNoAddress(42);
    }

    /**
     * Pins a known gap: a row without ->id gives an E_WARNING and car id 0,
     * not an exception. Low severity: the id goes only into a log message.
     */
    public function testCarIdsWithBouncedFlagButNoAddressSilentlyCoercesUnexpectedRowShapeToZero(): void
    {
        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn([(object) ['not_id' => 999]]);

        $repo = new CarRepository($db);

        $result = @$repo->carIdsWithBouncedFlagButNoAddress(42);

        $this->assertSame(
            [0],
            $result,
            'Documents current behavior: an unexpected row shape silently maps to car id 0 '
            . 'rather than throwing — a real (low-severity, SELECT-only) gap worth triaging, '
            . 'not a bug this test suite should paper over.'
        );
    }

    // findVerificationStateByOwner() / findLatestEmailEventsByCarIds() (#1924)

    /** Ordered `model, year` to match the car-button list in user_form_hook.php. */
    public function testFindVerificationStateByOwnerSendsExpectedSqlAndReturnsRows(): void
    {
        $rows = [
            (object) [
                'id' => 1,
                'model' => 'Elan',
                'series' => '2',
                'variant' => 'S/E',
                'year' => 1971,
                'email' => 'owner@example.com',
                'email_bounced' => 1,
                'email_bounced_address' => 'owner@example.com',
                'email_suppressed' => 0,
                'owner_last_updated' => '2026-01-01 00:00:00',
                'last_verified' => '2025-01-01 00:00:00',
            ],
        ];

        $db = $this->makeDbMock();
        $db->expects($this->once())
            ->method('query')
            ->with(
                'SELECT id, model, series, variant, year, email, email_bounced, email_bounced_address,
                    email_suppressed, owner_last_updated, last_verified
               FROM cars
              WHERE user_id = ?
              ORDER BY model, year',
                [42]
            )
            ->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn($rows);

        $repo = new CarRepository($db);
        $result = $repo->findVerificationStateByOwner(42);

        $this->assertSame($rows, $result);
    }

    /** email_bounced/email_suppressed are ints (tinyint), not bool. */
    public function testFindVerificationStateByOwnerMapsAllColumnsAndKeepsBounceFlagsAsInt(): void
    {
        $row = (object) [
            'id' => 7,
            'model' => 'Elan',
            'series' => '2',
            'variant' => 'S/E',
            'year' => 1971,
            'email' => 'owner@example.com',
            'email_bounced' => 1,
            'email_bounced_address' => 'owner@example.com',
            'email_suppressed' => 0,
            'owner_last_updated' => '2026-01-01 00:00:00',
            'last_verified' => null,
        ];

        $db = $this->makeDbMock();
        $db->expects($this->once())->method('query')->willReturnSelf();
        $db->method('error')->willReturn(false);
        $db->method('results')->willReturn([$row]);

        $repo = new CarRepository($db);
        $result = $repo->findVerificationStateByOwner(42);

        $this->assertCount(1, $result);
        $car = $result[0];
        $this->assertSame(7, $car->id);
        $this->assertSame('Elan', $car->model);
        $this->assertSame('2', $car->series);
        $this->assertSame('S/E', $car->variant);
        $this->assertSame(1971, $car->year);
        $this->assertSame('owner@example.com', $car->email);
        $this->assertSame('owner@example.com', $car->email_bounced_address);
        $this->assertSame('2026-01-01 00:00:00', $car->owner_last_updated);
        $this->assertNull($car->last_verified);

        $this->assertIsInt($car->email_bounced, 'email_bounced must come back as int (tinyint), not bool');
        $this->assertSame(1, $car->email_bounced);
        $this->assertFalse(is_bool($car->email_bounced), 'email_bounced must not be a bool');

        $this->assertIsInt($car->email_suppressed, 'email_suppressed must come back as int (tinyint), not bool');
        $this->assertSame(0, $car->email_suppressed);
        $this->assertFalse(is_bool($car->email_suppressed), 'email_suppressed must not be a bool');
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
