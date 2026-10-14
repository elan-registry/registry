<?php

declare(strict_types=1);

use ElanRegistry\Car\BrevoWebhookEventProcessor;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\ProcessingResult;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BrevoWebhookEventProcessor.
 *
 * Mocks CarRepository and CarVerificationManager entirely — this class owns
 * only parsing/matching/dispatch logic, no HTTP or real DB concerns (see its
 * class docblock). Follows the mocking conventions established by
 * CarVerificationManagerTest.php (createMock, ->expects()->method()->with()).
 *
 * EmailEventApplier is injected as a REAL collaborator built over those same
 * two mocks, not as a mock of its own: the escalation mapping it owns is the
 * externally-observable contract of this endpoint, and these tests assert it
 * end-to-end through process(). EmailEventApplierTest covers the same mapping
 * directly, at the unit it now lives in.
 *
 * NOTE on the "retried identical local 'sent' row" dedup scenario from the
 * plan's Test Plan: insertOwnerEmailEvent() is mocked at this tier, so there is
 * nothing for a unit test to assert about MySQL's ON DUPLICATE KEY UPDATE
 * behavior — that is real-database behavior, covered instead at the
 * integration tier by BrevoWebhookEndpointTest's duplicate-delivery test.
 */
#[Group('fast')]
final class BrevoWebhookEventProcessorTest extends TestCase
{
    /** @var CarRepository&\PHPUnit\Framework\MockObject\MockObject */
    private CarRepository $mockRepo;

    /** @var CarVerificationManager&\PHPUnit\Framework\MockObject\MockObject */
    private CarVerificationManager $mockManager;

    private BrevoWebhookEventProcessor $processor;

    protected function setUp(): void
    {
        $this->mockRepo = $this->createMock(CarRepository::class);
        $this->mockManager = $this->createMock(CarVerificationManager::class);
        $this->processor = new BrevoWebhookEventProcessor(
            $this->mockRepo,
            new EmailEventApplier($this->mockRepo, $this->mockManager)
        );
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'owner@example.com',
            'event' => 'delivered',
            'message-id' => 'brevo-msg-1',
            'tags' => ['car_verification'],
        ], $overrides);
    }

    // --- Malformed payloads --------------------------------------------

    public function testListBodyIsMalformed(): void
    {
        $this->mockRepo->expects($this->never())->method('findByEmail');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process([1, 2, 3]);

        $this->assertSame(ProcessingResult::MALFORMED, $result);
    }

    public function testEmptyArrayIsMalformed(): void
    {
        $this->mockRepo->expects($this->never())->method('findByEmail');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process([]);

        $this->assertSame(ProcessingResult::MALFORMED, $result);
    }

    public function testNonArrayPayloadIsMalformed(): void
    {
        $this->mockRepo->expects($this->never())->method('findByEmail');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process('not an array');

        $this->assertSame(ProcessingResult::MALFORMED, $result);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function missingOrEmptyRequiredFieldProvider(): array
    {
        return [
            'missing email' => [
                ['event' => 'delivered', 'message-id' => 'm1', 'tags' => ['car_verification']],
            ],
            'empty string email' => [
                ['email' => '', 'event' => 'delivered', 'message-id' => 'm1', 'tags' => ['car_verification']],
            ],
            'missing event' => [
                ['email' => 'a@example.com', 'message-id' => 'm1', 'tags' => ['car_verification']],
            ],
            'empty string event' => [
                ['email' => 'a@example.com', 'event' => '', 'message-id' => 'm1', 'tags' => ['car_verification']],
            ],
            'missing message-id' => [
                ['email' => 'a@example.com', 'event' => 'delivered', 'tags' => ['car_verification']],
            ],
            'empty string message-id' => [
                ['email' => 'a@example.com', 'event' => 'delivered', 'message-id' => '', 'tags' => ['car_verification']],
            ],
            'non-string email' => [
                ['email' => 123, 'event' => 'delivered', 'message-id' => 'm1', 'tags' => ['car_verification']],
            ],
            'non-string event' => [
                ['email' => 'a@example.com', 'event' => 42, 'message-id' => 'm1', 'tags' => ['car_verification']],
            ],
            'non-string message-id' => [
                ['email' => 'a@example.com', 'event' => 'delivered', 'message-id' => 42, 'tags' => ['car_verification']],
            ],
        ];
    }

    #[DataProvider('missingOrEmptyRequiredFieldProvider')]
    public function testMissingOrEmptyRequiredFieldIsMalformed(array $payload): void
    {
        $this->mockRepo->expects($this->never())->method('findByEmail');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($payload);

        $this->assertSame(ProcessingResult::MALFORMED, $result);
    }

    // --- Tag filtering ----------------------------------------------------

    public function testUntaggedPayloadReturnsNoTagMatch(): void
    {
        $this->mockRepo->expects($this->never())->method('findByEmail');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload(['tags' => ['unrelated_tag']]));

        $this->assertSame(ProcessingResult::NO_TAG_MATCH, $result);
    }

    public function testMissingTagsKeyReturnsNoTagMatch(): void
    {
        $payload = $this->basePayload();
        unset($payload['tags']);

        $this->mockRepo->expects($this->never())->method('findByEmail');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($payload);

        $this->assertSame(ProcessingResult::NO_TAG_MATCH, $result);
    }

    public function testOversizedEventNameReturnsMalformedNotWriteFailure(): void
    {
        // er_email_events.event is varchar(32) under STRICT_TRANS_TABLES — an
        // unbounded value would throw a CarDatabaseException from
        // insertEmailEvent(), mapping to WRITE_FAILURE/5xx and putting Brevo
        // into a permanent retry loop on this one poisoned payload. Rejecting
        // it here as MALFORMED (4xx, no retry) before any DB call is the fix.
        $this->mockRepo->expects($this->never())->method('findByEmail');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload(['event' => str_repeat('x', 33)]));

        $this->assertSame(ProcessingResult::MALFORMED, $result);
    }

    public function testEventNameAtExactColumnWidthIsAccepted(): void
    {
        $car = (object) ['id' => 1, 'user_id' => 1, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())->method('insertOwnerEmailEvent')->willReturn(1);
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload(['event' => str_repeat('x', 32)]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    public function testOversizedMessageIdReturnsMalformedNotWriteFailure(): void
    {
        // er_email_events.brevo_message_id is varchar(255) — same failure
        // mode and same fix as the oversized event name above.
        $this->mockRepo->expects($this->never())->method('findByEmail');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload(['message-id' => str_repeat('x', 256)]));

        $this->assertSame(ProcessingResult::MALFORMED, $result);
    }

    public function testNonArrayTagsReturnsMalformed(): void
    {
        // A non-list `tags` means Brevo's payload contract changed, not that
        // this event is untagged — MALFORMED (a 4xx an operator will notice)
        // rather than NO_TAG_MATCH (a routine, unremarkable 200) is the
        // correct outcome here, since the latter would silently discard
        // every subsequent event under a contract change with no visible signal.
        $this->mockRepo->expects($this->never())->method('findByEmail');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload(['tags' => 'car_verification']));

        $this->assertSame(ProcessingResult::MALFORMED, $result);
    }

    // --- Car matching -------------------------------------------------

    public function testTaggedPayloadWithNoCarMatchReturnsNoCarMatch(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('findByEmail')
            ->with('owner@example.com')
            ->willReturn([]);
        $this->mockRepo->expects($this->never())->method('insertOwnerEmailEvent');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload());

        $this->assertSame(ProcessingResult::NO_CAR_MATCH, $result);
    }

    public function testFindByEmailThrowingCarDatabaseExceptionReturnsWriteFailure(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('findByEmail')
            ->willThrowException(new CarDatabaseException('connection lost'));
        $this->mockRepo->expects($this->never())->method('insertOwnerEmailEvent');
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload());

        $this->assertSame(ProcessingResult::WRITE_FAILURE, $result);
    }

    // --- Hard-bounce-class events ---------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function hardBounceClassEventProvider(): array
    {
        return [
            'hard_bounce' => ['hard_bounce'],
            'blocked' => ['blocked'],
            'invalid' => ['invalid'],
            // Never actually observed live (#1871 spike) — both spellings
            // are accepted since EMAIL_SYSTEM.md's own docs and this
            // project's issue text disagree on which one Brevo really sends.
            'invalid_email' => ['invalid_email'],
        ];
    }

    #[DataProvider('hardBounceClassEventProvider')]
    public function testHardBounceClassEventCallsSetBouncedFromEventWithOwnerIdEmailAndWebhookSource(string $event): void
    {
        $car = (object) ['id' => 42, 'user_id' => 42, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())
            ->method('insertOwnerEmailEvent')
            ->with(42, 'owner@example.com', $event, null, 'brevo-msg-1', $this->isString());

        $this->mockManager->expects($this->once())
            ->method('setBouncedFromEvent')
            ->with(42, 'owner@example.com', $this->isString(), 'webhook')
            ->willReturn(true);
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload(['event' => $event]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    // --- Spam --------------------------------------------------------

    public function testSpamCallsSetSuppressedFromEventWithCauseSpamNotSetBounced(): void
    {
        $car = (object) ['id' => 7, 'user_id' => 7, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())
            ->method('insertOwnerEmailEvent')
            ->with(7, 'owner@example.com', 'spam', null, 'brevo-msg-1', $this->isString());

        $this->mockManager->expects($this->once())
            ->method('setSuppressedFromEvent')
            ->with(7, 'owner@example.com', 'spam', $this->isString(), 'webhook')
            ->willReturn(true);
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');

        // No 'reason' field required for spam, per spike.
        $payload = $this->basePayload(['event' => 'spam']);
        unset($payload['reason']);

        $result = $this->processor->process($payload);

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    // --- Soft bounce escalation -----------------------------------------

    public function testSoftBounceBelowThresholdDoesNotEscalate(): void
    {
        $car = (object) ['id' => 3, 'user_id' => 3, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())->method('insertOwnerEmailEvent')->willReturn(1);
        $this->mockRepo->expects($this->once())
            ->method('countSoftBouncesSinceLastDelivered')
            ->with(3, 'owner@example.com')
            ->willReturn(2);

        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload(['event' => 'soft_bounce']));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    public function testSoftBounceAtThresholdEscalatesToSetBounced(): void
    {
        $car = (object) ['id' => 3, 'user_id' => 3, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())->method('insertOwnerEmailEvent')->willReturn(1);
        $this->mockRepo->expects($this->once())
            ->method('countSoftBouncesSinceLastDelivered')
            ->willReturn(3);

        $this->mockManager->expects($this->once())
            ->method('setBouncedFromEvent')
            ->with(3, 'owner@example.com', $this->isString(), 'webhook')
            ->willReturn(true);

        $result = $this->processor->process($this->basePayload(['event' => 'soft_bounce']));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    public function testSoftBounceAboveThresholdEscalatesToSetBounced(): void
    {
        $car = (object) ['id' => 3, 'user_id' => 3, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())->method('insertOwnerEmailEvent')->willReturn(1);
        $this->mockRepo->expects($this->once())
            ->method('countSoftBouncesSinceLastDelivered')
            ->willReturn(5);

        $this->mockManager->expects($this->once())->method('setBouncedFromEvent')->willReturn(true);

        $result = $this->processor->process($this->basePayload(['event' => 'soft_bounce']));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    // --- Inert events: delivered / unique_opened -------------------------

    public function testDeliveredRecordsEventRowOnlyNoException(): void
    {
        $car = (object) ['id' => 9, 'user_id' => 9, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())->method('insertOwnerEmailEvent')->willReturn(1);
        $this->mockRepo->expects($this->never())->method('countSoftBouncesSinceLastDelivered');

        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload(['event' => 'delivered']));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    /**
     * unique_opened must never touch flags or the escalation count, even
     * "after prior soft bounces" — since this is a unit test with a mocked
     * repo, that scenario collapses to: the processor itself never calls
     * countSoftBouncesSinceLastDelivered/setBouncedFromEvent/setSuppressedFromEvent for this
     * event type. The "since last delivered" windowing logic that would
     * otherwise matter here is countSoftBouncesSinceLastDelivered()'s own
     * job, tested at the repository/integration tier, not re-tested here.
     */
    public function testUniqueOpenedNeverTouchesFlagsOrEscalationCount(): void
    {
        $car = (object) ['id' => 11, 'user_id' => 11, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())->method('insertOwnerEmailEvent')->willReturn(1);
        $this->mockRepo->expects($this->never())->method('countSoftBouncesSinceLastDelivered');

        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload(['event' => 'unique_opened']));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    // --- One owner, several cars (#2358) ----------------------------------

    /**
     * An owner with three cars is one event for one owner. The webhook cannot
     * tell which car the email was for, so the row has no car.
     */
    public function testOwnerWithThreeCarsGetsOneApplyCallAndOneEventRow(): void
    {
        $cars = [
            (object) ['id' => 100, 'user_id' => 55, 'email' => 'shared@example.com'],
            (object) ['id' => 101, 'user_id' => 55, 'email' => 'shared@example.com'],
            (object) ['id' => 102, 'user_id' => 55, 'email' => 'shared@example.com'],
        ];
        $this->mockRepo->expects($this->once())
            ->method('findByEmail')
            ->with('shared@example.com')
            ->willReturn($cars);
        $this->mockRepo->expects($this->once())
            ->method('insertOwnerEmailEvent')
            ->with(55, 'shared@example.com', 'hard_bounce', null, 'shared-msg-1', $this->isString())
            ->willReturn(1);
        $this->mockManager->expects($this->once())
            ->method('setBouncedFromEvent')
            ->with(55, 'shared@example.com', $this->isString(), 'webhook')
            ->willReturn(true);

        $result = $this->processor->process([
            'email' => 'shared@example.com',
            'event' => 'hard_bounce',
            'message-id' => 'shared-msg-1',
            'tags' => ['car_verification'],
        ]);

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    /**
     * findByEmail() can return two owners for one address. Each owner gets
     * its own call, with the same message ID.
     */
    public function testTwoOwnersOnOneAddressGetOneApplyCallEach(): void
    {
        $cars = [
            (object) ['id' => 200, 'user_id' => 61, 'email' => 'shared2@example.com'],
            (object) ['id' => 201, 'user_id' => 62, 'email' => 'shared2@example.com'],
            (object) ['id' => 202, 'user_id' => 61, 'email' => 'shared2@example.com'],
        ];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn($cars);

        $inserts = [];
        $this->mockRepo->expects($this->exactly(2))
            ->method('insertOwnerEmailEvent')
            ->willReturnCallback(function (int $userId, string $email, string $event, ?string $reason, string $messageId) use (&$inserts) {
                $inserts[] = [$userId, $messageId];
                return 1;
            });
        $this->mockManager->expects($this->exactly(2))
            ->method('setBouncedFromEvent')
            ->willReturn(true);

        $result = $this->processor->process([
            'email' => 'shared2@example.com',
            'event' => 'hard_bounce',
            'message-id' => 'shared2-msg-1',
            'tags' => ['car_verification'],
        ]);

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $this->assertSame([[61, 'shared2-msg-1'], [62, 'shared2-msg-1']], $inserts);
    }

    // --- Mid-loop write failure -------------------------------------------

    public function testMidLoopWriteFailureReturnsWriteFailureAndStopsProcessingRemainingOwners(): void
    {
        $cars = [
            (object) ['id' => 1, 'user_id' => 71, 'email' => 'shared3@example.com'],
            (object) ['id' => 2, 'user_id' => 72, 'email' => 'shared3@example.com'],
            (object) ['id' => 3, 'user_id' => 73, 'email' => 'shared3@example.com'],
        ];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn($cars);

        // The first owner succeeds, the second owner's insert throws, and the
        // third owner must never be reached.
        $callCount = 0;
        $this->mockRepo->expects($this->exactly(2))
            ->method('insertOwnerEmailEvent')
            ->willReturnCallback(function (int $userId) use (&$callCount) {
                $callCount++;
                if ($userId === 72) {
                    throw new CarDatabaseException('write failed for owner 72');
                }
                return 1;
            });

        $this->mockManager->expects($this->once())
            ->method('setBouncedFromEvent')
            ->with(71, 'shared3@example.com', $this->isString(), 'webhook')
            ->willReturn(true);

        $result = $this->processor->process([
            'email' => 'shared3@example.com',
            'event' => 'hard_bounce',
            'message-id' => 'shared3-msg-1',
            'tags' => ['car_verification'],
        ]);

        $this->assertSame(ProcessingResult::WRITE_FAILURE, $result);
        $this->assertSame(2, $callCount, 'The third owner must never be processed after the second owner throws');
    }

    public function testMidLoopFailureInManagerCallReturnsWriteFailure(): void
    {
        $cars = [
            (object) ['id' => 1, 'user_id' => 81, 'email' => 'shared4@example.com'],
            (object) ['id' => 2, 'user_id' => 82, 'email' => 'shared4@example.com'],
        ];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn($cars);
        $this->mockRepo->expects($this->exactly(2))->method('insertOwnerEmailEvent')->willReturn(1);

        $this->mockManager->expects($this->exactly(2))
            ->method('setBouncedFromEvent')
            ->willReturnCallback(function (int $userId) {
                if ($userId === 82) {
                    throw new CarDatabaseException('setBouncedFromEvent failed for owner 82');
                }
                return true;
            });

        $result = $this->processor->process([
            'email' => 'shared4@example.com',
            'event' => 'hard_bounce',
            'message-id' => 'shared4-msg-1',
            'tags' => ['car_verification'],
        ]);

        $this->assertSame(ProcessingResult::WRITE_FAILURE, $result);
    }

    // --- Reason field handling -------------------------------------------

    public function testReasonFieldPassedThroughWhenPresentAndString(): void
    {
        $car = (object) ['id' => 5, 'user_id' => 5, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())
            ->method('insertOwnerEmailEvent')
            ->with(5, 'owner@example.com', 'hard_bounce', 'Mailbox full', 'brevo-msg-1', $this->isString());

        $this->mockManager->expects($this->once())->method('setBouncedFromEvent')->willReturn(true);
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload(['event' => 'hard_bounce', 'reason' => 'Mailbox full']));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    public function testNonStringReasonBecomesNull(): void
    {
        $car = (object) ['id' => 5, 'user_id' => 5, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())
            ->method('insertOwnerEmailEvent')
            ->with(5, 'owner@example.com', 'delivered', null, 'brevo-msg-1', $this->isString());
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload(['event' => 'delivered', 'reason' => ['not', 'a', 'string']]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    // --- occurred_at resolution -------------------------------------------

    public function testOccurredAtResolvesFromTsEvent(): void
    {
        $car = (object) ['id' => 5, 'user_id' => 5, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);

        $ts = 1_700_000_000;
        $expected = date('Y-m-d H:i:s', $ts);

        $this->mockRepo->expects($this->once())
            ->method('insertOwnerEmailEvent')
            ->with(5, 'owner@example.com', 'delivered', null, 'brevo-msg-1', $expected);
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload(['event' => 'delivered', 'ts_event' => $ts]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    public function testOccurredAtFallsBackToTsWhenTsEventAbsent(): void
    {
        $car = (object) ['id' => 5, 'user_id' => 5, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);

        $ts = 1_700_000_100;
        $expected = date('Y-m-d H:i:s', $ts);

        $this->mockRepo->expects($this->once())
            ->method('insertOwnerEmailEvent')
            ->with(5, 'owner@example.com', 'delivered', null, 'brevo-msg-1', $expected);
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload(['event' => 'delivered', 'ts' => (string) $ts]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    public function testOccurredAtFallsBackToNowWhenAbsent(): void
    {
        $car = (object) ['id' => 5, 'user_id' => 5, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);

        $this->mockRepo->expects($this->once())
            ->method('insertOwnerEmailEvent')
            ->with(5, 'owner@example.com', 'delivered', null, 'brevo-msg-1', $this->isString());
        $this->mockManager->expects($this->never())->method('setBouncedFromEvent');
        $this->mockManager->expects($this->never())->method('setSuppressedFromEvent');

        $result = $this->processor->process($this->basePayload(['event' => 'delivered']));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }
}
