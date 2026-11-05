<?php

declare(strict_types=1);

namespace ElanRegistry\Email;

use ElanRegistry\Car\EmailEventApplier;

/**
 * DeliveryOutcome - the webhook outcome of one mail on the admin email
 * delivery tab (#2102)
 *
 * The value is the text that the tab's outcome filter sends in the URL.
 * `None` means that the mail has no outcome event.
 *
 * @package ElanRegistry\Email
 * @since v2.31.1
 * @see https://github.com/elan-registry/registry/issues/2102
 */
enum DeliveryOutcome: string
{
    case Delivered = 'delivered';
    case HardBounce = 'hard_bounce';
    case SoftBounce = 'soft_bounce';
    case Blocked = 'blocked';
    case Unsubscribed = 'unsubscribed';
    case SpamComplaint = 'spam_complaint';
    case None = 'none';

    /**
     * Map a Brevo event name to its outcome.
     *
     * The hard-bounce names come from {@see EmailEventApplier::HARD_BOUNCE_EVENTS},
     * so a new hard-bounce spelling follows. That list also holds `blocked`,
     * so `blocked` is matched first and keeps its own outcome.
     *
     * @param string $event The `er_email_events.event` value
     * @return self|null The outcome, or null for `sent` and for an unknown name
     */
    public static function fromEvent(string $event): ?self
    {
        return match (true) {
            $event === 'delivered' => self::Delivered,
            $event === 'blocked' => self::Blocked,
            $event === 'soft_bounce' => self::SoftBounce,
            $event === 'unsubscribed' => self::Unsubscribed,
            $event === 'spam' => self::SpamComplaint,
            in_array($event, EmailEventApplier::HARD_BOUNCE_EVENTS, true) => self::HardBounce,
            default => null,
        };
    }

    /**
     * Get the display text of the outcome.
     *
     * @return string The label, for example "Hard bounce"
     */
    public function label(): string
    {
        return match ($this) {
            self::Delivered => 'Delivered',
            self::HardBounce => 'Hard bounce',
            self::SoftBounce => 'Soft bounce',
            self::Blocked => 'Blocked',
            self::Unsubscribed => 'Unsubscribed',
            self::SpamComplaint => 'Spam complaint',
            self::None => 'No outcome',
        };
    }
}
