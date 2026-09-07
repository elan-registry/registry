<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

// Phinx migrations are not autoloaded (they live outside the PSR-4 tree and are
// loaded by Phinx at migrate time), so the file is required directly.
require_once __DIR__ . '/../../../database/migrations/20260905172137_convert_car_timestamps_to_datetime.php';

/**
 * Issue #1953: the clock-alignment guard on migration 20260905172137.
 *
 * This guard is the single most consequential safety mechanism in that
 * migration. `ALTER TABLE ... MODIFY COLUMN <ts> DATETIME` renders each stored
 * value as a wall-clock string in the session's timezone, so if MySQL's clock
 * and PHP's clock disagree, every timestamp in `cars` and `cars_hist` shifts
 * permanently. The shifted values are still well-formed, so nothing downstream
 * detects the corruption — a false pass here is silent and irreversible, and
 * the documented remedy is restoring from backup.
 *
 * The guard must compare the two CLOCKS, not `@@session.time_zone` against
 * `@@global.time_zone`. On the project's local MAMP environment both of those
 * read `SYSTEM` while PHP — with `date.timezone` unset, falling back to UTC —
 * sits seven hours from MySQL: the naive comparison passes while every value
 * would shift. That measured case is pinned below so a future "simplification"
 * back to the timezone-variable comparison fails loudly.
 *
 * @issue 1953
 * @link https://github.com/elan-registry/registry/issues/1953
 */
#[Group('fast')]
#[Group('regression')]
final class Issue1953ClockGuardRegressionTest extends TestCase
{
    /**
     * Aligned clocks must not throw — otherwise the migration could never run
     * on a correctly configured host.
     */
    public function testPassesWhenClocksAgreeExactly(): void
    {
        $this->expectNotToPerformAssertions();

        ConvertCarTimestampsToDatetime::assertClocksAligned(
            '2026-09-05 10:00:00',
            '2026-09-05 10:00:00'
        );
    }

    /**
     * Sub-tolerance drift (query round-trip, minor NTP drift) must pass: a
     * guard that tripped on normal jitter would block every deploy.
     *
     * @param int $seconds Skew to apply, within the 120s tolerance
     */
    #[DataProvider('withinToleranceProvider')]
    public function testPassesWithinTolerance(int $seconds): void
    {
        $this->expectNotToPerformAssertions();

        ConvertCarTimestampsToDatetime::assertClocksAligned(
            '2026-09-05 10:00:00',
            date('Y-m-d H:i:s', (int) strtotime('2026-09-05 10:00:00') + $seconds)
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function withinToleranceProvider(): array
    {
        return [
            'one second ahead'     => [1],
            'one second behind'    => [-1],
            'at the tolerance'     => [120],
            'at tolerance, behind' => [-120],
        ];
    }

    /**
     * One second past the tolerance must throw — pins the boundary direction so
     * a later edit cannot silently widen the window.
     *
     * @param int $seconds Skew to apply, beyond the 120s tolerance
     */
    #[DataProvider('beyondToleranceProvider')]
    public function testThrowsBeyondTolerance(int $seconds): void
    {
        $this->expectException(\RuntimeException::class);

        ConvertCarTimestampsToDatetime::assertClocksAligned(
            '2026-09-05 10:00:00',
            date('Y-m-d H:i:s', (int) strtotime('2026-09-05 10:00:00') + $seconds)
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function beyondToleranceProvider(): array
    {
        return [
            'one past tolerance'         => [121],
            'one past tolerance, behind' => [-121],
            'fifteen minutes'            => [900],
            'the measured local 7h skew' => [25200],
        ];
    }

    /**
     * The exact skew measured on the project's local MAMP environment: MySQL on
     * US/Pacific, PHP fallen back to UTC. This is the case the guard exists for,
     * and precisely the case a `@@session` vs `@@global` comparison misses.
     */
    public function testThrowsOnTheMeasuredLocalPhpUtcVersusMysqlPacificSkew(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/25200 seconds apart/');

        ConvertCarTimestampsToDatetime::assertClocksAligned(
            '2026-09-05 10:00:00',
            '2026-09-05 17:00:00'
        );
    }

    /**
     * The message must name both clock readings and point at the fix, because
     * the operator's next action is to correct one of the two clocks.
     */
    public function testExceptionMessageReportsBothClocksAndTheRemedy(): void
    {
        try {
            ConvertCarTimestampsToDatetime::assertClocksAligned(
                '2026-09-05 10:00:00',
                '2026-09-05 17:00:00'
            );
            $this->fail('Expected a RuntimeException for a 7-hour clock skew');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('2026-09-05 10:00:00', $e->getMessage());
            $this->assertStringContainsString('2026-09-05 17:00:00', $e->getMessage());
            $this->assertStringContainsString('date.timezone', $e->getMessage());
        }
    }

    /**
     * An unreadable MySQL clock must fail closed. Treating an empty reading as
     * "no skew detected" would let the conversion proceed unguarded — the worst
     * outcome this guard can produce.
     *
     * @param string $dbNow An unusable MySQL NOW() reading
     */
    #[DataProvider('unreadableClockProvider')]
    public function testFailsClosedWhenMysqlClockIsUnreadable(string $dbNow): void
    {
        $this->expectException(\RuntimeException::class);

        ConvertCarTimestampsToDatetime::assertClocksAligned($dbNow, '2026-09-05 10:00:00');
    }

    /**
     * The guard must fail closed on an unusable PHP reading too — the first
     * implementation checked only the MySQL side.
     *
     * @param string $phpNow An unusable PHP time reading
     */
    #[DataProvider('unreadableClockProvider')]
    public function testFailsClosedWhenPhpClockIsUnreadable(string $phpNow): void
    {
        $this->expectException(\RuntimeException::class);

        ConvertCarTimestampsToDatetime::assertClocksAligned('2026-09-05 10:00:00', $phpNow);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreadableClockProvider(): array
    {
        return [
            'empty (no usable row returned)' => [''],
            'unparseable garbage'            => ['not-a-timestamp'],
        ];
    }
}
