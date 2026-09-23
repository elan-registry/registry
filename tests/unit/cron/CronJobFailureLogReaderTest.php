<?php

declare(strict_types=1);

use ElanRegistry\Cron\CronJobFailureLogReader;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\CronFailureLogFakeDatabase;

require_once __DIR__ . '/../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../Support/CronFailureLogFakeDatabase.php';

/**
 * Unit tests for CronJobFailureLogReader — the summary that finally makes
 * LOG_CATEGORY_CRON_JOB_FAILURE readable from the admin UI.
 *
 * The behaviour that matters here is the null-vs-zero distinction. This is a
 * fault channel, and "0 failures" is the one reading that tells an operator to
 * stop looking — so an unreadable summary must never render as one. Every
 * fault path below is asserted to return `count => null`, and the single
 * genuine-zero path to return `count => 0`, precisely because a caller cannot
 * otherwise tell them apart.
 */
#[Group('fast')]
final class CronJobFailureLogReaderTest extends TestCase
{
    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];
    }

    public function testReturnsZeroAndNoEntriesWhenNothingHasFailed(): void
    {
        $db = new CronFailureLogFakeDatabase(count: 0);

        $result = (new CronJobFailureLogReader($db))->recentFailures();

        $this->assertSame(['count' => 0, 'recent' => []], $result);
    }

    /**
     * A genuine zero must not cost a second query — there is nothing to list,
     * and the detail SELECT would scan the same window to return nothing.
     */
    public function testDoesNotIssueTheDetailQueryWhenTheCountIsZero(): void
    {
        $db = new CronFailureLogFakeDatabase(count: 0);

        (new CronJobFailureLogReader($db))->recentFailures();

        $this->assertCount(1, $db->sqlLog(), 'A zero count must short-circuit before the detail query');
    }

    public function testReturnsCountAndRecentEntriesWhenFailuresExist(): void
    {
        $db = new CronFailureLogFakeDatabase(count: 3, rows: [
            ['logdate' => '2026-09-22 02:00:00', 'lognote' => "Cron job 'send_verification_batch' failed: boom"],
            ['logdate' => '2026-09-21 02:00:00', 'lognote' => "Cron job 'send_verification_batch' failed: boom"],
        ]);

        $result = (new CronJobFailureLogReader($db))->recentFailures();

        $this->assertSame(3, $result['count']);
        $this->assertCount(2, $result['recent']);
        $this->assertSame('2026-09-22 02:00:00', $result['recent'][0]['loggedAt']);
        $this->assertStringContainsString('boom', $result['recent'][0]['message']);
    }

    /**
     * The whole point of the class: it must read back the same category the
     * jobs write to. A mismatched constant would render a permanent, and
     * entirely convincing, zero.
     */
    public function testFiltersOnTheCronJobFailureLogCategory(): void
    {
        $db = new CronFailureLogFakeDatabase(count: 0);

        (new CronJobFailureLogReader($db))->recentFailures();

        $this->assertSame(
            LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
            $db->lastParams()[0] ?? null,
            'The log category must be bound as the first parameter, not interpolated'
        );
        $this->assertStringContainsString('logtype = ?', $db->lastSql());
    }

    public function testBoundsTheWindowToTheLookbackPeriod(): void
    {
        $db = new CronFailureLogFakeDatabase(count: 0);

        (new CronJobFailureLogReader($db))->recentFailures();

        $cutoff = $db->lastParams()[1] ?? null;

        $this->assertIsString($cutoff, 'The cutoff must be bound as the second parameter');
        $this->assertGreaterThan(
            0,
            CronJobFailureLogReader::LOOKBACK_DAYS,
            'A non-positive lookback would make the window empty or unbounded'
        );

        $expected = time() - (CronJobFailureLogReader::LOOKBACK_DAYS * 24 * 60 * 60);
        $this->assertEqualsWithDelta(
            $expected,
            strtotime($cutoff),
            5,
            'The cutoff must be LOOKBACK_DAYS ago, give or take the test\'s own execution time'
        );
    }

    /**
     * The null-not-zero contract. A failed count query rendered as 0 would
     * turn a broken log read into a clean bill of health — the exact failure
     * mode VerificationSettings::unmatchedRecipientCount() returns null to
     * avoid.
     */
    public function testReportsNullCountWhenTheCountQueryErrors(): void
    {
        $db = new CronFailureLogFakeDatabase(countErrors: true);

        $result = (new CronJobFailureLogReader($db))->recentFailures();

        $this->assertSame(['count' => null, 'recent' => []], $result);
    }

    public function testReportsNullCountWhenTheCountQueryReturnsNoRow(): void
    {
        $db = new CronFailureLogFakeDatabase(countRowMissing: true);

        $result = (new CronJobFailureLogReader($db))->recentFailures();

        $this->assertSame(['count' => null, 'recent' => []], $result);
    }

    /**
     * Deliberately silent. Logging a failure to read LOG_CATEGORY_CRON_JOB_FAILURE
     * into that same category would add a row this very query may be unable to
     * see, and would grow the category by one entry on every admin page view
     * for as long as the fault lasted.
     */
    public function testDoesNotLogWhenItsOwnReadFails(): void
    {
        global $mockLogEntries;

        (new CronJobFailureLogReader(new CronFailureLogFakeDatabase(countErrors: true)))->recentFailures();

        $this->assertSame(
            [],
            $mockLogEntries,
            'Reporting a failure to read the failure log by writing to the failure log would be self-defeating'
        );
    }

    /**
     * A known-good count must survive a failing detail query. "3 failures, no
     * detail available" still sends an operator to the log; discarding the
     * count would tell them nothing is wrong.
     */
    public function testKeepsAKnownCountWhenOnlyTheDetailQueryFails(): void
    {
        $db = new CronFailureLogFakeDatabase(count: 3, detailErrors: true);

        $result = (new CronJobFailureLogReader($db))->recentFailures();

        $this->assertSame(['count' => 3, 'recent' => []], $result);
    }

    /**
     * The limit is interpolated into the LIMIT clause (it cannot be bound
     * under this connection's emulated prepares), so the int cast and clamp
     * are what make that interpolation safe rather than an injection point.
     * Both ends of the clamp are asserted, since a one-sided guard would still
     * pass a test that only checked the other.
     */
    public function testClampsTheLimitAtBothEnds(): void
    {
        $low = new CronFailureLogFakeDatabase(count: 1);
        (new CronJobFailureLogReader($low))->recentFailures(limit: 0);
        $this->assertStringContainsString('LIMIT 1', $low->lastSql(), 'A limit below 1 must clamp up to 1');

        $high = new CronFailureLogFakeDatabase(count: 1);
        (new CronJobFailureLogReader($high))->recentFailures(limit: 5000);
        $this->assertStringContainsString('LIMIT 50', $high->lastSql(), 'An unbounded limit must clamp down to 50');
    }

    public function testOrdersMostRecentFirst(): void
    {
        $db = new CronFailureLogFakeDatabase(count: 1, rows: [
            ['logdate' => '2026-09-22 02:00:00', 'lognote' => 'newest'],
        ]);

        (new CronJobFailureLogReader($db))->recentFailures();

        $this->assertStringContainsString(
            'ORDER BY logdate DESC',
            $db->lastSql(),
            'The newest failures are the ones an operator needs — an ascending order would show the oldest'
        );
    }
}
