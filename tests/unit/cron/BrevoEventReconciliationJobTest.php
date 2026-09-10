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
 * Unit tests for BrevoEventReconciliationJob (#1889).
 *
 * CarRepository is a PHPUnit double, matching BrevoWebhookEventProcessorTest's
 * convention for that collaborator type. EmailEventApplier and the Brevo
 * client are named Support doubles instead, because both need to record and
 * replay more than an expectation asserts conveniently: the applier's tests
 * turn on the *order* of calls across a page, and the client's on the exact
 * window/limit/offset it was handed and how many times. The vendored Brevo
 * SDK is also absent from the unit suite's autoloader, so the client's
 * returned model objects have to be stubbed regardless.
 *
 * Tests drive execute() via runNow(), which reaches it without the enabled
 * check or the guard claim — those belong to AbstractCronJob and are covered
 * by AbstractCronJobTest. runNow()'s crash isolation is identical to run()'s,
 * so anything escaping execute() would still be caught and logged; tests that
 * care assert on $mockLogEntries rather than on an exception.
 */
#[Group('fast')]
final class BrevoEventReconciliationJobTest extends TestCase
{
    /** Fixed "now" so window and retention-cutoff assertions are exact. */
    private const NOW = '2026-09-09 03:00:00';

    /**
     * Brevo's `date` is UTC, but occurred_at is stored in PHP's default
     * timezone (see resolveOccurredAt()) — so this test's expected value is
     * timezone-dependent and would silently pass under a UTC CI runner while
     * the real production conversion differed. Pinning the app's timezone
     * (users/init.php sets America/Los_Angeles) makes the conversion the
     * assertion actually exercises, regardless of the runner's system clock.
     */
    private const APP_TIMEZONE = 'America/Los_Angeles';

    /** Brevo's UTC event date used by FakeBrevoEvent's default. */
    private const EVENT_DATE_UTC = '2026-09-08T12:00:00Z';

    /** self::EVENT_DATE_UTC rendered in self::APP_TIMEZONE (UTC-7, PDT). */
    private const EVENT_DATE_LOCAL = '2026-09-08 05:00:00';

    /** Restores the process timezone after each test pins it. */
    private string $originalTimezone = 'UTC';

    /**
     * The repository double. Created as a stub by default and swapped for a
     * mock only by the tests that assert on how it was called — PHPUnit emits
     * a notice for a mock carrying no expectations.
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
     * Upgrade the repository double from a stub to a mock, for the tests that
     * assert on how it was called. Must be called before makeJob().
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
     * @param FakeBrevoEventReconciliationClient|null $client Receives the fake
     *        client this job was built with, so callers can assert on it.
     * @param-out FakeBrevoEventReconciliationClient $client
     */
    private function makeJob(?array $events, ?FakeBrevoEventReconciliationClient &$client = null): BrevoEventReconciliationJob
    {
        $client = new FakeBrevoEventReconciliationClient($events);

        return new BrevoEventReconciliationJob(
            new AbstractCronJobFakeDatabase(),
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

    /**
     * The tag gate must not stop the page — an untagged event sitting between
     * two verification events is routine traffic, not a reason to stop.
     */
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

    /**
     * The retry model (48h window + idempotent writes) covers a skipped
     * event, so one bad write must not starve the events behind it.
     */
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

        // A page full of events must not induce a second fetch — this job is
        // one bounded page per invocation, never a pagination walk.
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

        // No expectException(): a prune failure must not escape execute() —
        // it is logged distinctly so it is not conflated with a backfill failure.
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

        // EMAIL_WEBHOOK, not CRON_JOB_FAILURE: an oversized value is a Brevo
        // payload-contract warning, and the job itself is healthy. Same
        // category BrevoWebhookEventProcessor uses for its identical check.
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

        // EMAIL_WEBHOOK, not CRON_JOB_FAILURE: an oversized value is a Brevo
        // payload-contract warning, and the job itself is healthy. Same
        // category BrevoWebhookEventProcessor uses for its identical check.
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

        // EMAIL_WEBHOOK, not CRON_JOB_FAILURE: an empty required field is a
        // Brevo payload-contract warning, and the job itself is healthy. Same
        // category the non-string and oversized-value branches use.
        $log = $this->logsContaining('empty required field in event payload');
        $this->assertNotEmpty($log, 'A skipped event must be logged, not silent');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
    }

    // --- Non-string payload fields ---------------------------------------

    /**
     * The SDK's getters are untyped, so a payload-contract change could yield
     * an array or an int. A bare (string) cast would record an array as the
     * literal "Array" and an int as a numeric string, feeding garbage into
     * EmailEventApplier::apply()'s escalation logic — these must be skipped.
     *
     * @param array{email?: mixed, event?: mixed, messageId?: mixed} $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonStringPayloadFields')]
    public function testNonStringPayloadFieldIsSkippedAndLogged(array $overrides): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findByEmail');

        $this->makeJob([new FakeBrevoEvent(...$overrides)])->runNow();

        $this->assertSame([], $this->applier->calls, 'A non-string payload field must not be applied');

        // EMAIL_WEBHOOK, not CRON_JOB_FAILURE: a shape change in Brevo's
        // payload is a data-contract warning, and the job itself is healthy.
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

    /**
     * getTag() is untyped at the SDK boundary like the three fields above,
     * and hits the tag gate before those checks even run. Unlike them, a
     * non-string tag needs no data-hygiene log: it can never equal
     * VERIFICATION_EMAIL_TAG, so it is routine non-matching traffic, not a
     * payload-contract warning — same as any other tag that doesn't match.
     */
    public function testNonStringTagIsSkippedWithoutLogging(): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findByEmail');

        $this->makeJob([new FakeBrevoEvent(tag: ['car_verification'])])->runNow();

        $this->assertSame([], $this->applier->calls, 'A non-string tag must not be applied');
        $this->assertSame([], $this->logsContaining('non-string field in event payload'));
    }

    /**
     * A malformed event must not stop the page — the same containment the tag
     * gate and per-event write failures already have.
     */
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
     * er_email_events.occurred_at is a naive DATETIME written by both this job
     * and BrevoWebhookEventProcessor, and compared across them by
     * CarRepository::countSoftBouncesSinceLastDelivered(). Brevo's `date` is
     * UTC, so a backfilled row is only comparable if it is converted to the
     * same clock the webhook writes (PHP's default timezone) rather than kept
     * at its source offset.
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

    /**
     * The SDK's getDate() is untyped, so an int (the shape the webhook's
     * `ts_event` carries) is a plausible payload-contract change. resolveOccurredAt()
     * only trusts strings, so anything else must take the logged "now" fallback
     * rather than being coerced.
     */
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
     * An absurd but *parseable* date is the case an unbounded
     * `new DateTimeImmutable()` would let through: it yields a DATETIME string
     * MySQL rejects, failing every write for that event. Clamped to the same
     * year-9999 ceiling BrevoWebhookEventProcessor applies to `ts_event`.
     *
     * @param string $date A date string that parses but lands outside the
     *                     plausible range
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

    /**
     * A date problem is a Brevo data-hygiene warning, not a broken job.
     * Keeping it out of CRON_JOB_FAILURE is what makes that category usable
     * as an operator's "something is actually wrong" filter.
     */
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

    /**
     * fetchEvents() returning [] means the poll succeeded with no events for
     * the window — distinct from null, which means the poll failed (see
     * testPollFailureIsReflectedInTheSummaryWithoutThrowing, which asserts
     * the null case still prunes too). Either way the job must still prune.
     */
    public function testEmptyPollStillPrunes(): void
    {
        $this->expectRepoCalls()->expects($this->once())->method('deleteEmailEventsOlderThan')->willReturn(0);

        $this->makeJob([])->runNow();

        $this->assertSame([], $this->applier->calls);
    }

    /**
     * execute() (the nightly cron path, unlike runNowWithSummary()) logs its
     * summary and discards it — nobody renders `$summary->pollFailed`. Without
     * a distinguishing suffix, a failed poll (all counts 0) and a genuinely
     * quiet night (also all counts 0) log byte-for-byte identical lines,
     * silently misrepresenting a failed run as a clean one on the one path
     * nobody is actively watching. Guards against that regression.
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

    /**
     * findByEmail() returning [] is a routine "Brevo knows an address the
     * registry doesn't" outcome, not an error — must count as unmatched, and
     * must not touch matchedCount.
     */
    public function testUnmatchedEventIncrementsUnmatchedCountNotMatchedCount(): void
    {
        $this->expectRepoCalls()->expects($this->once())->method('findByEmail')->willReturn([]);

        $summary = $this->makeJob([new FakeBrevoEvent()])->runNowWithSummary();

        $this->assertSame(0, $summary->matchedCount);
        $this->assertSame(1, $summary->unmatchedCount);
    }

    /**
     * A tag-mismatched event must still be silently skipped (no new log line)
     * — only the count is new behavior.
     */
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
     * The multi-car partial-failure case this issue exists for (#2061):
     * matchedCount/skippedCount are counted per car-write, not per event. One
     * event matching 3 cars where 1 write succeeds and 2 throw must
     * contribute +1 matched and +2 skipped — not "the whole event counted as
     * skipped", and eventsExamined must stay 1.
     *
     * Two (not one) failing car-writes deliberately, so skippedCount is
     * distinguishable from a plausible event-granularity bug: with only one
     * failing car, "skippedCount = 1" is what BOTH per-car-write counting AND
     * a wrong "flag the whole event as skipped if any write failed"
     * implementation would produce. A 1-matched/2-skipped split only comes
     * out of genuine per-car-write counting.
     */
    public function testOneEventMatchingThreeCarsWithTwoFailedWritesCountsPerCar(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([
            (object) ['id' => 1],
            (object) ['id' => 2],
            (object) ['id' => 3],
        ]);
        // All three calls share this event's single message-id, so failOn()
        // alone cannot isolate individual calls — failOnCarId() targets cars
        // 2 and 3 specifically.
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
     * fetchEvents() returning null (poll failure, #2061) is not a thrown
     * exception in this design — runNowWithSummary() must still return a
     * summary, with pollFailed set and nothing counted, matching
     * BrevoSuppressionSyncJob::syncPage()'s identical null-poll handling.
     *
     * Also asserts pruneExpiredEvents() still runs on a poll failure — the
     * job's own docblock is explicit that "a Brevo outage must not stop
     * retention pruning", and the null-poll branch is a new early return
     * (#2061) sitting ahead of that call, so this is the one place a future
     * refactor could silently short-circuit 24-month retention pruning
     * during every Brevo outage without any test catching it.
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

    /**
     * runNowWithSummary() rethrows rather than fabricating an all-zero
     * summary, which would be indistinguishable from a genuinely successful
     * empty run — mirrors BrevoSuppressionSyncJob::runNowWithSummary().
     */
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
