<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1953: CarRepository::freshnessSql()/stalenessSql() run as real queries.
 * The unit tests only string-match the SQL, so they cannot catch a fragment
 * that fails against the real schema or sql_mode. No migration skip: the
 * expression is valid before and after the #1953 migration.
 */
#[Group('integration')]
#[Group('car-verification')]
final class CarFreshnessSqlLiveQueryTest extends IntegrationTestCase
{
    private int $testUserId;
    private CarRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->testUserId = $this->createTestUser();
        $this->loginAsTestUser($this->testUserId);
        $this->repo = new CarRepository($this->db);
    }

    // -------------------------------------------------------------------------
    // freshnessSql() as a raw ad-hoc query
    // -------------------------------------------------------------------------

    #[Group('fast')]
    public function testFreshnessSqlExecutesWithoutSqlErrorAndMatchesFreshCar(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'last_verified'      => null,
            'owner_last_updated' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $fresh = CarRepository::freshnessSql('cars');
        $result = $this->db->query("SELECT id FROM cars WHERE id = ? AND {$fresh}", [$carId]);

        $this->assertFalse($result->error(), 'freshnessSql() must execute without a SQL error');
        $this->assertSame(
            1,
            $result->count(),
            'A car with a recent owner_last_updated must match freshnessSql()'
        );
    }

    #[Group('fast')]
    public function testFreshnessSqlExcludesStaleCarWithNoVerification(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'last_verified'      => null,
            'owner_last_updated' => date('Y-m-d H:i:s', strtotime('-2 years')),
        ]);

        $fresh = CarRepository::freshnessSql('cars');
        $result = $this->db->query("SELECT id FROM cars WHERE id = ? AND {$fresh}", [$carId]);

        $this->assertFalse($result->error(), 'freshnessSql() must execute without a SQL error');
        $this->assertSame(
            0,
            $result->count(),
            'A car with a stale owner_last_updated and no last_verified must not match freshnessSql()'
        );
    }

    #[Group('fast')]
    public function testFreshnessSqlMatchesCarVerifiedRecentlyDespiteStaleOwnerLastUpdated(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'last_verified'      => date('Y-m-d H:i:s', strtotime('-1 day')),
            'owner_last_updated' => date('Y-m-d H:i:s', strtotime('-2 years')),
        ]);

        $fresh = CarRepository::freshnessSql('cars');
        $result = $this->db->query("SELECT id FROM cars WHERE id = ? AND {$fresh}", [$carId]);

        $this->assertFalse($result->error(), 'freshnessSql() must execute without a SQL error');
        $this->assertSame(
            1,
            $result->count(),
            'A car verified recently must match freshnessSql() even with a stale owner_last_updated'
        );
    }

    // -------------------------------------------------------------------------
    // stalenessSql() as a raw ad-hoc query
    // -------------------------------------------------------------------------

    #[Group('fast')]
    public function testStalenessSqlExecutesWithoutSqlErrorAndMatchesStaleCar(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'last_verified'      => null,
            'owner_last_updated' => date('Y-m-d H:i:s', strtotime('-2 years')),
        ]);

        $stale = CarRepository::stalenessSql('cars');
        $result = $this->db->query("SELECT id FROM cars WHERE id = ? AND {$stale}", [$carId]);

        $this->assertFalse($result->error(), 'stalenessSql() must execute without a SQL error');
        $this->assertSame(
            1,
            $result->count(),
            'A car with a stale owner_last_updated and no last_verified must match stalenessSql()'
        );
    }

    #[Group('fast')]
    public function testStalenessSqlExcludesFreshCar(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'last_verified'      => null,
            'owner_last_updated' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $stale = CarRepository::stalenessSql('cars');
        $result = $this->db->query("SELECT id FROM cars WHERE id = ? AND {$stale}", [$carId]);

        $this->assertFalse($result->error(), 'stalenessSql() must execute without a SQL error');
        $this->assertSame(
            0,
            $result->count(),
            'A car with a recent owner_last_updated must not match stalenessSql()'
        );
    }

    // -------------------------------------------------------------------------
    // findVerificationEligible() end-to-end against the live schema/sql_mode
    // -------------------------------------------------------------------------

    #[Group('fast')]
    public function testFindVerificationEligibleExecutesWithoutSqlErrorOnLiveSchema(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'email'              => 'live-query-eligible@example.com',
            'email_bounced'      => 0,
            'last_verified'      => null,
            'owner_last_updated' => date('Y-m-d H:i:s', strtotime('-2 years')),
            'solddate'           => null,
        ]);

        // A malformed query would throw CarDatabaseException here.
        $results = $this->repo->findVerificationEligible(1000, 0);

        $ids = array_map(static fn ($row) => (int) $row->id, $results);
        $this->assertContains(
            $carId,
            $ids,
            'findVerificationEligible() must execute against the live schema/sql_mode without ' .
            'error and return the eligible synthetic car'
        );
    }

    // -------------------------------------------------------------------------
    // The one-year boundary, in SQL
    // -------------------------------------------------------------------------

    /**
     * A car one minute inside the one-year window must read fresh. With its
     * just-stale sibling this pins the interval behaviorally, not only as a
     * SQL string.
     */
    #[Group('fast')]
    public function testFreshnessSqlMatchesCarJustInsideTheOneYearBoundary(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'last_verified'      => null,
            'owner_last_updated' => $this->mysqlDatetime(
                'DATE_ADD(NOW() - INTERVAL 1 YEAR, INTERVAL 60 SECOND)'
            ),
        ]);

        $fresh  = CarRepository::freshnessSql('cars');
        $result = $this->db->query("SELECT id FROM cars WHERE id = ? AND {$fresh}", [$carId]);

        $this->assertFalse($result->error(), 'freshnessSql() must execute without a SQL error');
        $this->assertSame(
            1,
            $result->count(),
            'A car 60 seconds inside the one-year window must match freshnessSql(). '
            . 'If this fails, the freshness interval is no longer one year.'
        );
    }

    #[Group('fast')]
    public function testFreshnessSqlExcludesCarJustOutsideTheOneYearBoundary(): void
    {
        $carId = $this->createTestCar($this->testUserId, [
            'last_verified'      => null,
            'owner_last_updated' => $this->mysqlDatetime(
                'DATE_SUB(NOW() - INTERVAL 1 YEAR, INTERVAL 60 SECOND)'
            ),
        ]);

        $fresh  = CarRepository::freshnessSql('cars');
        $result = $this->db->query("SELECT id FROM cars WHERE id = ? AND {$fresh}", [$carId]);

        $this->assertFalse($result->error(), 'freshnessSql() must execute without a SQL error');
        $this->assertSame(
            0,
            $result->count(),
            'A car 60 seconds outside the one-year window must not match freshnessSql(). '
            . 'If this fails, the freshness interval is no longer one year.'
        );
    }

    /**
     * The same boundary via `last_verified`, the other operand of the OR, so a
     * retune of only that half is caught.
     */
    #[Group('fast')]
    public function testFreshnessSqlAppliesTheSameBoundaryToLastVerified(): void
    {
        $justInside = $this->createTestCar($this->testUserId, [
            'last_verified'      => $this->mysqlDatetime(
                'DATE_ADD(NOW() - INTERVAL 1 YEAR, INTERVAL 60 SECOND)'
            ),
            'owner_last_updated' => $this->mysqlDatetime('NOW() - INTERVAL 5 YEAR'),
        ]);

        $justOutside = $this->createTestCar($this->testUserId, [
            'last_verified'      => $this->mysqlDatetime(
                'DATE_SUB(NOW() - INTERVAL 1 YEAR, INTERVAL 60 SECOND)'
            ),
            'owner_last_updated' => $this->mysqlDatetime('NOW() - INTERVAL 5 YEAR'),
        ]);

        $fresh = CarRepository::freshnessSql('cars');

        $this->assertSame(
            1,
            $this->db->query("SELECT id FROM cars WHERE id = ? AND {$fresh}", [$justInside])->count(),
            'A car verified 60 seconds inside the one-year window must match freshnessSql()'
        );
        $this->assertSame(
            0,
            $this->db->query("SELECT id FROM cars WHERE id = ? AND {$fresh}", [$justOutside])->count(),
            'A car verified 60 seconds outside the one-year window must not match freshnessSql()'
        );
    }
}
