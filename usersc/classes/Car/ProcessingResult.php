<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * ProcessingResult - Outcome of BrevoWebhookEventProcessor::process()
 *
 * The webhook endpoint (`app/api/webhooks/brevo.php`) maps each case 1:1 to
 * an HTTP status per the contract documented in
 * docs/development/EMAIL_SYSTEM.md.
 *
 * @package ElanRegistry\Car
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1887
 */
enum ProcessingResult
{
    /** The event matched one user, and the applier's transaction committed. Maps to 2xx. */
    case MATCHED_AND_RECORDED;

    /**
     * Not a delivery event (for example `opened`), or a message that this
     * environment did not send. Maps to 2xx, no row, no log line.
     */
    case IGNORED;

    /**
     * The message is ours, but no single user holds the address. Maps to 2xx,
     * no row, logged. A verification-tagged event increments the unmatched counter.
     */
    case NO_USER_MATCH;

    /**
     * The payload is not a JSON object, misses a required field, or has a bad
     * address or a message ID over 255 bytes. Or the match or apply step
     * cannot parse a stored value (for example a zero message time). Maps to
     * 4xx, logged. A retry cannot succeed, so the status asks for none.
     */
    case MALFORMED;

    /** A database read or write failed. Maps to 5xx, so Brevo can retry. */
    case WRITE_FAILURE;
}
