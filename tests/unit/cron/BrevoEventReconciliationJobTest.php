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

        $this->mockRepo = $this->createStub(CarRepository::class);
        $this->applier = new SpyEmailEventApplier();
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
     * @param list<object> $events
     * @param FakeBrevoEventReconciliationClient|null $client Receives the fake
     *        client this job was built with, so callers can assert on it.
     * @param-out FakeBrevoEventReconciliationClient $client
     */
    private function makeJob(array $events, ?FakeBrevoEventReconciliationClient &$client = null): BrevoEventReconciliationJob
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
                date: '2026-09-08T12:00:00Z',
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
                'occurredAt' => '2026-09-08 12:00:00',
            ],
            [
                'carId' => 9,
                'email' => 'owner@example.com',
                'event' => 'hard_bounce',
                'reason' => 'mailbox unavailable',
                'messageId' => 'brevo-msg-42',
                'occurredAt' => '2026-09-08 12:00:00',
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
    }

    // --- Date handling ---------------------------------------------------

    public function testUnparseableDateFallsBackToRunTimeAndLogs(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);

        $this->makeJob([new FakeBrevoEvent(date: 'not-a-date')])->runNow();

        $this->assertSame(self::NOW, $this->applier->calls[0]['occurredAt']);
        $this->assertNotEmpty($this->logsContaining('no usable date'));
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
     * fetchEvents() returns [] for both "no events" and "poll failed" (it logs
     * the distinction itself). Either way the job must still prune.
     */
    public function testEmptyPollStillPrunes(): void
    {
        $this->expectRepoCalls()->expects($this->once())->method('deleteEmailEventsOlderThan')->willReturn(0);

        $this->makeJob([])->runNow();

        $this->assertSame([], $this->applier->calls);
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
