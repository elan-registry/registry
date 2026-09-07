<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live-DB tests for CarRepository::freshnessSql()/stalenessSql() (issue #1953).
 *
 * Mocked unit tests (tests/unit/cars/services/CarRepositoryFreshnessTest.php)
 * exact-string-match the generated SQL against a mocked DB — they cannot catch
 * a fragment that is fatally wrong against the real schema or the production
 * sql_mode (STRICT_TRANS_TABLES, NO_ZERO_IN_DATE, NO_ZERO_DATE; no sql_mode
 * override is documented in this repo beyond DB.php's `SET SESSION sql_mode = ''`).
 * These tests exercise the fragments as raw ad-hoc queries against the live
 * schema instead.
 *
 * Deliberately does NOT skip when the #1953 migration hasn't run: freshnessSql()/
 * stalenessSql() reference only owner_last_updated and last_verified, both of
 * which already existed as nullable DATETIME/TIMESTAMP columns before this
 * migration. The expression is valid SQL against either column type, so these
 * tests are expected to pass whether or not `composer migrate` has been run
 * locally (confirmed by hand against the production sql_mode during planning).
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

        // No exception, no ApiResponse/error wrapping — a genuinely malformed
        // query would surface here as a thrown CarDatabaseException.
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
     * Evaluate a datetime expression on the MySQL server and return the result.
     *
     * Fixtures for the boundary tests must be derived from MySQL's clock, not
     * PHP's. `freshnessSql()` compares against MySQL's `NOW()`, and the two
     * clocks are not guaranteed to agree — on the reference dev box PHP is UTC
     * while MySQL is Pacific, 7 hours apart, which would swamp a 60-second
     * boundary offset and make these tests flap for a reason unrelated to the
     * behaviour under test.
     */
    private function mysqlDatetime(string $expression): string
    {
        $row = $this->db->query("SELECT {$expression} AS t")->first();

        return (string) $row->t;
    }

    /**
     * A car one minute inside the one-year window must read fresh.
     *
     * Together with its just-stale sibling this is what pins the interval to a
     * year. Without them the window is asserted only as an exact SQL string, so
     * retuning it (1 YEAR -> 18 MONTH, say) leaves every behavioural test green
     * and the string assertions read as "this string changed" rather than "this
     * behaviour is wrong" — inviting a maintainer to update them and ship a
     * silently different verification window. That is the same shape of miss
     * that let #1953 itself pass a green suite.
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

    /**
     * A car one minute outside the one-year window must read stale.
     */
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
     * The same boundary via `last_verified`, the other operand of the OR.
     *
     * `owner_last_updated` alone would leave the verified-recently disjunct
     * unpinned, so a retune of only that half would go unnoticed.
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
