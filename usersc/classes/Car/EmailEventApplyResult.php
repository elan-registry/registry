<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * EmailEventApplyResult - What one {@see EmailEventApplier::apply()} call did
 *
 * `eventRowWritten` is true when the call wrote a new `er_email_events` row.
 * `flagApplied` is true only when the call ran the flag step for the
 * owner's current address. It is false when the owner has no `users` row,
 * a date guard fails, the owner has no `profiles` row, the event address is
 * not the current address, or a {@see EmailEventApplier::MODE_EVENT} event
 * is a duplicate. In {@see EmailEventApplier::MODE_STATE} a duplicate event
 * row can still apply the flag.
 *
 * `flagApplied` true does not mean that a flag changed: the flag step can
 * keep the flag as it is (for example, a soft bounce below the escalation
 * threshold, or a block that is not later than the last admin Clear Block).
 *
 * @package ElanRegistry\Car
 * @since v2.30.8
 * @see https://github.com/elan-registry/registry/issues/2393
 */
final readonly class EmailEventApplyResult
{
    /**
     * @param bool $eventRowWritten True when the call wrote a new event row
     * @param bool $flagApplied True when the flag step ran for the current address
     */
    public function __construct(
        public bool $eventRowWritten,
        public bool $flagApplied,
    ) {}
}
