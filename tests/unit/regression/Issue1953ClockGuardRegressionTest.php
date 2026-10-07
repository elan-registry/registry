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
 * If the MySQL and PHP clocks disagree, the TIMESTAMP -> DATETIME conversion
 * shifts every stored value. The values stay well-formed, so the damage is
 * silent and only a backup restore fixes it. The guard must compare the
 * clocks, not @@session.time_zone against @@global.time_zone: both can read
 * SYSTEM while the clocks are seven hours apart.
 *
 * @issue 1953
 * @link https://github.com/elan-registry/registry/issues/1953
 */
#[Group('fast')]
#[Group('regression')]
final class Issue1953ClockGuardRegressionTest extends TestCase
{
    public function testPassesWhenClocksAgreeExactly(): void
    {
        $this->expectNotToPerformAssertions();

        ConvertCarTimestampsToDatetime::assertClocksAligned(
            '2026-09-05 10:00:00',
            '2026-09-05 10:00:00'
        );
    }

    /**
     * A guard that tripped on normal jitter would block every deploy.
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
     * The skew measured locally for #1953 (MySQL on US/Pacific, PHP on UTC),
     * which a @@session vs @@global comparison misses.
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
     * The operator's next action is to correct one of the two clocks.
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
     * Treating an empty reading as "no skew" would let the conversion run unguarded.
     */
    #[DataProvider('unreadableClockProvider')]
    public function testFailsClosedWhenMysqlClockIsUnreadable(string $dbNow): void
    {
        $this->expectException(\RuntimeException::class);

        ConvertCarTimestampsToDatetime::assertClocksAligned($dbNow, '2026-09-05 10:00:00');
    }

    /**
     * The first implementation checked only the MySQL side.
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
