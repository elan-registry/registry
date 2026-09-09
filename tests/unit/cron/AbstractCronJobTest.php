<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\AbstractCronJobFakeDatabase;
use Tests\Support\SpyCronJob;

require_once __DIR__ . '/../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../Support/AbstractCronJobFakeDatabase.php';
require_once __DIR__ . '/../../Support/SpyCronJob.php';

/**
 * Unit tests for AbstractCronJob — the template-method base every cron job
 * builds on (#1889).
 *
 * The behaviour under test is mostly *negative*: what must NOT happen when a
 * job is disabled, and what must NOT escape when a job's work throws. Both
 * exist because cron.php dispatches jobs in-process, so a job that throws or
 * runs when it shouldn't affects every job after it.
 */
#[Group('fast')]
final class AbstractCronJobTest extends TestCase
{
    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];
    }

    public function testRunExecutesWhenEnabledAndClaimSucceeds(): void
    {
        $job = new SpyCronJob(new AbstractCronJobFakeDatabase(enabled: true, claimSucceeds: true));

        $job->run();

        $this->assertSame(1, $job->executeCalls);
    }

    public function testRunSwallowsThrowableFromExecuteAndLogsFailure(): void
    {
        global $mockLogEntries;

        $job = new SpyCronJob(new AbstractCronJobFakeDatabase(enabled: true, claimSucceeds: true));
        $job->throwOnExecute(new RuntimeException('boom'));

        // No expectException(): the whole point is that nothing escapes run().
        $job->run();

        $this->assertSame(1, $job->executeCalls, 'execute() must have been reached before it threw');
        $this->assertCount(1, $mockLogEntries, 'A job failure must be logged, not silent');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('RuntimeException', $mockLogEntries[0]['message']);
        $this->assertStringContainsString('boom', $mockLogEntries[0]['message']);
    }

    /**
     * An \Error (not an \Exception) is the case a bare `catch (\Exception)`
     * would miss — and it is exactly what a fatal TypeError in a job's work
     * raises, which is the cascade this class exists to contain.
     */
    public function testRunSwallowsErrorNotJustException(): void
    {
        $job = new SpyCronJob(new AbstractCronJobFakeDatabase(enabled: true, claimSucceeds: true));
        $job->throwOnExecute(new TypeError('bad type'));

        $job->run();

        $this->addToAssertionCount(1);
    }

    public function testRunDoesNotExecuteWhenJobDisabled(): void
    {
        global $mockLogEntries;

        $job = new SpyCronJob(new AbstractCronJobFakeDatabase(enabled: false));

        $job->run();

        $this->assertSame(0, $job->executeCalls, 'A disabled job must not run its work');
        $this->assertCount(1, $mockLogEntries, 'A disabled job must be logged so an operator sees why it stopped');
        $this->assertSame(
            LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED,
            $mockLogEntries[0]['category'],
            'Disabled is a skip, not a failure — the two must be independently observable'
        );
    }

    /**
     * A row that was never seeded (or was deleted) means this job can never
     * run again. That is a misconfiguration an operator must see, and it must
     * not be reported as a deliberate pause — hence FAILURE, not SKIPPED.
     */
    public function testRunDoesNotExecuteWhenGuardRowMissing(): void
    {
        global $mockLogEntries;

        $job = new SpyCronJob(new AbstractCronJobFakeDatabase(rowExists: false));

        $job->run();

        $this->assertSame(0, $job->executeCalls, 'A missing er_cron_job_runs row must fail safe, not run');
        $this->assertCount(1, $mockLogEntries, 'A missing row must be logged, not silently skipped');
        $this->assertSame(
            LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
            $mockLogEntries[0]['category'],
            'A never-seeded row is a fault, not an operator pause'
        );
    }

    /**
     * A failed read is an infrastructure fault. It must be independently
     * observable from a job an operator paused on purpose, which is exactly
     * what the old single-boolean isEnabled() made impossible.
     */
    public function testRunDoesNotExecuteWhenEnabledReadErrors(): void
    {
        global $mockLogEntries;

        $job = new SpyCronJob(new AbstractCronJobFakeDatabase(queryErrors: true));

        $job->run();

        $this->assertSame(0, $job->executeCalls, 'An unreadable guard row must fail safe, not run');
        $this->assertCount(1, $mockLogEntries, 'An unreadable row must be logged, not silently skipped');
        $this->assertSame(
            LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
            $mockLogEntries[0]['category'],
            'A DB fault must not be indistinguishable from a deliberate pause'
        );
    }

    /**
     * The three non-enabled states asserted side by side: the distinction is
     * the whole point of the state resolution, so it gets one test that would
     * fail if any two were ever collapsed back into a single category.
     */
    public function testEachNonEnabledStateLogsItsOwnCategory(): void
    {
        $categories = [];

        foreach ([
            'disabled' => new AbstractCronJobFakeDatabase(enabled: false),
            'missing' => new AbstractCronJobFakeDatabase(rowExists: false),
            'unreadable' => new AbstractCronJobFakeDatabase(queryErrors: true),
        ] as $state => $db) {
            $categories[$state] = $this->categoryLoggedBy($db);
        }

        $this->assertSame([
            'disabled' => LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED,
            'missing' => LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
            'unreadable' => LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
        ], $categories);
    }

    /**
     * Run a job against $db from a clean log and report the category of the
     * single line it emitted.
     */
    private function categoryLoggedBy(AbstractCronJobFakeDatabase $db): ?string
    {
        global $mockLogEntries;

        $mockLogEntries = [];
        (new SpyCronJob($db))->run();

        $entries = $mockLogEntries;
        $this->assertCount(1, $entries, 'Every non-enabled state must log exactly once');

        return $entries[0]['category'];
    }

    /**
     * A job disabled before it ever logged a skip (last_skip_logged_at NULL —
     * including a freshly seeded row, whose last_run_at is NULL too) must
     * still surface the pause once, and must stamp the throttle column so the
     * next hit is quiet.
     */
    public function testDisabledJobLogsOnceWhenItHasNeverLoggedASkip(): void
    {
        global $mockLogEntries;

        $db = new AbstractCronJobFakeDatabase(enabled: false, lastSkipLoggedAtMinutesAgo: null);

        (new SpyCronJob($db))->run();

        $this->assertCount(1, $mockLogEntries, 'A never-logged pause must surface');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED, $mockLogEntries[0]['category']);
        $this->assertSame(1, $db->skipStampWrites(), 'Logging the skip must stamp last_skip_logged_at');
    }

    public function testDisabledJobIsSilentWhenItLoggedWithinTheGuardInterval(): void
    {
        global $mockLogEntries;

        // 24h interval; last logged an hour ago.
        $db = new AbstractCronJobFakeDatabase(enabled: false, lastSkipLoggedAtMinutesAgo: 60);

        (new SpyCronJob($db))->run();

        $this->assertSame([], $mockLogEntries, 'A skip logged within the interval must not log again');
        $this->assertSame(0, $db->skipStampWrites(), 'A suppressed skip must not refresh the throttle stamp');
    }

    public function testDisabledJobLogsAgainOnceTheGuardIntervalHasElapsed(): void
    {
        global $mockLogEntries;

        // 24h interval; last logged 25h ago.
        $db = new AbstractCronJobFakeDatabase(enabled: false, lastSkipLoggedAtMinutesAgo: 25 * 60);

        (new SpyCronJob($db))->run();

        $this->assertCount(1, $mockLogEntries, 'The first hit past the interval must surface the pause again');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_SKIPPED, $mockLogEntries[0]['category']);
        $this->assertSame(1, $db->skipStampWrites());
    }

    /**
     * #1974 removed a per-cron-hit log line from the transport precisely
     * because ~144 identical rows a day carry no diagnostic value past the
     * first. A job left disabled must not reintroduce that.
     *
     * The regression this guards is specifically a throttle that holds for one
     * interval and then degrades to logging every hit: the first attempt at
     * this rate limit gated on `last_run_at`, which a disabled job never
     * advances (only CronJobGuard::claim() writes it, and a disabled job
     * returns before claiming), so its predicate latched permanently true.
     * Asserting only on the first interval would have passed against that bug.
     * So this drives 144 hits — a full simulated day at the real 10-minute
     * transport cadence, crossing the 24-hour guard boundary — and requires
     * the count to stay at the throttled cadence rather than tracking the hit
     * count.
     */
    public function testDisabledJobLogsOncePerIntervalAcrossManyCronHits(): void
    {
        global $mockLogEntries;

        $db = new AbstractCronJobFakeDatabase(enabled: false, lastSkipLoggedAtMinutesAgo: null);
        $job = new SpyCronJob($db);

        // 146 hits × 10 minutes spans 24h10m of simulated time. The first hit
        // logs (and stamps) at t=0; the interval expires strictly after
        // t=1440, so the 146th hit (t=1450) is the only other one due —
        // exactly two lines out of 146 dispatches.
        for ($hit = 0; $hit < 146; $hit++) {
            $job->run();
            $db->advanceMinutes(10);
        }

        $this->assertSame(0, $job->executeCalls);
        $this->assertCount(
            2,
            $mockLogEntries,
            'A disabled job must log once per guard interval, not once per cron hit (#1974)'
        );
        $this->assertSame(2, $db->skipStampWrites(), 'One throttle stamp per line actually logged');
    }

    /**
     * A lost/too-recent claim is the routine per-cron-hit outcome. Logging it
     * would put a line in the log on every dispatch for every job that isn't
     * due — hence the deliberate silence.
     */
    public function testRunIsSilentWhenClaimFails(): void
    {
        global $mockLogEntries;

        $job = new SpyCronJob(new AbstractCronJobFakeDatabase(enabled: true, claimSucceeds: false));

        $job->run();

        $this->assertSame(0, $job->executeCalls);
        $this->assertSame([], $mockLogEntries, 'A "not due yet" claim must not log — it is not a failure');
    }

    public function testRunNowExecutesEvenWhenDisabledAndClaimWouldFail(): void
    {
        $job = new SpyCronJob(new AbstractCronJobFakeDatabase(enabled: false, claimSucceeds: false));

        $job->runNow();

        $this->assertSame(1, $job->executeCalls, 'The manual bypass must skip both the enabled check and the guard');
    }

    public function testRunNowSwallowsThrowableFromExecute(): void
    {
        global $mockLogEntries;

        $job = new SpyCronJob(new AbstractCronJobFakeDatabase(enabled: false, claimSucceeds: false));
        $job->throwOnExecute(new RuntimeException('boom'));

        // A job bug must not fatal the admin page that triggered the run.
        $job->runNow();

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $mockLogEntries[0]['category']);
    }
}
