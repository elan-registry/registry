<?php

declare(strict_types=1);

namespace Tests\Integration;

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use ElanRegistry\Owner;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration test for Owner::getCarsOwned()/getOwnershipHistory()'s
 * DB-error propagation (#1505 PR B).
 *
 * Before this fix, both methods checked `$this->_db->error()` and logged,
 * but then fell through to `return []` — collapsing "the query genuinely
 * returned no rows" and "the query itself failed" into the identical empty
 * array, so callers could not distinguish the two without reading logs.
 * These tests verify OwnerDatabaseException is now thrown instead, mirroring
 * CarRepositoryFindByOwnerFailureTest's pattern for the sibling Car-side gap
 * fixed in PR A (#1816).
 *
 * Extends plain TestCase, not IntegrationTestCase — it needs no DB fixtures
 * or connection (fully stubbed via DatabaseInterface), so
 * IntegrationTestCase::setUp()'s connection check/requireDatabase() would be
 * pure overhead. Don't "fix" this to extend IntegrationTestCase; it's
 * deliberate, matching CarRepositoryFindByOwnerFailureTest's own comment.
 */
#[Group('integration')]
#[Group('owner')]
final class OwnerReadMethodsDatabaseFailureTest extends TestCase
{
    /**
     * A stub DatabaseInterface whose query() returns itself (real \DB
     * contract) with error() reporting true and errorString() set — no
     * real database connection is touched.
     */
    private function failingDbStub(string $errorMessage): DatabaseInterface
    {
        $stub = $this->createStub(DatabaseInterface::class);
        $stub->method('query')->willReturnSelf();
        $stub->method('error')->willReturn(true);
        $stub->method('errorString')->willReturn($errorMessage);

        return $stub;
    }

    /**
     * Build an Owner with $_data populated via Reflection, bypassing find()
     * (which itself needs a successful query) so getCarsOwned()/
     * getOwnershipHistory() can be exercised directly against a stub whose
     * only configured behavior is the failing query.
     */
    private function ownerWithLoadedData(DatabaseInterface $db, int $userId): Owner
    {
        $owner = new Owner(null, $db);

        $ref = new \ReflectionClass(Owner::class);
        $dataProp = $ref->getProperty('_data');
        $dataProp->setValue($owner, (object) ['id' => $userId]);

        return $owner;
    }

    public function testGetCarsOwnedThrowsOwnerDatabaseExceptionOnQueryError(): void
    {
        $db = $this->failingDbStub('mock cars query failure');
        $owner = $this->ownerWithLoadedData($db, 42);

        $this->expectException(OwnerDatabaseException::class);
        $this->expectExceptionMessage('Owner::getCarsOwned failed for userId=42: mock cars query failure');

        $owner->getCarsOwned();
    }

    public function testGetOwnershipHistoryThrowsOwnerDatabaseExceptionOnQueryError(): void
    {
        $db = $this->failingDbStub('mock history query failure');
        $owner = $this->ownerWithLoadedData($db, 42);

        $this->expectException(OwnerDatabaseException::class);
        $this->expectExceptionMessage('Owner::getOwnershipHistory failed for userId=42: mock history query failure');

        $owner->getOwnershipHistory();
    }

    /**
     * Regression guard: with no owner data loaded, both methods still
     * return [] immediately without querying — a DB failure only surfaces
     * once an owner is actually loaded.
     */
    public function testGetCarsOwnedReturnsEmptyArrayWhenNoDataLoaded(): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->expects($this->never())->method('query');

        $owner = new Owner(null, $db);

        $this->assertSame([], $owner->getCarsOwned());
    }

    public function testGetOwnershipHistoryReturnsEmptyArrayWhenNoDataLoaded(): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->expects($this->never())->method('query');

        $owner = new Owner(null, $db);

        $this->assertSame([], $owner->getOwnershipHistory());
    }

    /**
     * syncOwnerFieldsToCars()'s behavior per the resolved PR-B design
     * decision (carried over from the renamed syncLocationToCars()): it must
     * not catch getCarsOwned()'s exception — a DB failure should surface as a
     * real exception, not silently collapse into "0 cars synced" (which looks
     * identical to a legitimate no-op).
     */
    public function testSyncOwnerFieldsToCarsPropagatesGetCarsOwnedException(): void
    {
        $db = $this->failingDbStub('mock cars query failure during sync');
        $owner = new Owner(null, $db);

        $ref = new \ReflectionClass(Owner::class);
        $dataProp = $ref->getProperty('_data');
        $dataProp->setValue($owner, (object) [
            'id'      => 7,
            'fname'   => 'Test',
            'lname'   => 'Owner',
            'email'   => 'test@example.com',
            'city'    => 'Portland',
            'state'   => 'OR',
            'country' => 'USA',
            'lat'     => '45.5',
            'lon'     => '-122.6',
            'website' => '',
        ]);

        $this->expectException(OwnerDatabaseException::class);

        $owner->syncOwnerFieldsToCars();
    }
}
