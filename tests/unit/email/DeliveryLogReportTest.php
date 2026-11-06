<?php

declare(strict_types=1);

use ElanRegistry\Email\DeliveryLogFilter;
use ElanRegistry\Email\DeliveryLogReport;
use ElanRegistry\Email\DeliveryOutcome;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DeliveryLogReport::build (#2102).
 *
 * @see usersc/classes/Email/DeliveryLogReport.php
 */
#[Group('fast')]
#[Group('unit')]
final class DeliveryLogReportTest extends TestCase
{
    private const NOW = '2026-01-02 12:00:00';

    public function testBuildWithNoRowsGivesEmptyReportWithOnePage(): void
    {
        $report = DeliveryLogReport::build([], self::filter(), self::now());

        $this->assertSame([], $report->entries);
        $this->assertSame(0, $report->total);
        $this->assertSame(1, $report->page);
        $this->assertSame(1, $report->pageCount);
        $this->assertCount(count(DeliveryOutcome::cases()), $report->counts);
        $this->assertSame(0, array_sum($report->counts));
    }

    public function testBuildGivesMailWithoutEventsOutcomeNoneAndNullOutcomeFields(): void
    {
        $rows = [self::mail(1, 'm1', '2026-01-02 11:00:00')];

        $report = DeliveryLogReport::build($rows, self::filter(), self::now());

        $this->assertCount(1, $report->entries);
        $entry = $report->entries[0];
        $this->assertSame(DeliveryOutcome::None, $entry->outcome);
        $this->assertNull($entry->outcomeAt);
        $this->assertNull($entry->reason);
        $this->assertNull($entry->userId);
        $this->assertNull($entry->carId);
        $this->assertSame('m1', $entry->messageId);
        $this->assertSame('a@example.com', $entry->recipient);
        $this->assertSame('verification', $entry->tags);
    }

    public function testBuildTakesOutcomeFieldsFromChosenEvent(): void
    {
        $rows = [
            self::event(self::mail(1, 'm1', '2026-01-02 09:00:00'), 10, 'delivered', '2026-01-02 09:01:00', 5, 6, null),
            self::event(self::mail(1, 'm1', '2026-01-02 09:00:00'), 11, 'hard_bounce', '2026-01-02 09:05:00', 5, 6, 'mailbox full'),
        ];

        $entry = DeliveryLogReport::build($rows, self::filter(), self::now())->entries[0];

        $this->assertSame(DeliveryOutcome::HardBounce, $entry->outcome);
        $this->assertSame('2026-01-02 09:05:00', $entry->outcomeAt?->format('Y-m-d H:i:s'));
        $this->assertSame('mailbox full', $entry->reason);
        $this->assertSame(5, $entry->userId);
        $this->assertSame(6, $entry->carId);
    }

    public function testBuildTakesUserIdFromChosenEventNotFromEarlierEvent(): void
    {
        $mail = self::mail(1, 'm1', '2026-01-02 09:00:00');
        $rows = [
            self::event($mail, 10, 'delivered', '2026-01-02 09:01:00', 5, 50, null),
            self::event($mail, 11, 'hard_bounce', '2026-01-02 09:05:00', 6, 60, null),
        ];

        $entry = DeliveryLogReport::build($rows, self::filter(), self::now())->entries[0];

        $this->assertSame(6, $entry->userId);
        $this->assertSame(60, $entry->carId);
    }

    public function testBuildAttachesEventsOnlyByMessageIdNotByAddress(): void
    {
        // Same recipient address, two mails, events from two users.
        $mailA = self::mail(1, 'mA', '2026-01-02 09:00:00');
        $mailB = self::mail(2, 'mB', '2026-01-02 09:30:00');
        $rows = [
            self::event($mailA, 10, 'delivered', '2026-01-02 09:01:00', 100, null, null),
            self::event($mailB, 20, 'hard_bounce', '2026-01-02 09:31:00', 200, null, 'bad'),
        ];

        $report = DeliveryLogReport::build($rows, self::filter(), self::now());

        $this->assertCount(2, $report->entries);
        $byId = [];
        foreach ($report->entries as $entry) {
            $byId[$entry->messageId] = $entry;
        }
        $this->assertSame(DeliveryOutcome::Delivered, $byId['mA']->outcome);
        $this->assertSame(100, $byId['mA']->userId);
        $this->assertSame(DeliveryOutcome::HardBounce, $byId['mB']->outcome);
        $this->assertSame(200, $byId['mB']->userId);
    }

    public function testBuildMailWithOnlySentEventHasOutcomeNone(): void
    {
        $rows = [self::event(self::mail(1, 'm1', '2026-01-02 09:00:00'), 10, 'sent', '2026-01-02 09:00:01', 5, 6, null)];

        $entry = DeliveryLogReport::build($rows, self::filter(), self::now())->entries[0];

        $this->assertSame(DeliveryOutcome::None, $entry->outcome);
        $this->assertNull($entry->userId);
    }

    public function testBuildOutcomeFilterKeepsOnlyThatOutcomeAndCountsMatchFilteredSet(): void
    {
        $rows = [
            self::event(self::mail(1, 'm1', '2026-01-02 08:00:00'), 10, 'delivered', '2026-01-02 08:01:00', 1, null, null),
            self::event(self::mail(2, 'm2', '2026-01-02 08:10:00'), 11, 'hard_bounce', '2026-01-02 08:11:00', 1, null, null),
            self::event(self::mail(3, 'm3', '2026-01-02 08:20:00'), 12, 'hard_bounce', '2026-01-02 08:21:00', 1, null, null),
            self::mail(4, 'm4', '2026-01-02 08:30:00'),
        ];

        $report = DeliveryLogReport::build($rows, self::filter(outcome: 'hard_bounce'), self::now());

        $this->assertSame(2, $report->total);
        $this->assertSame(['m3', 'm2'], array_map(static fn($e) => $e->messageId, $report->entries));
        $this->assertSame(2, $report->counts['hard_bounce']);
        $this->assertSame(0, $report->counts['delivered']);
        $this->assertSame(0, $report->counts['none']);
    }

    public function testBuildAllFilterCountsEveryOutcome(): void
    {
        $rows = [
            self::event(self::mail(1, 'm1', '2026-01-02 08:00:00'), 10, 'delivered', '2026-01-02 08:01:00', 1, null, null),
            self::event(self::mail(2, 'm2', '2026-01-02 08:10:00'), 11, 'blocked', '2026-01-02 08:11:00', 1, null, null),
            self::event(self::mail(3, 'm3', '2026-01-02 08:20:00'), 12, 'spam', '2026-01-02 08:21:00', 1, null, null),
            self::mail(4, 'm4', '2026-01-02 08:30:00'),
        ];

        $report = DeliveryLogReport::build($rows, self::filter(), self::now());

        $this->assertSame(4, $report->total);
        $this->assertSame(1, $report->counts['delivered']);
        $this->assertSame(1, $report->counts['blocked']);
        $this->assertSame(1, $report->counts['spam_complaint']);
        $this->assertSame(1, $report->counts['none']);
        $this->assertSame(0, $report->counts['hard_bounce']);
    }

    public function testBuildStaleKeepsOnlyNoOutcomeMailOlderThanHours(): void
    {
        // now 12:00, hours 2 -> cut-off 10:00:00
        $rows = [
            self::mail(1, 'old', '2026-01-02 09:59:59'),
            self::mail(2, 'boundary', '2026-01-02 10:00:00'),
            self::mail(3, 'fresh', '2026-01-02 11:00:00'),
            self::event(self::mail(4, 'oldDelivered', '2026-01-02 08:00:00'), 10, 'delivered', '2026-01-02 08:01:00', 1, null, null),
            self::event(self::mail(5, 'oldSent', '2026-01-02 07:00:00'), 11, 'sent', '2026-01-02 07:00:01', 1, null, null),
        ];

        $report = DeliveryLogReport::build($rows, self::filter(outcome: 'stale', hours: 2), self::now());

        $this->assertSame(['old', 'oldSent'], array_map(static fn($e) => $e->messageId, $report->entries));
        $this->assertSame(2, $report->counts['none']);
        $this->assertSame(0, $report->counts['delivered']);
    }

    public function testBuildStaleLeavesOutOldMailWhoseOnlyEventIsUnsubscribedOrSpam(): void
    {
        // now 12:00, hours 2 -> cut-off 10:00:00. Both mails are older than that.
        $rows = [
            self::mail(1, 'oldNone', '2026-01-02 08:00:00'),
            self::event(self::mail(2, 'oldUnsubscribed', '2026-01-02 08:00:00'), 20, 'unsubscribed', '2026-01-02 08:05:00', 1, null, null),
            self::event(self::mail(3, 'oldSpam', '2026-01-02 08:00:00'), 21, 'spam', '2026-01-02 08:06:00', 1, null, null),
        ];

        $report = DeliveryLogReport::build($rows, self::filter(outcome: 'stale', hours: 2), self::now());

        $this->assertSame(['oldNone'], array_map(static fn($e) => $e->messageId, $report->entries));
    }

    public function testBuildStaleUsesFilterHours(): void
    {
        $rows = [self::mail(1, 'm1', '2026-01-02 08:30:00')];

        $with2 = DeliveryLogReport::build($rows, self::filter(outcome: 'stale', hours: 2), self::now());
        $with4 = DeliveryLogReport::build($rows, self::filter(outcome: 'stale', hours: 4), self::now());

        $this->assertSame(1, $with2->total);
        $this->assertSame(0, $with4->total);
    }

    public function testBuildSortsNewestFirstAndBreaksTieWithHigherId(): void
    {
        $rows = [
            self::mail(1, 'a', '2026-01-02 08:00:00'),
            self::mail(7, 'b', '2026-01-02 09:00:00'),
            self::mail(3, 'c', '2026-01-02 09:00:00'),
            self::mail(9, 'd', '2026-01-02 07:00:00'),
        ];

        $report = DeliveryLogReport::build($rows, self::filter(), self::now());

        $this->assertSame([7, 3, 1, 9], array_map(static fn($e) => $e->id, $report->entries));
    }

    public function testBuildPaginatesFiftyPerPage(): void
    {
        $rows = self::manyMails(120);

        $page1 = DeliveryLogReport::build($rows, self::filter(), self::now());
        $page3 = DeliveryLogReport::build($rows, self::filter(page: 3), self::now());

        $this->assertSame(120, $page1->total);
        $this->assertSame(3, $page1->pageCount);
        $this->assertCount(50, $page1->entries);
        $this->assertSame(120, $page1->entries[0]->id);
        $this->assertSame(3, $page3->page);
        $this->assertCount(20, $page3->entries);
        $this->assertSame(20, $page3->entries[0]->id);
        $this->assertSame(1, $page3->entries[19]->id);
    }

    public function testBuildExactlyFiftyIsOnePage(): void
    {
        $report = DeliveryLogReport::build(self::manyMails(50), self::filter(), self::now());

        $this->assertSame(1, $report->pageCount);
        $this->assertCount(50, $report->entries);
    }

    public function testBuildPageBeyondLastClampsToLastPage(): void
    {
        $report = DeliveryLogReport::build(self::manyMails(51), self::filter(page: 99), self::now());

        $this->assertSame(2, $report->page);
        $this->assertSame(2, $report->pageCount);
        $this->assertCount(1, $report->entries);
        $this->assertSame(1, $report->entries[0]->id);
    }

    public function testBuildEmptyFilteredSetWithHighPageGivesPageOne(): void
    {
        $report = DeliveryLogReport::build([], self::filter(page: 5), self::now());

        $this->assertSame(1, $report->page);
        $this->assertSame(1, $report->pageCount);
    }

    public function testBuildNullTagsStaysNull(): void
    {
        $mail = self::mail(1, 'm1', '2026-01-02 08:00:00');
        $mail['tags'] = null;

        $entry = DeliveryLogReport::build([$mail], self::filter(), self::now())->entries[0];

        $this->assertNull($entry->tags);
    }

    /** @return array<string, array{mixed}> */
    public static function noRealIdProvider(): array
    {
        return [
            'zero'        => [0],
            'zero string' => ['0'],
            'negative'    => [-3],
            'non-numeric' => ['abc'],
        ];
    }

    #[DataProvider('noRealIdProvider')]
    public function testBuildWithNoRealUserOrCarIdGivesNullIds(mixed $id): void
    {
        $rows = [self::event(self::mail(1, 'm1', '2026-01-02 08:00:00'), 10, 'delivered', '2026-01-02 08:01:00', $id, $id, null)];

        $entry = DeliveryLogReport::build($rows, self::filter(), self::now())->entries[0];

        $this->assertNull($entry->userId);
        $this->assertNull($entry->carId);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    private static function filter(string $outcome = 'all', int $hours = 2, int $page = 1): DeliveryLogFilter
    {
        return DeliveryLogFilter::fromInput(null, null, $outcome, $hours, $page);
    }

    /**
     * A LEFT JOIN row of a mail with no event.
     *
     * @return array<string, mixed>
     */
    private static function mail(int $id, string $messageId, string $acceptedAt): array
    {
        return [
            'id' => $id,
            'message_id' => $messageId,
            'recipient_email' => 'a@example.com',
            'tags' => 'verification',
            'accepted_at' => $acceptedAt,
            'event_id' => null,
            'event' => null,
            'occurred_at' => null,
            'reason' => null,
            'user_id' => null,
            'car_id' => null,
        ];
    }

    /**
     * @param array<string, mixed> $mail
     * @return array<string, mixed>
     */
    private static function event(array $mail, int $eventId, string $event, string $at, int|string $userId, int|string|null $carId, ?string $reason): array
    {
        return array_merge($mail, [
            'event_id' => $eventId,
            'event' => $event,
            'occurred_at' => $at,
            'reason' => $reason,
            'user_id' => $userId,
            'car_id' => $carId,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function manyMails(int $n): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            // Strictly increasing time with the id, so the newest has the highest id.
            $rows[] = self::mail($i, "m{$i}", (new DateTimeImmutable('2026-01-01 00:00:00'))->modify("+{$i} minutes")->format('Y-m-d H:i:s'));
        }

        return $rows;
    }
}
