<?php

declare(strict_types=1);

use ElanRegistry\Cron\CronJobEnabledState;
use ElanRegistry\Cron\CronJobRunsReader;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\CronJobRunsReaderFakeDatabase;

require_once __DIR__ . '/../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../Support/CronJobRunsReaderFakeDatabase.php';

/**
 * Unit tests for CronJobRunsReader — the read-only, never-throws
 * er_cron_job_runs reader backing the verification tab's reconciliation
 * status row (#2054).
 *
 * `status()` is the primary surface under test: it covers all four
 * CronJobEnabledState outcomes plus the three lastRunAt shapes a caller can
 * receive (missing/unreadable row, "never run" row, and a parsed timestamp)
 * in a single read. `state()` and `lastRunAt()` are thin wrappers around
 * `status()` and get a light smoke test each rather than full duplicate
 * coverage. Logging is asserted separately: only the two fault cases
 * (MISSING, UNREADABLE) log under LOG_CATEGORY_CRON_JOB_FAILURE — DISABLED
 * and ENABLED are routine display reads and must stay silent (see the class
 * docblock).
 */
#[Group('fast')]
final class CronJobRunsReaderTest extends TestCase
{
    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];
    }

    // =========================================================================
    // status()
    // =========================================================================

    public function testStatusReturnsEnabledWhenRowEnabled(): void
    {
        $db = (new CronJobRunsReaderFakeDatabase())->withRow('reconciliation', enabled: true);

        $status = (new CronJobRunsReader($db))->status('reconciliation');

        $this->assertSame(CronJobEnabledState::ENABLED, $status['state']);
    }

    public function testStatusReturnsDisabledWhenRowDisabled(): void
    {
        $db = (new CronJobRunsReaderFakeDatabase())->withRow('reconciliation', enabled: false);

        $status = (new CronJobRunsReader($db))->status('reconciliation');

        $this->assertSame(CronJobEnabledState::DISABLED, $status['state']);
    }

    public function testStatusReturnsMissingWhenNoRowConfigured(): void
    {
        // Unconfigured job name defaults to "no row" per the fake DB's docblock.
        $db = new CronJobRunsReaderFakeDatabase();

        $status = (new CronJobRunsReader($db))->status('reconciliation');

        $this->assertSame(CronJobEnabledState::MISSING, $status['state']);
    }

    public function testStatusReturnsUnreadableWhenQueryErrors(): void
    {
        $db = (new CronJobRunsReaderFakeDatabase())->withError('reconciliation');

        $status = (new CronJobRunsReader($db))->status('reconciliation');

        $this->assertSame(CronJobEnabledState::UNREADABLE, $status['state']);
    }

    /**
     * The four states side by side: the distinction between MISSING and
     * UNREADABLE (both "not enabled", but one is a fault and the other a
     * seeding gap) is the whole point of this class over a plain boolean —
     * one test that fails if any two states are ever collapsed together.
     */
    public function testAllFourStatesAreIndependentlyDistinguishable(): void
    {
        $enabledDb = (new CronJobRunsReaderFakeDatabase())->withRow('job', enabled: true);
        $disabledDb = (new CronJobRunsReaderFakeDatabase())->withRow('job', enabled: false);
        $missingDb = new CronJobRunsReaderFakeDatabase();
        $unreadableDb = (new CronJobRunsReaderFakeDatabase())->withError('job');

        $this->assertSame([
            'enabled' => CronJobEnabledState::ENABLED,
            'disabled' => CronJobEnabledState::DISABLED,
            'missing' => CronJobEnabledState::MISSING,
            'unreadable' => CronJobEnabledState::UNREADABLE,
        ], [
            'enabled' => (new CronJobRunsReader($enabledDb))->status('job')['state'],
            'disabled' => (new CronJobRunsReader($disabledDb))->status('job')['state'],
            'missing' => (new CronJobRunsReader($missingDb))->status('job')['state'],
            'unreadable' => (new CronJobRunsReader($unreadableDb))->status('job')['state'],
        ]);
    }

    public function testStatusLastRunAtIsNullWhenRowMissing(): void
    {
        $db = new CronJobRunsReaderFakeDatabase();

        $status = (new CronJobRunsReader($db))->status('reconciliation');

        $this->assertNull($status['lastRunAt']);
    }

    public function testStatusLastRunAtIsNullWhenRowUnreadable(): void
    {
        $db = (new CronJobRunsReaderFakeDatabase())->withError('reconciliation');

        $status = (new CronJobRunsReader($db))->status('reconciliation');

        $this->assertNull($status['lastRunAt']);
    }

    /**
     * A row that exists (enabled) but has never claimed a run — last_run_at
     * is NULL. This must be distinguished from the missing/unreadable cases
     * via state, but lastRunAt alone reports null for all three.
     */
    public function testStatusLastRunAtIsNullWhenJobHasNeverRun(): void
    {
        $db = (new CronJobRunsReaderFakeDatabase())->withRow('reconciliation', enabled: true, lastRunAt: null);

        $status = (new CronJobRunsReader($db))->status('reconciliation');

        $this->assertSame(CronJobEnabledState::ENABLED, $status['state']);
        $this->assertNull($status['lastRunAt']);
    }

    public function testStatusLastRunAtIsParsedDateTimeImmutableWhenSet(): void
    {
        $expected = '2026-09-01 12:34:56';
        $db = (new CronJobRunsReaderFakeDatabase())->withRow('reconciliation', enabled: true, lastRunAt: $expected);

        $status = (new CronJobRunsReader($db))->status('reconciliation');

        $this->assertInstanceOf(DateTimeImmutable::class, $status['lastRunAt']);
        $this->assertSame($expected, $status['lastRunAt']->format('Y-m-d H:i:s'));
    }

    // =========================================================================
    // state() / lastRunAt() — thin wrappers around status()
    // =========================================================================

    public function testStateReturnsSameStateAsStatus(): void
    {
        $db = (new CronJobRunsReaderFakeDatabase())->withRow('reconciliation', enabled: true);

        $this->assertSame(
            CronJobEnabledState::ENABLED,
            (new CronJobRunsReader($db))->state('reconciliation')
        );
    }

    public function testLastRunAtReturnsSameValueAsStatus(): void
    {
        $expected = '2026-09-01 12:34:56';
        $db = (new CronJobRunsReaderFakeDatabase())->withRow('reconciliation', enabled: true, lastRunAt: $expected);

        $result = (new CronJobRunsReader($db))->lastRunAt('reconciliation');

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame($expected, $result->format('Y-m-d H:i:s'));
    }

    // =========================================================================
    // Logging — only the two fault cases (MISSING, UNREADABLE) log, and only
    // once per status() call (a single fetchRow() call backs both the state
    // and lastRunAt halves of the result).
    // =========================================================================

    public function testStatusLogsCronJobFailureOnceWhenMissing(): void
    {
        global $mockLogEntries;

        $db = new CronJobRunsReaderFakeDatabase();
        (new CronJobRunsReader($db))->status('reconciliation');

        $this->assertCount(1, $mockLogEntries, 'A missing row must be logged exactly once, not twice');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $mockLogEntries[0]['category']);
    }

    public function testStatusLogsCronJobFailureOnceWhenUnreadable(): void
    {
        global $mockLogEntries;

        $db = (new CronJobRunsReaderFakeDatabase())->withError('reconciliation');
        (new CronJobRunsReader($db))->status('reconciliation');

        $this->assertCount(1, $mockLogEntries, 'An unreadable row must be logged exactly once, not twice');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $mockLogEntries[0]['category']);
    }

    public function testStatusDoesNotLogWhenDisabled(): void
    {
        global $mockLogEntries;

        $db = (new CronJobRunsReaderFakeDatabase())->withRow('reconciliation', enabled: false);
        (new CronJobRunsReader($db))->status('reconciliation');

        $this->assertSame([], $mockLogEntries, 'Reading a disabled job\'s status for display is not a fault');
    }

    public function testStatusDoesNotLogWhenEnabled(): void
    {
        global $mockLogEntries;

        $db = (new CronJobRunsReaderFakeDatabase())->withRow('reconciliation', enabled: true);
        (new CronJobRunsReader($db))->status('reconciliation');

        $this->assertSame([], $mockLogEntries, 'Reading an enabled job\'s status for display is not a fault');
    }

    public function testStatusDoesNotLogWhenJobHasNeverRun(): void
    {
        global $mockLogEntries;

        $db = (new CronJobRunsReaderFakeDatabase())->withRow('reconciliation', enabled: true, lastRunAt: null);
        (new CronJobRunsReader($db))->status('reconciliation');

        $this->assertSame([], $mockLogEntries, 'A "never run" enabled row is not a fault');
    }

    public function testStatusDoesNotLogWhenTimestampParsesSuccessfully(): void
    {
        global $mockLogEntries;

        $db = (new CronJobRunsReaderFakeDatabase())
            ->withRow('reconciliation', enabled: true, lastRunAt: '2026-09-01 12:34:56');
        (new CronJobRunsReader($db))->status('reconciliation');

        $this->assertSame([], $mockLogEntries, 'A successfully parsed timestamp is not a fault');
    }

    // =========================================================================
    // lastOutcomeCounts() — sent/skipped/failed tallies from a job's most
    // recent recorded run. Never-throws contract: a thrown prepare()-time
    // fault, an ordinary error() fault, a missing row, and a row whose counts
    // are still NULL all report `counts => null` rather than raising or
    // inventing zeros. The "never run" (null) vs. "ran and did nothing"
    // (all-zero) distinction is the entire point of this method per its own
    // docblock, so both are covered explicitly below.
    //
    // The second axis, `unreadable`, separates the two infrastructure faults
    // from the routine never-run case, which a bare `?array` could not: all
    // three used to return null and render identically as the reassuring "No
    // automatic run yet". testLastOutcomeCountsDistinguishesNeverRunFromBoth
    // FaultPaths below is the direct regression guard for that.
    // =========================================================================

    /**
     * The try/catch added around query() for the prepare()-time PDOException
     * case (a missing column on a half-applied
     * 20260916000000_add_cron_job_runs_last_outcome_counts migration) — the
     * one path this class docblock explicitly calls out as making
     * lastOutcomeCounts() not naturally throw-free like the rest of the class.
     */
    public function testLastOutcomeCountsReportsUnreadableWhenQueryThrows(): void
    {
        $db = (new CronJobRunsReaderFakeDatabase())->withOutcomeCountsThrowing('send_verification_batch');

        $result = (new CronJobRunsReader($db))->lastOutcomeCounts('send_verification_batch');

        $this->assertSame(['counts' => null, 'unreadable' => true], $result);
    }

    public function testLastOutcomeCountsLogsCronJobFailureWhenQueryThrows(): void
    {
        global $mockLogEntries;

        $db = (new CronJobRunsReaderFakeDatabase())->withOutcomeCountsThrowing('send_verification_batch');
        (new CronJobRunsReader($db))->lastOutcomeCounts('send_verification_batch');

        $this->assertCount(1, $mockLogEntries, 'A thrown query fault must be logged exactly once');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $mockLogEntries[0]['category']);
    }

    public function testLastOutcomeCountsReportsUnreadableWhenDbErrorsWithoutThrowing(): void
    {
        $db = (new CronJobRunsReaderFakeDatabase())->withOutcomeCountsError('send_verification_batch');

        $result = (new CronJobRunsReader($db))->lastOutcomeCounts('send_verification_batch');

        $this->assertSame(['counts' => null, 'unreadable' => true], $result);
    }

    public function testLastOutcomeCountsLogsCronJobFailureWhenDbErrors(): void
    {
        global $mockLogEntries;

        $db = (new CronJobRunsReaderFakeDatabase())->withOutcomeCountsError('send_verification_batch');
        (new CronJobRunsReader($db))->lastOutcomeCounts('send_verification_batch');

        $this->assertCount(1, $mockLogEntries, 'An error()-reported fault must be logged exactly once');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $mockLogEntries[0]['category']);
    }

    public function testLastOutcomeCountsReportsNeverRunWhenRowIsMissing(): void
    {
        // Unconfigured job_name — no row, no error, no throw configured.
        $db = new CronJobRunsReaderFakeDatabase();

        $result = (new CronJobRunsReader($db))->lastOutcomeCounts('send_verification_batch');

        $this->assertSame(
            ['counts' => null, 'unreadable' => false],
            $result,
            'A missing row is the routine never-run case, not an unreadable one'
        );
    }

    /**
     * The load-bearing "never run" vs. "ran and did nothing" distinction the
     * class docblock calls out: a row exists but last_sent_count is still
     * NULL (the job has never recorded a run) must report null, not zeros.
     */
    public function testLastOutcomeCountsReturnsNullWhenLastSentCountIsNull(): void
    {
        $db = (new CronJobRunsReaderFakeDatabase())
            ->withOutcomeCounts('send_verification_batch', sentCount: null, skippedCount: null, failedCount: null);

        $result = (new CronJobRunsReader($db))->lastOutcomeCounts('send_verification_batch');

        $this->assertSame(
            ['counts' => null, 'unreadable' => false],
            $result,
            'A row with last_sent_count still NULL means "never run", not zeros and not a fault'
        );
    }

    /**
     * The regression guard for the bug this shape exists to fix: all three of
     * these situations used to return a bare null and render identically as
     * the reassuring "No automatic run yet". Asserting each one's null-ness
     * separately would not prove they are distinguishable, so this asserts
     * the full shapes side by side in one test.
     */
    public function testLastOutcomeCountsDistinguishesNeverRunFromBothFaultPaths(): void
    {
        $neverRun = (new CronJobRunsReader(
            (new CronJobRunsReaderFakeDatabase())->withOutcomeCounts('send_verification_batch', sentCount: null)
        ))->lastOutcomeCounts('send_verification_batch');

        $threw = (new CronJobRunsReader(
            (new CronJobRunsReaderFakeDatabase())->withOutcomeCountsThrowing('send_verification_batch')
        ))->lastOutcomeCounts('send_verification_batch');

        $errored = (new CronJobRunsReader(
            (new CronJobRunsReaderFakeDatabase())->withOutcomeCountsError('send_verification_batch')
        ))->lastOutcomeCounts('send_verification_batch');

        $this->assertFalse($neverRun['unreadable'], 'A job that has simply never run is not a fault');
        $this->assertTrue($threw['unreadable'], 'A thrown query fault must not read as "never run"');
        $this->assertTrue($errored['unreadable'], 'An error()-reported fault must not read as "never run"');

        // The counts half is null in all three — which is exactly why the
        // caller cannot use it alone to tell them apart.
        $this->assertNull($neverRun['counts']);
        $this->assertNull($threw['counts']);
        $this->assertNull($errored['counts']);
    }

    /**
     * The inverse of the above: a job that genuinely ran and sent/skipped/
     * failed nothing must report real zeros, distinguishable from the NULL
     * "never run" case.
     */
    public function testLastOutcomeCountsReturnsAllZerosWhenJobRanAndDidNothing(): void
    {
        $db = (new CronJobRunsReaderFakeDatabase())
            ->withOutcomeCounts('send_verification_batch', sentCount: 0, skippedCount: 0, failedCount: 0);

        $result = (new CronJobRunsReader($db))->lastOutcomeCounts('send_verification_batch');

        $this->assertSame(
            ['counts' => ['sent' => 0, 'skipped' => 0, 'failed' => 0], 'unreadable' => false],
            $result
        );
    }

    public function testLastOutcomeCountsReturnsPopulatedCountsWhenAllColumnsSet(): void
    {
        $db = (new CronJobRunsReaderFakeDatabase())
            ->withOutcomeCounts('send_verification_batch', sentCount: 12, skippedCount: 3, failedCount: 1);

        $result = (new CronJobRunsReader($db))->lastOutcomeCounts('send_verification_batch');

        $this->assertSame(
            ['counts' => ['sent' => 12, 'skipped' => 3, 'failed' => 1], 'unreadable' => false],
            $result
        );
    }

    public function testLastOutcomeCountsDoesNotLogWhenCountsArePopulated(): void
    {
        global $mockLogEntries;

        $db = (new CronJobRunsReaderFakeDatabase())
            ->withOutcomeCounts('send_verification_batch', sentCount: 12, skippedCount: 3, failedCount: 1);
        (new CronJobRunsReader($db))->lastOutcomeCounts('send_verification_batch');

        $this->assertSame([], $mockLogEntries, 'Successfully reading populated counts is not a fault');
    }

    public function testLastOutcomeCountsDoesNotLogWhenRowIsMissing(): void
    {
        global $mockLogEntries;

        $db = new CronJobRunsReaderFakeDatabase();
        (new CronJobRunsReader($db))->lastOutcomeCounts('send_verification_batch');

        $this->assertSame(
            [],
            $mockLogEntries,
            'A missing row (job seeded before outcome-count columns existed) is not logged by lastOutcomeCounts() itself'
        );
    }

    public function testLastOutcomeCountsDoesNotLogWhenNeverRun(): void
    {
        global $mockLogEntries;

        $db = (new CronJobRunsReaderFakeDatabase())
            ->withOutcomeCounts('send_verification_batch', sentCount: null);
        (new CronJobRunsReader($db))->lastOutcomeCounts('send_verification_batch');

        $this->assertSame([], $mockLogEntries, 'A "never run" NULL-counts row is not a fault');
    }
}
