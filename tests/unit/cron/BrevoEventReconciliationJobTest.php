<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventMatch;
use ElanRegistry\Car\EmailEventMatcher;
use ElanRegistry\Cron\BrevoEventReconciliationJob;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * BrevoEventReconciliationJob (#1889, #2359). The matcher is a stub, and the
 * applier and client are recording Support doubles: tests check the calls
 * and the exact window, limit, offset and event type. The Brevo SDK is not
 * in the unit autoloader.
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

    /** User that the default matcher stub gives every event. */
    private const USER_ID = 10;

    private string $originalTimezone = 'UTC';

    /**
     * A stub unless a test calls expectRepoCalls(): PHPUnit warns on a mock
     * with no expectations.
     *
     * @var CarRepository&\PHPUnit\Framework\MockObject\Stub
     */
    private CarRepository $mockRepo;

    private SpyEmailEventApplier $applier;

    /** @var \Closure(string, string): EmailEventMatch */
    private \Closure $matchFor;

    /** @var list<array{messageId: string, address: string}> */
    private array $matcherCalls = [];

    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set(self::APP_TIMEZONE);

        $this->mockRepo = $this->createStub(CarRepository::class);
        $this->applier = new SpyEmailEventApplier();
        $this->matcherCalls = [];
        $this->matchFor = static fn (string $messageId, string $address): EmailEventMatch
            => EmailEventMatch::matched(self::USER_ID, null, '2026-09-08 04:00:00');
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
     * @param list<string> $failingTypes Event types whose poll fails
     * @param-out FakeBrevoEventReconciliationClient $client
     * @param-out AbstractCronJobFakeDatabase $db
     */
    private function makeJob(
        ?array $events,
        ?FakeBrevoEventReconciliationClient &$client = null,
        bool $verificationEnabled = true,
        ?AbstractCronJobFakeDatabase &$db = null,
        bool $unmatchedCounterUpdateSucceeds = true,
        array $failingTypes = [],
        bool $applyTypeFilter = true,
    ): BrevoEventReconciliationJob {
        $client = new FakeBrevoEventReconciliationClient($events, $failingTypes, $applyTypeFilter);
        $db = new AbstractCronJobFakeDatabase(
            verificationEnabled: $verificationEnabled,
            unmatchedCounterUpdateSucceeds: $unmatchedCounterUpdateSucceeds,
        );

        $matcher = $this->createStub(EmailEventMatcher::class);
        $matcher->method('matchByMessageId')->willReturnCallback(
            function (string $messageId, string $address): EmailEventMatch {
                $this->matcherCalls[] = ['messageId' => $messageId, 'address' => $address];

                return ($this->matchFor)($messageId, $address);
            }
        );

        return new BrevoEventReconciliationJob(
            $db,
            $this->mockRepo,
            $matcher,
            $this->applier,
            $client,
            new DateTimeImmutable(self::NOW)
        );
    }

    // --- Happy path ------------------------------------------------------

    public function testMatchedEventIsAppliedOnceUnderTheWebhookEventName(): void
    {
        $this->matchFor = static fn (): EmailEventMatch => EmailEventMatch::matched(50, 7, '2026-09-08 04:00:00');

        $this->makeJob([
            new FakeBrevoEvent(
                email: 'owner@example.com',
                event: 'hardBounces',
                messageId: 'brevo-msg-42',
                date: self::EVENT_DATE_UTC,
                reason: 'mailbox unavailable'
            ),
        ])->runNow();

        $this->assertCount(1, $this->applier->calls);
        $call = $this->applier->calls[0];
        $this->assertSame(50, $call['userId']);
        $this->assertSame(7, $call['match']->carId());
        $this->assertSame('owner@example.com', $call['email']);
        $this->assertSame('hard_bounce', $call['event']);
        $this->assertSame('mailbox unavailable', $call['reason']);
        $this->assertSame('brevo-msg-42', $call['messageId']);
        $this->assertSame(self::EVENT_DATE_LOCAL, $call['occurredAt']);
        $this->assertSame('reconciliation', $call['source']);
        $this->assertSame(EmailEventApplier::MODE_EVENT, $call['mode']);
        $this->assertNull($call['blockedCause']);
        $this->assertSame([['messageId' => 'brevo-msg-42', 'address' => 'owner@example.com']], $this->matcherCalls);
    }

    /** Matching ignores case and outer whitespace, so the job normalizes first. */
    public function testAddressIsNormalizedBeforeTheMatchAndTheWrite(): void
    {
        $this->makeJob([new FakeBrevoEvent(email: '  Owner@Example.COM ')])->runNow();

        $this->assertSame('owner@example.com', $this->matcherCalls[0]['address']);
        $this->assertSame('owner@example.com', $this->applier->calls[0]['email']);
    }

    public function testEveryEventInThePageIsProcessed(): void
    {
        $this->makeJob([
            new FakeBrevoEvent(messageId: 'm1'),
            new FakeBrevoEvent(messageId: 'm2'),
            new FakeBrevoEvent(messageId: 'm3'),
        ])->runNow();

        $this->assertSame(['m1', 'm2', 'm3'], $this->applier->messageIds());
    }

    /** All registry mail counts (#2359): no tag is needed. */
    #[DataProvider('anyTag')]
    public function testEventOfOursIsAppliedWhateverItsTag(mixed $tag): void
    {
        $this->makeJob([new FakeBrevoEvent(tag: $tag)])->runNow();

        $this->assertCount(1, $this->applier->calls);
    }

    /** @return array<string, array{mixed}> */
    public static function anyTag(): array
    {
        return [
            'verification tag' => ['car_verification'],
            'another tag' => ['password_reset'],
            'no tag' => [null],
            'non-string tag' => [['car_verification']],
        ];
    }

    #[DataProvider('absentReasons')]
    public function testAbsentReasonIsPassedAsNull(mixed $reason): void
    {
        $this->makeJob([new FakeBrevoEvent(reason: $reason)])->runNow();

        $this->assertNull($this->applier->calls[0]['reason']);
    }

    /** @return array<string, array{mixed}> */
    public static function absentReasons(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'non-string' => [['mailbox unavailable']],
        ];
    }

    // --- One page per event type -------------------------------------------

    public function testEachEventTypeIsFetchedSeparatelyWithOneBoundedPage(): void
    {
        $this->makeJob([], $client)->runNow();

        $this->assertSame(
            ['hardBounces', 'softBounces', 'blocked', 'invalid', 'spam', 'unsubscribed', 'delivered'],
            $client->fetchedTypes()
        );
        foreach ($client->fetchArgs as $args) {
            $this->assertSame(1000, $args['limit']);
            $this->assertSame(0, $args['offset'], 'Offset must stay 0 — newest events are the ones most likely missing');
            $this->assertSame('2026-09-07 03:00:00', $args['start']->format('Y-m-d H:i:s'));
            $this->assertSame(self::NOW, $args['end']->format('Y-m-d H:i:s'));
        }
    }

    #[DataProvider('eventTypeNames')]
    public function testEachApiEventTypeIsRecordedUnderTheWebhookName(string $apiType, string $webhookName): void
    {
        $this->makeJob([new FakeBrevoEvent(event: $apiType)])->runNow();

        $this->assertSame([$webhookName], array_column($this->applier->calls, 'event'));
    }

    /** @return array<string, array{string, string}> */
    public static function eventTypeNames(): array
    {
        return [
            'hard bounce' => ['hardBounces', 'hard_bounce'],
            'soft bounce' => ['softBounces', 'soft_bounce'],
            'blocked' => ['blocked', 'blocked'],
            'invalid' => ['invalid', 'invalid_email'],
            'spam' => ['spam', 'spam'],
            'unsubscribed' => ['unsubscribed', 'unsubscribed'],
            'delivered' => ['delivered', 'delivered'],
        ];
    }

    /** One mixed page of 1000 would hold only the newer deliveries. */
    public function testOneThousandDeliveredEventsDoNotHideABounce(): void
    {
        $events = array_map(
            static fn (int $i): FakeBrevoEvent => new FakeBrevoEvent(event: 'delivered', messageId: 'd' . $i),
            range(1, 1000)
        );
        $events[] = new FakeBrevoEvent(event: 'hardBounces', messageId: 'bounce-1');

        $summary = $this->makeJob($events)->runNowWithSummary();

        $bounces = array_values(array_filter(
            $this->applier->calls,
            static fn (array $call): bool => $call['event'] === 'hard_bounce'
        ));
        $this->assertCount(1, $bounces, 'The bounce must be applied');
        $this->assertSame('bounce-1', $bounces[0]['messageId']);
        $this->assertSame(1001, $summary->matchedCount);
        $this->assertSame(1001, $summary->eventsExamined);
    }

    public function testOneFailedPollDoesNotStopTheOtherTypes(): void
    {
        $summary = $this->makeJob(
            [new FakeBrevoEvent(event: 'softBounces', messageId: 's1'), new FakeBrevoEvent(event: 'hardBounces', messageId: 'h1')],
            failingTypes: ['softBounces'],
        )->runNowWithSummary();

        $this->assertSame(['h1'], $this->applier->messageIds());
        $this->assertTrue($summary->pollFailed);
        $this->assertSame(6, $summary->pagesFetched);
    }

    /** An API that ignores the filter must not turn a delivery into a bounce. */
    public function testEventOfAnotherTypeIsSkippedAndLogged(): void
    {
        $summary = $this->makeJob(
            [new FakeBrevoEvent(event: 'delivered', messageId: 'm1')],
            applyTypeFilter: false,
        )->runNowWithSummary();

        $this->assertSame(['delivered'], array_column($this->applier->calls, 'event'));
        $this->assertSame(6, $summary->skippedCount);
        $log = $this->logsContaining('returned an event of another type');
        $this->assertCount(6, $log);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
    }

    // --- Not ours, no user --------------------------------------------------

    /** Mail of the other environment on the shared Brevo account. */
    public function testEventThatIsNotOursWritesNothingCountsNothingAndLogsNothing(): void
    {
        $this->matchFor = static fn (): EmailEventMatch => EmailEventMatch::notOurs();

        $summary = $this->makeJob([new FakeBrevoEvent(event: 'hardBounces')], db: $db)->runNowWithSummary();

        $this->assertSame([], $this->applier->calls);
        $this->assertSame(0, $summary->unmatchedCount);
        $this->assertSame(0, $summary->skippedCount);
        $this->assertSame(0, $summary->matchedCount);
        $this->assertSame(1, $summary->eventsExamined);
        $this->assertSame(0, $db->unmatchedCounterIncrementCalls());
        global $mockLogEntries;
        $this->assertSame(
            $this->logsContaining('retention prune'),
            $mockLogEntries,
            'A foreign event stays silent: the prune line is the only log line'
        );
    }

    public function testVerificationEventWithNoUserIncrementsTheUnmatchedCounter(): void
    {
        $this->matchFor = static fn (): EmailEventMatch => EmailEventMatch::noUser();

        $summary = $this->makeJob([new FakeBrevoEvent(tag: 'car_verification')], db: $db)->runNowWithSummary();

        $this->assertSame([], $this->applier->calls);
        $this->assertSame(1, $summary->unmatchedCount);
        $this->assertSame(1, $db->unmatchedCounterIncrementCalls());
    }

    #[DataProvider('nonVerificationTags')]
    public function testNonVerificationEventWithNoUserIsNotCounted(mixed $tag): void
    {
        $this->matchFor = static fn (): EmailEventMatch => EmailEventMatch::noUser();

        $summary = $this->makeJob([new FakeBrevoEvent(tag: $tag)], db: $db)->runNowWithSummary();

        $this->assertSame([], $this->applier->calls);
        $this->assertSame(0, $summary->unmatchedCount);
        $this->assertSame(0, $summary->skippedCount);
        $this->assertSame(0, $db->unmatchedCounterIncrementCalls());
    }

    /** @return array<string, array{mixed}> */
    public static function nonVerificationTags(): array
    {
        return [
            'another tag' => ['password_reset'],
            'no tag' => [null],
            'non-string tag' => [['car_verification']],
        ];
    }

    // --- Address validation -------------------------------------------------

    #[DataProvider('invalidAddresses')]
    public function testInvalidAddressIsSkippedCountedAndLoggedByHashOnly(string $address): void
    {
        $summary = $this->makeJob([new FakeBrevoEvent(email: $address)])->runNowWithSummary();

        $this->assertSame([], $this->matcherCalls, 'An invalid address must be rejected before any match');
        $this->assertSame([], $this->applier->calls);
        $this->assertSame(1, $summary->invalidAddressCount);
        $this->assertSame(0, $summary->skippedCount);

        $log = $this->logsContaining('invalid recipient address');
        $this->assertCount(1, $log);
        $hash = substr(hash('sha256', strtolower(trim($address))), 0, 8);
        $this->assertStringContainsString("hash {$hash}", $log[0]['message']);
        $this->assertStringNotContainsString('@', $log[0]['message']);
        $this->assertStringNotContainsString('not-an-address', $log[0]['message']);
    }

    /** @return array<string, array{string}> */
    public static function invalidAddresses(): array
    {
        return [
            '156 bytes' => [str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 27)],
            'no at sign' => ['not-an-address'],
            'two at signs' => ['a@b@example.com'],
        ];
    }

    public function testAddressOf155BytesIsAccepted(): void
    {
        $address = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 26);
        $this->assertSame(155, strlen($address));

        $summary = $this->makeJob([new FakeBrevoEvent(email: $address)])->runNowWithSummary();

        $this->assertSame(0, $summary->invalidAddressCount);
        $this->assertCount(1, $this->applier->calls);
    }

    // --- Per-event failure isolation -------------------------------------

    /** The 48h window and idempotent writes cover a skipped event. */
    public function testWriteFailureForOneEventDoesNotAbortTheRest(): void
    {
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
        $failed = $this->logsContaining('write FAILED');
        $this->assertNotEmpty($failed, 'A skipped event must be logged, not silent');
        $this->assertStringContainsString('write FAILED for owner 10, event "delivered" —', $failed[0]['message']);
        $this->assertStringContainsString(CarDatabaseException::class . ' (code', $failed[0]['message']);
        $this->assertStringNotContainsString('@', $failed[0]['message'], 'The log line must not hold an address');
        $this->assertStringNotContainsString('simulated write failure', $failed[0]['message'], 'No exception message in the log');
    }

    /** @return array<string, array{\Throwable}> */
    public static function dataFaultProvider(): array
    {
        return [
            'unparseable message time' => [new CarValidationException('bad time for owner@example.com')],
            'empty time or bad id'     => [new \InvalidArgumentException('bad match for owner@example.com')],
        ];
    }

    /** A data fault fails on every run, so it must not stop the events behind it. */
    #[DataProvider('dataFaultProvider')]
    public function testDataFaultInTheApplyForOneEventSkipsOnlyThatEvent(\Throwable $fault): void
    {
        $this->applier->failOn('m2', $fault);

        $summary = $this->makeJob([
            new FakeBrevoEvent(messageId: 'm1'),
            new FakeBrevoEvent(messageId: 'm2'),
            new FakeBrevoEvent(messageId: 'm3'),
        ])->runNowWithSummary();

        $this->assertSame(['m1', 'm2', 'm3'], $this->applier->messageIds(), 'Later events must still be applied');
        $this->assertSame(1, $summary->skippedCount);
        $log = $this->logsContaining('write REJECTED');
        $this->assertCount(1, $log);
        $this->assertStringContainsString('owner 10', $log[0]['message']);
        $this->assertStringContainsString(get_class($fault) . ' (code', $log[0]['message']);
        $this->assertStringNotContainsString('@', $log[0]['message'], 'The log line must not hold an address');
    }

    public function testDataFaultInTheMatchForOneEventSkipsOnlyThatEvent(): void
    {
        $fault = new \InvalidArgumentException('bad match for owner@example.com');
        $this->matchFor = static function (string $messageId) use ($fault): EmailEventMatch {
            if ($messageId === 'm2') {
                throw $fault;
            }
            return EmailEventMatch::matched(self::USER_ID, null, '2026-09-08 04:00:00');
        };

        $summary = $this->makeJob([
            new FakeBrevoEvent(messageId: 'm1'),
            new FakeBrevoEvent(messageId: 'm2'),
            new FakeBrevoEvent(messageId: 'm3'),
        ])->runNowWithSummary();

        $this->assertSame(['m1', 'm3'], $this->applier->messageIds(), 'Later events must still be applied');
        $this->assertSame(1, $summary->skippedCount);
        $log = $this->logsContaining('user lookup REJECTED');
        $this->assertCount(1, $log);
        $this->assertStringContainsString(get_class($fault) . ' (code', $log[0]['message']);
        $this->assertStringNotContainsString('@', $log[0]['message'], 'The log line must not hold an address');
    }

    /** CarRepository puts the address in its exception messages. */
    public function testUserLookupFailureSkipsOnlyThatEventAndLogsNoAddress(): void
    {
        $this->matchFor = static function (string $messageId, string $address): EmailEventMatch {
            if ($messageId === 'm1') {
                throw new CarDatabaseException("lookup failed for {$address}");
            }
            return EmailEventMatch::matched(self::USER_ID, null, '2026-09-08 04:00:00');
        };

        $summary = $this->makeJob([
            new FakeBrevoEvent(email: 'broken@example.com', messageId: 'm1'),
            new FakeBrevoEvent(email: 'ok@example.com', messageId: 'm2'),
        ])->runNowWithSummary();

        $this->assertSame(['m2'], $this->applier->messageIds());
        $this->assertSame(1, $summary->skippedCount);
        $log = $this->logsContaining('user lookup FAILED');
        $this->assertCount(1, $log);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);
        $this->assertStringContainsString(substr(hash('sha256', 'broken@example.com'), 0, 8), $log[0]['message']);
        $this->assertStringNotContainsString('@', $log[0]['message']);
    }

    // --- Retention prune -------------------------------------------------

    public function testPruneBindsTheTerminalCutoffThenTheShortCutoff(): void
    {
        $this->expectRepoCalls()->expects($this->once())
            ->method('deleteEmailEventsOlderThan')
            ->with(
                $this->callback(
                    static fn (DateTimeImmutable $cutoff): bool
                        => $cutoff->format('Y-m-d H:i:s') === '2024-09-09 03:00:00'
                ),
                $this->callback(
                    static fn (DateTimeImmutable $cutoff): bool
                        => $cutoff->format('Y-m-d H:i:s') === '2026-06-11 03:00:00'
                )
            )
            ->willReturn(0);

        $this->makeJob([])->runNow();
    }

    public function testPruneRunsAfterEventsAreProcessedAndItsFailureIsContained(): void
    {
        $this->mockRepo->method('deleteEmailEventsOlderThan')
            ->willThrowException(new CarDatabaseException('delete failed for owner@example.com'));

        // A prune failure is logged apart from a backfill failure, not thrown.
        $this->makeJob([new FakeBrevoEvent()])->runNow();

        $this->assertCount(1, $this->applier->calls, 'The backfill must have completed before the prune ran');

        $pruneLogs = $this->logsContaining('retention prune FAILED');
        $this->assertCount(1, $pruneLogs, 'A prune failure must be logged distinctly');
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $pruneLogs[0]['category']);
        $this->assertStringNotContainsString('@', $pruneLogs[0]['message']);
        $this->assertSame(
            [],
            $this->logsContaining("failed: ElanRegistry"),
            'It must not surface as a generic AbstractCronJob job failure'
        );
        $this->assertSame([], $this->logsContaining('retention prune deleted'), 'A failed prune logs no count');
    }

    /** A PDOException from the driver is a prune failure too, so the run must not crash. */
    public function testPrunePdoExceptionIsContainedAndLogged(): void
    {
        $this->mockRepo->method('deleteEmailEventsOlderThan')
            ->willThrowException(new \PDOException('server has gone away'));

        $this->makeJob([])->runNow();

        $pruneLogs = $this->logsContaining('retention prune FAILED');
        $this->assertCount(1, $pruneLogs);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $pruneLogs[0]['category']);
        $this->assertStringContainsString('PDOException (code', $pruneLogs[0]['message']);
        $this->assertStringNotContainsString('server has gone away', $pruneLogs[0]['message']);
        $this->assertSame([], $this->logsContaining("failed: PDOException"));
    }

    public function testPruneSuccessLogsTheCountOfChangedRows(): void
    {
        $this->mockRepo->method('deleteEmailEventsOlderThan')->willReturn(17);

        $this->makeJob([])->runNow();

        $logs = $this->logsContaining('retention prune deleted or cleared');
        $this->assertCount(1, $logs);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $logs[0]['category']);
        $this->assertStringContainsString('cleared 17 er_email_events rows', $logs[0]['message']);
    }

    // --- Oversized and empty values ------------------------------------------

    public function testOversizedMessageIdIsSkipped(): void
    {
        $this->makeJob([new FakeBrevoEvent(messageId: str_repeat('m', 256))])->runNow();

        $this->assertSame([], $this->matcherCalls);
        $this->assertSame([], $this->applier->calls);

        // A payload-contract warning: the job itself is healthy.
        $log = $this->logsContaining('exceeds storage width');
        $this->assertNotEmpty($log);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
    }

    public function testMaxLengthMessageIdIsAccepted(): void
    {
        $this->makeJob([new FakeBrevoEvent(messageId: str_repeat('m', 255))])->runNow();

        $this->assertCount(1, $this->applier->calls);
    }

    public function testEmptyRequiredFieldIsSkippedAndLoggedWithoutTheAddress(): void
    {
        $this->makeJob([new FakeBrevoEvent(email: 'owner@example.com', messageId: '')])->runNow();

        $this->assertSame([], $this->matcherCalls);
        $this->assertSame([], $this->applier->calls);

        // A payload-contract warning: the job itself is healthy.
        $log = $this->logsContaining('empty required field in event payload');
        $this->assertNotEmpty($log, 'A skipped event must be logged, not silent');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
        $this->assertStringNotContainsString('@', $log[0]['message']);
    }

    // --- Non-string payload fields ---------------------------------------

    /**
     * The SDK getters are untyped. A (string) cast would turn an array into
     * "Array" and feed garbage to EmailEventApplier::apply().
     *
     * @param array{email?: mixed, event?: mixed, messageId?: mixed} $overrides
     */
    #[DataProvider('nonStringPayloadFields')]
    public function testNonStringPayloadFieldIsSkippedAndLogged(array $overrides): void
    {
        $this->makeJob([new FakeBrevoEvent(...$overrides)], applyTypeFilter: false)->runNow();

        $this->assertSame([], $this->matcherCalls);
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

    public function testNonStringPayloadFieldDoesNotBlockLaterEvents(): void
    {
        $this->makeJob([
            new FakeBrevoEvent(messageId: 'm1'),
            new FakeBrevoEvent(email: ['owner@example.com'], messageId: 'm2'),
            new FakeBrevoEvent(messageId: 'm3'),
        ])->runNow();

        $this->assertSame(['m1', 'm3'], $this->applier->messageIds());
    }

    // --- Date handling ---------------------------------------------------

    public function testUnparseableDateFallsBackToRunTimeAndLogsTheUserIdOnly(): void
    {
        $this->makeJob([new FakeBrevoEvent(email: 'owner@example.com', date: 'not-a-date')])->runNow();

        $this->assertSame(self::NOW, $this->applier->calls[0]['occurredAt']);
        $log = $this->logsContaining('no usable date');
        $this->assertNotEmpty($log);
        $this->assertStringContainsString('for user 10', $log[0]['message']);
        $this->assertStringNotContainsString('@', $log[0]['message']);
    }

    /**
     * The webhook writes occurred_at in PHP's timezone and
     * countSoftBouncesSinceLastDelivered() compares rows, so backfilled UTC dates
     * must be converted.
     */
    public function testUtcDateIsStoredInThePhpDefaultTimezoneLikeTheWebhook(): void
    {
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
        $this->makeJob([new FakeBrevoEvent(date: 1_700_000_000)])->runNow();

        $this->assertSame(self::NOW, $this->applier->calls[0]['occurredAt']);

        $log = $this->logsContaining('no usable date');
        $this->assertNotEmpty($log, 'A non-string date must be logged, not silently coerced');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $log[0]['category']);
        $this->assertStringContainsString('(int,', $log[0]['message']);
    }

    public function testMissingDateFallsBackToRunTime(): void
    {
        $this->makeJob([new FakeBrevoEvent(date: null)])->runNow();

        $this->assertSame(self::NOW, $this->applier->calls[0]['occurredAt']);
    }

    /**
     * A date far after the run would sort after every real event. A far-future
     * `delivered` row would then stop the soft-bounce count for good.
     *
     * @param string $date
     */
    #[DataProvider('outOfRangeDates')]
    public function testOutOfRangeDateFallsBackToRunTimeAndLogs(string $date): void
    {
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
            'six minutes after the run' => ['2026-09-09 03:06:00 America/Los_Angeles'],
            'a year after the run' => ['2027-09-09T10:00:00Z'],
        ];
    }

    public function testDateFiveMinutesAfterTheRunIsKept(): void
    {
        $this->makeJob([new FakeBrevoEvent(date: '2026-09-09 03:05:00 America/Los_Angeles')])->runNow();

        $this->assertSame('2026-09-09 03:05:00', $this->applier->calls[0]['occurredAt']);
        $this->assertSame([], $this->logsContaining('no usable date'));
    }

    /** CRON_JOB_FAILURE must stay an operator's "really broken" filter. */
    public function testDateFallbackIsNotLoggedAsAJobFailure(): void
    {
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
        $this->matchFor = static fn (): EmailEventMatch => EmailEventMatch::noUser();

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
        $this->matchFor = static fn (): EmailEventMatch => EmailEventMatch::noUser();

        $this->makeJob([
            new FakeBrevoEvent(email: 'a@example.com', messageId: 'm1'),
            new FakeBrevoEvent(email: 'b@example.com', messageId: 'm2'),
        ])->runNow();

        $log = $this->logsContaining('incremental run complete');
        $this->assertNotEmpty($log);
        $this->assertStringNotContainsString('NOT recorded in the dashboard', $log[0]['message']);
    }

    public function testNightlyRunLogsTheInvalidAddressCount(): void
    {
        $this->makeJob([new FakeBrevoEvent(email: 'not-an-address')])->runNow();

        $log = $this->logsContaining('incremental run complete');
        $this->assertNotEmpty($log);
        $this->assertStringContainsString('1 invalid address(es)', $log[0]['message']);
    }

    // --- runNowWithSummary() counts ---------------------------------------

    public function testRunNowWithSummaryHappyPathCountsAreCorrect(): void
    {
        $summary = $this->makeJob([
            new FakeBrevoEvent(email: 'a@example.com', event: 'delivered', messageId: 'm1'),
            new FakeBrevoEvent(email: 'b@example.com', event: 'delivered', messageId: 'm2'),
            new FakeBrevoEvent(email: 'c@example.com', event: 'hardBounces', messageId: 'm3'),
        ])->runNowWithSummary();

        $this->assertSame(3, $summary->matchedCount);
        $this->assertSame(0, $summary->unmatchedCount);
        $this->assertSame(0, $summary->skippedCount);
        $this->assertSame(0, $summary->invalidAddressCount);
        $this->assertSame(['hard_bounce' => 1, 'delivered' => 2], $summary->eventTypeCounts);
        $this->assertSame(3, $summary->eventsExamined);
        $this->assertSame(7, $summary->pagesFetched);
        $this->assertFalse($summary->pollFailed);
    }

    /** unmatchedCount alone cannot show a counter UPDATE that never fires (#2085). */
    public function testUnmatchedVerificationEventsIncrementTheCounterOnceEach(): void
    {
        $this->matchFor = static fn (): EmailEventMatch => EmailEventMatch::noUser();

        $summary = $this->makeJob([
            new FakeBrevoEvent(email: 'a@example.com', messageId: 'm1'),
            new FakeBrevoEvent(email: 'b@example.com', messageId: 'm2'),
            new FakeBrevoEvent(email: 'c@example.com', messageId: 'm3'),
        ], db: $db)->runNowWithSummary();

        $this->assertSame(3, $summary->unmatchedCount);
        $this->assertSame(0, $summary->matchedCount);
        $this->assertSame(3, $db->unmatchedCounterIncrementCalls());
    }

    /**
     * A counter failure must not abort the run, but counterFailureCount must
     * report it. After the first failure the increment is skipped (the fault is
     * in the settings row) while the tally keeps counting.
     */
    public function testUnmatchedRecipientCounterFailureIsReportedInSummary(): void
    {
        $this->matchFor = static fn (): EmailEventMatch => EmailEventMatch::noUser();

        $summary = $this->makeJob(
            [
                new FakeBrevoEvent(email: 'a@example.com', messageId: 'm1'),
                new FakeBrevoEvent(email: 'b@example.com', messageId: 'm2'),
            ],
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

    public function testUnmatchedRecipientCounterSuccessReportsNoFailures(): void
    {
        $this->matchFor = static fn (): EmailEventMatch => EmailEventMatch::noUser();

        $summary = $this->makeJob([
            new FakeBrevoEvent(email: 'a@example.com', messageId: 'm1'),
            new FakeBrevoEvent(email: 'b@example.com', messageId: 'm2'),
        ], db: $db)->runNowWithSummary();

        $this->assertSame(0, $summary->counterFailureCount);
        $this->assertSame(2, $summary->unmatchedCount);
        $this->assertSame(2, $db->unmatchedCounterIncrementCalls());
    }

    /**
     * A null poll returns a summary, not an exception (#2061). Pruning must
     * still run: a Brevo outage must not stop 24-month retention.
     */
    public function testPollFailureIsReflectedInTheSummaryWithoutThrowing(): void
    {
        $repo = $this->expectRepoCalls();
        $repo->expects($this->once())->method('deleteEmailEventsOlderThan')->willReturn(0);

        $summary = $this->makeJob(null, $client)->runNowWithSummary();

        $this->assertSame([], $this->matcherCalls);
        $this->assertSame(7, $client->fetchCalls, 'Every type is still polled');
        $this->assertTrue($summary->pollFailed);
        $this->assertSame(0, $summary->eventsExamined);
        $this->assertSame(0, $summary->matchedCount);
        $this->assertSame(0, $summary->unmatchedCount);
        $this->assertSame(0, $summary->skippedCount);
        $this->assertSame(0, $summary->invalidAddressCount);
        $this->assertSame(0, $summary->pagesFetched);
    }

    /** An all-zero summary would look like a successful empty run. */
    public function testRunNowWithSummaryLogsAndRethrowsAnUnexpectedFailure(): void
    {
        $this->expectRepoCalls()->expects($this->once())
            ->method('deleteEmailEventsOlderThan')
            ->willThrowException(new RuntimeException('unexpected prune failure for owner@example.com', 42));

        $job = $this->makeJob([]);

        try {
            $job->runNowWithSummary();
            $this->fail('An unexpected failure must not be reported as an empty successful run');
        } catch (RuntimeException $e) {
            $this->assertSame('unexpected prune failure for owner@example.com', $e->getMessage());
        }

        $log = $this->logsContaining('failed (manual run)');
        $this->assertNotEmpty($log);
        $this->assertSame(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, $log[0]['category']);
        $this->assertStringContainsString('RuntimeException (code 42)', $log[0]['message']);
        $this->assertStringNotContainsString('@', $log[0]['message'], 'No exception message in the log');
    }

    /**
     * Only the send batch honours the verification switch (#2358, #2359).
     * The backfill applies events and the prune runs while sends are off.
     */
    public function testRunNowWithSummaryAppliesABounceWhenVerificationSwitchIsOff(): void
    {
        $repo = $this->expectRepoCalls();
        $repo->expects($this->once())->method('deleteEmailEventsOlderThan')->willReturn(0);

        $summary = $this->makeJob(
            [new FakeBrevoEvent(event: 'hardBounces', tag: 'password_reset')],
            $client,
            verificationEnabled: false
        )->runNowWithSummary();

        $this->assertSame(7, $client->fetchCalls, 'The backfill must poll Brevo with the switch off');
        $this->assertSame(['hard_bounce'], array_column($this->applier->calls, 'event'));
        $this->assertSame(1, $summary->matchedCount);
    }

    public function testRunAppliesABounceWhenVerificationSwitchIsOff(): void
    {
        $repo = $this->expectRepoCalls();
        $repo->expects($this->once())->method('deleteEmailEventsOlderThan')->willReturn(0);

        $this->makeJob([new FakeBrevoEvent(event: 'hardBounces')], $client, verificationEnabled: false)->run();

        $this->assertSame(7, $client->fetchCalls, 'run() must reach execute() with the switch off');
        $this->assertSame(['hard_bounce'], array_column($this->applier->calls, 'event'));
    }

    /** The prune is a separate step: a crash in the backfill must not stop it. */
    public function testPruneRunsAfterAFailedBackfillAndTheFailureStillReachesRun(): void
    {
        $this->mockRepo = $this->createMock(CarRepository::class);
        $this->mockRepo->expects($this->once())->method('deleteEmailEventsOlderThan')->willReturn(0);
        // A non-database exception escapes applyEvent()'s catch, so it is a crash of the backfill.
        $this->applier->failWith(new RuntimeException('backfill crashed for owner@example.com'));

        $this->makeJob([new FakeBrevoEvent()])->run();

        $log = $this->logsContaining('failed: RuntimeException (code 0)');
        $this->assertNotEmpty($log, 'The backfill failure must still be logged by run() after the prune');
        $this->assertStringNotContainsString('@', $log[0]['message']);
    }

    public function testRunNowWithSummaryPrunesAfterAFailedBackfillAndRethrows(): void
    {
        $this->mockRepo = $this->createMock(CarRepository::class);
        $this->mockRepo->expects($this->once())->method('deleteEmailEventsOlderThan')->willReturn(0);
        $this->applier->failWith(new RuntimeException('backfill crashed'));

        try {
            $this->makeJob([new FakeBrevoEvent()])->runNowWithSummary();
            $this->fail('The backfill failure must be rethrown');
        } catch (RuntimeException $e) {
            $this->assertSame('backfill crashed', $e->getMessage());
        }
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
