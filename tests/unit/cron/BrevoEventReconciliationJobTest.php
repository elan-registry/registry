<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Cron\BrevoEventReconciliationJob;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\AbstractCronJobFakeDatabase;
use Tests\Support\FakeBrevoEvent;
use Tests\Support\FakeBrevoEventReconciliationClient;
use Tests\Support\SpyEmailEventApplier;

require_once __DIR__ . '/../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../Support/AbstractCronJobFakeDatabase.php';
require_once __DIR__ . '/../../Support/FakeBrevoEvent.php';
require_once __DIR__ . '/../../Support/FakeBrevoEventReconciliationClient.php';
require_once __DIR__ . '/../../Support/SpyEmailEventApplier.php';

/**
 * BrevoEventReconciliationJob (#1889). The applier and client are recording
 * Support doubles: tests check call order and the exact window/limit/offset.
 * The Brevo SDK is not in the unit autoloader.
 *
 * runNow() skips the enabled check and guard claim (AbstractCronJobTest covers
 * them) but keeps crash isolation, so tests assert on $mockLogEntries.
 */
#[Group('fast')]
final class BrevoEventReconciliationJobTest extends TestCase
{
    /** Fixed "now" so window and retention-cutoff assertions are exact. */
    private const NOW = '2026-09-09 03:00:00';

    /**
     * Pinned to the app timezone (users/init.php) so the UTC conversion is
     * tested even on a UTC runner.
     */
    private const APP_TIMEZONE = 'America/Los_Angeles';

    /** Brevo's UTC event date used by FakeBrevoEvent's default. */
    private const EVENT_DATE_UTC = '2026-09-08T12:00:00Z';

    /** self::EVENT_DATE_UTC rendered in self::APP_TIMEZONE (UTC-7, PDT). */
    private const EVENT_DATE_LOCAL = '2026-09-08 05:00:00';

    private string $originalTimezone = 'UTC';

    /**
     * A stub unless a test calls expectRepoCalls(): PHPUnit warns on a mock
     * with no expectations.
     *
     * @var CarRepository&\PHPUnit\Framework\MockObject\Stub
     */
    private CarRepository $mockRepo;

    private SpyEmailEventApplier $applier;

    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set(self::APP_TIMEZONE);

        $this->mockRepo = $this->createStub(CarRepository::class);
        $this->applier = new SpyEmailEventApplier();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
    }

    /**
     * Call before makeJob().
     *
     * @return CarRepository&\PHPUnit\Framework\MockObject\MockObject
     */
    private function expectRepoCalls(): CarRepository
    {
        $mock = $this->createMock(CarRepository::class);
        $this->mockRepo = $mock;

        return $mock;
    }

    /**
     * @param list<object>|null $events Null scripts a poll failure.
     * @param FakeBrevoEventReconciliationClient|null $client Out: the job's client
     * @param AbstractCronJobFakeDatabase|null $db Out: the job's database
     * @param-out FakeBrevoEventReconciliationClient $client
     * @param-out AbstractCronJobFakeDatabase $db
     */
    private function makeJob(
        ?array $events,
        ?FakeBrevoEventReconciliationClient &$client = null,
        bool $verificationEnabled = true,
        ?AbstractCronJobFakeDatabase &$db = null,
        bool $unmatchedCounterUpdateSucceeds = true,
    ): BrevoEventReconciliationJob {
        $client = new FakeBrevoEventReconciliationClient($events);
        $db = new AbstractCronJobFakeDatabase(
            verificationEnabled: $verificationEnabled,
            unmatchedCounterUpdateSucceeds: $unmatchedCounterUpdateSucceeds,
        );

        return new BrevoEventReconciliationJob(
            $db,
            $this->mockRepo,
            $this->applier,
            $client,
            new DateTimeImmutable(self::NOW)
        );
    }

    // --- Happy path ------------------------------------------------------

    public function testTagMatchingEventIsAppliedToEveryMatchedCar(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([
            (object) ['id' => 7],
            (object) ['id' => 9],
        ]);

        $this->makeJob([
            new FakeBrevoEvent(
                email: 'owner@example.com',
                event: 'hard_bounce',
                messageId: 'brevo-msg-42',
                date: self::EVENT_DATE_UTC,
                reason: 'mailbox unavailable'
            ),
        ])->runNow();

        $this->assertSame([
            [
                'carId' => 7,
                'email' => 'owner@example.com',
                'event' => 'hard_bounce',
                'reason' => 'mailbox unavailable',
                'messageId' => 'brevo-msg-42',
                'occurredAt' => self::EVENT_DATE_LOCAL,
            ],
            [
                'carId' => 9,
                'email' => 'owner@example.com',
                'event' => 'hard_bounce',
                'reason' => 'mailbox unavailable',
                'messageId' => 'brevo-msg-42',
                'occurredAt' => self::EVENT_DATE_LOCAL,
            ],
        ], $this->applier->calls);
    }

    public function testEveryEventInThePageIsProcessed(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);

        $this->makeJob([
            new FakeBrevoEvent(messageId: 'm1'),
            new FakeBrevoEvent(messageId: 'm2'),
            new FakeBrevoEvent(messageId: 'm3'),
        ])->runNow();

        $this->assertSame(['m1', 'm2', 'm3'], $this->applier->messageIds());
    }

    public function testAbsentReasonIsPassedAsNull(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);

        $this->makeJob([new FakeBrevoEvent(reason: null)])->runNow();

        $this->assertNull($this->applier->calls[0]['reason']);
    }

    // --- Tag gate --------------------------------------------------------

    public function testEventWithNonMatchingTagIsSkipped(): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findByEmail');

        $this->makeJob([new FakeBrevoEvent(tag: 'newsletter')])->runNow();

        $this->assertSame([], $this->applier->calls);
    }

    public function testEventWithNoTagIsSkipped(): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findByEmail');

        $this->makeJob([new FakeBrevoEvent(tag: null)])->runNow();

        $this->assertSame([], $this->applier->calls);
    }

    /** Untagged events between verification events are routine traffic. */
    public function testUntaggedEventDoesNotBlockLaterTaggedEvents(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);

        $this->makeJob([
            new FakeBrevoEvent(messageId: 'm1'),
            new FakeBrevoEvent(messageId: 'm2', tag: 'newsletter'),
            new FakeBrevoEvent(messageId: 'm3'),
        ])->runNow();

        $this->assertSame(['m1', 'm3'], $this->applier->messageIds());
    }

    // --- No car match ----------------------------------------------------

    public function testEventMatchingNoCarIsANoOp(): void
    {
        $this->expectRepoCalls()->expects($this->once())->method('findByEmail')->willReturn([]);

        $this->makeJob([new FakeBrevoEvent()])->runNow();

        $this->assertSame([], $this->applier->calls);
    }

    // --- Per-event failure isolation -------------------------------------

    /** The 48h window and idempotent writes cover a skipped event. */
    public function testWriteFailureForOneEventDoesNotAbortTheRest(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);
        $this->applier->failOn('m2');

        $this->makeJob([
            new FakeBrevoEvent(messageId: 'm1'),
            new FakeBrevoEvent(messageId: 'm2'),
            new FakeBrevoEvent(messageId: 'm3'),
        ])->runNow();

        $this->assertSame(
            ['m1', 'm2', 'm3'],
            $this->applier->messageIds(),
            'A failed write must not stop later events in the page'
        );
        $this->assertNotEmpty($this->logsContaining('write FAILED'), 'A skipped event must be logged, not silent');
    }

    public function testCarLookupFailureSkipsOnlyThatEvent(): void
    {
        $this->mockRepo->method('findByEmail')->willReturnCallback(
            static function (string $email): array {
                if ($email === 'broken@example.com') {
                    throw new CarDatabaseException('lookup failed');
                }
                return [(object) ['id' => 1]];
            }
        );

        $this->makeJob([
            new FakeBrevoEvent(email: 'broken@example.com', messageId: 'm1'),
            new FakeBrevoEvent(email: 'ok@example.com', messageId: 'm2'),
        ])->runNow();

        $this->assertSame(['m2'], $this->applier->messageIds());
        $this->assertNotEmpty($this->logsContaining('car lookup FAILED'));
    }

    // --- Bounded work ----------------------------------------------------

    public function testExactlyOneBoundedPageIsFetchedPerRun(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);

        // One bounded page per run, never a pagination walk.
        $events = array_map(
            static fn (int $i): FakeBrevoEvent => new FakeBrevoEvent(messageId: 'm' . $i),
            range(1, 25)
        );

        $this->makeJob($events, $client)->runNow();

        $this->assertSame(1, $client->fetchCalls, 'execute() must fetch exactly one page');
        $this->assertSame(1000, $client->fetchArgs[0]['limit']);
        $this->assertSame(
            0,
            $client->fetchArgs[0]['offset'],
            'Offset must stay 0 — newest events are the ones most likely missing'
        );
        $this->assertCount(25, $this->applier->calls);
    }

    public function testWindowIsThe48HoursEndingNow(): void
    {
        $this->makeJob([], $client)->runNow();

        $this->assertSame('2026-09-07 03:00:00', $client->fetchArgs[0]['start']->format('Y-m-d H:i:s'));
        $this->assertSame(self::NOW, $client->fetchArgs[0]['end']->format('Y-m-d H:i:s'));
    }

    // --- Retention prune -------------------------------------------------

    public function testPruneUsesA24MonthCutoff(): void
    {
        $this->expectRepoCalls()->expects($this->once())
            ->method('deleteEmailEventsOlderThan')
            ->with($this->callback(
                static fn (DateTimeImmutable $cutoff): bool
                    => $cutoff->format('Y-m-d H:i:s') === '2024-09-09 03:00:00'
            ))
            ->willReturn(0);

        $this->makeJob([])->runNow();
    }

    public function testPruneRunsAfterEventsAreProcessedAndItsFailureIsContained(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);
        $this->mockRepo->method('deleteEmailEventsOlderThan')
            ->willThrowException(new CarDatabaseException('delete failed'));

        // A prune failure is logged apart from a backfill failure, not thrown.
        $this->makeJob([new FakeBrevoEvent()])->runNow();

        $this->assertCount(1, $this->applier->calls, 'The backfill must have completed before the prune ran');

        $pruneLogs = $this->logsContaining('retention prune FAILED');
        $this->assertCount(1, $pruneLogs, 'A prune failure must be logged distinctly');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $pruneLogs[0]['category']);
        $this->assertSame(
            [],
            $this->logsContaining("failed: ElanRegistry"),
            'It must not surface as a generic AbstractCronJob job failure'
        );
    }

    // --- Oversized values ------------------------------------------------

    public function testOversizedEventNameIsSkipped(): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findByEmail');

        $this->makeJob([new FakeBrevoEvent(event: str_repeat('a', 33))])->runNow();

        $this->assertSame([], $this->applier->calls);

        // A payload-contract warning: the job itself is healthy.
        $log = $this->logsContaining('exceeds storage width');
        $this->assertNotEmpty($log);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
    }

    public function testMaxLengthEventNameIsAccepted(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);

        $this->makeJob([new FakeBrevoEvent(event: str_repeat('a', 32))])->runNow();

        $this->assertCount(1, $this->applier->calls);
    }

    public function testOversizedMessageIdIsSkipped(): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findByEmail');

        $this->makeJob([new FakeBrevoEvent(messageId: str_repeat('m', 256))])->runNow();

        $this->assertSame([], $this->applier->calls);

        // A payload-contract warning: the job itself is healthy.
        $log = $this->logsContaining('exceeds storage width');
        $this->assertNotEmpty($log);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
    }

    public function testMaxLengthMessageIdIsAccepted(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);

        $this->makeJob([new FakeBrevoEvent(messageId: str_repeat('m', 255))])->runNow();

        $this->assertCount(1, $this->applier->calls);
    }

    public function testEmptyRequiredFieldIsSkipped(): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findByEmail');

        $this->makeJob([new FakeBrevoEvent(email: '')])->runNow();

        $this->assertSame([], $this->applier->calls);

        // A payload-contract warning: the job itself is healthy.
        $log = $this->logsContaining('empty required field in event payload');
        $this->assertNotEmpty($log, 'A skipped event must be logged, not silent');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
    }

    // --- Non-string payload fields ---------------------------------------

    /**
     * The SDK getters are untyped. A (string) cast would turn an array into
     * "Array" and feed garbage to EmailEventApplier::apply().
     *
     * @param array{email?: mixed, event?: mixed, messageId?: mixed} $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonStringPayloadFields')]
    public function testNonStringPayloadFieldIsSkippedAndLogged(array $overrides): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findByEmail');

        $this->makeJob([new FakeBrevoEvent(...$overrides)])->runNow();

        $this->assertSame([], $this->applier->calls, 'A non-string payload field must not be applied');

        // A payload-contract warning: the job itself is healthy.
        $log = $this->logsContaining('non-string field in event payload');
        $this->assertNotEmpty($log, 'A skipped event must be logged, not silent');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function nonStringPayloadFields(): array
    {
        return [
            'event as an array' => [['event' => ['delivered']]],
            'event as an int' => [['event' => 42]],
            'email as an array' => [['email' => ['owner@example.com']]],
            'message id as an int' => [['messageId' => 12345]],
        ];
    }

    /** A non-string tag never matches, so it is routine traffic and needs no log. */
    public function testNonStringTagIsSkippedWithoutLogging(): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findByEmail');

        $this->makeJob([new FakeBrevoEvent(tag: ['car_verification'])])->runNow();

        $this->assertSame([], $this->applier->calls, 'A non-string tag must not be applied');
        $this->assertSame([], $this->logsContaining('non-string field in event payload'));
    }

    public function testNonStringPayloadFieldDoesNotBlockLaterEvents(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);

        $this->makeJob([
            new FakeBrevoEvent(messageId: 'm1'),
            new FakeBrevoEvent(event: ['delivered'], messageId: 'm2'),
            new FakeBrevoEvent(messageId: 'm3'),
        ])->runNow();

        $this->assertSame(['m1', 'm3'], $this->applier->messageIds());
    }

    // --- Date handling ---------------------------------------------------

    public function testUnparseableDateFallsBackToRunTimeAndLogs(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);

        $this->makeJob([new FakeBrevoEvent(date: 'not-a-date')])->runNow();

        $this->assertSame(self::NOW, $this->applier->calls[0]['occurredAt']);
        $this->assertNotEmpty($this->logsContaining('no usable date'));
    }

    /**
     * The webhook writes occurred_at in PHP's timezone and
     * countSoftBouncesSinceLastDelivered() compares rows, so backfilled UTC dates
     * must be converted.
     */
    public function testUtcDateIsStoredInThePhpDefaultTimezoneLikeTheWebhook(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);

        $this->makeJob([new FakeBrevoEvent(date: self::EVENT_DATE_UTC)])->runNow();

        $this->assertSame(
            date('Y-m-d H:i:s', (new DateTimeImmutable(self::EVENT_DATE_UTC))->getTimestamp()),
            $this->applier->calls[0]['occurredAt'],
            'occurred_at must use the same clock BrevoWebhookEventProcessor writes'
        );
        $this->assertSame(self::EVENT_DATE_LOCAL, $this->applier->calls[0]['occurredAt']);
        $this->assertSame([], $this->logsContaining('no usable date'), 'A valid date must not log a fallback');
    }

    /** An int date (the webhook's `ts_event` shape) is not trusted. */
    public function testNonStringDateFallsBackToRunTimeAndLogs(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);

        $this->makeJob([new FakeBrevoEvent(date: 1_700_000_000)])->runNow();

        $this->assertSame(self::NOW, $this->applier->calls[0]['occurredAt']);

        $log = $this->logsContaining('no usable date');
        $this->assertNotEmpty($log, 'A non-string date must be logged, not silently coerced');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
        $this->assertStringContainsString('(int)', $log[0]['message']);
    }

    public function testMissingDateFallsBackToRunTime(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);

        $this->makeJob([new FakeBrevoEvent(date: null)])->runNow();

        $this->assertSame(self::NOW, $this->applier->calls[0]['occurredAt']);
    }

    /**
     * A parseable but absurd date gives a DATETIME MySQL rejects. Clamped to
     * year 9999, as BrevoWebhookEventProcessor does.
     *
     * @param string $date
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('outOfRangeDates')]
    public function testOutOfRangeDateFallsBackToRunTimeAndLogs(string $date): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);

        $this->makeJob([new FakeBrevoEvent(date: $date)])->runNow();

        $this->assertSame(self::NOW, $this->applier->calls[0]['occurredAt']);
        $this->assertNotEmpty($this->logsContaining('no usable date'));
    }

    /** @return array<string, array{string}> */
    public static function outOfRangeDates(): array
    {
        return [
            'past the year-9999 ceiling' => ['+100000 years'],
            'before the epoch' => ['1900-01-01 00:00:00'],
        ];
    }

    /** CRON_JOB_FAILURE must stay an operator's "really broken" filter. */
    public function testDateFallbackIsNotLoggedAsAJobFailure(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);

        $this->makeJob([new FakeBrevoEvent(date: 'not-a-date')])->runNow();

        $this->assertSame(
            LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
            $this->logsContaining('no usable date')[0]['category']
        );
    }

    // --- Empty / failed poll ---------------------------------------------

    /** [] is an empty poll; null is a failed poll. Both must still prune. */
    public function testEmptyPollStillPrunes(): void
    {
        $this->expectRepoCalls()->expects($this->once())->method('deleteEmailEventsOlderThan')->willReturn(0);

        $this->makeJob([])->runNow();

        $this->assertSame([], $this->applier->calls);
    }

    /**
     * On the nightly path the log line is the only record, so a failed poll
     * must not log the same line as a quiet night.
     */
    public function testNightlyRunLogsAPollFailureDistinctlyFromAnEmptyRun(): void
    {
        $this->mockRepo->method('deleteEmailEventsOlderThan')->willReturn(0);

        $this->makeJob(null)->runNow();
        $failedPollLog = $this->logsContaining('incremental run complete');
        $this->assertNotEmpty($failedPollLog);
        $this->assertStringContainsString('poll failed', $failedPollLog[0]['message']);

        global $mockLogEntries;
        $mockLogEntries = [];

        $this->makeJob([])->runNow();
        $emptyRunLog = $this->logsContaining('incremental run complete');
        $this->assertNotEmpty($emptyRunLog);
        $this->assertStringNotContainsString('poll failed', $emptyRunLog[0]['message']);

        $this->assertNotSame(
            $failedPollLog[0]['message'],
            $emptyRunLog[0]['message'],
            'A failed poll must not log identically to a genuinely empty page'
        );
    }

    /**
     * The nightly log line is the only record of a counter failure, so this
     * checks the logged text, not only the summary (#2085).
     */
    public function testNightlyRunLogsTheCounterFailureWarningClause(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([]);

        $this->makeJob(
            [
                new FakeBrevoEvent(email: 'a@example.com', messageId: 'm1'),
                new FakeBrevoEvent(email: 'b@example.com', messageId: 'm2'),
            ],
            unmatchedCounterUpdateSucceeds: false,
        )->runNow();

        $log = $this->logsContaining('incremental run complete');
        $this->assertNotEmpty($log);
        $this->assertStringContainsString(
            '2 of the 2 unmatched event(s) were NOT recorded in the dashboard'
            . ' unmatched-recipient counter; see VerificationConfigWarning log entries',
            $log[0]['message']
        );
    }

    public function testNightlyRunOmitsTheCounterFailureClauseWhenCounterSucceeds(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([]);

        $this->makeJob([
            new FakeBrevoEvent(email: 'a@example.com', messageId: 'm1'),
            new FakeBrevoEvent(email: 'b@example.com', messageId: 'm2'),
        ])->runNow();

        $log = $this->logsContaining('incremental run complete');
        $this->assertNotEmpty($log);
        $this->assertStringNotContainsString('NOT recorded in the dashboard', $log[0]['message']);
    }

    // --- runNowWithSummary() counts ---------------------------------------

    public function testRunNowWithSummaryHappyPathCountsAreCorrect(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);

        $summary = $this->makeJob([
            new FakeBrevoEvent(email: 'a@example.com', event: 'delivered', messageId: 'm1'),
            new FakeBrevoEvent(email: 'b@example.com', event: 'delivered', messageId: 'm2'),
            new FakeBrevoEvent(email: 'c@example.com', event: 'hard_bounce', messageId: 'm3'),
        ], $client)->runNowWithSummary();

        $this->assertSame(3, $summary->matchedCount);
        $this->assertSame(0, $summary->unmatchedCount);
        $this->assertSame(0, $summary->skippedCount);
        $this->assertSame(0, $summary->ignoredByTagCount);
        $this->assertSame(['delivered' => 2, 'hard_bounce' => 1], $summary->eventTypeCounts);
        $this->assertSame(3, $summary->eventsExamined);
        $this->assertSame(1, $summary->pagesFetched);
        $this->assertFalse($summary->pollFailed);
    }

    /** An address Brevo knows and the registry does not is routine. */
    public function testUnmatchedEventIncrementsUnmatchedCountNotMatchedCount(): void
    {
        $this->expectRepoCalls()->expects($this->once())->method('findByEmail')->willReturn([]);

        $summary = $this->makeJob([new FakeBrevoEvent()])->runNowWithSummary();

        $this->assertSame(0, $summary->matchedCount);
        $this->assertSame(1, $summary->unmatchedCount);
    }

    /** unmatchedCount alone cannot show a counter UPDATE that never fires (#2085). */
    public function testApplyEventUnmatchedRecipientIncrementsCounter(): void
    {
        $this->expectRepoCalls()->expects($this->exactly(3))->method('findByEmail')->willReturn([]);

        $summary = $this->makeJob([
            new FakeBrevoEvent(email: 'a@example.com', messageId: 'm1'),
            new FakeBrevoEvent(email: 'b@example.com', messageId: 'm2'),
            new FakeBrevoEvent(email: 'c@example.com', messageId: 'm3'),
        ], $client, db: $db)->runNowWithSummary();

        $this->assertSame(3, $summary->unmatchedCount);
        $this->assertSame(3, $db->unmatchedCounterIncrementCalls());
    }

    /**
     * A counter failure must not abort the run, but counterFailureCount must
     * report it. After the first failure the increment is skipped (the fault is
     * in the settings row) while the tally keeps counting.
     */
    public function testApplyEventUnmatchedRecipientCounterFailureIsReportedInSummary(): void
    {
        $this->expectRepoCalls()->expects($this->exactly(2))->method('findByEmail')->willReturn([]);

        $summary = $this->makeJob(
            [
                new FakeBrevoEvent(email: 'a@example.com', messageId: 'm1'),
                new FakeBrevoEvent(email: 'b@example.com', messageId: 'm2'),
            ],
            $client,
            db: $db,
            unmatchedCounterUpdateSucceeds: false,
        )->runNowWithSummary();

        $this->assertSame(2, $summary->counterFailureCount, 'Both unmatched events went unrecorded in the dashboard counter');
        $this->assertSame(2, $summary->unmatchedCount, 'A failed counter increment must not affect the job\'s own unmatchedCount');
        $this->assertSame(0, $summary->skippedCount, 'A failed counter increment must not be reported as a skipped event');
        $this->assertSame(
            1,
            $db->unmatchedCounterIncrementCalls(),
            'Once one increment fails the call is not re-attempted, to avoid one duplicate warning-log row per unmatched event'
        );
    }

    public function testApplyEventUnmatchedRecipientCounterSuccessReportsNoFailures(): void
    {
        $this->expectRepoCalls()->expects($this->exactly(2))->method('findByEmail')->willReturn([]);

        $summary = $this->makeJob([
            new FakeBrevoEvent(email: 'a@example.com', messageId: 'm1'),
            new FakeBrevoEvent(email: 'b@example.com', messageId: 'm2'),
        ], $client, db: $db)->runNowWithSummary();

        $this->assertSame(0, $summary->counterFailureCount);
        $this->assertSame(2, $summary->unmatchedCount);
        $this->assertSame(2, $db->unmatchedCounterIncrementCalls());
    }

    public function testTagMismatchedEventIncrementsIgnoredByTagCountWithoutLogging(): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findByEmail');

        $summary = $this->makeJob([
            new FakeBrevoEvent(tag: 'newsletter'),
            new FakeBrevoEvent(tag: null),
        ])->runNowWithSummary();

        $this->assertSame(2, $summary->ignoredByTagCount);
        $this->assertSame(0, $summary->skippedCount);
        $this->assertSame([], $this->logsContaining('newsletter'), 'A tag mismatch must stay silent');
    }

    /**
     * Counts are per car-write, not per event (#2061). Two failing writes, not
     * one: with one, an event-level bug also gives skippedCount = 1.
     */
    public function testOneEventMatchingThreeCarsWithTwoFailedWritesCountsPerCar(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([
            (object) ['id' => 1],
            (object) ['id' => 2],
            (object) ['id' => 3],
        ]);
        // One message-id for all three calls, so failOnCarId() targets cars 2 and 3.
        $this->applier->failOnCarId(2, 3);

        $summary = $this->makeJob([
            new FakeBrevoEvent(email: 'multi@example.com', event: 'hard_bounce', messageId: 'm1'),
        ])->runNowWithSummary();

        $this->assertCount(3, $this->applier->calls, 'All three car writes must be attempted');
        $this->assertSame(1, $summary->matchedCount, 'One of three car writes succeeded');
        $this->assertSame(2, $summary->skippedCount, 'Two of three car writes failed');
        $this->assertSame(0, $summary->unmatchedCount);
        $this->assertSame(1, $summary->eventsExamined, 'One event was examined, regardless of car count');
        $this->assertSame(['hard_bounce' => 1], $summary->eventTypeCounts);
    }

    /**
     * A null poll returns a summary, not an exception (#2061). Pruning must
     * still run: a Brevo outage must not stop 24-month retention.
     */
    public function testPollFailureIsReflectedInTheSummaryWithoutThrowing(): void
    {
        $repo = $this->expectRepoCalls();
        $repo->expects($this->never())->method('findByEmail');
        $repo->expects($this->once())->method('deleteEmailEventsOlderThan')->willReturn(0);

        $summary = $this->makeJob(null, $client)->runNowWithSummary();

        $this->assertTrue($summary->pollFailed);
        $this->assertSame(0, $summary->eventsExamined);
        $this->assertSame(0, $summary->matchedCount);
        $this->assertSame(0, $summary->unmatchedCount);
        $this->assertSame(0, $summary->skippedCount);
        $this->assertSame(0, $summary->ignoredByTagCount);
        $this->assertSame(0, $summary->pagesFetched);
    }

    /** An all-zero summary would look like a successful empty run. */
    public function testRunNowWithSummaryLogsAndRethrowsAnUnexpectedFailure(): void
    {
        $this->expectRepoCalls()->expects($this->once())
            ->method('deleteEmailEventsOlderThan')
            ->willThrowException(new RuntimeException('unexpected prune failure'));

        $job = $this->makeJob([]);

        try {
            $job->runNowWithSummary();
            $this->fail('An unexpected failure must not be reported as an empty successful run');
        } catch (RuntimeException $e) {
            $this->assertSame('unexpected prune failure', $e->getMessage());
        }

        $log = $this->logsContaining('failed (manual run)');
        $this->assertNotEmpty($log);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);
    }

    /**
     * runNowWithSummary() bypasses run(), so it repeats the verification-switch
     * check and throws so the operator sees why.
     */
    public function testRunNowWithSummaryThrowsWhenVerificationSwitchIsOff(): void
    {
        $job = $this->makeJob([], verificationEnabled: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/switched off site-wide/');

        $job->runNowWithSummary();
    }

    // --- Helpers ---------------------------------------------------------

    /**
     * @return list<array{category: string, message: string}>
     */
    private function logsContaining(string $needle): array
    {
        global $mockLogEntries;

        return array_values(array_filter(
            $mockLogEntries ?? [],
            static fn (array $entry): bool => str_contains((string) $entry['message'], $needle)
        ));
    }
}
