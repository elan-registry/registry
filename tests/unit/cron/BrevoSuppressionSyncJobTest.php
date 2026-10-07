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
 * BrevoSuppressionSyncJob (#1923). Same doubles as BrevoEventReconciliationJobTest.
 * Incremental mode runs through runNow(); the backfill modes are called
 * directly because run()/execute() cannot reach them.
 */
#[Group('fast')]
final class BrevoSuppressionSyncJobTest extends TestCase
{
    /** Fixed "now" so window and date-fallback assertions are exact. */
    private const NOW = '2026-09-09 03:00:00';

    /** self::NOW minus the job's 48-hour LOOKBACK_HOURS. */
    private const WINDOW_START = '2026-09-07 03:00:00';

    /**
     * Pinned to the app timezone (users/init.php) so the UTC conversion is
     * tested even on a UTC runner.
     */
    private const APP_TIMEZONE = 'America/Los_Angeles';

    /** Brevo's UTC blocked-at used by FakeBrevoBlockedContact's default. */
    private const BLOCKED_AT_UTC = '2026-09-08T12:00:00.000Z';

    /** self::BLOCKED_AT_UTC rendered in self::APP_TIMEZONE (UTC-7, PDT). */
    private const BLOCKED_AT_LOCAL = '2026-09-08 05:00:00';

    /** Mirrors private BrevoSuppressionSyncJob::PAGE_SIZE. Brevo rejects 1000. */
    private const PAGE_SIZE = 100;

    /** Mirrors private BrevoSuppressionSyncJob::MAX_BACKFILL_PAGES. */
    private const MAX_BACKFILL_PAGES = 500;

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

    // --- Fixtures --------------------------------------------------------

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
     * Every car reads back unflagged, so the pre-check always says "write".
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
     * @param FakeBrevoSuppressionSyncClient|null $client Out: the job's client
     * @param AbstractCronJobFakeDatabase|null $db Out: the job's database
     * @param-out FakeBrevoSuppressionSyncClient $client
     * @param-out AbstractCronJobFakeDatabase $db
     */
    private function makeJob(
        array $pages,
        ?FakeBrevoSuppressionSyncClient &$client = null,
        bool $verificationEnabled = true,
        ?AbstractCronJobFakeDatabase &$db = null,
        bool $unmatchedCounterUpdateSucceeds = true,
    ): BrevoSuppressionSyncJob {
        $client = new FakeBrevoSuppressionSyncClient($pages);
        $db = new AbstractCronJobFakeDatabase(
            verificationEnabled: $verificationEnabled,
            unmatchedCounterUpdateSucceeds: $unmatchedCounterUpdateSucceeds,
        );

        return new BrevoSuppressionSyncJob(
            $db,
            $this->mockRepo,
            $this->applier,
            $client,
            new DateTimeImmutable(self::NOW)
        );
    }

    /**
     * @param list<FakeBrevoBlockedContact> $contacts
     * @param FakeBrevoSuppressionSyncClient|null $client
     * @param AbstractCronJobFakeDatabase|null $db
     * @param-out FakeBrevoSuppressionSyncClient $client
     * @param-out AbstractCronJobFakeDatabase $db
     */
    private function makeJobWithContacts(
        array $contacts,
        ?FakeBrevoSuppressionSyncClient &$client = null,
        bool $verificationEnabled = true,
        ?AbstractCronJobFakeDatabase &$db = null,
        bool $unmatchedCounterUpdateSucceeds = true,
    ): BrevoSuppressionSyncJob {
        return $this->makeJob(
            [FakeBrevoSuppressionSyncClient::page($contacts)],
            $client,
            $verificationEnabled,
            $db,
            $unmatchedCounterUpdateSucceeds,
        );
    }

    // --- Reason-code mapping ---------------------------------------------

    /** 'blocked' sets the bounce flag; 'spam' and 'unsubscribed' set the suppression flag. */
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
     * Literal strings: the SDK's reason constants need the vendored SDK, which
     * the unit suite cannot load.
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
     * `adminBlocked` is a human suppression decision, not a dead mailbox, so it
     * must not set the hard-bounce flag. A reader may want to "correct" this.
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

    /** The 'unrecognized' tally tells the operator that Brevo added a reason. */
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

    /** Raw codes show the operator which suppression path an address took. */
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

    /** '' would claim that Brevo said something. */
    public function testEmptyReasonMessageBecomesNull(): void
    {
        $this->matchEveryEmailTo([1]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce', ''),
        ])->runNow();

        $this->assertNull($this->applier->calls[0]['reason']);
    }

    /**
     * Three writers share occurred_at and countSoftBouncesSinceLastDelivered()
     * compares their rows, so a UTC blockedAt must be converted.
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

        // One bounded page per run, never the first step of a walk.
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

    /** A full page looks like "there may be more"; execute() must still stop. */
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

    /**
     * The nightly log line is the only record of a counter failure, so this
     * checks the logged text, not only the summary (#2085).
     */
    public function testNightlyRunLogsTheCounterFailureWarningClause(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([]);

        $this->makeJobWithContacts(
            [
                FakeBrevoBlockedContact::withReason('a@example.com', 'hardBounce'),
                FakeBrevoBlockedContact::withReason('b@example.com', 'hardBounce'),
            ],
            unmatchedCounterUpdateSucceeds: false,
        )->runNow();

        $log = $this->logsContaining('incremental run complete');
        $this->assertNotEmpty($log);
        $this->assertStringContainsString(
            '2 of the 2 unmatched contact(s) were NOT recorded in the dashboard'
            . ' unmatched-recipient counter; see VerificationConfigWarning log entries',
            $log[0]['message']
        );
    }

    public function testNightlyRunOmitsTheCounterFailureClauseWhenCounterSucceeds(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('a@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('b@example.com', 'hardBounce'),
        ])->runNow();

        $log = $this->logsContaining('incremental run complete');
        $this->assertNotEmpty($log);
        $this->assertStringNotContainsString('NOT recorded in the dashboard', $log[0]['message']);
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
     * A full page with a skipped contact is still full (#1923). The walk once
     * compared the bucket sum to PAGE_SIZE and stopped early, so the rest of
     * the suppression list was never imported.
     */
    public function testBackfillContinuesPastAFullPageContainingASkippedContact(): void
    {
        $this->matchEveryEmailTo([1]);

        // The first contact is skipped, so the bucket sum is PAGE_SIZE - 1.
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

    /** Unlike a failed poll, an empty page counts as fetched. */
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
     * The SDK's getContacts() returns null when the response has no `contacts`
     * key, which is how Brevo sends an empty list. Without `?? []` every backfill
     * fataled on its last page (#1923).
     */
    public function testBackfillTreatsNullContactsAsAnEmptyExhaustedPageRatherThanThrowing(): void
    {
        $summary = $this->makeJob(
            [FakeBrevoSuppressionSyncClient::pageWithNullContacts()],
            $client
        )->runFullBackfill();

        $this->assertSame(1, $client->fetchCalls);
        $this->assertSame(1, $summary->pagesFetched);
        $this->assertSame(0, $summary->contactsExamined);
        $this->assertSame(0, $summary->matchedCount);
        $this->assertFalse($summary->backfillCapped);
    }

    /** A failed poll is not end-of-data and is not retried or counted as fetched. */
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
        $this->assertTrue(
            $summary->pollFailed,
            'pollFailed must be true here specifically, so an operator can tell a Brevo'
                . ' outage apart from the page-count cap — both mean "incomplete, re-run me"'
        );
    }

    // --- Backfill page cap ------------------------------------------------

    /** The summary flag tells the operator the import is incomplete. */
    public function testBackfillStopsAtItsPageCapAndFlagsTheSummary(): void
    {
        $this->matchEveryEmailTo([1]);

        // Every page is full, so only the cap can stop the walk.
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

    /**
     * A list that ends on a short page that is also the cap-th page is not
     * capped (#1923). The cap test above passes even with the old bug.
     */
    public function testBackfillEndingExactlyOnTheCapBoundaryIsNotFlaggedAsCapped(): void
    {
        $this->matchEveryEmailTo([1]);

        $fullPage = FakeBrevoSuppressionSyncClient::page(array_fill(
            0,
            self::PAGE_SIZE,
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce')
        ));

        $pages = array_fill(0, self::MAX_BACKFILL_PAGES - 1, $fullPage);
        $pages[] = FakeBrevoSuppressionSyncClient::page([
            FakeBrevoBlockedContact::withReason('last@example.com', 'hardBounce'),
        ]);

        $summary = $this->makeJob($pages, $client)->runFullBackfill();

        $this->assertFalse(
            $summary->backfillCapped,
            'Exhaustion and the page cap coinciding must report exhausted, not capped'
        );
        $this->assertSame(
            self::MAX_BACKFILL_PAGES,
            $client->fetchCalls,
            'The walk must still reach exactly the cap-th page — no page beyond it'
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

    /** The admin page's numbers depend on the buckets being disjoint. */
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
            // A skipped contact, so contactsExamined differs from the bucket sum.
            FakeBrevoBlockedContact::withReason('skip@example.com', 'someFutureBrevoCode'),
        ])->runNowWithSummary();

        $this->assertSame(2, $summary->matchedCount);
        $this->assertSame(2, $summary->unmatchedCount);
        $this->assertSame(1, $summary->alreadyFlaggedCount);
        $this->assertSame(1, $summary->skippedCount, 'The unrecognized-code contact reaches no bucket');

        $this->assertSame(
            5,
            $summary->matchedCount + $summary->unmatchedCount + $summary->alreadyFlaggedCount,
            'The three flag-decision buckets must sum to the contacts that reached one'
        );
        $this->assertSame(
            6,
            $summary->contactsExamined,
            'contactsExamined is the real row count, including the skipped contact the'
                . ' three buckets above do not account for'
        );

        $this->assertSame(1, $summary->pagesFetched);
        $this->assertFalse($summary->backfillCapped);
        $this->assertFalse($summary->pollFailed);

        $this->assertSame(
            ['hardBounce' => 1, 'contactFlaggedAsSpam' => 2, 'unrecognized' => 1],
            $summary->reasonCodeCounts
        );

        // Still applied: the write is idempotent and repairs a missing event row.
        $this->assertSame(
            ['bounce@example.com', 'spam@example.com', 'already@example.com'],
            array_column($this->applier->calls, 'email')
        );
    }

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

    /** 'blocked' reads email_bounced; the others read email_suppressed. */
    public function testAlreadyFlaggedCheckReadsTheColumnTheEventWouldSet(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);
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

    /** Fails open: hiding a broken read is worse than overstating new matches. */
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

    /** A car that vanished between reads gets its own log line for triage. */
    public function testCarVanishedBetweenLookupAndPreCheckStillAppliesTheSuppressionAndLogs(): void
    {
        $this->mockRepo->method('findByEmail')->willReturn([(object) ['id' => 1]]);
        $this->mockRepo->method('findById')->willReturn(null);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce'),
        ])->runNowWithSummary();

        $this->assertCount(1, $this->applier->calls, 'A vanished car must never cost a suppression');
        $this->assertSame(1, $summary->matchedCount);
        $this->assertSame(0, $summary->alreadyFlaggedCount);

        $log = $this->logsContaining('vanished before the flag pre-check');
        $this->assertNotEmpty($log);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);
    }

    /** An all-zero summary would look like a successful run over an empty list. */
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

    /**
     * runNowWithSummary() bypasses run(), so it repeats the verification-switch
     * check and throws so the operator sees why.
     */
    public function testRunNowWithSummaryThrowsWhenVerificationSwitchIsOff(): void
    {
        $job = $this->makeJobWithContacts(
            [FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce')],
            verificationEnabled: false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/switched off site-wide/');

        $job->runNowWithSummary();
    }

    // --- Per-contact / per-car failure isolation --------------------------

    /** The 48h window or a backfill re-run covers a skipped car. */
    public function testWriteFailureForOneCarDoesNotAbortTheRest(): void
    {
        $this->matchEveryEmailTo([1]);

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

        $this->assertSame(2, $summary->matchedCount);
        $this->assertSame(0, $summary->unmatchedCount);
        $this->assertSame(0, $summary->alreadyFlaggedCount);
        $this->assertSame(['hardBounce' => 2], $summary->reasonCodeCounts);
    }

    public function testWriteFailureForOneCarDoesNotStopTheContactsOtherCars(): void
    {
        $this->matchEveryEmailTo([1, 2, 3]);
        $this->applier->failOn($this->syntheticId('owner@example.com', 'hardBounce'));

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce'),
        ])->runFullBackfill();

        // All cars share the message-id, so all three fail; all must be attempted.
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

    /** Brevo's list includes addresses that were never registry cars. */
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

    /** unmatchedCount alone cannot show a counter UPDATE that never fires (#2085). */
    public function testSyncPageUnmatchedContactIncrementsCounter(): void
    {
        $this->expectRepoCalls()->expects($this->exactly(3))->method('findByEmail')->willReturn([]);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('a@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('b@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('c@example.com', 'hardBounce'),
        ], $client, db: $db)->runFullBackfill();

        $this->assertSame(3, $summary->unmatchedCount);
        $this->assertSame(3, $db->unmatchedCounterIncrementCalls());
    }

    /**
     * A counter failure must not abort the run, but counterFailureCount must
     * report it. After the first failure the increment is skipped (the fault is
     * in the settings row) while the tally keeps counting.
     */
    public function testSyncPageUnmatchedContactCounterFailureIsReportedInSummary(): void
    {
        $this->expectRepoCalls()->expects($this->exactly(2))->method('findByEmail')->willReturn([]);

        $summary = $this->makeJobWithContacts(
            [
                FakeBrevoBlockedContact::withReason('a@example.com', 'hardBounce'),
                FakeBrevoBlockedContact::withReason('b@example.com', 'hardBounce'),
            ],
            $client,
            db: $db,
            unmatchedCounterUpdateSucceeds: false,
        )->runFullBackfill();

        $this->assertSame(2, $summary->counterFailureCount, 'Both unmatched contacts went unrecorded in the dashboard counter');
        $this->assertSame(2, $summary->unmatchedCount, 'A failed counter increment must not affect the job\'s own unmatchedCount');
        $this->assertSame(0, $summary->skippedCount, 'A failed counter increment must not be reported as a skipped contact');
        $this->assertSame(
            1,
            $db->unmatchedCounterIncrementCalls(),
            'Once one increment fails the call is not re-attempted, to avoid one duplicate warning-log row per unmatched contact'
        );
    }

    public function testSyncPageUnmatchedContactCounterSuccessReportsNoFailures(): void
    {
        $this->expectRepoCalls()->expects($this->exactly(2))->method('findByEmail')->willReturn([]);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('a@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('b@example.com', 'hardBounce'),
        ], $client, db: $db)->runFullBackfill();

        $this->assertSame(0, $summary->counterFailureCount);
        $this->assertSame(2, $summary->unmatchedCount);
        $this->assertSame(2, $db->unmatchedCounterIncrementCalls());
    }

    // --- Synthetic message-id determinism ---------------------------------

    /** A stable id makes a re-import collide with UNIQUE (car_id, brevo_message_id, event). */
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

    /** Built from the raw blockedAt: the run-time fallback date would change the id. */
    public function testUnparseableBlockedAtStillYieldsAStableId(): void
    {
        $this->matchEveryEmailTo([1]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce', null, 'not-a-date'),
        ])->runFullBackfill();
        $firstId = $this->applier->messageIds()[0];

        $this->applier = new SpyEmailEventApplier();
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

    /** Colliding ids would lose the second suppression to the UNIQUE key. */
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
     * The SDK getters are untyped. A (string) cast would store "Array" as an address.
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

    /** The SDK does not enforce getReason()'s type, so a missing `reason` gives null. */
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

        // Malformed and unmapped codes need different operator actions.
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

    /** The message is only annotation; the suppression is still valid. */
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

    /** Mirrors the job's id scheme on purpose: a scheme change must break these tests. */
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
