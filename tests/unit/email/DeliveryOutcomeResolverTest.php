<?php

declare(strict_types=1);

use ElanRegistry\Email\DeliveryOutcome;
use ElanRegistry\Email\DeliveryOutcomeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DeliveryOutcome and DeliveryOutcomeResolver (#2102).
 *
 * @see usersc/classes/Email/DeliveryOutcome.php
 * @see usersc/classes/Email/DeliveryOutcomeResolver.php
 */
#[Group('fast')]
#[Group('unit')]
final class DeliveryOutcomeResolverTest extends TestCase
{
    /**
     * @return array<string, array{string, DeliveryOutcome|null}>
     */
    public static function eventProvider(): array
    {
        return [
            'delivered' => ['delivered', DeliveryOutcome::Delivered],
            'hard_bounce' => ['hard_bounce', DeliveryOutcome::HardBounce],
            'invalid' => ['invalid', DeliveryOutcome::HardBounce],
            'invalid_email' => ['invalid_email', DeliveryOutcome::HardBounce],
            'soft_bounce' => ['soft_bounce', DeliveryOutcome::SoftBounce],
            'blocked keeps its own outcome' => ['blocked', DeliveryOutcome::Blocked],
            'unsubscribed' => ['unsubscribed', DeliveryOutcome::Unsubscribed],
            'spam' => ['spam', DeliveryOutcome::SpamComplaint],
            'sent is ignored' => ['sent', null],
            'unknown name is ignored' => ['opened_by_robot', null],
            'empty name is ignored' => ['', null],
            'case matters' => ['Delivered', null],
        ];
    }

    #[DataProvider('eventProvider')]
    public function testFromEventMapsBrevoNameToOutcome(string $event, ?DeliveryOutcome $expected): void
    {
        $this->assertSame($expected, DeliveryOutcome::fromEvent($event));
    }

    /**
     * @return array<string, array{DeliveryOutcome, string}>
     */
    public static function labelProvider(): array
    {
        return [
            'delivered' => [DeliveryOutcome::Delivered, 'Delivered'],
            'hard' => [DeliveryOutcome::HardBounce, 'Hard bounce'],
            'soft' => [DeliveryOutcome::SoftBounce, 'Soft bounce'],
            'blocked' => [DeliveryOutcome::Blocked, 'Blocked'],
            'unsub' => [DeliveryOutcome::Unsubscribed, 'Unsubscribed'],
            'spam' => [DeliveryOutcome::SpamComplaint, 'Spam complaint'],
            'none' => [DeliveryOutcome::None, 'No outcome'],
        ];
    }

    #[DataProvider('labelProvider')]
    public function testLabelReturnsDisplayText(DeliveryOutcome $outcome, string $expected): void
    {
        $this->assertSame($expected, $outcome->label());
    }

    public function testResolveReturnsNullForNoRows(): void
    {
        $this->assertNull((new DeliveryOutcomeResolver())->resolve([]));
    }

    public function testResolveIgnoresSentUnknownAndLeftJoinNullRows(): void
    {
        $rows = [
            self::row(1, 'sent', '2026-01-01 10:00:00'),
            self::row(2, 'opened_by_robot', '2026-01-01 11:00:00'),
            ['event_id' => null, 'event' => null, 'occurred_at' => null],
        ];

        $this->assertNull((new DeliveryOutcomeResolver())->resolve($rows));
    }

    public function testResolveIgnoresSentRowThatIsLaterThanOutcomeRow(): void
    {
        $rows = [
            self::row(1, 'delivered', '2026-01-01 10:00:00'),
            self::row(2, 'sent', '2026-01-01 12:00:00'),
        ];

        $chosen = (new DeliveryOutcomeResolver())->resolve($rows);

        $this->assertNotNull($chosen);
        $this->assertSame(1, $chosen['event_id']);
    }

    public function testResolvePicksHardBounceThatFollowsDelivery(): void
    {
        $rows = [
            self::row(1, 'delivered', '2026-01-01 10:00:00'),
            self::row(2, 'hard_bounce', '2026-01-01 10:05:00'),
        ];

        $chosen = (new DeliveryOutcomeResolver())->resolve($rows);

        $this->assertNotNull($chosen);
        $this->assertSame('hard_bounce', $chosen['event']);
    }

    public function testResolvePicksDeliveryThatFollowsSoftBounce(): void
    {
        $rows = [
            self::row(1, 'soft_bounce', '2026-01-01 10:00:00'),
            self::row(2, 'delivered', '2026-01-01 10:30:00'),
        ];

        $chosen = (new DeliveryOutcomeResolver())->resolve($rows);

        $this->assertNotNull($chosen);
        $this->assertSame('delivered', $chosen['event']);
    }

    public function testResolveIgnoresRowOrderAndUsesOccurredAt(): void
    {
        // The later event comes first in the list, with the lower ID.
        $rows = [
            self::row(1, 'hard_bounce', '2026-01-01 10:30:00'),
            self::row(2, 'delivered', '2026-01-01 10:00:00'),
        ];

        $chosen = (new DeliveryOutcomeResolver())->resolve($rows);

        $this->assertNotNull($chosen);
        $this->assertSame('hard_bounce', $chosen['event']);
    }

    public function testResolveBreaksTieWithHighestEventIdInEitherOrder(): void
    {
        $a = self::row(5, 'delivered', '2026-01-01 10:00:00');
        $b = self::row(9, 'hard_bounce', '2026-01-01 10:00:00');
        $resolver = new DeliveryOutcomeResolver();

        $first = $resolver->resolve([$a, $b]);
        $second = $resolver->resolve([$b, $a]);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(9, $first['event_id']);
        $this->assertSame(9, $second['event_id']);
    }

    public function testResolveKeepsOtherKeysOfChosenRow(): void
    {
        $row = self::row(3, 'spam', '2026-01-01 10:00:00') + ['user_id' => 77, 'reason' => 'complaint'];

        $chosen = (new DeliveryOutcomeResolver())->resolve([$row]);

        $this->assertSame($row, $chosen);
    }

    /**
     * Callers must group by message ID. The resolver trusts its input, so the
     * caller-side guarantee is covered in DeliveryLogReportTest; here the
     * resolver is shown to choose only among the rows it receives.
     */
    public function testResolveChoosesOnlyAmongGivenRowsOfOneMail(): void
    {
        $mailA = [self::row(1, 'delivered', '2026-01-01 10:00:00') + ['user_id' => 10]];
        $mailB = [self::row(2, 'hard_bounce', '2026-01-01 11:00:00') + ['user_id' => 20]];
        $resolver = new DeliveryOutcomeResolver();

        $chosenA = $resolver->resolve($mailA);
        $chosenB = $resolver->resolve($mailB);

        $this->assertNotNull($chosenA);
        $this->assertNotNull($chosenB);
        $this->assertSame(10, $chosenA['user_id']);
        $this->assertSame(20, $chosenB['user_id']);
    }

    /**
     * @return array{event_id: int, event: string, occurred_at: string}
     */
    private static function row(int $id, string $event, string $at): array
    {
        return ['event_id' => $id, 'event' => $event, 'occurred_at' => $at];
    }
}
