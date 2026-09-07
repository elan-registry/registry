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
    /** Payload parsed, recipient matched a car, and the write(s) committed. Maps to 2xx. */
    case MATCHED_AND_RECORDED;

    /** Payload parsed but carries no recognized verification-system tag. Maps to 2xx, logged. */
    case NO_TAG_MATCH;

    /** Payload parsed and tagged, but the recipient matches no car. Maps to 2xx, logged, unmatched counter incremented. */
    case NO_CAR_MATCH;

    /** Payload is not a JSON object, or is missing a required field. Maps to 4xx, logged. */
    case MALFORMED;

    /** A database write failed. The only retryable case — maps to 5xx. */
    case WRITE_FAILURE;
}
