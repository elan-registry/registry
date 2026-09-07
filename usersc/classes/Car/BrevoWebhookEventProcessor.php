<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\AppConstants;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;

/**
 * BrevoWebhookEventProcessor - Parsing, matching, and escalation logic for
 * inbound Brevo delivery-status webhook events
 *
 * Owns all parsing/matching/escalation *logic* for issue #1887; no HTTP
 * concerns (`http_response_code()`, `exit`) live here — that separation is
 * what makes this class unit-testable without the subprocess dance the
 * endpoint itself needs (see `app/api/webhooks/brevo.php`).
 *
 * ESCALATION RULES:
 * - `hard_bounce` / `blocked` / `invalid` immediately flag the car as bounced.
 * - `soft_bounce` does not immediately change anything. After the event row
 *   is recorded, {@see CarRepository::countSoftBouncesSinceLastDelivered()}
 *   is consulted; at 3 or more distinct send cycles (distinct
 *   `brevo_message_id` values) since the email's last `delivered` event, the
 *   car is escalated to bounced via the same path as a hard bounce.
 * - `delivered` and `unique_opened` only ever record an event row. `delivered`
 *   implicitly resets the soft-bounce escalation window (the count query is
 *   scoped to occur after the most recent `delivered` row), so there is no
 *   separate "clear escalation" write. `unique_opened` never touches flags or
 *   the escalation count under any circumstance.
 * - `spam` flags the car as suppressed (a distinct signal from a bounce).
 *
 * @package ElanRegistry\Car
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1887
 */
final class BrevoWebhookEventProcessor
{
    /**
     * Number of distinct soft-bounce send cycles since the last delivery that
     * escalates a car to a confirmed bounce.
     */
    private const SOFT_BOUNCE_ESCALATION_THRESHOLD = 3;

    /** Brevo event names that immediately count as a confirmed bounce. */
    private const HARD_BOUNCE_EVENTS = ['hard_bounce', 'blocked', 'invalid'];

    /**
     * Brevo event names known to have no flag/escalation effect. Anything
     * outside this list still records its event row (Brevo may add or rename
     * event types at any time) but is logged, since an unrecognized event is
     * more likely a payload-contract change than routine traffic.
     */
    private const KNOWN_INERT_EVENTS = ['delivered', 'unique_opened'];

    /**
     * Latest Unix timestamp `resolveOccurredAt()` will accept (9999-12-31
     * 23:59:59 UTC). Bounds a malformed/overflowing `ts_event` so it cannot
     * produce a DATETIME string MySQL rejects — which would otherwise turn
     * one poisoned payload into a permanent Brevo retry loop, since the
     * resulting CarDatabaseException maps to a retryable 5xx.
     */
    private const MAX_PLAUSIBLE_TIMESTAMP = 253402300799;

    /** Matches er_email_events.event's column width (migration 20260907141817). */
    private const MAX_EVENT_LENGTH = 32;

    /** Matches er_email_events.brevo_message_id's column width (migration 20260907141817). */
    private const MAX_MESSAGE_ID_LENGTH = 255;

    public function __construct(
        private CarRepository $repo,
        private CarVerificationManager $verificationManager,
    ) {}

    /**
     * Process one decoded Brevo webhook payload
     *
     * @param mixed $decodedPayload The result of json_decode($rawBody, true) —
     *                              typed mixed because the caller has not yet
     *                              validated its shape; that validation is this
     *                              method's first job.
     * @return ProcessingResult The outcome, which the endpoint maps 1:1 to an
     *                          HTTP status.
     */
    public function process(mixed $decodedPayload): ProcessingResult
    {
        if (!is_array($decodedPayload) || !$this->isAssociative($decodedPayload)) {
            // Rejects a top-level JSON array explicitly — Brevo's `batched: false`
            // guarantee means a list body is never legitimate here.
            return ProcessingResult::MALFORMED;
        }

        $email = $decodedPayload['email'] ?? null;
        $event = $decodedPayload['event'] ?? null;
        $messageId = $decodedPayload['message-id'] ?? null;

        if (!is_string($email) || $email === ''
            || !is_string($event) || $event === ''
            || !is_string($messageId) || $messageId === ''
        ) {
            return ProcessingResult::MALFORMED;
        }

        // Bounded against er_email_events' actual column widths
        // (event varchar(32), brevo_message_id varchar(255)) so an
        // oversized value is rejected here as MALFORMED (4xx, no retry)
        // rather than reaching insertEmailEvent() and throwing a
        // CarDatabaseException under STRICT_TRANS_TABLES — which would map
        // to WRITE_FAILURE/5xx and put Brevo into a permanent retry loop on
        // one poisoned payload, the same failure mode MAX_PLAUSIBLE_TIMESTAMP
        // exists to prevent for ts_event.
        if (strlen($event) > self::MAX_EVENT_LENGTH || strlen($messageId) > self::MAX_MESSAGE_ID_LENGTH) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo webhook: event or message-id exceeds storage width (event=%d bytes, message-id=%d bytes).',
                strlen($event),
                strlen($messageId)
            ));
            return ProcessingResult::MALFORMED;
        }

        $tags = $decodedPayload['tags'] ?? [];
        if (!is_array($tags)) {
            // Not NO_TAG_MATCH: a non-list `tags` means Brevo's payload
            // contract changed, not that this event is untagged. MALFORMED
            // gives a 4xx an operator will notice, instead of a 200 that
            // would silently discard every bounce event from here on — an
            // untagged/no-match 200 is expected, routine traffic, so this
            // shape needs a status that stands out from that noise.
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo webhook: "tags" was %s, expected a list — payload contract may have changed.',
                get_debug_type($tags)
            ));
            return ProcessingResult::MALFORMED;
        }
        if (!in_array(AppConstants::VERIFICATION_EMAIL_TAG, array_values($tags), true)) {
            return ProcessingResult::NO_TAG_MATCH;
        }

        $reason = $decodedPayload['reason'] ?? null;
        $reason = is_string($reason) ? $reason : null;

        $occurredAt = $this->resolveOccurredAt($decodedPayload, $email, $event);

        try {
            $matchedCars = $this->repo->findByEmail($email);
        } catch (CarDatabaseException $e) {
            $this->logFailure($email, $event, $e);
            return ProcessingResult::WRITE_FAILURE;
        }

        if ($matchedCars === []) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo webhook: event "%s" (message-id %s) for %s matched no car.',
                $event,
                $messageId,
                $email
            ));
            return ProcessingResult::NO_CAR_MATCH;
        }

        foreach ($matchedCars as $car) {
            try {
                $this->applyEventToCar((int) $car->id, $email, $event, $reason, $messageId, $occurredAt);
            } catch (CarDatabaseException $e) {
                // Fail the whole request on the first write failure, even if
                // earlier cars in this loop already succeeded — Brevo's retry
                // is safe against this because every write here is
                // ON DUPLICATE KEY UPDATE-idempotent (insertEmailEvent) or a
                // plain column UPDATE (setBounced/setSuppressed).
                $this->logFailure($email, $event, $e);
                return ProcessingResult::WRITE_FAILURE;
            }
        }

        return ProcessingResult::MATCHED_AND_RECORDED;
    }

    /**
     * Record the event for one matched car and apply any resulting escalation
     *
     * @throws CarDatabaseException If any write fails
     */
    private function applyEventToCar(
        int $carId,
        string $email,
        string $event,
        ?string $reason,
        string $messageId,
        string $occurredAt
    ): void {
        $this->repo->insertEmailEvent($carId, $email, $event, $reason, $messageId, $occurredAt);

        if (in_array($event, self::HARD_BOUNCE_EVENTS, true)) {
            $this->verificationManager->setBounced((object) ['id' => $carId], $email);
            return;
        }

        if ($event === 'soft_bounce') {
            $cycles = $this->repo->countSoftBouncesSinceLastDelivered($email);
            if ($cycles >= self::SOFT_BOUNCE_ESCALATION_THRESHOLD) {
                $this->verificationManager->setBounced((object) ['id' => $carId], $email);
            }
            return;
        }

        if ($event === 'spam') {
            $this->verificationManager->setSuppressed((object) ['id' => $carId]);
            return;
        }

        // 'delivered', 'unique_opened': the event row itself (already written
        // above) is the only effect. Anything else reaching here is a Brevo
        // event name this processor doesn't recognize — still recorded (Brevo
        // may add/rename event types at any time and a dropped row would be
        // worse), but logged, since an unrecognized event is more likely a
        // payload-contract change than routine traffic and would otherwise
        // silently disable bounce detection for that event type.
        if (!in_array($event, self::KNOWN_INERT_EVENTS, true)) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo webhook: unrecognized event "%s" recorded for car %d with no flag change'
                . ' — Brevo may have added or renamed an event type.',
                $event,
                $carId
            ));
        }
    }

    /**
     * Resolve the event's occurrence timestamp from the payload
     *
     * Brevo sends a Unix timestamp in `ts_event` (or `ts` as a fallback on
     * some event types); an absent or unparseable value falls back to "now"
     * rather than rejecting an otherwise-valid, tag-matched, car-matched
     * event over a missing timestamp field — but the fallback is logged, since
     * it silently skews the `occurred_at` ordering
     * {@see CarRepository::countSoftBouncesSinceLastDelivered()} depends on.
     *
     * A value is only trusted if it parses as digits AND falls at or below
     * {@see self::MAX_PLAUSIBLE_TIMESTAMP} — an overflowing value (verified:
     * `(int) "999999999999999999999999"` produces a `DATETIME` string of year
     * 292277026596) would otherwise reach `CarRepository::insertEmailEvent()`
     * as a value MySQL rejects, turning one poisoned payload into a permanent
     * Brevo retry loop (the resulting CarDatabaseException maps to a
     * retryable 5xx, and Brevo resends the same payload forever).
     *
     * @param string $email Recipient email, for the fallback's log context only
     * @param string $event Brevo event name, for the fallback's log context only
     */
    private function resolveOccurredAt(array $payload, string $email, string $event): string
    {
        $ts = $payload['ts_event'] ?? $payload['ts'] ?? null;
        if (is_int($ts) || (is_string($ts) && ctype_digit($ts))) {
            $seconds = (int) $ts;
            if ($seconds > 0 && $seconds <= self::MAX_PLAUSIBLE_TIMESTAMP) {
                return date(AppConstants::DATETIME_FORMAT, $seconds);
            }
        }

        logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
            'Brevo webhook: event "%s" for %s carried no usable ts_event/ts (%s);'
            . ' occurred_at defaulted to receipt time — soft-bounce windowing may be skewed.',
            $event,
            $email,
            get_debug_type($ts)
        ));
        return date(AppConstants::DATETIME_FORMAT);
    }

    /**
     * True if $array is associative (a JSON object), false for a JSON list
     *
     * PHP's json_decode(..., true) represents both a `{}` object and a `[]`
     * list as an ordinary array — this distinguishes them by checking whether
     * the keys are the default sequential integer sequence. An empty array
     * (`{}` or `[]`, indistinguishable in this representation) is treated as
     * associative, which is fine here: it will fail the subsequent required-field
     * check regardless.
     */
    private function isAssociative(array $array): bool
    {
        return $array === [] || array_keys($array) !== range(0, count($array) - 1);
    }

    private function logFailure(string $email, string $event, CarDatabaseException $e): void
    {
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
            'Failed to process Brevo webhook event "%s" for %s: %s',
            $event,
            $email,
            $e->getMessage()
        ));
    }
}
