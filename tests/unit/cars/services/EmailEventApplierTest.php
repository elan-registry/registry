<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EmailEventApplier — the event→escalation mapping shared by
 * the Brevo webhook (#1887) and the nightly reconciliation job (#1889).
 *
 * These tests exercise the mapping directly, at the unit that owns it, so a
 * change to the rules fails here rather than only through the webhook's
 * process() path. Mocks CarRepository and CarVerificationManager entirely,
 * following CarVerificationManagerTest.php's conventions; logger() assertions
 * use the $mockLogEntries global installed by tests/bootstrap-unit.php.
 */
#[Group('fast')]
final class EmailEventApplierTest extends TestCase
{
    /** @var CarRepository&\PHPUnit\Framework\MockObject\MockObject */
    private CarRepository $mockRepo;

    /** @var CarVerificationManager&\PHPUnit\Framework\MockObject\MockObject */
    private CarVerificationManager $mockManager;

    private EmailEventApplier $applier;

    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        $this->mockRepo = $this->createMock(CarRepository::class);
        $this->mockManager = $this->createMock(CarVerificationManager::class);
        $this->applier = new EmailEventApplier($this->mockRepo, $this->mockManager);
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
            // Never actually observed live (#1871 spike) — both spellings are
            // accepted since EMAIL_SYSTEM.md and the original issue text
            // disagree on which one Brevo really sends.
            'invalid_email' => ['invalid_email'],
        ];
    }

    #[DataProvider('hardBounceClassEventProvider')]
    public function testHardBounceClassEventFlagsCarAsBounced(string $event): void
    {
        $this->mockRepo->expects($this->once())
            ->method('insertEmailEvent')
            ->with(42, 'owner@example.com', $event, null, 'msg-1', '2026-01-01 00:00:00');
        $this->mockRepo->expects($this->never())->method('countSoftBouncesSinceLastDelivered');

        $this->mockManager->expects($this->once())
            ->method('setBounced')
            ->with($this->callback(fn ($carData) => $carData->id === 42), 'owner@example.com')
            ->willReturn(true);
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $this->applier->apply(42, 'owner@example.com', $event, null, 'msg-1', '2026-01-01 00:00:00');

        $this->assertNoLogEntries();
    }

    // --- Soft bounce escalation -----------------------------------------

    public function testSoftBounceBelowThresholdDoesNotEscalate(): void
    {
        $this->mockRepo->expects($this->once())->method('insertEmailEvent')->willReturn(1);
        $this->mockRepo->expects($this->once())
            ->method('countSoftBouncesSinceLastDelivered')
            ->with('owner@example.com')
            ->willReturn(2);

        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $this->applier->apply(3, 'owner@example.com', 'soft_bounce', null, 'msg-1', '2026-01-01 00:00:00');

        $this->assertNoLogEntries();
    }

    /**
     * @return array<string, array{int}>
     */
    public static function softBounceEscalatingCountProvider(): array
    {
        return [
            'at threshold' => [3],
            'above threshold' => [5],
        ];
    }

    #[DataProvider('softBounceEscalatingCountProvider')]
    public function testSoftBounceAtOrAboveThresholdEscalatesToBounced(int $cycles): void
    {
        $this->mockRepo->expects($this->once())->method('insertEmailEvent')->willReturn(1);
        $this->mockRepo->expects($this->once())
            ->method('countSoftBouncesSinceLastDelivered')
            ->with('owner@example.com')
            ->willReturn($cycles);

        $this->mockManager->expects($this->once())
            ->method('setBounced')
            ->with($this->callback(fn ($carData) => $carData->id === 3), 'owner@example.com')
            ->willReturn(true);
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $this->applier->apply(3, 'owner@example.com', 'soft_bounce', null, 'msg-1', '2026-01-01 00:00:00');
    }

    // --- Spam ------------------------------------------------------------

    public function testSpamSuppressesRatherThanBounces(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('insertEmailEvent')
            ->with(7, 'owner@example.com', 'spam', null, 'msg-1', '2026-01-01 00:00:00');
        $this->mockRepo->expects($this->never())->method('countSoftBouncesSinceLastDelivered');

        $this->mockManager->expects($this->once())
            ->method('setSuppressed')
            ->with($this->callback(fn ($carData) => $carData->id === 7))
            ->willReturn(true);
        $this->mockManager->expects($this->never())->method('setBounced');

        $this->applier->apply(7, 'owner@example.com', 'spam', null, 'msg-1', '2026-01-01 00:00:00');

        $this->assertNoLogEntries();
    }

    // --- Known inert events ----------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function knownInertEventProvider(): array
    {
        return [
            'delivered' => ['delivered'],
            'unique_opened' => ['unique_opened'],
        ];
    }

    /**
     * A known inert event records its row and nothing else — no flag change,
     * no escalation count, and crucially NO warning log: these are routine
     * traffic, and logging them would drown out the unrecognized-event signal
     * the log line below exists to provide.
     */
    #[DataProvider('knownInertEventProvider')]
    public function testKnownInertEventRecordsRowWithoutFlagChangeOrLog(string $event): void
    {
        $this->mockRepo->expects($this->once())
            ->method('insertEmailEvent')
            ->with(9, 'owner@example.com', $event, null, 'msg-1', '2026-01-01 00:00:00');
        $this->mockRepo->expects($this->never())->method('countSoftBouncesSinceLastDelivered');

        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $this->applier->apply(9, 'owner@example.com', $event, null, 'msg-1', '2026-01-01 00:00:00');

        $this->assertNoLogEntries();
    }

    // --- Unrecognized events ---------------------------------------------

    /**
     * An unrecognized event is still recorded (Brevo may add or rename event
     * types at any time, and a dropped row would be worse than an unmapped
     * one) but is logged, since it more likely signals a payload-contract
     * change than routine traffic.
     */
    public function testUnrecognizedEventIsRecordedAndLogged(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('insertEmailEvent')
            ->with(13, 'owner@example.com', 'deferred', 'greylisted', 'msg-1', '2026-01-01 00:00:00');
        $this->mockRepo->expects($this->never())->method('countSoftBouncesSinceLastDelivered');

        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $this->applier->apply(13, 'owner@example.com', 'deferred', 'greylisted', 'msg-1', '2026-01-01 00:00:00');

        global $mockLogEntries;
        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, $mockLogEntries[0]['category']);
        $this->assertStringContainsString('unrecognized event "deferred"', $mockLogEntries[0]['message']);
        $this->assertStringContainsString('car 13', $mockLogEntries[0]['message']);
    }

    // --- Write failures propagate ----------------------------------------

    /**
     * A failed write must surface to the caller, not be swallowed: the webhook
     * maps it to a retryable 5xx, and the #1889 job needs it to leave the event
     * unreconciled for the next run.
     */
    public function testInsertFailurePropagatesAndSkipsEscalation(): void
    {
        $this->mockRepo->expects($this->once())
            ->method('insertEmailEvent')
            ->willThrowException(new CarDatabaseException('write failed'));
        $this->mockManager->expects($this->never())->method('setBounced');
        $this->mockManager->expects($this->never())->method('setSuppressed');

        $this->expectException(CarDatabaseException::class);

        $this->applier->apply(1, 'owner@example.com', 'hard_bounce', null, 'msg-1', '2026-01-01 00:00:00');
    }

    private function assertNoLogEntries(): void
    {
        global $mockLogEntries;
        $this->assertSame([], $mockLogEntries, 'Expected no log entries for this event type');
    }
}
