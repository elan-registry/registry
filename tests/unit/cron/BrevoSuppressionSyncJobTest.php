<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Cron\BrevoSuppressionSyncJob;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\AbstractCronJobFakeDatabase;
use Tests\Support\FakeBrevoBlockedContact;
use Tests\Support\FakeBrevoSuppressionSyncClient;
use Tests\Support\SpyEmailEventApplier;

require_once __DIR__ . '/../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../Support/AbstractCronJobFakeDatabase.php';
require_once __DIR__ . '/../../Support/FakeBrevoBlockedContact.php';
require_once __DIR__ . '/../../Support/FakeBrevoSuppressionSyncClient.php';
require_once __DIR__ . '/../../Support/SpyEmailEventApplier.php';

/**
 * Unit tests for BrevoSuppressionSyncJob (#1923).
 *
 * Structured after BrevoEventReconciliationJobTest, which tests this job's
 * sibling and uses the same doubles for the same reasons: CarRepository is a
 * PHPUnit stub (upgraded to a mock only where a test asserts on how it was
 * called), while EmailEventApplier and the Brevo client are named Support
 * doubles because the assertions turn on recorded call *sequences* and on the
 * exact window/limit/offset the client was handed across several calls.
 *
 * The incremental mode is driven through runNow(), which reaches execute()
 * without the enabled check or the guard claim — both belong to AbstractCronJob
 * and are covered by AbstractCronJobTest. The backfill modes are called
 * directly, since they are deliberately unreachable from run()/execute().
 *
 * Every job is built with a fixed `$now`, so the incremental window and the
 * unparseable-date fallback are exact rather than approximate.
 */
#[Group('fast')]
final class BrevoSuppressionSyncJobTest extends TestCase
{
    /** Fixed "now" so window and date-fallback assertions are exact. */
    private const NOW = '2026-09-09 03:00:00';

    /** self::NOW minus the job's 48-hour LOOKBACK_HOURS. */
    private const WINDOW_START = '2026-09-07 03:00:00';

    /**
     * Brevo's `blockedAt` is UTC, but occurred_at is stored in PHP's default
     * timezone (see resolveOccurredAt()) — so the expected local value is
     * timezone-dependent and would silently pass under a UTC CI runner while
     * the real production conversion differed. Pinning the app's timezone
     * (users/init.php sets America/Los_Angeles) makes the conversion the
     * assertion actually exercises, regardless of the runner's system clock.
     */
    private const APP_TIMEZONE = 'America/Los_Angeles';

    /** Brevo's UTC blocked-at used by FakeBrevoBlockedContact's default. */
    private const BLOCKED_AT_UTC = '2026-09-08T12:00:00.000Z';

    /** self::BLOCKED_AT_UTC rendered in self::APP_TIMEZONE (UTC-7, PDT). */
    private const BLOCKED_AT_LOCAL = '2026-09-08 05:00:00';

    /**
     * BrevoSuppressionSyncJob::PAGE_SIZE — a private constant, mirrored here.
     * 100, confirmed against the live Brevo API on 2026-09-09 (a prior value
     * of 1000 was hard-rejected by the SDK — see the source constant's
     * docblock for the full story).
     */
    private const PAGE_SIZE = 100;

    /** BrevoSuppressionSyncJob::MAX_BACKFILL_PAGES — a private constant, mirrored here. */
    private const MAX_BACKFILL_PAGES = 500;

    /** Restores the process timezone after each test pins it. */
    private string $originalTimezone = 'UTC';

    /**
     * The repository double. A stub by default; swapped for a mock only by the
     * tests asserting on how it was called, because PHPUnit emits a notice for
     * a mock carrying no expectations.
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

    // --- Fixtures --------------------------------------------------------

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
     * Every contact's address resolves to the given car ids, and every car row
     * reads back with both flags clear — i.e. nothing is already flagged, so
     * the isAlreadyInTargetState() pre-check always says "needs the write".
     *
     * @param list<int> $carIds
     */
    private function matchEveryEmailTo(array $carIds): void
    {
        $this->mockRepo->method('findByEmail')->willReturn(array_map(
            static fn (int $id): object => (object) ['id' => $id],
            $carIds
        ));
        $this->mockRepo->method('findById')->willReturnCallback(
            static fn (int $id): object => (object) [
                'id' => $id,
                'email_bounced' => 0,
                'email_suppressed' => 0,
            ]
        );
    }

    /**
     * @param list<\Brevo\Client\Model\GetTransacBlockedContacts|null> $pages
     * @param FakeBrevoSuppressionSyncClient|null $client Receives the fake
     *        client this job was built with, so callers can assert on it.
     * @param-out FakeBrevoSuppressionSyncClient $client
     */
    private function makeJob(
        array $pages,
        ?FakeBrevoSuppressionSyncClient &$client = null
    ): BrevoSuppressionSyncJob {
        $client = new FakeBrevoSuppressionSyncClient($pages);

        return new BrevoSuppressionSyncJob(
            new AbstractCronJobFakeDatabase(),
            $this->mockRepo,
            $this->applier,
            $client,
            new DateTimeImmutable(self::NOW)
        );
    }

    /**
     * A job whose single page holds exactly the given contacts.
     *
     * @param list<FakeBrevoBlockedContact> $contacts
     * @param FakeBrevoSuppressionSyncClient|null $client
     * @param-out FakeBrevoSuppressionSyncClient $client
     */
    private function makeJobWithContacts(
        array $contacts,
        ?FakeBrevoSuppressionSyncClient &$client = null
    ): BrevoSuppressionSyncJob {
        return $this->makeJob([FakeBrevoSuppressionSyncClient::page($contacts)], $client);
    }

    // --- Reason-code mapping ---------------------------------------------

    /**
     * The mapping table in the class docblock, asserted arm by arm. Each code
     * must reach EmailEventApplier as the event name whose escalation the
     * suppression deserves — 'blocked' sets the bounce flag, 'spam' and
     * 'unsubscribed' the suppression flag.
     */
    #[DataProvider('reasonCodeMappings')]
    public function testReasonCodeMapsToItsEvent(string $code, string $expectedEvent): void
    {
        $this->matchEveryEmailTo([7]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', $code),
        ])->runNow();

        $this->assertCount(1, $this->applier->calls);
        $this->assertSame($expectedEvent, $this->applier->calls[0]['event']);
        $this->assertSame(7, $this->applier->calls[0]['carId']);
        $this->assertSame('owner@example.com', $this->applier->calls[0]['email']);
    }

    /**
     * Brevo's raw reason-code wire values, as plain string literals rather
     * than the SDK class's constants — matching how
     * {@see BrevoSuppressionSyncJob::mapReasonCodeToEvent()} itself now owns
     * these strings (see that method's `REASON_CODE_*` constants) rather than
     * referencing `\Brevo\Client\Model\GetTransacBlockedContactsReason`'s
     * constants at runtime, which required the vendored SDK to be loadable
     * — unavailable in this unit suite.
     *
     * @return array<string, array{string, string}>
     */
    public static function reasonCodeMappings(): array
    {
        return [
            'hardBounce is a dead mailbox' => ['hardBounce', 'blocked'],
            'contactFlaggedAsSpam' => ['contactFlaggedAsSpam', 'spam'],
            'unsubscribedViaEmail' => ['unsubscribedViaEmail', 'unsubscribed'],
            'unsubscribedViaMA' => ['unsubscribedViaMA', 'unsubscribed'],
            'unsubscribedViaApi' => ['unsubscribedViaApi', 'unsubscribed'],
            'adminBlocked' => ['adminBlocked', 'unsubscribed'],
        ];
    }

    /**
     * Pinned separately from the provider above because it is the one
     * non-obvious arm in the table and the one a future reader is most likely
     * to "correct". `adminBlocked` means a human deliberately suppressed the
     * address at Brevo — a suppression decision, not evidence the mailbox is
     * dead — so it must NOT set the hard-bounce flag the way 'blocked' would.
     */
    public function testAdminBlockedIsASuppressionNotABounce(): void
    {
        $this->matchEveryEmailTo([1]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('admin@example.com', 'adminBlocked'),
        ])->runNow();

        $this->assertSame('unsubscribed', $this->applier->calls[0]['event']);
        $this->assertNotSame(
            'blocked',
            $this->applier->calls[0]['event'],
            'adminBlocked is a human decision, not a dead mailbox — it must not set email_bounced'
        );
    }

    /**
     * An unmapped code is never guessed at: no apply() call, a tally under
     * 'unrecognized' so the operator can see Brevo added a reason this job does
     * not understand, and a log line under the payload-observation category.
     */
    public function testUnrecognizedReasonCodeIsTalliedAndSkipped(): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findByEmail');

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('mystery@example.com', 'someNewBrevoReason'),
        ])->runFullBackfill();

        $this->assertSame([], $this->applier->calls, 'An unknown code must not flag any car');
        $this->assertSame(['unrecognized' => 1], $summary->reasonCodeCounts);
        $this->assertSame(0, $summary->matchedCount);
        $this->assertSame(0, $summary->unmatchedCount);
        $this->assertSame(0, $summary->alreadyFlaggedCount);

        $log = $this->logsContaining('unrecognized reason code');
        $this->assertNotEmpty($log, 'An unmapped code must be logged, not silently dropped');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
        $this->assertStringContainsString('someNewBrevoReason', $log[0]['message']);
    }

    /** An unmapped code must not stop the contacts behind it on the page. */
    public function testUnrecognizedReasonCodeDoesNotBlockLaterContacts(): void
    {
        $this->matchEveryEmailTo([1]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('a@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('b@example.com', 'someNewBrevoReason'),
            FakeBrevoBlockedContact::withReason('c@example.com', 'hardBounce'),
        ])->runNow();

        $this->assertSame(
            ['a@example.com', 'c@example.com'],
            array_column($this->applier->calls, 'email')
        );
    }

    /**
     * The reason-code breakdown is keyed on Brevo's own raw codes, not the
     * mapped event names — that is what lets an operator see which suppression
     * path an address actually took.
     */
    public function testReasonCodeCountsAreKeyedOnBrevosRawCodes(): void
    {
        $this->matchEveryEmailTo([1]);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('a@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('b@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('c@example.com', 'unsubscribedViaMA'),
        ])->runFullBackfill();

        $this->assertSame(
            ['hardBounce' => 2, 'unsubscribedViaMA' => 1],
            $summary->reasonCodeCounts
        );
    }

    // --- Applied payload -------------------------------------------------

    public function testEveryMatchedCarReceivesTheSuppression(): void
    {
        $this->matchEveryEmailTo([7, 9]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce', 'mailbox unavailable'),
        ])->runNow();

        $this->assertSame([7, 9], array_column($this->applier->calls, 'carId'));
        $this->assertSame('mailbox unavailable', $this->applier->calls[0]['reason']);
        $this->assertSame(self::BLOCKED_AT_LOCAL, $this->applier->calls[0]['occurredAt']);
    }

    /**
     * An empty reason message is normalized to null rather than passed through
     * as '' — er_email_events.reason is nullable and '' would be a lie about
     * Brevo having said something.
     */
    public function testEmptyReasonMessageBecomesNull(): void
    {
        $this->matchEveryEmailTo([1]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce', ''),
        ])->runNow();

        $this->assertNull($this->applier->calls[0]['reason']);
    }

    /**
     * occurred_at is a naive DATETIME written by the webhook, the reconciliation
     * job, and this one, and compared across all three by
     * CarRepository::countSoftBouncesSinceLastDelivered(). A UTC blockedAt is
     * only comparable if converted to the same clock the other two write.
     */
    public function testUtcBlockedAtIsStoredInThePhpDefaultTimezone(): void
    {
        $this->matchEveryEmailTo([1]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce', null, self::BLOCKED_AT_UTC),
        ])->runNow();

        $this->assertSame(
            date('Y-m-d H:i:s', (new DateTimeImmutable(self::BLOCKED_AT_UTC))->getTimestamp()),
            $this->applier->calls[0]['occurredAt']
        );
        $this->assertSame(self::BLOCKED_AT_LOCAL, $this->applier->calls[0]['occurredAt']);
        $this->assertSame([], $this->logsContaining('no usable blocked-at date'));
    }

    public function testUnparseableBlockedAtFallsBackToRunTimeAndLogs(): void
    {
        $this->matchEveryEmailTo([1]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce', null, 'not-a-date'),
        ])->runNow();

        $this->assertSame(self::NOW, $this->applier->calls[0]['occurredAt']);

        $log = $this->logsContaining('no usable blocked-at date');
        $this->assertNotEmpty($log);
        $this->assertSame(
            LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
            $log[0]['category'],
            'A date problem is a Brevo data-hygiene warning, not a broken job'
        );
    }

    // --- Incremental window / bounded work --------------------------------

    public function testIncrementalRunFetchesExactlyOneBoundedPage(): void
    {
        $this->matchEveryEmailTo([1]);

        // A page full of contacts must not induce a second fetch: execute() is
        // one bounded page per invocation, never the first step of a walk.
        $contacts = array_map(
            static fn (int $i): FakeBrevoBlockedContact
                => FakeBrevoBlockedContact::withReason("owner{$i}@example.com", 'hardBounce'),
            range(1, 25)
        );

        $this->makeJobWithContacts($contacts, $client)->runNow();

        $this->assertSame(1, $client->fetchCalls, 'execute() must fetch exactly one page');
        $this->assertSame(self::PAGE_SIZE, $client->fetchArgs[0]['limit']);
        $this->assertSame(
            0,
            $client->fetchArgs[0]['offset'],
            'Offset must stay 0 — the client sorts newest-first, and the newest suppressions'
                . ' are exactly the ones not yet reflected locally'
        );
        $this->assertCount(25, $this->applier->calls);
    }

    /**
     * A full page in incremental mode is the case a paging bug would show up
     * in: PAGE_SIZE contacts is indistinguishable from "there may be more", and
     * execute() must still stop at one page.
     */
    public function testIncrementalRunDoesNotPageEvenOnAFullPage(): void
    {
        $this->matchEveryEmailTo([1]);

        $this->makeJob([
            FakeBrevoSuppressionSyncClient::page(
                array_fill(
                    0,
                    self::PAGE_SIZE,
                    FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce')
                ),
                self::PAGE_SIZE * 10
            ),
        ], $client)->runNow();

        $this->assertSame(1, $client->fetchCalls);
    }

    public function testIncrementalWindowIsThe48HoursEndingNow(): void
    {
        $this->makeJob([FakeBrevoSuppressionSyncClient::page([])], $client)->runNow();

        $this->assertInstanceOf(DateTimeImmutable::class, $client->fetchArgs[0]['start']);
        $this->assertInstanceOf(DateTimeImmutable::class, $client->fetchArgs[0]['end']);
        $this->assertSame(self::WINDOW_START, $client->fetchArgs[0]['start']->format('Y-m-d H:i:s'));
        $this->assertSame(self::NOW, $client->fetchArgs[0]['end']->format('Y-m-d H:i:s'));
    }

    public function testIncrementalRunLogsItsOutcome(): void
    {
        $this->matchEveryEmailTo([1]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('a@example.com', 'hardBounce'),
        ])->runNow();

        $log = $this->logsContaining('incremental run complete');
        $this->assertNotEmpty($log, 'The log line is the only artifact an unattended run leaves');
        $this->assertSame(
            LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
            $log[0]['category'],
            'Routine success must stay out of the CRON_JOB_* exception categories'
        );
        $this->assertStringContainsString('1 matched', $log[0]['message']);
    }

    // --- Full backfill window / paging ------------------------------------

    public function testBackfillPassesNoDateWindow(): void
    {
        $this->makeJob([FakeBrevoSuppressionSyncClient::page([])], $client)->runFullBackfill();

        $this->assertNull(
            $client->fetchArgs[0]['start'],
            'The backfill walks the whole suppression list, so it must send no start date'
        );
        $this->assertNull($client->fetchArgs[0]['end']);
    }

    /**
     * The paging loop: a full page means "there may be more", so it fetches
     * again at the next offset; the first short page ends the walk.
     */
    public function testBackfillPagesUntilAShortPageEndsTheWalk(): void
    {
        $this->matchEveryEmailTo([1]);

        $fullPage = static fn (int $page): \Brevo\Client\Model\GetTransacBlockedContacts
            => FakeBrevoSuppressionSyncClient::page(array_map(
                static fn (int $i): FakeBrevoBlockedContact => FakeBrevoBlockedContact::withReason(
                    "p{$page}-{$i}@example.com",
                    'hardBounce'
                ),
                range(1, self::PAGE_SIZE)
            ));

        $summary = $this->makeJob([
            $fullPage(1),
            $fullPage(2),
            // Short page — the end of the list.
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason('last@example.com', 'hardBounce'),
            ]),
        ], $client)->runFullBackfill();

        $this->assertSame(3, $client->fetchCalls, 'The walk must stop on the first short page');
        $this->assertSame(3, $summary->pagesFetched);
        $this->assertSame(self::PAGE_SIZE * 2 + 1, $summary->matchedCount);

        $this->assertSame(
            [0, self::PAGE_SIZE, self::PAGE_SIZE * 2],
            array_column($client->fetchArgs, 'offset'),
            'Each page must advance the offset by exactly PAGE_SIZE'
        );
        $this->assertSame(
            [self::PAGE_SIZE, self::PAGE_SIZE, self::PAGE_SIZE],
            array_column($client->fetchArgs, 'limit')
        );
    }

    /**
     * REGRESSION (found in review, #1923): a full page containing even one
     * contact that reaches none of the three flag-decision buckets — here, an
     * unrecognized reason code — must NOT be mistaken for a short page. An
     * earlier version compared the sum of matched+unmatched+alreadyFlagged
     * against PAGE_SIZE, which undercounts whenever any contact is skipped
     * (malformed field, unrecognized code, failed lookup, or every matching
     * car's write failing) — so a full page with one skip looked exactly like
     * a short page, and the walk stopped there with the rest of Brevo's
     * suppression list silently never imported. The fix compares
     * contactsExamined (the real row count Brevo returned) instead.
     */
    public function testBackfillContinuesPastAFullPageContainingASkippedContact(): void
    {
        $this->matchEveryEmailTo([1]);

        // A full page of PAGE_SIZE contacts, but the first one carries a
        // reason code this job does not map — it is skipped and reaches none
        // of the three buckets, so matched+unmatched+alreadyFlagged on this
        // page is PAGE_SIZE - 1, one short of PAGE_SIZE. If the walk used
        // that sum as its end-of-list test, it would stop here.
        $firstPageContacts = array_map(
            static fn (int $i): FakeBrevoBlockedContact => FakeBrevoBlockedContact::withReason(
                "p1-{$i}@example.com",
                'hardBounce'
            ),
            range(1, self::PAGE_SIZE)
        );
        $firstPageContacts[0] = FakeBrevoBlockedContact::withReason(
            'p1-1@example.com',
            'someFutureBrevoCode'
        );
        $firstPage = FakeBrevoSuppressionSyncClient::page($firstPageContacts);

        // Second page proves the walk actually continued — never reached if
        // the bug is present.
        $secondPage = FakeBrevoSuppressionSyncClient::page([
            FakeBrevoBlockedContact::withReason('p2-1@example.com', 'hardBounce'),
        ]);

        $summary = $this->makeJob([$firstPage, $secondPage], $client)->runFullBackfill();

        $this->assertSame(
            2,
            $client->fetchCalls,
            'The walk must continue past a full page containing a skipped contact,'
                . ' not mistake it for a short page'
        );
        $this->assertSame(2, $summary->pagesFetched);
        $this->assertSame(
            self::PAGE_SIZE + 1,
            $summary->contactsExamined,
            'Both pages\' contacts, including the one skipped'
        );
        $this->assertSame(1, $summary->skippedCount, 'Exactly the one unrecognized-code contact');
        $this->assertSame(self::PAGE_SIZE, $summary->matchedCount, 'All contacts except the skipped one');
        $this->assertFalse($summary->backfillCapped);
    }

    /**
     * An empty page is still a page Brevo answered — it ends the walk as a
     * short page, and counts as fetched (unlike a failed poll below).
     */
    public function testBackfillStopsOnAnEmptyPageAndCountsItAsFetched(): void
    {
        $summary = $this->makeJob(
            [FakeBrevoSuppressionSyncClient::page([])],
            $client
        )->runFullBackfill();

        $this->assertSame(1, $client->fetchCalls);
        $this->assertSame(1, $summary->pagesFetched);
        $this->assertFalse($summary->backfillCapped);
    }

    /**
     * A failed poll is explicitly NOT end-of-data, but it is also not retried:
     * the walk stops and returns what it has, and the page is not counted as
     * fetched — which is exactly how the loop tells a null poll from an empty
     * page.
     */
    public function testBackfillStopsOnAFailedPollWithoutCountingThePage(): void
    {
        $this->matchEveryEmailTo([1]);

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page(
                array_fill(
                    0,
                    self::PAGE_SIZE,
                    FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce')
                )
            ),
            null,
        ], $client)->runFullBackfill();

        $this->assertSame(2, $client->fetchCalls);
        $this->assertSame(1, $summary->pagesFetched, 'A failed poll is not a fetched page');
        $this->assertSame(self::PAGE_SIZE, $summary->matchedCount, 'Work already applied must be reported');
        $this->assertFalse($summary->backfillCapped);
    }

    // --- Backfill page cap ------------------------------------------------

    /**
     * Seeded with more full pages than the cap allows, the walk must stop AT the
     * cap — not walk the extra pages — and say so in the summary so the operator
     * knows the import is incomplete and worth re-running.
     */
    public function testBackfillStopsAtItsPageCapAndFlagsTheSummary(): void
    {
        $this->matchEveryEmailTo([1]);

        // One car per contact keeps the applier's recorded calls proportional,
        // but the page contents are irrelevant here — only that every page is
        // full, so the short-page stop never fires before the cap does.
        $fullPage = FakeBrevoSuppressionSyncClient::page(array_map(
            static fn (int $i): FakeBrevoBlockedContact
                => FakeBrevoBlockedContact::withReason("owner{$i}@example.com", 'hardBounce'),
            range(1, self::PAGE_SIZE)
        ));

        $summary = $this->makeJob(
            array_fill(0, self::MAX_BACKFILL_PAGES + 5, $fullPage),
            $client
        )->runFullBackfill();

        $this->assertTrue($summary->backfillCapped);
        $this->assertSame(
            self::MAX_BACKFILL_PAGES,
            $summary->pagesFetched,
            'The loop must actually stop at the cap, not merely report it'
        );
        $this->assertSame(
            self::MAX_BACKFILL_PAGES,
            $client->fetchCalls,
            'No page may be fetched past the cap'
        );
    }

    public function testBackfillPageCapIsLogged(): void
    {
        $this->matchEveryEmailTo([1]);

        $fullPage = FakeBrevoSuppressionSyncClient::page(array_fill(
            0,
            self::PAGE_SIZE,
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce')
        ));

        $this->makeJob(array_fill(0, self::MAX_BACKFILL_PAGES + 5, $fullPage))->runFullBackfill();

        $log = $this->logsContaining('safety cap');
        $this->assertCount(1, $log, 'The cap must be logged exactly once, on the final page');
        $this->assertSame(
            LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
            $log[0]['category'],
            'Hitting the cap is neither a job failure nor an operator pause'
        );
    }

    /** A walk that ends naturally must not claim it was capped. */
    public function testBackfillEndingNaturallyIsNotFlaggedAsCapped(): void
    {
        $this->matchEveryEmailTo([1]);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('a@example.com', 'hardBounce'),
        ])->runFullBackfill();

        $this->assertFalse($summary->backfillCapped);
        $this->assertSame([], $this->logsContaining('safety cap'));
    }

    // --- runNowWithSummary() counts ---------------------------------------

    /**
     * The three contact buckets are disjoint and sum to the number of contacts
     * the run examined, which is the property the admin page's numbers rest on.
     * Seeded with all three kinds at once: two newly-flagged, one already
     * flagged, two matching no car.
     */
    public function testRunNowWithSummaryCountsAreCorrectAndDisjoint(): void
    {
        $this->mockRepo->method('findByEmail')->willReturnCallback(
            static fn (string $email): array => match ($email) {
                'unknown-a@example.com', 'unknown-b@example.com' => [],
                'already@example.com' => [(object) ['id' => 30]],
                'bounce@example.com' => [(object) ['id' => 10]],
                'spam@example.com' => [(object) ['id' => 20]],
                default => [],
            }
        );

        // Car 30 already carries email_suppressed, so its 'spam' suppression is
        // a no-op the run must count separately from real work.
        $this->mockRepo->method('findById')->willReturnCallback(
            static fn (int $id): object => (object) [
                'id' => $id,
                'email_bounced' => 0,
                'email_suppressed' => $id === 30 ? 1 : 0,
            ]
        );

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('bounce@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('spam@example.com', 'contactFlaggedAsSpam'),
            FakeBrevoBlockedContact::withReason('already@example.com', 'contactFlaggedAsSpam'),
            FakeBrevoBlockedContact::withReason('unknown-a@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('unknown-b@example.com', 'unsubscribedViaEmail'),
        ])->runNowWithSummary();

        $this->assertSame(2, $summary->matchedCount);
        $this->assertSame(2, $summary->unmatchedCount);
        $this->assertSame(1, $summary->alreadyFlaggedCount);

        $this->assertSame(
            5,
            $summary->matchedCount + $summary->unmatchedCount + $summary->alreadyFlaggedCount,
            'The three buckets must sum to the number of contacts examined'
        );

        $this->assertSame(1, $summary->pagesFetched);
        $this->assertFalse($summary->backfillCapped);

        // Only the three matched contacts (including the already-flagged one)
        // are tallied by reason code; unmatched contacts never reach the tally.
        $this->assertSame(
            ['hardBounce' => 1, 'contactFlaggedAsSpam' => 2],
            $summary->reasonCodeCounts
        );

        // The already-flagged contact is still applied — the write is
        // idempotent, and skipping it would let a partially-applied state
        // (event row missing, flag set) persist forever.
        $this->assertSame(
            ['bounce@example.com', 'spam@example.com', 'already@example.com'],
            array_column($this->applier->calls, 'email')
        );
    }

    /**
     * A contact counts as already-flagged only when EVERY matching car was
     * already in the target state — if even one car needed the flag, the run
     * did real work for that contact.
     */
    public function testContactWithOneUnflaggedCarCountsAsMatchedNotAlreadyFlagged(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([
            (object) ['id' => 1],
            (object) ['id' => 2],
        ]);
        $this->mockRepo->method('findById')->willReturnCallback(
            static fn (int $id): object => (object) [
                'id' => $id,
                'email_bounced' => $id === 1 ? 1 : 0,
                'email_suppressed' => 0,
            ]
        );

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce'),
        ])->runNowWithSummary();

        $this->assertSame(1, $summary->matchedCount);
        $this->assertSame(0, $summary->alreadyFlaggedCount);
    }

    /**
     * The pre-check reads the column the mapped event actually sets: 'blocked'
     * reads email_bounced, everything else email_suppressed. A car carrying the
     * *other* flag is not already in the target state.
     */
    public function testAlreadyFlaggedCheckReadsTheColumnTheEventWouldSet(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);
        // Suppressed but not bounced — a hardBounce is still new work.
        $this->mockRepo->method('findById')->willReturn((object) [
            'id' => 1,
            'email_bounced' => 0,
            'email_suppressed' => 1,
        ]);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce'),
        ])->runNowWithSummary();

        $this->assertSame(1, $summary->matchedCount);
        $this->assertSame(0, $summary->alreadyFlaggedCount);
    }

    /**
     * The pre-check fails *open*: an unreadable car row is treated as "not
     * already flagged", so the suppression is still applied and the contact
     * counted as newly matched. Overstating new matches is the right way to be
     * wrong — treating an unreadable car as handled would hide the broken read.
     */
    public function testUnreadableCarRowStillAppliesTheSuppressionAndLogs(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);
        $this->mockRepo->method('findById')
            ->willThrowException(new CarDatabaseException('flag read failed'));

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce'),
        ])->runNowWithSummary();

        $this->assertCount(1, $this->applier->calls, 'A failed pre-check must never cost a suppression');
        $this->assertSame(1, $summary->matchedCount);
        $this->assertSame(0, $summary->alreadyFlaggedCount);

        $log = $this->logsContaining('could not read current flags');
        $this->assertNotEmpty($log);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);
    }

    /**
     * runNowWithSummary() rethrows rather than fabricating an all-zero summary,
     * which would be indistinguishable from a successful run over an empty
     * suppression list. A repository that throws something other than
     * CarDatabaseException is not caught anywhere below, so it reaches the
     * rethrow — and the failure is logged on the way out.
     */
    public function testRunNowWithSummaryLogsAndRethrowsAnUnexpectedFailure(): void
    {
        $this->mockRepo->method('findByEmail')
            ->willThrowException(new RuntimeException('something unexpected'));

        $job = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce'),
        ]);

        try {
            $job->runNowWithSummary();
            $this->fail('An unexpected failure must not be reported as an empty successful run');
        } catch (RuntimeException $e) {
            $this->assertSame('something unexpected', $e->getMessage());
        }

        $log = $this->logsContaining('failed (manual backfill)');
        $this->assertNotEmpty($log);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);
    }

    // --- Per-contact / per-car failure isolation --------------------------

    /**
     * The retry model (a 48h window, or simply re-running the backfill) covers a
     * skipped car, so one failed write must not starve the contacts behind it.
     */
    public function testWriteFailureForOneCarDoesNotAbortTheRest(): void
    {
        $this->matchEveryEmailTo([1]);

        // The synthetic id is per-contact, so failing on b@example.com's id
        // fails exactly that contact's single car.
        $this->applier->failOn($this->syntheticId('b@example.com', 'hardBounce'));

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('a@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('b@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('c@example.com', 'hardBounce'),
        ])->runFullBackfill();

        $this->assertSame(
            ['a@example.com', 'b@example.com', 'c@example.com'],
            array_column($this->applier->calls, 'email'),
            'A failed write must not stop later contacts in the page'
        );

        $log = $this->logsContaining('write FAILED');
        $this->assertCount(1, $log, 'A skipped car must be logged, not silent');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);

        // The wholly-failed contact lands in no bucket at all — counting it as
        // matched would overstate the run, as unmatched would misstate why.
        $this->assertSame(2, $summary->matchedCount);
        $this->assertSame(0, $summary->unmatchedCount);
        $this->assertSame(0, $summary->alreadyFlaggedCount);
        $this->assertSame(['hardBounce' => 2], $summary->reasonCodeCounts);
    }

    /**
     * One car of a multi-car contact failing must not stop the contact's other
     * cars, and the contact still counts as matched because real work landed.
     */
    public function testWriteFailureForOneCarDoesNotStopTheContactsOtherCars(): void
    {
        $this->matchEveryEmailTo([1, 2, 3]);
        $this->applier->failOn($this->syntheticId('owner@example.com', 'hardBounce'));

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce'),
        ])->runFullBackfill();

        // failOn() matches on message-id, which every car of this contact
        // shares, so all three writes throw — the point being that all three
        // were still attempted.
        $this->assertSame([1, 2, 3], array_column($this->applier->calls, 'carId'));
        $this->assertCount(3, $this->logsContaining('write FAILED'));
        $this->assertSame(0, $summary->matchedCount, 'No write landed, so no bucket may claim the contact');
    }

    public function testCarLookupFailureSkipsOnlyThatContact(): void
    {
        $this->mockRepo->method('findByEmail')->willReturnCallback(
            static function (string $email): array {
                if ($email === 'broken@example.com') {
                    throw new CarDatabaseException('lookup failed');
                }
                return [(object) ['id' => 1]];
            }
        );
        $this->mockRepo->method('findById')->willReturn((object) [
            'id' => 1,
            'email_bounced' => 0,
            'email_suppressed' => 0,
        ]);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('broken@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('ok@example.com', 'hardBounce'),
        ])->runFullBackfill();

        $this->assertSame(['ok@example.com'], array_column($this->applier->calls, 'email'));
        $this->assertSame(1, $summary->matchedCount);

        $log = $this->logsContaining('car lookup FAILED');
        $this->assertNotEmpty($log);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);
    }

    /**
     * Brevo's suppression list covers every address the account has ever sent
     * to, including addresses that were never registry cars. No match is
     * routine, not an error.
     */
    public function testContactMatchingNoCarIsCountedNotLoggedAsAFailure(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([]);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('stranger@example.com', 'hardBounce'),
        ])->runFullBackfill();

        $this->assertSame([], $this->applier->calls);
        $this->assertSame(1, $summary->unmatchedCount);
        $this->assertSame(0, $summary->matchedCount);
        $this->assertSame([], $summary->reasonCodeCounts, 'An unmatched contact has no reason tally');
        $this->assertSame([], $this->logsContaining('FAILED'));
    }

    // --- Synthetic message-id determinism ---------------------------------

    /**
     * The synthetic id is what makes re-running the backfill idempotent under
     * er_email_events' UNIQUE (car_id, brevo_message_id, event): the same
     * contact must produce the same id on every run, so a re-import collides
     * with the existing row instead of inserting a duplicate.
     */
    public function testSyntheticMessageIdIsStableAcrossRuns(): void
    {
        $this->matchEveryEmailTo([1]);

        $contact = static fn (): FakeBrevoBlockedContact => FakeBrevoBlockedContact::withReason(
            'owner@example.com',
            'hardBounce',
            null,
            self::BLOCKED_AT_UTC
        );

        $this->makeJobWithContacts([$contact()])->runFullBackfill();
        $firstId = $this->applier->messageIds()[0];

        $this->applier = new SpyEmailEventApplier();
        $this->makeJobWithContacts([$contact()])->runFullBackfill();
        $secondId = $this->applier->messageIds()[0];

        $this->assertSame($firstId, $secondId);
        $this->assertStringStartsWith('suppression-import-', $firstId);
    }

    /**
     * The id is built from the raw blockedAt rather than the resolved
     * occurred_at precisely so an unparseable date does NOT make it drift:
     * resolveOccurredAt() falls back to run time, and folding that in would
     * give the same contact a new id on every run, defeating the idempotence
     * the id exists to provide.
     */
    public function testUnparseableBlockedAtStillYieldsAStableId(): void
    {
        $this->matchEveryEmailTo([1]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce', null, 'not-a-date'),
        ])->runFullBackfill();
        $firstId = $this->applier->messageIds()[0];

        $this->applier = new SpyEmailEventApplier();
        // A different injected "now" — the fallback occurred_at differs between
        // the two runs, which is exactly what must not reach the id.
        $client = new FakeBrevoSuppressionSyncClient([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce', null, 'not-a-date'),
            ]),
        ]);
        (new BrevoSuppressionSyncJob(
            new AbstractCronJobFakeDatabase(),
            $this->mockRepo,
            $this->applier,
            $client,
            new DateTimeImmutable('2027-01-01 00:00:00')
        ))->runFullBackfill();

        $this->assertSame(
            $firstId,
            $this->applier->messageIds()[0],
            'The id must not absorb the run-time date fallback'
        );
    }

    /**
     * A non-string blockedAt collapses to a fixed marker for the same reason —
     * constant across runs. Asserted against the marker's actual value so the
     * id scheme cannot change silently.
     */
    public function testNonStringBlockedAtUsesTheFixedNoDateMarker(): void
    {
        $this->matchEveryEmailTo([1]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce', null, 12345),
        ])->runFullBackfill();

        $this->assertSame(
            $this->syntheticId('owner@example.com', 'hardBounce', 'no-date'),
            $this->applier->messageIds()[0]
        );
    }

    /** An empty-string blockedAt takes the same fixed marker as a non-string one. */
    public function testEmptyBlockedAtUsesTheFixedNoDateMarker(): void
    {
        $this->matchEveryEmailTo([1]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce', null, ''),
        ])->runFullBackfill();

        $this->assertSame(
            $this->syntheticId('owner@example.com', 'hardBounce', 'no-date'),
            $this->applier->messageIds()[0]
        );
    }

    /**
     * The id must distinguish contacts, or two different suppressions would
     * collide on the same car and the second would be lost to the UNIQUE
     * constraint.
     */
    public function testSyntheticIdDiffersByEmailCodeAndDate(): void
    {
        $this->matchEveryEmailTo([1]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('a@example.com', 'hardBounce', null, self::BLOCKED_AT_UTC),
            FakeBrevoBlockedContact::withReason('b@example.com', 'hardBounce', null, self::BLOCKED_AT_UTC),
            FakeBrevoBlockedContact::withReason('a@example.com', 'contactFlaggedAsSpam', null, self::BLOCKED_AT_UTC),
            FakeBrevoBlockedContact::withReason('a@example.com', 'hardBounce', null, '2026-01-01T00:00:00.000Z'),
        ])->runFullBackfill();

        $ids = $this->applier->messageIds();
        $this->assertCount(4, $ids);
        $this->assertCount(4, array_unique($ids), 'Each distinct contact must get a distinct id');
    }

    /** Every car of one contact shares the contact's id — the id keys the contact, not the car. */
    public function testAllCarsOfOneContactShareTheSameSyntheticId(): void
    {
        $this->matchEveryEmailTo([1, 2, 3]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce'),
        ])->runFullBackfill();

        $this->assertCount(1, array_unique($this->applier->messageIds()));
    }

    // --- Malformed payload fields -----------------------------------------

    /**
     * The SDK's getters are untyped, so a payload-contract change really can
     * hand the job an array or an int. A bare (string) cast would record an
     * array as the literal "Array" and treat it as a real address — these must
     * be skipped and logged instead.
     *
     * @param mixed $email
     */
    #[DataProvider('unusableEmails')]
    public function testUnusableEmailIsSkippedAndLogged(mixed $email): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findByEmail');

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason($email, 'hardBounce'),
        ])->runNow();

        $this->assertSame([], $this->applier->calls);

        $log = $this->logsContaining('unusable email');
        $this->assertNotEmpty($log, 'A skipped contact must be logged, not silent');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
    }

    /** @return array<string, array{mixed}> */
    public static function unusableEmails(): array
    {
        return [
            'an array' => [['owner@example.com']],
            'an int' => [42],
            'null' => [null],
            'an empty string' => [''],
        ];
    }

    /**
     * The SDK declares the Reason model as getReason()'s return type but
     * enforces nothing at deserialization, so a payload missing `reason` really
     * does yield null.
     */
    public function testContactWithNoReasonObjectIsSkippedAndLogged(): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findByEmail');

        $this->makeJobWithContacts([
            new FakeBrevoBlockedContact('owner@example.com'),
        ])->runNow();

        $this->assertSame([], $this->applier->calls);

        $log = $this->logsContaining('carried no reason object');
        $this->assertNotEmpty($log);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
    }

    /**
     * @param mixed $code
     */
    #[DataProvider('unusableReasonCodes')]
    public function testNonStringReasonCodeIsSkippedAndLogged(mixed $code): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findByEmail');

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', $code),
        ])->runNow();

        $this->assertSame([], $this->applier->calls);

        $log = $this->logsContaining('non-string reason code');
        $this->assertNotEmpty($log);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);

        // A malformed code must NOT be conflated with a code Brevo added that
        // this job has yet to map — they call for different operator responses.
        $this->assertSame([], $this->logsContaining('unrecognized reason code'));
    }

    /** @return array<string, array{mixed}> */
    public static function unusableReasonCodes(): array
    {
        return [
            'an array' => [['hardBounce']],
            'an int' => [7],
            'null' => [null],
            'an empty string' => [''],
        ];
    }

    /**
     * A malformed contact must not stop the page — the same containment the
     * unrecognized-code path and per-car write failures already have.
     */
    public function testMalformedContactDoesNotBlockLaterContacts(): void
    {
        $this->matchEveryEmailTo([1]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('a@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason(['b@example.com'], 'hardBounce'),
            new FakeBrevoBlockedContact('c@example.com'),
            FakeBrevoBlockedContact::withReason('d@example.com', 42),
            FakeBrevoBlockedContact::withReason('e@example.com', 'hardBounce'),
        ])->runNow();

        $this->assertSame(
            ['a@example.com', 'e@example.com'],
            array_column($this->applier->calls, 'email')
        );
    }

    /**
     * A non-string reason *message* is not a reason to skip the contact — the
     * suppression itself is still valid, and the message is only annotation.
     */
    public function testNonStringReasonMessageIsNormalizedToNullNotSkipped(): void
    {
        $this->matchEveryEmailTo([1]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce', ['some', 'array']),
        ])->runNow();

        $this->assertCount(1, $this->applier->calls, 'A bad message must not cost the suppression');
        $this->assertNull($this->applier->calls[0]['reason']);
    }

    // --- Helpers ---------------------------------------------------------

    /**
     * Recompute the job's synthetic message id, so a test can name a specific
     * contact's id (for SpyEmailEventApplier::failOn()) or assert the scheme
     * itself. Mirrors BrevoSuppressionSyncJob's own construction deliberately
     * — if the scheme changes, the assertions built on it must be revisited
     * rather than silently following along.
     */
    private function syntheticId(string $email, string $code, string $datePart = self::BLOCKED_AT_UTC): string
    {
        return 'suppression-import-' . md5($email . '|' . $code . '|' . $datePart);
    }

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
