<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EffectiveEmailFlags;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventMatcher;
use ElanRegistry\Cron\BrevoSuppressionSyncJob;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
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
 * BrevoSuppressionSyncJob (#1923, #2359). Same doubles as BrevoEventReconciliationJobTest.
 * Incremental mode runs through runNow(); the backfill modes are called
 * directly because run()/execute() cannot reach them. The job gets a real
 * EmailEventMatcher over the repository stub, so a test stubs
 * findUserIdsByAddress() to choose the user.
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
     * Every address belongs to this one user, who reads back unflagged, so
     * the pre-check always says "write".
     */
    private function matchEveryEmailTo(int $userId): void
    {
        $this->mockRepo->method('findUserIdsByAddress')->willReturn([$userId]);
        $this->stubOwnerFlags([]);
    }

    /**
     * Stub the profile read the pre-check makes for each owner.
     *
     * An owner not listed in $flagsByUserId has a profile with every flag clear.
     *
     * @param array<int, array{bounced?: int, blocked?: int}> $flagsByUserId
     */
    private function stubOwnerFlags(array $flagsByUserId): void
    {
        $this->mockRepo->method('effectiveEmailFlags')->willReturnCallback(
            static function (int $userId) use ($flagsByUserId): EffectiveEmailFlags {
                $flags = $flagsByUserId[$userId] ?? [];
                $blocked = ($flags['blocked'] ?? 0) === 1;
                $bounced = ($flags['bounced'] ?? 0) === 1;

                // The repository applies the address match, so a flag here applies.
                return new EffectiveEmailFlags(
                    true,
                    false,
                    null,
                    $blocked,
                    $blocked ? 'owner@example.com' : null,
                    null,
                    null,
                    $bounced,
                    $bounced ? 'owner@example.com' : null,
                    null,
                    'owner@example.com'
                );
            }
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
            new EmailEventMatcher($this->mockRepo),
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

    /**
     * 'blocked' sets the bounce flag; 'spam' and 'unsubscribed' set the
     * suppression flag. Only `adminBlocked` replaces the cause (#2359).
     */
    #[DataProvider('reasonCodeMappings')]
    public function testReasonCodeMapsToItsEventAndCause(string $code, string $expectedEvent, ?string $expectedCause): void
    {
        $this->matchEveryEmailTo(7);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', $code),
        ])->runNow();

        $this->assertCount(1, $this->applier->calls);
        $this->assertSame($expectedEvent, $this->applier->calls[0]['event']);
        $this->assertSame($expectedCause, $this->applier->calls[0]['blockedCause']);
        $this->assertSame(7, $this->applier->calls[0]['userId']);
        $this->assertSame('owner@example.com', $this->applier->calls[0]['email']);
        $this->assertSame(EmailEventApplier::MODE_STATE, $this->applier->calls[0]['mode']);
    }

    /**
     * Literal strings: the SDK's reason constants need the vendored SDK, which
     * the unit suite cannot load.
     *
     * @return array<string, array{string, string, ?string}>
     */
    public static function reasonCodeMappings(): array
    {
        return [
            'hardBounce is a dead mailbox' => ['hardBounce', 'blocked', null],
            'contactFlaggedAsSpam' => ['contactFlaggedAsSpam', 'spam', null],
            'unsubscribedViaEmail' => ['unsubscribedViaEmail', 'unsubscribed', null],
            'unsubscribedViaMA' => ['unsubscribedViaMA', 'unsubscribed', null],
            'unsubscribedViaApi' => ['unsubscribedViaApi', 'unsubscribed', null],
            'adminBlocked' => ['adminBlocked', 'unsubscribed', CarRepository::BLOCKED_CAUSE_BLOCKLIST],
        ];
    }

    /**
     * `adminBlocked` is a human suppression decision, not a dead mailbox, so it
     * must not set the hard-bounce flag. A reader may want to "correct" this.
     */
    public function testAdminBlockedIsASuppressionNotABounce(): void
    {
        $this->matchEveryEmailTo(1);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('admin@example.com', 'adminBlocked'),
        ])->runNow();

        $this->assertSame('unsubscribed', $this->applier->calls[0]['event']);
        $this->assertNotSame(
            'blocked',
            $this->applier->calls[0]['event'],
            'adminBlocked is a human decision, not a dead mailbox — it must not set email_bounced'
        );
        $this->assertSame(
            CarRepository::BLOCKED_CAUSE_BLOCKLIST,
            $this->applier->calls[0]['blockedCause'],
            'The event row stays unsubscribed, but the profile cause is blocklist'
        );
    }

    /** The 'unrecognized' tally tells the operator that Brevo added a reason. */
    public function testUnrecognizedReasonCodeIsTalliedAndSkipped(): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findUserIdsByAddress');

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('mystery@example.com', 'someNewBrevoReason'),
        ])->runFullBackfill();

        $this->assertSame([], $this->applier->calls, 'An unknown code must not flag any owner');
        $this->assertSame(['unrecognized' => 1], $summary->reasonCodeCounts);
        $this->assertSame(0, $summary->matchedCount);
        $this->assertSame(0, $summary->unmatchedCount);
        $this->assertSame(0, $summary->alreadyFlaggedCount);

        $log = $this->logsContaining('unrecognized reason code');
        $this->assertNotEmpty($log, 'An unmapped code must be logged, not silently dropped');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
        $this->assertStringContainsString('someNewBrevoReason', $log[0]['message']);
        $this->assertStringNotContainsString('@', $log[0]['message'], 'The log line must not hold an address');
    }

    /** An unmapped code must not stop the contacts behind it on the page. */
    public function testUnrecognizedReasonCodeDoesNotBlockLaterContacts(): void
    {
        $this->matchEveryEmailTo(1);

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
        $this->matchEveryEmailTo(1);

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

    /**
     * The import has no message to match, so the match time is `blockedAt`
     * and there is no car (#2359).
     */
    public function testMatchedUserReceivesTheSuppressionWithBlockedAtAsTheMatchTime(): void
    {
        $this->matchEveryEmailTo(7);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce', 'mailbox unavailable'),
        ])->runNow();

        $this->assertCount(1, $this->applier->calls);
        $call = $this->applier->calls[0];
        $this->assertSame(7, $call['userId']);
        $this->assertSame('mailbox unavailable', $call['reason']);
        $this->assertSame(self::BLOCKED_AT_LOCAL, $call['occurredAt']);
        $this->assertNull($call['match']->carId());
        $this->assertSame(self::BLOCKED_AT_LOCAL, $call['match']->messageTime());
    }

    /**
     * The flags belong to the owner's profile, so a user with no car gets the
     * flag. The import never looks up cars (#2359).
     */
    public function testUserWithNoCarGetsTheFlag(): void
    {
        $this->mockRepo->method('findUserIdsByAddress')->willReturn([55]);
        $this->stubOwnerFlags([]);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('carless@example.com', 'contactFlaggedAsSpam'),
        ])->runNowWithSummary();

        $this->assertSame([55], array_column($this->applier->calls, 'userId'));
        $this->assertSame(1, $summary->matchedCount);
    }

    /** An address that two users share sets no flag: a guess could flag the wrong owner. */
    public function testAddressSharedByTwoUsersIsUnmatched(): void
    {
        $this->mockRepo->method('findUserIdsByAddress')->willReturn([55, 56]);
        $this->stubOwnerFlags([]);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('shared@example.com', 'hardBounce'),
        ])->runNowWithSummary();

        $this->assertSame([], $this->applier->calls);
        $this->assertSame(1, $summary->unmatchedCount);
        $this->assertSame(0, $summary->matchedCount);
    }

    /** The matcher compares the normalized address, and the event row keeps it. */
    public function testAddressIsNormalizedBeforeTheMatch(): void
    {
        $repo = $this->expectRepoCalls();
        $repo->expects($this->once())->method('findUserIdsByAddress')
            ->with('owner@example.com')
            ->willReturn([7]);
        $this->stubOwnerFlags([]);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('  Owner@Example.COM ', 'hardBounce'),
        ])->runNow();

        $this->assertSame('owner@example.com', $this->applier->calls[0]['email']);
    }

    /** '' would claim that Brevo said something. */
    public function testEmptyReasonMessageBecomesNull(): void
    {
        $this->matchEveryEmailTo(1);

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
        $this->matchEveryEmailTo(1);

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

    /**
     * A missing, wrong-typed or bad `blockedAt` takes the run time (#2359).
     *
     * @param mixed $blockedAt
     */
    #[DataProvider('unusableBlockedAts')]
    public function testUnusableBlockedAtFallsBackToRunTimeAndLogs(mixed $blockedAt): void
    {
        $this->matchEveryEmailTo(1);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce', null, $blockedAt),
        ])->runNow();

        $this->assertSame(self::NOW, $this->applier->calls[0]['occurredAt']);
        $this->assertSame(self::NOW, $this->applier->calls[0]['match']->messageTime());

        $log = $this->logsContaining('no usable blocked-at date');
        $this->assertNotEmpty($log);
        $this->assertSame(
            LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK,
            $log[0]['category'],
            'A date problem is a Brevo data-hygiene warning, not a broken job'
        );
        $this->assertStringContainsString('owner 1', $log[0]['message']);
        $this->assertStringNotContainsString('@', $log[0]['message'], 'The log line must not hold an address');
    }

    /** @return array<string, array{mixed}> */
    public static function unusableBlockedAts(): array
    {
        return [
            'an unparseable string' => ['not-a-date'],
            'an implausible date' => ['+100000 years'],
            'an empty string' => [''],
            'null' => [null],
            'an int' => [1757332800],
            'an array' => [['2026-09-08T12:00:00.000Z']],
        ];
    }

    // --- Incremental window / bounded work --------------------------------

    public function testIncrementalRunFetchesExactlyOneBoundedPage(): void
    {
        $this->matchEveryEmailTo(1);

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
        $this->matchEveryEmailTo(1);

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
        $this->matchEveryEmailTo(1);

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
        $this->assertStringContainsString('0 invalid address(es)', $log[0]['message']);
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
        $this->matchEveryEmailTo(1);

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
        $this->matchEveryEmailTo(1);

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
        $this->matchEveryEmailTo(1);

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
        $this->matchEveryEmailTo(1);

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
        $this->matchEveryEmailTo(1);

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
        $this->matchEveryEmailTo(1);

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
        $this->matchEveryEmailTo(1);

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
        $this->mockRepo->method('findUserIdsByAddress')->willReturnCallback(
            static fn (string $email): array => match ($email) {
                'already@example.com' => [30],
                'bounce@example.com' => [10],
                'spam@example.com' => [20],
                default => [],
            }
        );

        // Owner 30 is already blocked.
        $this->stubOwnerFlags([30 => ['blocked' => 1]]);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('bounce@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('spam@example.com', 'contactFlaggedAsSpam'),
            FakeBrevoBlockedContact::withReason('already@example.com', 'contactFlaggedAsSpam'),
            FakeBrevoBlockedContact::withReason('unknown-a@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('unknown-b@example.com', 'unsubscribedViaEmail'),
            // A skipped contact, so contactsExamined differs from the bucket sum.
            FakeBrevoBlockedContact::withReason('skip@example.com', 'someFutureBrevoCode'),
            FakeBrevoBlockedContact::withReason('not-an-address', 'hardBounce'),
        ])->runNowWithSummary();

        $this->assertSame(2, $summary->matchedCount);
        $this->assertSame(2, $summary->unmatchedCount);
        $this->assertSame(1, $summary->alreadyFlaggedCount);
        $this->assertSame(1, $summary->skippedCount, 'The unrecognized-code contact reaches no bucket');
        $this->assertSame(1, $summary->invalidAddressCount, 'An invalid address is not a skipped contact');

        $this->assertSame(
            5,
            $summary->matchedCount + $summary->unmatchedCount + $summary->alreadyFlaggedCount,
            'The three flag-decision buckets must sum to the contacts that reached one'
        );
        $this->assertSame(
            7,
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

    /** 'blocked' reads the owner's email_bounced; the others read email_blocked. */
    public function testAlreadyFlaggedCheckReadsTheOwnerFlagTheEventWouldSet(): void
    {
        $this->mockRepo->method('findUserIdsByAddress')->willReturn([1]);
        $this->stubOwnerFlags([1 => ['blocked' => 1]]);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce'),
        ])->runNowWithSummary();

        $this->assertSame(1, $summary->matchedCount);
        $this->assertSame(0, $summary->alreadyFlaggedCount);
    }

    /** Fails open: hiding a broken read is worse than overstating new matches. */
    public function testUnreadableOwnerFlagsStillApplyTheSuppressionAndLog(): void
    {
        $this->mockRepo->method('findUserIdsByAddress')->willReturn([1]);
        $this->mockRepo->method('effectiveEmailFlags')
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

    /** The flags are owner-level: an owner with no profile cannot be "already flagged". */
    public function testOwnerWithNoProfileCountsAsMatchedNotAlreadyFlagged(): void
    {
        $this->mockRepo->method('findUserIdsByAddress')->willReturn([1]);
        $this->mockRepo->method('effectiveEmailFlags')->willReturn(EffectiveEmailFlags::none());

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'contactFlaggedAsSpam'),
        ])->runNowWithSummary();

        $this->assertSame(1, $summary->matchedCount);
        $this->assertSame(0, $summary->alreadyFlaggedCount);
    }

    /** The flag-change audit row records who found the event. */
    public function testEveryApplyCallCarriesTheImportSource(): void
    {
        $this->matchEveryEmailTo(1);

        $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce'),
        ])->runNowWithSummary();

        $this->assertCount(1, $this->applier->calls);
        $this->assertSame(['import'], array_column($this->applier->calls, 'source'));
    }

    /** An all-zero summary would look like a successful run over an empty list. */
    public function testRunNowWithSummaryLogsAndRethrowsAnUnexpectedFailure(): void
    {
        $this->mockRepo->method('findUserIdsByAddress')
            ->willThrowException(new RuntimeException('something unexpected for owner@example.com'));

        $job = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce'),
        ]);

        try {
            $job->runNowWithSummary();
            $this->fail('An unexpected failure must not be reported as an empty successful run');
        } catch (RuntimeException $e) {
            $this->assertSame('something unexpected for owner@example.com', $e->getMessage());
        }

        $log = $this->logsContaining('failed (manual backfill)');
        $this->assertNotEmpty($log);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);
        $this->assertStringContainsString('RuntimeException', $log[0]['message']);
        $this->assertStringNotContainsString('something unexpected', $log[0]['message'], 'The log logs the class and code only');
        $this->assertStringNotContainsString('@', $log[0]['message'], 'The log line must not hold an address');
    }

    /**
     * Only the send batch honours the verification switch (#2358). The
     * suppression import must run while sends are off.
     */
    public function testRunNowWithSummaryRunsWhenVerificationSwitchIsOff(): void
    {
        $this->matchEveryEmailTo(1);

        $summary = $this->makeJobWithContacts(
            [FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce')],
            verificationEnabled: false,
        )->runNowWithSummary();

        $this->assertSame(1, $summary->matchedCount);
        $this->assertCount(1, $this->applier->calls, 'The import must write with the switch off');
    }

    public function testRunReachesExecuteWhenVerificationSwitchIsOff(): void
    {
        $this->matchEveryEmailTo(1);

        $this->makeJobWithContacts(
            [FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce')],
            $client,
            verificationEnabled: false,
        )->run();

        $this->assertSame(1, $client->fetchCalls, 'run() must reach execute() with the switch off');
        $this->assertCount(1, $this->applier->calls);
    }

    // --- Per-contact failure isolation --------------------------------------

    /** The 48h window or a backfill re-run covers a skipped contact. */
    public function testWriteFailureForOneContactDoesNotAbortTheRest(): void
    {
        $this->matchEveryEmailTo(1);

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
        $this->assertCount(1, $log, 'A skipped write must be logged, not silent');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);
        $this->assertStringContainsString('write FAILED for owner 1, event "blocked" —', $log[0]['message']);
        $this->assertStringNotContainsString('@', $log[0]['message'], 'The log line must not hold an address');

        $this->assertSame(2, $summary->matchedCount);
        $this->assertSame(0, $summary->unmatchedCount);
        $this->assertSame(0, $summary->alreadyFlaggedCount);
        $this->assertSame(1, $summary->skippedCount, 'A failed write must not count as matched');
        $this->assertSame(['hardBounce' => 2], $summary->reasonCodeCounts);
    }

    /** @return array<string, array{\Throwable}> */
    public static function dataFaultProvider(): array
    {
        return [
            'unparseable time' => [new CarValidationException('bad time for b@example.com')],
            'empty time or bad id' => [new \InvalidArgumentException('bad match for b@example.com')],
        ];
    }

    /** A data fault fails on every run, so it must not stop the contacts behind it. */
    #[DataProvider('dataFaultProvider')]
    public function testDataFaultForOneContactSkipsOnlyThatContact(\Throwable $fault): void
    {
        $this->matchEveryEmailTo(1);
        $this->applier->failOn($this->syntheticId('b@example.com', 'hardBounce'), $fault);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('a@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('b@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('c@example.com', 'hardBounce'),
        ])->runFullBackfill();

        $this->assertSame(
            ['a@example.com', 'b@example.com', 'c@example.com'],
            array_column($this->applier->calls, 'email'),
            'Later contacts must still be applied'
        );
        $this->assertSame(2, $summary->matchedCount);
        $this->assertSame(1, $summary->skippedCount);

        $log = $this->logsContaining('write REJECTED');
        $this->assertCount(1, $log);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);
        $this->assertStringContainsString('owner 1, event "blocked"', $log[0]['message']);
        $this->assertStringContainsString(get_class($fault) . ' (code', $log[0]['message']);
        $this->assertStringNotContainsString('@', $log[0]['message'], 'The log line must not hold an address');
    }

    /** CarRepository puts the address in its exception messages, so the log keeps only the class and code. */
    public function testWriteFailureLogHoldsNoExceptionMessage(): void
    {
        $this->matchEveryEmailTo(1);
        $this->applier->failWith(new CarDatabaseException('insert failed for owner@example.com', 1062));

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce'),
        ])->runFullBackfill();

        $this->assertSame(1, $summary->skippedCount);
        $this->assertSame(0, $summary->matchedCount, 'No write landed, so no bucket may claim the contact');

        $log = $this->logsContaining('write FAILED');
        $this->assertCount(1, $log);
        $this->assertStringContainsString('CarDatabaseException (code 1062)', $log[0]['message']);
        $this->assertStringNotContainsString('insert failed', $log[0]['message']);
        $this->assertStringNotContainsString('@', $log[0]['message']);
    }

    public function testUserLookupFailureSkipsOnlyThatContact(): void
    {
        $this->mockRepo->method('findUserIdsByAddress')->willReturnCallback(
            static function (string $email): array {
                if ($email === 'broken@example.com') {
                    throw new CarDatabaseException('lookup failed for broken@example.com');
                }
                return [1];
            }
        );
        $this->stubOwnerFlags([]);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('broken@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('ok@example.com', 'hardBounce'),
        ])->runFullBackfill();

        $this->assertSame(['ok@example.com'], array_column($this->applier->calls, 'email'));
        $this->assertSame(1, $summary->matchedCount);
        $this->assertSame(1, $summary->skippedCount);

        $log = $this->logsContaining('user lookup FAILED');
        $this->assertNotEmpty($log);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);
        $this->assertStringContainsString('hash ' . $this->hashPrefix('broken@example.com'), $log[0]['message']);
        $this->assertStringNotContainsString('lookup failed', $log[0]['message']);
        $this->assertStringNotContainsString('@', $log[0]['message']);
    }

    /** Brevo's list includes addresses that no registry user holds. */
    public function testContactMatchingNoUserIsCountedNotLoggedAsAFailure(): void
    {
        $this->mockRepo->method('findUserIdsByAddress')->willReturn([]);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('stranger@example.com', 'hardBounce'),
        ])->runFullBackfill();

        $this->assertSame([], $this->applier->calls);
        $this->assertSame(1, $summary->unmatchedCount);
        $this->assertSame(0, $summary->matchedCount);
        $this->assertSame([], $summary->reasonCodeCounts, 'An unmatched contact has no reason tally');
        $this->assertSame([], $this->logsContaining('FAILED'));
    }

    /**
     * The dashboard counter counts verification-tagged events only (#2359).
     * A blocklist entry has no tag, so the import never touches the counter.
     */
    public function testUnmatchedContactDoesNotIncrementTheUnmatchedRecipientCounter(): void
    {
        $this->mockRepo->method('findUserIdsByAddress')->willReturn([]);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('a@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('b@example.com', 'hardBounce'),
        ], $client, db: $db)->runFullBackfill();

        $this->assertSame(2, $summary->unmatchedCount);
        $this->assertSame(0, $db->unmatchedCounterIncrementCalls());
    }

    // --- Address check (#2359) ----------------------------------------------

    /**
     * The check runs before any match: no lookup, no write. The log names the
     * hash prefix, never the address.
     */
    #[DataProvider('invalidAddresses')]
    public function testInvalidAddressIsSkippedCountedAndLoggedByHash(string $address): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findUserIdsByAddress');

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason($address, 'hardBounce'),
        ])->runNowWithSummary();

        $this->assertSame([], $this->applier->calls);
        $this->assertSame(1, $summary->invalidAddressCount);
        $this->assertSame(0, $summary->skippedCount);
        $this->assertSame(0, $summary->unmatchedCount);
        $this->assertSame(1, $summary->contactsExamined);

        $log = $this->logsContaining('invalid address');
        $this->assertCount(1, $log);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
        $this->assertStringContainsString('hash ' . $this->hashPrefix($address), $log[0]['message']);
        $this->assertStringNotContainsString('@', $log[0]['message']);
    }

    /** @return array<string, array{string}> */
    public static function invalidAddresses(): array
    {
        return [
            'longer than 155 bytes' => [self::addressOfLength(156)],
            'no at sign' => ['not-an-address'],
            'two at signs' => ['two@at@example.com'],
            'a space inside' => ['own er@example.com'],
        ];
    }

    /** 155 bytes is the limit, not past it. */
    public function testAddressOf155BytesIsAccepted(): void
    {
        $this->matchEveryEmailTo(1);
        $address = self::addressOfLength(155);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason($address, 'hardBounce'),
        ])->runNowWithSummary();

        $this->assertSame(0, $summary->invalidAddressCount);
        $this->assertSame([$address], array_column($this->applier->calls, 'email'));
    }

    /** The backfill adds each page's invalid count, and contactsExamined includes them. */
    public function testBackfillTotalsInvalidAddresses(): void
    {
        $this->matchEveryEmailTo(1);

        $summary = $this->makeJobWithContacts([
            FakeBrevoBlockedContact::withReason('bad-one', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('owner@example.com', 'hardBounce'),
            FakeBrevoBlockedContact::withReason('bad-two', 'hardBounce'),
        ])->runFullBackfill();

        $this->assertSame(2, $summary->invalidAddressCount);
        $this->assertSame(1, $summary->matchedCount);
        $this->assertSame(3, $summary->contactsExamined);
    }

    // --- Synthetic message-id determinism ---------------------------------

    /** A stable id makes a re-import collide with UNIQUE (user_id, brevo_message_id, event). */
    public function testSyntheticMessageIdIsStableAcrossRuns(): void
    {
        $this->matchEveryEmailTo(1);

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
        $this->matchEveryEmailTo(1);

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
            new EmailEventMatcher($this->mockRepo),
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
        $this->matchEveryEmailTo(1);

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
        $this->matchEveryEmailTo(1);

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
        $this->matchEveryEmailTo(1);

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

    // --- Malformed payload fields -----------------------------------------

    /**
     * The SDK getters are untyped. A (string) cast would store "Array" as an address.
     *
     * @param mixed $email
     */
    #[DataProvider('unusableEmails')]
    public function testUnusableEmailIsSkippedAndLogged(mixed $email): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findUserIdsByAddress');

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
        $this->expectRepoCalls()->expects($this->never())->method('findUserIdsByAddress');

        $this->makeJobWithContacts([
            new FakeBrevoBlockedContact('owner@example.com'),
        ])->runNow();

        $this->assertSame([], $this->applier->calls);

        $log = $this->logsContaining('carried no reason object');
        $this->assertNotEmpty($log);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
        $this->assertStringNotContainsString('@', $log[0]['message'], 'The log line must not hold an address');
    }

    /**
     * @param mixed $code
     */
    #[DataProvider('unusableReasonCodes')]
    public function testNonStringReasonCodeIsSkippedAndLogged(mixed $code): void
    {
        $this->expectRepoCalls()->expects($this->never())->method('findUserIdsByAddress');

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
        $this->matchEveryEmailTo(1);

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
        $this->matchEveryEmailTo(1);

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

    /** First 8 hex characters of the sha256 of the normalized address, as the job logs it. */
    private function hashPrefix(string $address): string
    {
        return substr(hash('sha256', strtolower(trim($address))), 0, 8);
    }

    /**
     * A valid address of exactly $bytes bytes (between 134 and 196). Each part
     * stays inside the RFC limits, so only the 155-byte rule can reject it.
     */
    private static function addressOfLength(int $bytes): string
    {
        // 64 (local) + 1 (@) + 63 (label) + 1 (.) + n (label) + 4 (.com)
        return str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', $bytes - 133) . '.com';
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
