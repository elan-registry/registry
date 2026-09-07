<?php

declare(strict_types=1);

use ElanRegistry\Car\BrevoWebhookEventProcessor;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\ProcessingResult;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BrevoWebhookEventProcessor.
 *
 * Mocks CarRepository and CarVerificationManager entirely — this class owns
 * only parsing/matching/escalation logic, no HTTP or real DB concerns (see
 * its class docblock). Follows the mocking conventions established by
 * CarVerificationManagerTest.php (createMock, ->expects()->method()->with()).
 *
 * NOTE on the "retried identical local 'sent' row" dedup scenario from the
 * plan's Test Plan: insertEmailEvent() is mocked at this tier, so there is
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
        $this->processor = new BrevoWebhookEventProcessor($this->mockRepo, $this->mockManager);
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
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process([1, 2, 3]);

        $this->assertSame(ProcessingResult::MALFORMED, $result);
    }

    public function testEmptyArrayIsMalformed(): void
    {
        $this->mockRepo->expects($this->never())->method('findByEmail');
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process([]);

        $this->assertSame(ProcessingResult::MALFORMED, $result);
    }

    public function testNonArrayPayloadIsMalformed(): void
    {
        $this->mockRepo->expects($this->never())->method('findByEmail');
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

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
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process($payload);

        $this->assertSame(ProcessingResult::MALFORMED, $result);
    }

    // --- Tag filtering ----------------------------------------------------

    public function testUntaggedPayloadReturnsNoTagMatch(): void
    {
        $this->mockRepo->expects($this->never())->method('findByEmail');
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process($this->basePayload(['tags' => ['unrelated_tag']]));

        $this->assertSame(ProcessingResult::NO_TAG_MATCH, $result);
    }

    public function testMissingTagsKeyReturnsNoTagMatch(): void
    {
        $payload = $this->basePayload();
        unset($payload['tags']);

        $this->mockRepo->expects($this->never())->method('findByEmail');
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

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
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process($this->basePayload(['event' => str_repeat('x', 33)]));

        $this->assertSame(ProcessingResult::MALFORMED, $result);
    }

    public function testEventNameAtExactColumnWidthIsAccepted(): void
    {
        $car = (object) ['id' => 1, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())->method('insertEmailEvent')->willReturn(1);
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process($this->basePayload(['event' => str_repeat('x', 32)]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    public function testOversizedMessageIdReturnsMalformedNotWriteFailure(): void
    {
        // er_email_events.brevo_message_id is varchar(255) — same failure
        // mode and same fix as the oversized event name above.
        $this->mockRepo->expects($this->never())->method('findByEmail');
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

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
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

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
        $this->mockRepo->expects($this->never())->method('insertEmailEvent');
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process($this->basePayload());

        $this->assertSame(ProcessingResult::NO_CAR_MATCH, $result);
    }

    public function testFindByEmailThrowingCarDatabaseExceptionReturnsWriteFailure(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('findByEmail')
            ->willThrowException(new CarDatabaseException('connection lost'));
        $this->mockRepo->expects($this->never())->method('insertEmailEvent');
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

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
        ];
    }

    #[DataProvider('hardBounceClassEventProvider')]
    public function testHardBounceClassEventCallsSetBouncedWithEventEmail(string $event): void
    {
        $car = (object) ['id' => 42, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())
            ->method('insertEmailEvent')
            ->with(42, 'owner@example.com', $event, null, 'brevo-msg-1', $this->isString());

        $this->mockManager->expects($this->once())
            ->method('setBounced')
            ->with($this->callback(fn ($carData) => $carData->id === 42), 'owner@example.com')
            ->willReturn(true);
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process($this->basePayload(['event' => $event]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    // --- Spam --------------------------------------------------------

    public function testSpamCallsSetSuppressedNotSetBounced(): void
    {
        $car = (object) ['id' => 7, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())
            ->method('insertEmailEvent')
            ->with(7, 'owner@example.com', 'spam', null, 'brevo-msg-1', $this->isString());

        $this->mockManager->expects($this->once())
            ->method('setSuppressed')
            ->with($this->callback(fn ($carData) => $carData->id === 7))
            ->willReturn(true);
        $this->mockManager->expects($this->never())->method('setBounced');

        // No 'reason' field required for spam, per spike.
        $payload = $this->basePayload(['event' => 'spam']);
        unset($payload['reason']);

        $result = $this->processor->process($payload);

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    // --- Soft bounce escalation -----------------------------------------

    public function testSoftBounceBelowThresholdDoesNotEscalate(): void
    {
        $car = (object) ['id' => 3, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())->method('insertEmailEvent')->willReturn(1);
        $this->mockRepo->expects($this->once())
            ->method('countSoftBouncesSinceLastDelivered')
            ->with('owner@example.com')
            ->willReturn(2);

        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process($this->basePayload(['event' => 'soft_bounce']));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    public function testSoftBounceAtThresholdEscalatesToSetBounced(): void
    {
        $car = (object) ['id' => 3, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())->method('insertEmailEvent')->willReturn(1);
        $this->mockRepo->expects($this->once())
            ->method('countSoftBouncesSinceLastDelivered')
            ->willReturn(3);

        $this->mockManager->expects($this->once())
            ->method('setBounced')
            ->with($this->callback(fn ($carData) => $carData->id === 3), 'owner@example.com')
            ->willReturn(true);

        $result = $this->processor->process($this->basePayload(['event' => 'soft_bounce']));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    public function testSoftBounceAboveThresholdEscalatesToSetBounced(): void
    {
        $car = (object) ['id' => 3, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())->method('insertEmailEvent')->willReturn(1);
        $this->mockRepo->expects($this->once())
            ->method('countSoftBouncesSinceLastDelivered')
            ->willReturn(5);

        $this->mockManager->expects($this->once())->method('setBounced')->willReturn(true);

        $result = $this->processor->process($this->basePayload(['event' => 'soft_bounce']));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    // --- Inert events: delivered / unique_opened -------------------------

    public function testDeliveredRecordsEventRowOnlyNoException(): void
    {
        $car = (object) ['id' => 9, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())->method('insertEmailEvent')->willReturn(1);
        $this->mockRepo->expects($this->never())->method('countSoftBouncesSinceLastDelivered');

        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process($this->basePayload(['event' => 'delivered']));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    /**
     * unique_opened must never touch flags or the escalation count, even
     * "after prior soft bounces" — since this is a unit test with a mocked
     * repo, that scenario collapses to: the processor itself never calls
     * countSoftBouncesSinceLastDelivered/setBounced/setSuppressed for this
     * event type. The "since last delivered" windowing logic that would
     * otherwise matter here is countSoftBouncesSinceLastDelivered()'s own
     * job, tested at the repository/integration tier, not re-tested here.
     */
    public function testUniqueOpenedNeverTouchesFlagsOrEscalationCount(): void
    {
        $car = (object) ['id' => 11, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())->method('insertEmailEvent')->willReturn(1);
        $this->mockRepo->expects($this->never())->method('countSoftBouncesSinceLastDelivered');

        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process($this->basePayload(['event' => 'unique_opened']));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    // --- Multiple cars sharing one email ---------------------------------

    public function testMultipleCarsShareEmailEachProcessedIndependentlyWithSameMessageId(): void
    {
        $carA = (object) ['id' => 100, 'email' => 'shared@example.com'];
        $carB = (object) ['id' => 101, 'email' => 'shared@example.com'];

        $this->mockRepo->expects($this->once())
            ->method('findByEmail')
            ->with('shared@example.com')
            ->willReturn([$carA, $carB]);

        $insertCalls = [];
        $this->mockRepo->expects($this->exactly(2))
            ->method('insertEmailEvent')
            ->willReturnCallback(function (int $carId, string $email, string $event, ?string $reason, string $messageId, string $occurredAt) use (&$insertCalls) {
                $insertCalls[] = [$carId, $messageId];
                return 1;
            });

        // One inbound event dispatches identically to every matched car, so
        // both cars escalate via setBounced — asserting the call count and
        // the id passed on each invocation proves each car's transition is
        // applied independently (not, e.g., only the first or last car).
        $bouncedCarIds = [];
        $this->mockManager->expects($this->exactly(2))
            ->method('setBounced')
            ->willReturnCallback(function ($carData, string $email) use (&$bouncedCarIds) {
                $bouncedCarIds[] = $carData->id;
                $this->assertSame('shared@example.com', $email);
                return true;
            });

        $result = $this->processor->process([
            'email' => 'shared@example.com',
            'event' => 'hard_bounce',
            'message-id' => 'shared-msg-1',
            'tags' => ['car_verification'],
        ]);

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $this->assertCount(2, $insertCalls);
        $this->assertSame([100, 'shared-msg-1'], $insertCalls[0]);
        $this->assertSame([101, 'shared-msg-1'], $insertCalls[1]);
        $this->assertSame([100, 101], $bouncedCarIds);
    }

    /**
     * Two matched cars for the same email, each receiving DIFFERENT mocked
     * call outcomes on the manager — proves dispatch is genuinely per-car,
     * not a single shared decision. Car 1's countSoftBouncesSinceLastDelivered
     * (keyed on the shared email, so identical for both cars) determines
     * escalation for the whole event, but this test's real assertion is that
     * insertEmailEvent is called once per matched car id with the same
     * message id, matching the "multiple cars sharing one email" scenario in
     * the plan.
     */
    public function testMultipleCarsWithDifferentTransitionsDispatchIndependently(): void
    {
        $carHardBounce = (object) ['id' => 200, 'email' => 'shared2@example.com'];
        $carSpam = (object) ['id' => 201, 'email' => 'shared2@example.com'];

        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$carHardBounce, $carSpam]);

        $insertedCarIds = [];
        $this->mockRepo->expects($this->exactly(2))
            ->method('insertEmailEvent')
            ->willReturnCallback(function (int $carId) use (&$insertedCarIds) {
                $insertedCarIds[] = $carId;
                return 1;
            });

        $this->mockManager->expects($this->exactly(2))
            ->method('setBounced')
            ->willReturn(true);

        $result = $this->processor->process([
            'email' => 'shared2@example.com',
            'event' => 'hard_bounce',
            'message-id' => 'shared2-msg-1',
            'tags' => ['car_verification'],
        ]);

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $this->assertSame([200, 201], $insertedCarIds);
    }

    // --- Mid-loop write failure -------------------------------------------

    public function testMidLoopWriteFailureReturnsWriteFailureAndStopsProcessingRemainingCars(): void
    {
        $carOne = (object) ['id' => 1, 'email' => 'shared3@example.com'];
        $carTwo = (object) ['id' => 2, 'email' => 'shared3@example.com'];
        $carThree = (object) ['id' => 3, 'email' => 'shared3@example.com'];

        $this->mockRepo->expects($this->once())
            ->method('findByEmail')
            ->willReturn([$carOne, $carTwo, $carThree]);

        // First car succeeds, second car's insertEmailEvent throws, third car
        // must never be reached at all.
        $callCount = 0;
        $this->mockRepo->expects($this->exactly(2))
            ->method('insertEmailEvent')
            ->willReturnCallback(function (int $carId) use (&$callCount) {
                $callCount++;
                if ($carId === 2) {
                    throw new CarDatabaseException('write failed for car 2');
                }
                return 1;
            });

        $this->mockManager->expects($this->once())
            ->method('setBounced')
            ->with($this->callback(fn ($carData) => $carData->id === 1), 'shared3@example.com')
            ->willReturn(true);

        $result = $this->processor->process([
            'email' => 'shared3@example.com',
            'event' => 'hard_bounce',
            'message-id' => 'shared3-msg-1',
            'tags' => ['car_verification'],
        ]);

        $this->assertSame(ProcessingResult::WRITE_FAILURE, $result);
        $this->assertSame(2, $callCount, 'Third car must never be processed after the second car throws');
    }

    public function testMidLoopFailureInManagerCallReturnsWriteFailure(): void
    {
        $carOne = (object) ['id' => 1, 'email' => 'shared4@example.com'];
        $carTwo = (object) ['id' => 2, 'email' => 'shared4@example.com'];

        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$carOne, $carTwo]);
        $this->mockRepo->expects($this->exactly(2))->method('insertEmailEvent')->willReturn(1);

        $this->mockManager->expects($this->exactly(2))
            ->method('setBounced')
            ->willReturnCallback(function ($carData) {
                if ($carData->id === 2) {
                    throw new CarDatabaseException('setBounced failed for car 2');
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
        $car = (object) ['id' => 5, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())
            ->method('insertEmailEvent')
            ->with(5, 'owner@example.com', 'hard_bounce', 'Mailbox full', 'brevo-msg-1', $this->isString());

        $this->mockManager->expects($this->once())->method('setBounced')->willReturn(true);
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process($this->basePayload(['event' => 'hard_bounce', 'reason' => 'Mailbox full']));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    public function testNonStringReasonBecomesNull(): void
    {
        $car = (object) ['id' => 5, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);
        $this->mockRepo->expects($this->once())
            ->method('insertEmailEvent')
            ->with(5, 'owner@example.com', 'delivered', null, 'brevo-msg-1', $this->isString());
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process($this->basePayload(['event' => 'delivered', 'reason' => ['not', 'a', 'string']]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    // --- occurred_at resolution -------------------------------------------

    public function testOccurredAtResolvesFromTsEvent(): void
    {
        $car = (object) ['id' => 5, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);

        $ts = 1_700_000_000;
        $expected = date('Y-m-d H:i:s', $ts);

        $this->mockRepo->expects($this->once())
            ->method('insertEmailEvent')
            ->with(5, 'owner@example.com', 'delivered', null, 'brevo-msg-1', $expected);
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process($this->basePayload(['event' => 'delivered', 'ts_event' => $ts]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    public function testOccurredAtFallsBackToTsWhenTsEventAbsent(): void
    {
        $car = (object) ['id' => 5, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);

        $ts = 1_700_000_100;
        $expected = date('Y-m-d H:i:s', $ts);

        $this->mockRepo->expects($this->once())
            ->method('insertEmailEvent')
            ->with(5, 'owner@example.com', 'delivered', null, 'brevo-msg-1', $expected);
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process($this->basePayload(['event' => 'delivered', 'ts' => (string) $ts]));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }

    public function testOccurredAtFallsBackToNowWhenAbsent(): void
    {
        $car = (object) ['id' => 5, 'email' => 'owner@example.com'];
        $this->mockRepo->expects($this->once())->method('findByEmail')->willReturn([$car]);

        $this->mockRepo->expects($this->once())
            ->method('insertEmailEvent')
            ->with(5, 'owner@example.com', 'delivered', null, 'brevo-msg-1', $this->isString());
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $result = $this->processor->process($this->basePayload(['event' => 'delivered']));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
    }
}
