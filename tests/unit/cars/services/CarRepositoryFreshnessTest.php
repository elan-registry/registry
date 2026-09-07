<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarValidationException;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CarRepository::freshnessSql(), stalenessSql(), and isFresh().
 *
 * No database — these exercise pure string-building and PHP-clock comparison
 * logic. See Issue #1953 Test Plan §1/§2 (docs/plans/issue-1953-verification-freshness.md,
 * gitignored, private working doc) for the design of this suite, in particular
 * the truth-table matrix in §2 and the non-short-circuit guarantee of isFresh().
 */
#[Group('fast')]
final class CarRepositoryFreshnessTest extends TestCase
{
    // ------------------------------------------------------------------
    // freshnessSql() / stalenessSql()
    // ------------------------------------------------------------------

    public function testFreshnessSqlDefaultAliasExactString(): void
    {
        $this->assertSame(
            '((cars.last_verified IS NOT NULL AND cars.last_verified >= NOW() - INTERVAL 1 YEAR)'
                . ' OR cars.owner_last_updated >= NOW() - INTERVAL 1 YEAR)',
            CarRepository::freshnessSql()
        );
    }

    public function testFreshnessSqlCustomAliasExactString(): void
    {
        $this->assertSame(
            '((c.last_verified IS NOT NULL AND c.last_verified >= NOW() - INTERVAL 1 YEAR)'
                . ' OR c.owner_last_updated >= NOW() - INTERVAL 1 YEAR)',
            CarRepository::freshnessSql('c')
        );
    }

    public function testStalenessSqlIsExactlyNotFreshnessSql(): void
    {
        $this->assertSame('NOT ' . CarRepository::freshnessSql(), CarRepository::stalenessSql());
        $this->assertSame('NOT ' . CarRepository::freshnessSql('c'), CarRepository::stalenessSql('c'));
    }

    // ------------------------------------------------------------------
    // isFresh() truth table — Test Plan §2
    //
    // No PHP clock is consulted in these fixtures either: every date is
    // computed relative to a single fixed "now" so the boundary cases are
    // exact, not best-effort against wall-clock time at test-run.
    // ------------------------------------------------------------------

    private const NOW = '2026-09-05 12:00:00';

    /** @return int Unix timestamp for the fixed "now" used by this test class */
    private function now(): int
    {
        $ts = strtotime(self::NOW);
        $this->assertNotFalse($ts);
        return $ts;
    }

    /** @param int $offsetSeconds Seconds to add to the fixed "now" (negative = past) */
    private function at(int $offsetSeconds): string
    {
        $formatted = date('Y-m-d H:i:s', $this->now() + $offsetSeconds);
        $this->assertIsString($formatted);
        return $formatted;
    }

    public function testIsFreshLastVerifiedNullOwnerLastUpdatedOldIsNotFresh(): void
    {
        // Never verified, and owner_last_updated older than the 1-year cutoff.
        $this->assertFalse(CarRepository::isFresh(null, $this->oldRelativeToNow(-10)));
    }

    public function testIsFreshLastVerifiedNullOwnerLastUpdatedRecentIsFresh(): void
    {
        $this->assertTrue(CarRepository::isFresh(null, $this->at(-10)));
    }

    public function testIsFreshLastVerifiedOldOwnerLastUpdatedRecentIsFresh(): void
    {
        $this->assertTrue(CarRepository::isFresh($this->oldRelativeToNow(-3600), $this->at(-10)));
    }

    public function testIsFreshLastVerifiedRecentOwnerLastUpdatedOldIsFresh(): void
    {
        $this->assertTrue(CarRepository::isFresh($this->at(-10), $this->oldRelativeToNow(-3600)));
    }

    public function testIsFreshBothOldIsNotFresh(): void
    {
        $this->assertFalse(CarRepository::isFresh($this->oldRelativeToNow(-7200), $this->oldRelativeToNow(-10)));
    }

    public function testIsFreshBothRecentIsFresh(): void
    {
        $this->assertTrue(CarRepository::isFresh($this->at(-100), $this->at(-10)));
    }

    /**
     * Exact one-year boundary: owner_last_updated == now - 1 year must count
     * as fresh. Pins the >= (inclusive) direction of the comparison — isFresh()
     * computes the cutoff via strtotime('-1 year') from the real wall clock, so
     * this fixture derives its own cutoff the same way rather than assuming
     * seconds-per-year, keeping the two derivations independent of DST/leap
     * peculiarities while still testing the same boundary.
     */
    public function testIsFreshExactlyOnOneYearBoundaryIsFresh(): void
    {
        $cutoff = strtotime('-1 year');
        $this->assertNotFalse($cutoff);
        $ownerLastUpdated = date('Y-m-d H:i:s', $cutoff);

        $this->assertTrue(
            CarRepository::isFresh(null, $ownerLastUpdated),
            'owner_last_updated exactly at the 1-year cutoff must count as fresh (>=, not >)'
        );
    }

    /**
     * One second past the boundary (i.e. one second older than the cutoff)
     * must NOT count as fresh — pins the inequality direction from the other
     * side.
     */
    public function testIsFreshOneSecondPastOneYearBoundaryIsNotFresh(): void
    {
        $cutoff = strtotime('-1 year');
        $this->assertNotFalse($cutoff);
        $ownerLastUpdated = date('Y-m-d H:i:s', $cutoff - 1);

        $this->assertFalse(
            CarRepository::isFresh(null, $ownerLastUpdated),
            'owner_last_updated one second older than the 1-year cutoff must not count as fresh'
        );
    }

    /**
     * Helper for "old" fixtures: an offset applied on top of a date already
     * more than a year in the past, so tests reflecting "definitely stale"
     * are not sensitive to exact boundary arithmetic.
     */
    private function oldRelativeToNow(int $offsetSecondsFromTwoYearsAgo): string
    {
        $twoYearsAgo = strtotime('-2 years');
        $this->assertNotFalse($twoYearsAgo);
        return date('Y-m-d H:i:s', $twoYearsAgo + $offsetSecondsFromTwoYearsAgo);
    }

    // ------------------------------------------------------------------
    // isFresh() wrong-value tests (type errors are not tested — see
    // CLAUDE.md/plan: declare(strict_types=1) raises TypeError before the
    // body runs for a wrong-type argument, so only wrong-VALUE cases matter)
    // ------------------------------------------------------------------

    public function testIsFreshRejectsMalformedDateStringForLastVerified(): void
    {
        $this->expectException(CarValidationException::class);
        CarRepository::isFresh('not-a-date', $this->at(-10));
    }

    public function testIsFreshRejectsEmptyStringForLastVerified(): void
    {
        $this->expectException(CarValidationException::class);
        CarRepository::isFresh('', $this->at(-10));
    }

    public function testIsFreshRejectsMalformedDateStringForOwnerLastUpdated(): void
    {
        $this->expectException(CarValidationException::class);
        CarRepository::isFresh(null, 'not-a-date');
    }

    public function testIsFreshRejectsEmptyStringForOwnerLastUpdated(): void
    {
        $this->expectException(CarValidationException::class);
        CarRepository::isFresh(null, '');
    }

    /**
     * The non-short-circuit guarantee (CarRepository.php: "deliberately not
     * short-circuiting on a fresh owner_last_updated"). A naive implementation
     * that checks owner_last_updated first and returns true early would let a
     * malformed last_verified silently pass through whenever
     * owner_last_updated happens to be recent — exactly the corruption-goes-
     * unnoticed failure mode the plan calls out. This pins that BOTH operands
     * are validated unconditionally: a malformed last_verified must throw even
     * when owner_last_updated is recent enough that a short-circuiting
     * implementation would never reach the last_verified parse at all.
     */
    public function testIsFreshThrowsForMalformedLastVerifiedEvenWithRecentOwnerLastUpdated(): void
    {
        $this->expectException(CarValidationException::class);
        CarRepository::isFresh('not-a-date', $this->at(-10));
    }

    /**
     * strtotime() is permissive in a way that defeats this method's contract:
     * 'now', 'tomorrow', '+1 day', ' ' and a bare '2026' all parse to a
     * plausible timestamp rather than false. Left unguarded, any of them would
     * produce a confident true/false from corrupt data instead of the
     * documented CarValidationException — the silent-wrong-answer this method
     * exists to prevent. Both operands come from a DATETIME column, so only the
     * stored `Y-m-d H:i:s` shape is acceptable.
     *
     * @param string $value A string strtotime() accepts but the column cannot hold
     */
    #[DataProvider('strtotimeAcceptsButColumnCannotHoldProvider')]
    public function testIsFreshRejectsRelativeAndPartialDateStrings(string $value): void
    {
        $this->expectException(CarValidationException::class);
        CarRepository::isFresh(null, $value);
    }

    /**
     * Same permissive inputs, supplied as last_verified rather than
     * owner_last_updated — the nullable operand must be validated to the same
     * standard when it is non-null.
     *
     * @param string $value A string strtotime() accepts but the column cannot hold
     */
    #[DataProvider('strtotimeAcceptsButColumnCannotHoldProvider')]
    public function testIsFreshRejectsRelativeAndPartialDateStringsForLastVerified(string $value): void
    {
        $this->expectException(CarValidationException::class);
        CarRepository::isFresh($value, $this->at(-10));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function strtotimeAcceptsButColumnCannotHoldProvider(): array
    {
        return [
            'relative keyword now'      => ['now'],
            'relative keyword tomorrow' => ['tomorrow'],
            'relative offset'           => ['+1 day'],
            'zero date'                 => ['0000-00-00'],
            'year only'                 => ['2026'],
            'whitespace only'           => [' '],
            'date without time'         => ['2026-09-05'],
            // Shape-valid but calendar-invalid. A regex-only guard passes all
            // three and strtotime() then rolls them over into a plausible
            // timestamp, so isFresh() would report corrupt data as FRESH and
            // suppress the owner's verification email for a year — #1953's own
            // defect on the PHP side. Reachable because users/classes/DB.php
            // sets `sql_mode = ''` on every application connection, so MySQL
            // stores and returns a zero-date in a DATETIME column.
            'impossible day'            => ['2026-02-30 12:00:00'],
            'zero datetime full length' => ['0000-00-00 00:00:00'],
            'zero month and day'        => ['2026-00-00 00:00:00'],
            'month out of range'        => ['2026-13-01 00:00:00'],
        ];
    }

    /**
     * The stored DATETIME shape must still be accepted — the guard above must
     * reject malformed input without also rejecting real column values.
     */
    public function testIsFreshAcceptsTheStoredDatetimeShape(): void
    {
        $this->assertTrue(CarRepository::isFresh(null, $this->at(-10)));
    }

    // ------------------------------------------------------------------
    // Alias rejection — freshnessSql() / stalenessSql()
    // ------------------------------------------------------------------

    public function testFreshnessSqlRejectsInjectionShapedAlias(): void
    {
        $this->expectException(CarValidationException::class);
        CarRepository::freshnessSql('cars; DROP TABLE cars--');
    }

    public function testFreshnessSqlRejectsAliasStartingWithDigit(): void
    {
        $this->expectException(CarValidationException::class);
        CarRepository::freshnessSql('1cars');
    }

    public function testFreshnessSqlRejectsEmptyAlias(): void
    {
        $this->expectException(CarValidationException::class);
        CarRepository::freshnessSql('');
    }

    public function testStalenessSqlRejectsInjectionShapedAlias(): void
    {
        $this->expectException(CarValidationException::class);
        CarRepository::stalenessSql('cars; DROP TABLE cars--');
    }

    public function testStalenessSqlRejectsAliasStartingWithDigit(): void
    {
        $this->expectException(CarValidationException::class);
        CarRepository::stalenessSql('1cars');
    }

    public function testStalenessSqlRejectsEmptyAlias(): void
    {
        $this->expectException(CarValidationException::class);
        CarRepository::stalenessSql('');
    }
}
