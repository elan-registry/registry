<?php

declare(strict_types=1);

namespace Tests\Unit;

use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\StatisticsDataService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the SQL that StatisticsDataService::getDataCompleteness() sends.
 *
 * verified_cars must count cars that are fresh by the shared freshness rule.
 * COUNT(last_verified) counted every car that was ever verified, including a
 * car whose last verification is older than 12 months. The live-database
 * behavior is pinned in tests/integration/StatisticsApiTest.php. This test
 * needs no database, so it catches a return to COUNT() in the fast suite.
 */
#[Group('statistics')]
#[Group('fast')]
final class StatisticsDataServiceCompletenessTest extends TestCase
{
    /**
     * Run getDataCompleteness() against a stub database and return the SQL it sent.
     */
    private function captureCompletenessSql(): string
    {
        $captured = [];

        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use (&$captured, $db) {
                $captured[] = $sql;

                return $db;
            }
        );
        $db->method('error')->willReturn(false);
        $db->method('first')->willReturn((object) ['total_cars' => 0, 'verified_cars' => 0]);

        $result = (new StatisticsDataService($db))->getDataCompleteness();

        $this->assertIsObject($result, 'getDataCompleteness() must return the row from first()');
        $this->assertCount(1, $captured, 'getDataCompleteness() must send exactly one query');

        return $captured[0];
    }

    public function testGetDataCompletenessVerifiedCarsUsesFreshnessRule(): void
    {
        $sql = $this->captureCompletenessSql();

        $this->assertStringContainsString(
            'SUM(CASE WHEN ' . CarRepository::freshnessSql(),
            $sql,
            'verified_cars must sum the shared freshness predicate'
        );
        $this->assertStringContainsString('COALESCE(SUM(', $sql, 'an empty registry must give 0, not NULL');
        $this->assertStringContainsString('as verified_cars', $sql);
        $this->assertStringNotContainsString('COUNT(last_verified)', $sql);
    }
}
