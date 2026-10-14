<?php

declare(strict_types=1);

use ElanRegistry\Car\BrevoWebhookEventProcessor;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventMatch;
use ElanRegistry\Car\EmailEventMatcher;
use ElanRegistry\Car\ProcessingResult;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\SpyEmailEventApplier;

/**
 * Unit tests for BrevoWebhookEventProcessor.
 *
 * The matcher is a mock and the applier is SpyEmailEventApplier, so these
 * tests cover only the processor: parsing, the event and address checks,
 * the dispatch on the match result, the time fallback, the plugin call and
 * the log lines. EmailEventApplierTest covers the escalation rules, and
 * EmailEventMatcherTest covers the matching rule.
 *
 * The plugin function tests run in a separate process, because a global
 * function cannot be removed once defined.
 */
#[Group('fast')]
final class BrevoWebhookEventProcessorTest extends TestCase
{
    private const ADDRESS = 'owner@example.com';

    /** @var EmailEventMatcher&\PHPUnit\Framework\MockObject\MockObject */
    private EmailEventMatcher $matcher;

    private SpyEmailEventApplier $applier;

    private BrevoWebhookEventProcessor $processor;

    protected function setUp(): void
    {
        $GLOBALS['mockLogEntries'] = [];
        $this->matcher = $this->createMock(EmailEventMatcher::class);
        $this->applier = new SpyEmailEventApplier();
        $this->processor = new BrevoWebhookEventProcessor($this->matcher, $this->applier);
    }

    /** @param array<string, mixed> $overrides */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'email' => self::ADDRESS,
            'event' => 'delivered',
            'message-id' => 'brevo-msg-1',
        ], $overrides);
    }

    private function matchUser(int $userId = 42): void
    {
        $this->matcher->expects($this->once())
            ->method('matchByMessageId')
            ->willReturn(EmailEventMatch::matched($userId, null, '2026-10-01 00:00:00'));
    }

    private function expectNoMatch(): void
    {
        $this->matcher->expects($this->never())->method('matchByMessageId');
    }

    /** @return list<string> */
    private function logMessages(): array
    {
        return array_map(static fn (array $entry): string => (string) $entry['message'], $GLOBALS['mockLogEntries'] ?? []);
    }

    private function assertNoLogHolds(string $needle): void
    {
        foreach ($this->logMessages() as $message) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $message);
        }
    }

    // --- Malformed payloads --------------------------------------------

    public function testListBodyIsMalformed(): void
    {
        $this->expectNoMatch();

        $this->assertSame(ProcessingResult::MALFORMED, $this->processor->process([1, 2, 3]));
        $this->assertSame([], $this->applier->calls);
    }

    public function testEmptyArrayIsMalformed(): void
    {
        $this->expectNoMatch();

        $this->assertSame(ProcessingResult::MALFORMED, $this->processor->process([]));
    }

    public function testNonArrayPayloadIsMalformed(): void
    {
        $this->expectNoMatch();

        $this->assertSame(ProcessingResult::MALFORMED, $this->processor->process('not an array'));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function missingOrEmptyRequiredFieldProvider(): array
    {
        return [
            'missing email' => [['event' => 'delivered', 'message-id' => 'm1']],
            'empty string email' => [['email' => '', 'event' => 'delivered', 'message-id' => 'm1']],
            'missing event' => [['email' => 'a@example.com', 'message-id' => 'm1']],
            'empty string event' => [['email' => 'a@example.com', 'event' => '', 'message-id' => 'm1']],
            'missing message-id' => [['email' => 'a@example.com', 'event' => 'delivered']],
            'empty string message-id' => [['email' => 'a@example.com', 'event' => 'delivered', 'message-id' => '']],
            'non-string email' => [['email' => 123, 'event' => 'delivered', 'message-id' => 'm1']],
            'non-string event' => [['email' => 'a@example.com', 'event' => 42, 'message-id' => 'm1']],
            'non-string message-id' => [['email' => 'a@example.com', 'event' => 'delivered', 'message-id' => 42]],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('missingOrEmptyRequiredFieldProvider')]
    public function testMissingOrEmptyRequiredFieldIsMalformed(array $payload): void
    {
        $this->expectNoMatch();

        $this->assertSame(ProcessingResult::MALFORMED, $this->processor->process($payload));
        $this->assertSame([], $this->applier->calls);
    }

    public function testOversizedMessageIdReturnsMalformedNotWriteFailure(): void
    {
        // er_email_events.brevo_message_id is varchar(255). A longer value
        // would throw in the insert, map to a retryable 5xx and loop forever.
        $this->expectNoMatch();

        $result = $this->processor->process($this->basePayload(['message-id' => str_repeat('x', 256)]));

        $this->assertSame(ProcessingResult::MALFORMED, $result);
    }

    // --- Address validation (R5-SR6) ----------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function badAddressProvider(): array
    {
        $longLocal = str_repeat('a', 64);

        return [
            'more than 155 bytes' => [$longLocal . '@' . str_repeat('b', 60) . '.' . str_repeat('c', 30) . '.example.com'],
            'no at sign' => ['not-an-address'],
            'two addresses' => ['a@example.com,b@example.com'],
            'form feed only' => ["\f"],
        ];
    }

    #[DataProvider('badAddressProvider')]
    public function testBadAddressIsMalformedBeforeAnyMatch(string $address): void
    {
        $this->expectNoMatch();

        $result = $this->processor->process($this->basePayload(['email' => $address, 'event' => 'hard_bounce']));

        $this->assertSame(ProcessingResult::MALFORMED, $result);
        $this->assertSame([], $this->applier->calls);
    }

    public function testAddressWithOuterWhitespaceIsTrimmedAndAccepted(): void
    {
        $this->matcher->expects($this->once())
            ->method('matchByMessageId')
            ->with('brevo-msg-1', self::ADDRESS)
            ->willReturn(EmailEventMatch::matched(42, null, '2026-10-01 00:00:00'));

        $result = $this->processor->process($this->basePayload(['email' => '  ' . self::ADDRESS . "\t"]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $this->assertSame(self::ADDRESS, $this->applier->calls[0]['email']);
    }

    // --- Event filter -------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function deliveryEventProvider(): array
    {
        return [
            'delivered' => ['delivered'],
            'soft_bounce' => ['soft_bounce'],
            'hard_bounce' => ['hard_bounce'],
            'blocked' => ['blocked'],
            'invalid' => ['invalid'],
            'invalid_email' => ['invalid_email'],
            'spam' => ['spam'],
            'unsubscribed' => ['unsubscribed'],
        ];
    }

    #[DataProvider('deliveryEventProvider')]
    public function testDeliveryEventIsAppliedInEventModeWithWebhookSource(string $event): void
    {
        $this->matchUser(42);

        $result = $this->processor->process($this->basePayload(['event' => $event]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $this->assertCount(1, $this->applier->calls);
        $call = $this->applier->calls[0];
        $this->assertSame(42, $call['userId']);
        $this->assertSame(self::ADDRESS, $call['email']);
        $this->assertSame($event, $call['event']);
        $this->assertSame('brevo-msg-1', $call['messageId']);
        $this->assertSame('webhook', $call['source']);
        $this->assertSame(EmailEventApplier::MODE_EVENT, $call['mode']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonDeliveryEventProvider(): array
    {
        return [
            'opened' => ['opened'],
            'unique_opened' => ['unique_opened'],
            'click' => ['click'],
            'request' => ['request'],
            'oversized' => [str_repeat('x', 33)],
        ];
    }

    #[DataProvider('nonDeliveryEventProvider')]
    public function testNonDeliveryEventIsIgnoredWithNoMatchNoRowAndNoLog(string $event): void
    {
        $this->expectNoMatch();

        $result = $this->processor->process($this->basePayload(['event' => $event]));

        $this->assertSame(ProcessingResult::IGNORED, $result);
        $this->assertSame([], $this->applier->calls);
        $this->assertSame([], $this->logMessages());
    }

    // --- No tag filter (#2359) ----------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function anyTagsProvider(): array
    {
        return [
            'no tags key' => [[]],
            'other tag' => [['tags' => ['password_reset']]],
            'verification tag' => [['tags' => ['car_verification']]],
            'tags not a list' => [['tags' => 'car_verification']],
        ];
    }

    /** @param array<string, mixed> $tags */
    #[DataProvider('anyTagsProvider')]
    public function testNoTagIsNeededToRecordAnEvent(array $tags): void
    {
        $this->matchUser(42);

        $result = $this->processor->process($this->basePayload(['event' => 'hard_bounce'] + $tags));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $this->assertCount(1, $this->applier->calls);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasVerificationTag(): void
    {
        $this->assertTrue(BrevoWebhookEventProcessor::hasVerificationTag(['tags' => ['x', 'car_verification']]));
        $this->assertFalse(BrevoWebhookEventProcessor::hasVerificationTag(['tags' => ['password_reset']]));
        $this->assertFalse(BrevoWebhookEventProcessor::hasVerificationTag(['tags' => 'car_verification']));
        $this->assertFalse(BrevoWebhookEventProcessor::hasVerificationTag([]));
        $this->assertFalse(BrevoWebhookEventProcessor::hasVerificationTag(null));
    }

    // --- Match results --------------------------------------------------------

    public function testMessageNotOursIsIgnoredWithNoRowAndNoLog(): void
    {
        $this->matcher->expects($this->once())->method('matchByMessageId')->willReturn(EmailEventMatch::notOurs());

        $result = $this->processor->process($this->basePayload(['event' => 'hard_bounce']));

        $this->assertSame(ProcessingResult::IGNORED, $result);
        $this->assertSame([], $this->applier->calls);
        $this->assertSame([], $this->logMessages());
    }

    public function testNoUserReturnsNoUserMatchAndLogsTheHashPrefixOnly(): void
    {
        $this->matcher->expects($this->once())->method('matchByMessageId')->willReturn(EmailEventMatch::noUser());

        $result = $this->processor->process($this->basePayload(['event' => 'hard_bounce', 'email' => 'Owner@Example.com']));

        $this->assertSame(ProcessingResult::NO_USER_MATCH, $result);
        $this->assertSame([], $this->applier->calls);
        $this->assertCount(1, $this->logMessages());
        $this->assertStringContainsString(substr(hash('sha256', self::ADDRESS), 0, 8), $this->logMessages()[0]);
        $this->assertNoLogHolds(self::ADDRESS);
    }

    public function testMatcherFailureIsWriteFailureAndLogsNoAddress(): void
    {
        $this->matcher->expects($this->once())
            ->method('matchByMessageId')
            ->willThrowException(new CarDatabaseException('lookup failed for ' . self::ADDRESS));

        $result = $this->processor->process($this->basePayload());

        $this->assertSame(ProcessingResult::WRITE_FAILURE, $result);
        $this->assertSame([], $this->applier->calls);
        $this->assertCount(1, $this->logMessages());
        $this->assertStringContainsString(CarDatabaseException::class, $this->logMessages()[0]);
        $this->assertNoLogHolds(self::ADDRESS);
    }

    public function testApplierFailureIsWriteFailureAndLogsTheUserIdButNoAddress(): void
    {
        $this->matchUser(77);
        $this->applier->failWith(new CarDatabaseException('insert failed for ' . self::ADDRESS));

        $result = $this->processor->process($this->basePayload(['event' => 'hard_bounce', 'ts_event' => 1_700_000_000]));

        $this->assertSame(ProcessingResult::WRITE_FAILURE, $result);
        $this->assertCount(1, $this->logMessages());
        $this->assertStringContainsString('user 77', $this->logMessages()[0]);
        $this->assertStringContainsString(CarDatabaseException::class, $this->logMessages()[0]);
        $this->assertNoLogHolds(self::ADDRESS);
    }

    /** @return array<string, array{\Throwable}> */
    public static function dataFaultProvider(): array
    {
        return [
            'unparseable message time' => [new CarValidationException('bad time for ' . self::ADDRESS)],
            'empty time or bad id'     => [new \InvalidArgumentException('bad match for ' . self::ADDRESS)],
        ];
    }

    /** A data fault fails on every retry, so a 5xx would make Brevo resend it forever. */
    public function testMatcherDataFaultIsMalformedAndLogsTheHashButNoAddress(): void
    {
        $fault = new \InvalidArgumentException('bad match for ' . self::ADDRESS);
        $this->matcher->expects($this->once())->method('matchByMessageId')->willThrowException($fault);

        $result = $this->processor->process($this->basePayload());

        $this->assertSame(ProcessingResult::MALFORMED, $result);
        $this->assertSame([], $this->applier->calls);
        $this->assertCount(1, $this->logMessages());
        $this->assertStringContainsString(get_class($fault), $this->logMessages()[0]);
        $this->assertStringContainsString(substr(hash('sha256', self::ADDRESS), 0, 8), $this->logMessages()[0]);
        $this->assertNoLogHolds(self::ADDRESS);
        $this->assertNoLogHolds('bad ');
    }

    #[DataProvider('dataFaultProvider')]
    public function testApplierDataFaultIsMalformedAndLogsTheUserIdButNoAddress(\Throwable $fault): void
    {
        $this->matchUser(77);
        $this->applier->failWith($fault);

        $result = $this->processor->process($this->basePayload(['event' => 'hard_bounce', 'ts_event' => 1_700_000_000]));

        $this->assertSame(ProcessingResult::MALFORMED, $result);
        $this->assertCount(1, $this->logMessages());
        $this->assertStringContainsString('user 77', $this->logMessages()[0]);
        $this->assertStringContainsString(get_class($fault), $this->logMessages()[0]);
        $this->assertNoLogHolds(self::ADDRESS);
        $this->assertNoLogHolds('bad ');
    }

    // --- Reason field handling -------------------------------------------

    public function testReasonFieldPassedThroughWhenPresentAndString(): void
    {
        $this->matchUser();

        $this->processor->process($this->basePayload(['event' => 'hard_bounce', 'reason' => 'Mailbox full']));

        $this->assertSame('Mailbox full', $this->applier->calls[0]['reason']);
    }

    public function testNonStringReasonBecomesNull(): void
    {
        $this->matchUser();

        $this->processor->process($this->basePayload(['reason' => ['not', 'a', 'string']]));

        $this->assertNull($this->applier->calls[0]['reason']);
    }

    // --- occurred_at resolution -------------------------------------------

    public function testOccurredAtResolvesFromTsEvent(): void
    {
        $this->matchUser();
        $ts = 1_700_000_000;

        $this->processor->process($this->basePayload(['ts_event' => $ts]));

        $this->assertSame(date('Y-m-d H:i:s', $ts), $this->applier->calls[0]['occurredAt']);
        $this->assertSame([], $this->logMessages());
    }

    public function testOccurredAtFallsBackToTsWhenTsEventAbsent(): void
    {
        $this->matchUser();
        $ts = 1_700_000_100;

        $this->processor->process($this->basePayload(['ts' => (string) $ts]));

        $this->assertSame(date('Y-m-d H:i:s', $ts), $this->applier->calls[0]['occurredAt']);
    }

    public function testMissingTimeUsesReceiptTimeAndLogsTheUserIdOnly(): void
    {
        $this->matchUser(42);
        $before = time();

        $this->processor->process($this->basePayload());

        $occurredAt = strtotime($this->applier->calls[0]['occurredAt']);
        $this->assertGreaterThanOrEqual($before, $occurredAt);
        $this->assertLessThanOrEqual(time(), $occurredAt);
        $this->assertCount(1, $this->logMessages());
        $this->assertStringContainsString('user 42', $this->logMessages()[0]);
        $this->assertNoLogHolds(self::ADDRESS);
    }

    public function testTsEventMoreThanFiveMinutesAfterReceiptIsTreatedAsMissing(): void
    {
        $this->matchUser(42);
        $before = time();

        $this->processor->process($this->basePayload(['ts_event' => $before + 3600]));

        $occurredAt = strtotime($this->applier->calls[0]['occurredAt']);
        $this->assertLessThanOrEqual(time(), $occurredAt, 'A far-future ts_event must not become occurred_at');
        $this->assertGreaterThanOrEqual($before, $occurredAt);
        $this->assertCount(1, $this->logMessages());
        $this->assertStringContainsString('more than 5 minutes after receipt', $this->logMessages()[0]);
        $this->assertStringContainsString('user 42', $this->logMessages()[0]);
        $this->assertNoLogHolds(self::ADDRESS);
    }

    public function testTsEventWithinFiveMinutesAfterReceiptIsKept(): void
    {
        $this->matchUser();
        $ts = time() + 120;

        $this->processor->process($this->basePayload(['ts_event' => $ts]));

        $this->assertSame(date('Y-m-d H:i:s', $ts), $this->applier->calls[0]['occurredAt']);
    }

    // --- sendinblueRecordEvent() (#2355) -------------------------------------

    public function testWithoutThePluginFunctionNothingIsLoggedAboutIt(): void
    {
        $this->assertFalse(function_exists('sendinblueRecordEvent'), 'Test setup: the main process must not define the stub');
        $this->matchUser();

        $result = $this->processor->process($this->basePayload(['ts_event' => 1_700_000_000]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $this->assertSame([], $this->logMessages());
    }

    #[RunInSeparateProcess]
    public function testPluginFunctionGetsTheMessageIdEventIntegerTimeAndReason(): void
    {
        require_once __DIR__ . '/fixtures/sendinblue-record-event.php';
        $GLOBALS['mockRecordEventCalls'] = [];
        $GLOBALS['mockRecordEventMode'] = 'ok';
        $this->matchUser();

        $result = $this->processor->process($this->basePayload([
            'event' => 'hard_bounce',
            'reason' => 'Mailbox full',
            'ts_event' => 1_700_000_000,
        ]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $this->assertSame([['brevo-msg-1', 'hard_bounce', 1_700_000_000, 'Mailbox full']], $GLOBALS['mockRecordEventCalls']);
        $this->assertSame([], $this->logMessages());
    }

    #[RunInSeparateProcess]
    public function testPluginFunctionIsCalledForAnEventWithNoUser(): void
    {
        require_once __DIR__ . '/fixtures/sendinblue-record-event.php';
        $GLOBALS['mockRecordEventCalls'] = [];
        $GLOBALS['mockRecordEventMode'] = 'ok';
        $this->matcher->expects($this->once())->method('matchByMessageId')->willReturn(EmailEventMatch::noUser());

        $result = $this->processor->process($this->basePayload(['event' => 'hard_bounce', 'ts_event' => 1_700_000_000]));

        $this->assertSame(ProcessingResult::NO_USER_MATCH, $result);
        $this->assertSame([['brevo-msg-1', 'hard_bounce', 1_700_000_000, '']], $GLOBALS['mockRecordEventCalls']);
    }

    #[RunInSeparateProcess]
    public function testPluginFunctionIsNotCalledForAMessageNotOurs(): void
    {
        require_once __DIR__ . '/fixtures/sendinblue-record-event.php';
        $GLOBALS['mockRecordEventCalls'] = [];
        $this->matcher->expects($this->once())->method('matchByMessageId')->willReturn(EmailEventMatch::notOurs());

        $this->assertSame(ProcessingResult::IGNORED, $this->processor->process($this->basePayload()));
        $this->assertSame([], $GLOBALS['mockRecordEventCalls']);
    }

    #[RunInSeparateProcess]
    public function testPluginFunctionFalseIsLoggedAndTheEventStillCounts(): void
    {
        require_once __DIR__ . '/fixtures/sendinblue-record-event.php';
        $GLOBALS['mockRecordEventCalls'] = [];
        $GLOBALS['mockRecordEventMode'] = 'false';
        $this->matchUser();

        $result = $this->processor->process($this->basePayload(['ts_event' => 1_700_000_000]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $this->assertCount(1, $this->logMessages());
        $this->assertStringContainsString('returned false', $this->logMessages()[0]);
    }

    #[RunInSeparateProcess]
    public function testPluginFunctionThrowIsLoggedByClassAndCodeOnlyAndTheEventStillCounts(): void
    {
        require_once __DIR__ . '/fixtures/sendinblue-record-event.php';
        $GLOBALS['mockRecordEventCalls'] = [];
        $GLOBALS['mockRecordEventMode'] = 'throw';
        $this->matchUser();

        $result = $this->processor->process($this->basePayload(['ts_event' => 1_700_000_000]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $this->assertCount(1, $this->logMessages());
        $this->assertStringContainsString('RuntimeException', $this->logMessages()[0]);
        $this->assertStringContainsString('code 42', $this->logMessages()[0]);
        $this->assertNoLogHolds('leak@example.com');
    }
}
