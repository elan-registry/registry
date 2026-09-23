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

    // =========================================================================
    // The failure case. CronJobGuard::claim() stamps last_run_at BEFORE
    // execute() runs, so last_run_at alone says only "a run was claimed" — a
    // job that throws every night used to render the green "Ran" badge with a
    // timestamp minutes old. The badge answers the real question by comparing
    // the two timestamps: was the MOST RECENT claimed run the one that failed?
    // =========================================================================

    public function testEnabledWithFailureNewerThanLastRunRendersFailureBadge(): void
    {
        $badge = CronJobRunsReader::badgeFor(
            CronJobEnabledState::ENABLED,
            new DateTimeImmutable('2026-09-01 02:00:00'),
            new DateTimeImmutable('2026-09-02 02:00:00')
        );

        $this->assertSame('badge text-bg-danger', $badge['badgeClass']);
        $this->assertSame('Last run failed', $badge['text']);
        $this->assertNotSame('', $badge['icon']);
    }

    /**
     * A job whose very first claimed run threw: last_run_at was stamped by the
     * claim, but there has never been a successful run to compare against. The
     * failure is unambiguously current.
     */
    public function testEnabledWithFailureAndNoLastRunRendersFailureBadge(): void
    {
        $badge = CronJobRunsReader::badgeFor(
            CronJobEnabledState::ENABLED,
            null,
            new DateTimeImmutable('2026-09-02 02:00:00')
        );

        $this->assertSame('badge text-bg-danger', $badge['badgeClass']);
        $this->assertSame('Last run failed', $badge['text']);
    }

    /**
     * Both columns are written by NOW() at one-second resolution, and a run
     * that is claimed and throws immediately stamps both inside the same
     * second — the common shape for a job that throws on its first statement.
     * A strict `>` comparison would read that tie as a success and render
     * green for the very failure the case exists to surface, so the boundary
     * gets its own test rather than being implied by the newer-than case.
     */
    public function testEnabledWithFailureEqualToLastRunRendersFailureBadge(): void
    {
        $sameInstant = '2026-09-02 02:00:00';

        $badge = CronJobRunsReader::badgeFor(
            CronJobEnabledState::ENABLED,
            new DateTimeImmutable($sameInstant),
            new DateTimeImmutable($sameInstant)
        );

        $this->assertSame(
            'Last run failed',
            $badge['text'],
            'A failure stamped in the same second as the claim must not read as a successful run'
        );
    }

    /**
     * The converse: a failure a later run has already superseded is history,
     * not the current state. The badge reports what the job is doing now, and
     * the log (surfaced on the same tab) is where the history lives — a badge
     * that stayed red forever after one bad night would be ignored within a
     * week.
     */
    public function testEnabledWithLastRunNewerThanFailureRendersRanBadge(): void
    {
        $badge = CronJobRunsReader::badgeFor(
            CronJobEnabledState::ENABLED,
            new DateTimeImmutable('2026-09-03 02:00:00'),
            new DateTimeImmutable('2026-09-01 02:00:00')
        );

        $this->assertSame('badge text-bg-success', $badge['badgeClass']);
        $this->assertSame('Ran', $badge['text']);
    }

    /**
     * The omitted-argument default. Every caller written before this column
     * existed passes two arguments, and must keep its previous behaviour
     * rather than silently acquiring a failure state.
     */
    public function testOmittingTheFailureTimestampPreservesThePreviousBehaviour(): void
    {
        $lastRunAt = new DateTimeImmutable('2026-09-01 12:34:56');

        $this->assertSame(
            CronJobRunsReader::badgeFor(CronJobEnabledState::ENABLED, $lastRunAt, null),
            CronJobRunsReader::badgeFor(CronJobEnabledState::ENABLED, $lastRunAt)
        );
    }

    /**
     * Only the ENABLED arm consults the failure timestamp. A paused job's
     * actionable fact is the pause — nobody is expecting it to run — so a
     * stale failure from before it was paused must not override that, and
     * MISSING/UNREADABLE already render danger for a row whose contents could
     * not be trusted in the first place.
     */
    public function testNonEnabledStatesIgnoreTheFailureTimestamp(): void
    {
        $failureAt = new DateTimeImmutable('2026-09-02 02:00:00');

        foreach ([
            CronJobEnabledState::DISABLED,
            CronJobEnabledState::MISSING,
            CronJobEnabledState::UNREADABLE,
        ] as $state) {
            $this->assertSame(
                CronJobRunsReader::badgeFor($state, null),
                CronJobRunsReader::badgeFor($state, null, $failureAt),
                $state->name . ' must render identically whether or not a failure timestamp is present'
            );
        }
    }

    /**
     * The failure badge must not be confusable with the two existing danger
     * states: MISSING/UNREADABLE say "we cannot tell you anything", while this
     * says "we can, and the news is bad". They share a badge class (both are
     * genuinely bad news) but must differ in text, or an operator reading the
     * tab cannot tell a database fault from a crashing job.
     */
    public function testFailureBadgeTextIsDistinctFromStatusUnavailable(): void
    {
        $failureBadge = CronJobRunsReader::badgeFor(
            CronJobEnabledState::ENABLED,
            null,
            new DateTimeImmutable('2026-09-02 02:00:00')
        );
        $unavailableBadge = CronJobRunsReader::badgeFor(CronJobEnabledState::UNREADABLE, null);

        $this->assertNotSame($failureBadge['text'], $unavailableBadge['text']);
    }
}
