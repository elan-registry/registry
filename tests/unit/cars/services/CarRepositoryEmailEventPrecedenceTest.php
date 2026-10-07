<?php

declare(strict_types=1);

namespace Tests\Unit\Cars\Services;

use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for CarRepository::findLatestEmailEventPerCarWithPrecedence(),
 * mocking DatabaseInterface. Mirrors CarRepositoryFindByOwnerFailureTest.php's
 * and CarRepositoryFindLatestHistoryOperationTest.php's conventions: a mocked
 * DB double for the contract-shape behavior (empty-array short-circuit,
 * input validation, PHP-side "first row per car" reduction, error
 * propagation) that does not need a real self-join. The SQL's own
 * precedence/cycle-scoping correctness is covered by the live-DB
 * integration test (tests/integration/database/CarRepositoryEmailEventPrecedenceTest.php).
 *
 * The method's query already orders rows `car_id ASC, terminal DESC,
 * occurred_at DESC, id DESC` and returns the FIRST row seen per car_id
 * (see the PHP loop in findLatestEmailEventPerCarWithPrecedence()), so the
 * "terminal beats later delivered" precedence test below asserts the PHP
 * reduction keeps the first row in that pre-ordered sequence — it is a
 * contract test on the reduction step, not a substitute for the real
 * self-join, which only a live database can execute.
 */
#[Group('fast')]
final class CarRepositoryEmailEventPrecedenceTest extends TestCase
{
    public function testEmptyCarIdsArrayIsANoOpAndNeverQueries(): void
    {
        $stubDb = $this->createMock(DatabaseInterface::class);
        $stubDb->expects($this->never())->method('query');

        $repo = new CarRepository($stubDb);

        $result = $repo->findLatestEmailEventPerCarWithPrecedence([]);

        $this->assertSame([], $result);
    }

    public function testThrowsCarDatabaseExceptionOnQueryError(): void
    {
        $stubDb = $this->createStub(DatabaseInterface::class);
        $stubDb->method('query')->willReturn($stubDb);
        $stubDb->method('error')->willReturn(true);
        $stubDb->method('errorString')->willReturn('mock query failure');

        $repo = new CarRepository($stubDb);

        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('mock query failure');

        $repo->findLatestEmailEventPerCarWithPrecedence([1]);
    }

    /**
     * Rows arrive from the query already ordered
     * `car_id ASC, terminal DESC, occurred_at DESC, id DESC` (the ORDER BY in
     * findLatestEmailEventPerCarWithPrecedence()'s SQL). This fixture places a
     * `delivered` row at a LATER timestamp (occurred_at = 11:00) ahead of a
     * `hard_bounce` row at an EARLIER timestamp (10:00) in the array the mock
     * hands back — i.e. in the WRONG order a plain "first row wins" reduction
     * would need to pick the terminal row anyway only if the method re-sorted
     * by precedence itself. Since the production method does NOT re-sort in
     * PHP (it trusts the SQL ORDER BY and keeps the first row per car_id), a
     * correct caller contract requires the chosen row to be the one the SQL
     * placed first — this test pins that the PHP loop keeps the FIRST row
     * for a given car_id and ignores a later-arriving row for that same car,
     * which is the exact bug class a "last row wins" or "latest occurred_at
     * wins" reduction would introduce.
     */
    public function testKeepsFirstRowPerCarAndIgnoresLaterRowsForSameCar(): void
    {
        $terminalRow = (object) [
            'car_id' => 5,
            'event' => 'hard_bounce',
            'occurred_at' => '2026-01-01 10:00:00',
            'reason' => 'Mailbox full',
            'brevo_message_id' => 'msg-bounce',
        ];
        $deliveredRow = (object) [
            'car_id' => 5,
            'event' => 'delivered',
            'occurred_at' => '2026-01-01 11:00:00',
            'reason' => null,
            'brevo_message_id' => 'msg-delivered',
        ];

        // The SQL's ORDER BY ranks the terminal event ahead of the
        // later-timestamp delivered event for the same car_id, so the mock
        // hands the terminal row back FIRST, exactly as the real self-join
        // would for this scenario.
        $stubDb = $this->createStub(DatabaseInterface::class);
        $stubDb->method('query')->willReturn($stubDb);
        $stubDb->method('error')->willReturn(false);
        $stubDb->method('results')->willReturn([$terminalRow, $deliveredRow]);

        $repo = new CarRepository($stubDb);

        $result = $repo->findLatestEmailEventPerCarWithPrecedence([5]);

        $this->assertArrayHasKey(5, $result);
        $this->assertSame(
            'hard_bounce',
            $result[5]->event,
            'Must keep the first row per car_id as the SQL ordered it, not switch to a later row for the same car'
        );
        $this->assertSame('msg-bounce', $result[5]->brevo_message_id);
    }

    public function testCarIdWithNoEventRowIsAbsentFromTheResultNotNull(): void
    {
        $stubDb = $this->createStub(DatabaseInterface::class);
        $stubDb->method('query')->willReturn($stubDb);
        $stubDb->method('error')->willReturn(false);
        $stubDb->method('results')->willReturn([]);

        $repo = new CarRepository($stubDb);

        $result = $repo->findLatestEmailEventPerCarWithPrecedence([5]);

        $this->assertArrayNotHasKey(5, $result, 'A car with no event in its cycle must be absent, not present with a null value');
    }

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function invalidCarIdsProvider(): array
    {
        return [
            'non-int element (string)' => [[1, 'two']],
            'non-int element (float)' => [[1, 2.5]],
            'non-int element (null)' => [[1, null]],
            'non-int element (bool)' => [[1, true]],
            'negative element' => [[1, -1]],
            'zero element' => [[1, 0]],
            // Numeric string is NOT an int in PHP's type system (is_int('2') === false),
            // so normalizeCarIds() must reject it rather than silently coercing it.
            'string-numeric element' => [[1, '2']],
        ];
    }

    #[DataProvider('invalidCarIdsProvider')]
    public function testThrowsCarValidationExceptionForWrongTypedOrOutOfRangeElement(array $carIds): void
    {
        $stubDb = $this->createMock(DatabaseInterface::class);
        $stubDb->expects($this->never())->method('query');

        $repo = new CarRepository($stubDb);

        $this->expectException(CarValidationException::class);

        $repo->findLatestEmailEventPerCarWithPrecedence($carIds);
    }

    /**
     * Duplicate ids are not a validation error — normalizeCarIds() dedupes
     * them via array_unique() rather than throwing. Asserts on the actual
     * bound parameters of the query() call (not just the stubbed return
     * value) so the test can detect a regression that stops deduping: a
     * duplicated id would double the number of '?' placeholders bound for
     * the id list, which is the exact placeholder/value misalignment dedup
     * exists to prevent.
     */
    public function testDuplicateCarIdsAreDedupedNotRejected(): void
    {
        $stubDb = $this->createMock(DatabaseInterface::class);
        $stubDb->expects($this->once())
            ->method('query')
            ->with(
                $this->anything(),
                $this->callback(function (array $params): bool {
                    // $ids (deduped) appears twice in the bound params: once for the
                    // cycle subquery's IN(), once for the outer query's IN(). A
                    // dedup regression would make the id count 3 instead of 1 in
                    // each place.
                    $idOccurrences = array_filter($params, static fn ($p) => $p === 3);
                    return count($idOccurrences) === 2;
                })
            )
            ->willReturn($stubDb);
        $stubDb->method('error')->willReturn(false);
        $stubDb->method('results')->willReturn([]);

        $repo = new CarRepository($stubDb);

        $repo->findLatestEmailEventPerCarWithPrecedence([3, 3, 3]);
    }
}
