<?php

declare(strict_types=1);

use ElanRegistry\Cron\CronJobEnabledState;
use ElanRegistry\Cron\CronJobRunsReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CronJobRunsReader::badgeFor() — the CronJobEnabledState →
 * badge class/icon/text mapping backing the verification tab's
 * reconciliation status row (#2054).
 *
 * Extracted from an inline `match` block in tab-verification.php so this
 * display logic is independently testable; the regression test at
 * tests/unit/regression/Issue2054RegressionTest.php only asserts on the
 * file's text contents, not this mapping's actual behavior.
 */
#[Group('fast')]
final class CronJobRunsReaderBadgeTest extends TestCase
{
    /**
     * @return array<string, array{CronJobEnabledState, ?DateTimeImmutable, string, string}>
     */
    public static function badgeCases(): array
    {
        $timestamp = new DateTimeImmutable('2026-09-01 12:34:56');

        return [
            'enabled with timestamp: success badge, Ran' => [
                CronJobEnabledState::ENABLED,
                $timestamp,
                'badge text-bg-success',
                'Ran',
            ],
            'enabled with no timestamp: non-danger badge, Never run' => [
                CronJobEnabledState::ENABLED,
                null,
                'badge text-bg-secondary',
                'Never run',
            ],
            'disabled with no timestamp: Paused' => [
                CronJobEnabledState::DISABLED,
                null,
                'badge text-bg-secondary',
                'Paused',
            ],
            'disabled with timestamp: still Paused, not misclassified' => [
                CronJobEnabledState::DISABLED,
                $timestamp,
                'badge text-bg-secondary',
                'Paused',
            ],
            'missing: danger badge, Status unavailable' => [
                CronJobEnabledState::MISSING,
                null,
                'badge text-bg-danger',
                'Status unavailable',
            ],
            'unreadable: danger badge, Status unavailable (same as missing)' => [
                CronJobEnabledState::UNREADABLE,
                null,
                'badge text-bg-danger',
                'Status unavailable',
            ],
        ];
    }

    #[DataProvider('badgeCases')]
    public function testBadgeForMapsStateAndTimestampToDisplayAttributes(
        CronJobEnabledState $state,
        ?DateTimeImmutable $lastRunAt,
        string $expectedBadgeClass,
        string $expectedText
    ): void {
        $badge = CronJobRunsReader::badgeFor($state, $lastRunAt);

        $this->assertSame($expectedBadgeClass, $badge['badgeClass']);
        $this->assertSame($expectedText, $badge['text']);
        $this->assertNotSame('', $badge['icon'], 'Every state must map to a non-empty icon');
    }

    /**
     * The AC explicitly requires "Never run" not be rendered as an error —
     * ENABLED with a null timestamp is a routine "hasn't run yet" state, not
     * a fault, so it must never share the danger badge class with
     * MISSING/UNREADABLE.
     */
    public function testEnabledWithNoTimestampIsNotDangerBadge(): void
    {
        $badge = CronJobRunsReader::badgeFor(CronJobEnabledState::ENABLED, null);

        $this->assertNotSame('badge text-bg-danger', $badge['badgeClass']);
    }

    /**
     * DISABLED (a deliberate pause) must render distinctly from
     * MISSING/UNREADABLE (infrastructure faults) — collapsing them would
     * make an outage indistinguishable from an operator's own choice.
     */
    public function testPausedBadgeClassIsDistinctFromDangerBadgeClass(): void
    {
        $pausedBadge = CronJobRunsReader::badgeFor(CronJobEnabledState::DISABLED, null);
        $missingBadge = CronJobRunsReader::badgeFor(CronJobEnabledState::MISSING, null);

        $this->assertNotSame($pausedBadge['badgeClass'], $missingBadge['badgeClass']);
    }

    /**
     * MISSING and UNREADABLE are deliberately identical from the display's
     * perspective — the tab has no way to usefully distinguish "never
     * seeded" from "database fault" to an admin glancing at a badge — so
     * badgeFor() collapses them to the same text/class per the plan's design.
     */
    public function testMissingAndUnreadableMapIdentically(): void
    {
        $missingBadge = CronJobRunsReader::badgeFor(CronJobEnabledState::MISSING, null);
        $unreadableBadge = CronJobRunsReader::badgeFor(CronJobEnabledState::UNREADABLE, null);

        $this->assertSame($missingBadge, $unreadableBadge);
    }
}
