<?php

declare(strict_types=1);

namespace ElanRegistry\Email;

use DateTimeImmutable;

/**
 * DeliveryLogEntry - one mail on the admin email delivery tab (#2102)
 *
 * The mail fields come from `plg_sendinblue_messages`. The outcome fields,
 * the user ID and the car ID come from the chosen outcome event (see
 * {@see DeliveryOutcomeResolver}). A mail with no outcome event has outcome
 * {@see DeliveryOutcome::None} and null outcome fields.
 *
 * @package ElanRegistry\Email
 * @since v2.31.1
 * @see https://github.com/elan-registry/registry/issues/2102
 */
final readonly class DeliveryLogEntry
{
    /**
     * @param int                    $id          `plg_sendinblue_messages.id`
     * @param string                 $messageId   The Brevo message ID
     * @param string                 $recipient   The recipient address. The retention purge empties it, but only
     *                                            in rows older than the window that the tab reads, so the tab
     *                                            normally never sees an empty address.
     * @param string|null            $tags        The plugin's comma-separated tags, or null
     * @param DateTimeImmutable      $acceptedAt  When Brevo accepted the mail
     * @param DeliveryOutcome        $outcome     The outcome of the chosen event, or None
     * @param DateTimeImmutable|null $outcomeAt   When the chosen event occurred, or null
     * @param string|null            $reason      The reason of the chosen event, or null
     * @param int|null               $userId      The user ID of the chosen event, or null
     * @param int|null               $carId       The car ID of the chosen event, or null
     */
    public function __construct(
        public int $id,
        public string $messageId,
        public string $recipient,
        public ?string $tags,
        public DateTimeImmutable $acceptedAt,
        public DeliveryOutcome $outcome,
        public ?DateTimeImmutable $outcomeAt,
        public ?string $reason,
        public ?int $userId,
        public ?int $carId,
    ) {
    }
}
