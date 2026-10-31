<?php

declare(strict_types=1);

namespace ElanRegistry\Transfer;

/**
 * Result of the transfer request notice to the current owner
 *
 * A bool cannot tell a send policy skip from a delivery. The endpoint and the
 * admin alert must know when the owner got no notice, so sendRequest()
 * returns this enum.
 *
 * @since v2.31.0
 * @see https://github.com/elan-registry/registry/issues/2476
 * @see TransferEmailService::sendRequest()
 */
enum TransferNoticeOutcome: string
{
    /** The mailer accepted the notice. */
    case Sent = 'sent';

    /** The send policy refused the notice (no profile, bounced, opted out or blocked). */
    case Skipped = 'skipped';

    /** A lookup failed, the mailer returned false, or an error occurred. */
    case Failed = 'failed';

    /**
     * Tell if the current owner got the notice
     *
     * @return bool True only for Sent
     */
    public function ownerWasNotified(): bool
    {
        return match ($this) {
            self::Sent                 => true,
            self::Skipped, self::Failed => false,
        };
    }
}
