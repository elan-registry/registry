<?php

declare(strict_types=1);

use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\VerificationTabStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VerificationTabStatus::chip(), the Status-column chip logic
 * of the admin Verification tab (#1896, #2101, #2361, #2330).
 *
 * Since #2101 the owner's profile flags decide the chip. Since #2461 the
 * order is: no profile, blocked, the bounce flag, opted out, then the latest
 * event (a hard-bounce event still shows "Bounced"). The chip no longer reads
 * suppression events or EmailNoticeBuilder. Since #2361 a block reads
 * "Blocked", not "Brevo complaint".
 */
final class AdminVerificationStatusChipTest extends TestCase
{
    private static function event(string $eventName, string $occurredAt): object
    {
        return (object) ['event' => $eventName, 'occurred_at' => $occurredAt];
    }

    public function testOwnerBlockedShowsBlockedWithStoredCause(): void
    {
        $chip = VerificationTabStatus::chip(null, true, true, 'spam', false);

        $this->assertSame('blocked', $chip['kind']);
        $this->assertSame('Blocked', $chip['label']);
        $this->assertSame('text-bg-danger', $chip['class']);
        $this->assertSame('spam', $chip['reason']);
    }

    /**
     * Every email_blocked cause shows "Blocked" (#2361). The cause only
     * fills the reason text, and an "unsubscribed" cause is not "Opted out".
     */
    public function testBlockedWithUnsubscribedCauseShowsBlockedNotOptedOut(): void
    {
        $chip = VerificationTabStatus::chip(null, true, true, 'unsubscribed', false);

        $this->assertSame('blocked', $chip['kind']);
        $this->assertSame('Blocked', $chip['label']);
        $this->assertSame('unsubscribed', $chip['reason']);
    }

    public function testBlockedWithNoKnownCauseShowsCauseUnknown(): void
    {
        $chip = VerificationTabStatus::chip(null, true, true, null, false);

        $this->assertSame('blocked', $chip['kind']);
        $this->assertSame('cause unknown', $chip['reason']);
    }

    public function testOwnerOptedOutShowsOptedOut(): void
    {
        $chip = VerificationTabStatus::chip(null, true, false, null, true);

        $this->assertSame('opted_out', $chip['kind']);
        $this->assertSame('Opted out', $chip['label']);
    }

    public function testBlockedWinsOverOptedOut(): void
    {
        $chip = VerificationTabStatus::chip(null, true, true, 'spam', true);

        $this->assertSame('blocked', $chip['kind']);
    }

    public function testBouncedFlagWinsOverOptedOut(): void
    {
        $chip = VerificationTabStatus::chip(null, true, false, null, true, true);

        $this->assertSame('bounced', $chip['kind']);
        $this->assertSame('Bounced', $chip['label']);
    }

    public function testBlockedWinsOverBouncedFlag(): void
    {
        $chip = VerificationTabStatus::chip(null, true, true, 'spam', false, true);

        $this->assertSame('blocked', $chip['kind']);
    }

    public function testBouncedFlagWithNoEventShowsBounced(): void
    {
        $chip = VerificationTabStatus::chip(null, true, false, null, false, true);

        $this->assertSame('bounced', $chip['kind']);
        $this->assertSame('', $chip['reason']);
    }

    public function testBouncedFlagKeepsTheHardBounceEventReason(): void
    {
        $event = (object) ['event' => 'hard_bounce', 'occurred_at' => '2026-09-01 10:00:00', 'reason' => 'mailbox full'];

        $chip = VerificationTabStatus::chip($event, true, false, null, false, true);

        $this->assertSame('bounced', $chip['kind']);
        $this->assertSame('mailbox full', $chip['reason']);
    }

    public function testBouncedFlagDropsTheReasonOfANonHardBounceEvent(): void
    {
        $event = (object) ['event' => 'soft_bounce', 'occurred_at' => '2026-09-01 10:00:00', 'reason' => 'mailbox full'];

        $chip = VerificationTabStatus::chip($event, true, false, null, false, true);

        $this->assertSame('bounced', $chip['kind']);
        $this->assertSame('', $chip['reason']);
    }

    /** @return array<string, array{0: string}> */
    public static function hardBounceEventProvider(): array
    {
        $events = EmailEventApplier::HARD_BOUNCE_EVENTS;

        return array_combine($events, array_map(static fn (string $name): array => [$name], $events));
    }

    /**
     * An opted-out owner gets no new sends, so the latest event can be a
     * hard bounce at a previous address. With no bounce flag that applies,
     * the event must not hide the opt-out (#2461).
     */
    #[DataProvider('hardBounceEventProvider')]
    public function testOldHardBounceEventWithNoFlagDoesNotHideOptOut(string $eventName): void
    {
        $chip = VerificationTabStatus::chip(self::event($eventName, '2026-09-01 10:00:00'), true, false, null, true, false);

        $this->assertSame('opted_out', $chip['kind']);
    }

    public function testOptedOutWinsOverANonBounceEvent(): void
    {
        $chip = VerificationTabStatus::chip(self::event('soft_bounce', '2026-09-01 10:00:00'), true, false, null, true);

        $this->assertSame('opted_out', $chip['kind']);
    }

    public function testOwnerFlagWinsOverALatestDeliveredEvent(): void
    {
        $chip = VerificationTabStatus::chip(self::event('delivered', '2026-09-01 10:00:00'), true, false, null, true);

        $this->assertSame('opted_out', $chip['kind']);
    }

    public function testNoProfileShowsNoProfileChipWithoutAReason(): void
    {
        $chip = VerificationTabStatus::chip(null, false, true, 'spam', true);

        $this->assertSame('no_profile', $chip['kind']);
        $this->assertSame('No profile', $chip['label']);
        $this->assertSame('', $chip['reason']);
    }

    public function testLiveSpamEventOnANonSuppressedCarStillShowsSpamComplaint(): void
    {
        // A live 'spam' event on an owner with no block flag yet takes the
        // match() path and keeps its own, more specific label.
        $chip = VerificationTabStatus::chip(self::event('spam', '2026-09-01 10:00:00'));

        $this->assertSame('spam', $chip['kind']);
        $this->assertSame('Spam complaint', $chip['label']);
    }

    public function testHardBounceEventShowsBounced(): void
    {
        $event = (object) ['event' => 'hard_bounce', 'occurred_at' => '2026-09-01 10:00:00', 'reason' => 'mailbox full'];

        $chip = VerificationTabStatus::chip($event);

        $this->assertSame('bounced', $chip['kind']);
        $this->assertSame('Bounced', $chip['label']);
    }

    /**
     * invalid_email is a HARD_BOUNCE_EVENTS member, so it must render
     * "Bounced" via the in_array() check before the match() reaches its
     * default case — not "Invalid email" (see the EMAIL_SYSTEM.md fix for
     * this same confusion).
     */
    public function testInvalidEmailEventShowsBouncedNotADefaultChip(): void
    {
        $this->assertContains(
            'invalid_email',
            EmailEventApplier::HARD_BOUNCE_EVENTS,
            'Sanity: invalid_email must still be a hard-bounce event for this test to be meaningful'
        );

        $event = (object) ['event' => 'invalid_email', 'occurred_at' => '2026-09-01 10:00:00'];

        $chip = VerificationTabStatus::chip($event);

        $this->assertSame('bounced', $chip['kind']);
        $this->assertSame('Bounced', $chip['label']);
    }

    public function testUnrecognizedEventNameRendersAsADefaultChip(): void
    {
        $event = (object) ['event' => 'deferred', 'occurred_at' => '2026-09-01 10:00:00'];

        $chip = VerificationTabStatus::chip($event);

        $this->assertSame('other', $chip['kind']);
        $this->assertSame('Deferred', $chip['label']);
    }

    public function testNoEventAndAndNoFlagShowsEmptyChip(): void
    {
        $chip = VerificationTabStatus::chip(null);

        $this->assertSame('', $chip['kind']);
        $this->assertSame('', $chip['label']);
    }

    public function testUnsubscribedEventShowsOptedOut(): void
    {
        $chip = VerificationTabStatus::chip(self::event('unsubscribed', '2026-09-01 10:00:00'));

        $this->assertSame('opted_out', $chip['kind']);
        $this->assertSame('Opted out', $chip['label']);
    }

    public function testSoftBounceEventKeepsItsReason(): void
    {
        $event = (object) ['event' => 'soft_bounce', 'occurred_at' => '2026-09-01 10:00:00', 'reason' => ' mailbox full '];

        $chip = VerificationTabStatus::chip($event);

        $this->assertSame('soft_bounce', $chip['kind']);
        $this->assertSame('Soft bounce', $chip['label']);
        $this->assertSame('mailbox full', $chip['reason']);
    }

    public function testOpenedEventShowsDelivered(): void
    {
        $chip = VerificationTabStatus::chip(self::event('opened', '2026-09-01 10:00:00'));

        $this->assertSame('delivered', $chip['kind']);
        $this->assertSame('text-bg-success', $chip['class']);
    }
}
