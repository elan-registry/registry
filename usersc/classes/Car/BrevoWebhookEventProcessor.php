<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\AppConstants;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ExceptionLogSummary;
use ElanRegistry\LogCategories;

/**
 * BrevoWebhookEventProcessor - Parse, match and record one inbound Brevo
 * delivery-status webhook event
 *
 * Owns the parsing and dispatch logic of the webhook (#1887). No HTTP code
 * (`http_response_code()`, `exit`) lives here, so the class is unit-testable
 * without the subprocess that the endpoint itself needs (see
 * `app/api/webhooks/brevo.php`).
 *
 * Every registry mail counts, not only verification mail (#2359). There is no
 * tag filter. The steps:
 *
 * 1. Accept only the delivery events in {@see self::DELIVERY_EVENTS}. Any
 *    other event (for example `opened` or `click`) is {@see ProcessingResult::IGNORED},
 *    and the log line names the event only. The webhook subscribes only to
 *    delivery events, so another name shows that Brevo changed its events.
 * 2. Reject an address longer than {@see EmailEventMatcher::MAX_ADDRESS_BYTES} bytes, or
 *    one that fails `FILTER_VALIDATE_EMAIL`, before any match.
 * 3. {@see EmailEventMatcher::matchByMessageId()} finds the user. A message
 *    that this environment did not send is {@see ProcessingResult::IGNORED},
 *    with no row and no log line.
 * 4. {@see EmailEventApplier::apply()} records the event and applies the
 *    flag in one transaction. The escalation rules live there, so the
 *    reconciliation job escalates the same way.
 * 5. `sendinblueRecordEvent()` puts the event on the plugin's message row,
 *    when the installed plugin has that function. This step also runs for
 *    an event that matched no user, which has no registry row.
 *
 * A stored value that cannot be parsed (for example a zero message time) is
 * {@see ProcessingResult::MALFORMED}. It fails the same way on every retry.
 *
 * No log line or exception message of this class holds an email address.
 * A line names the user ID or, with no user, {@see EmailEventMatcher::addressLogHash()}.
 *
 * @package ElanRegistry\Car
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1887
 * @see https://github.com/elan-registry/registry/issues/2359
 */
final class BrevoWebhookEventProcessor
{
    /**
     * Brevo webhook event names that the registry records.
     *
     * `invalid` and `invalid_email` are both listed, because no live Brevo
     * traffic has shown which spelling it sends (see
     * {@see EmailEventApplier::HARD_BOUNCE_EVENTS}).
     *
     * @var list<string>
     */
    public const DELIVERY_EVENTS = [
        'delivered',
        'soft_bounce',
        'hard_bounce',
        'blocked',
        'invalid',
        'invalid_email',
        'spam',
        'unsubscribed',
    ];

    /**
     * Latest Unix timestamp `resolveOccurredAt()` will accept (9999-12-31
     * 23:59:59 UTC). Bounds a malformed/overflowing `ts_event`. The
     * connection runs with `sql_mode = ''`, so MySQL does not reject an
     * out-of-range DATETIME. It stores `0000-00-00 00:00:00`, which reads
     * as the earliest event and breaks the date guards and the event order.
     */
    private const MAX_PLAUSIBLE_TIMESTAMP = 253402300799;

    /**
     * Seconds that `ts_event` can be later than receipt. A later time is
     * treated as missing, because a far-future `delivered` row would stop
     * soft-bounce escalation until that time.
     */
    private const MAX_FUTURE_SKEW_SECONDS = 300;

    /** Matches er_email_events.brevo_message_id's column width (migration 20260907141817). */
    private const MAX_MESSAGE_ID_LENGTH = 255;

    /**
     * Longest event name that the log line of an ignored event keeps, in
     * characters. Brevo sends the name, so a long value must not fill the log.
     */
    private const MAX_LOGGED_EVENT_CHARS = 32;

    /**
     * @param EmailEventMatcher $matcher Finds the user that the event belongs to.
     * @param EmailEventApplier $applier Owns the event→escalation mapping,
     *                                   shared with the #1889 reconciliation job.
     */
    public function __construct(
        private EmailEventMatcher $matcher,
        private EmailEventApplier $applier,
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

        if (!in_array($event, self::DELIVERY_EVENTS, true)) {
            // The webhook subscribes only to delivery events
            // (scripts/spike-1888/brevo-register-webhook.php). Another name
            // shows that Brevo added or renamed an event type, and a renamed
            // bounce event would otherwise stop bounce detection with no sign.
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo webhook: ignored event "%s", which is not a delivery event.'
                . ' Brevo may have added or renamed an event type.',
                mb_substr(mb_scrub($event, 'UTF-8'), 0, self::MAX_LOGGED_EVENT_CHARS)
            ));
            return ProcessingResult::IGNORED;
        }

        // The connection sets sql_mode '' (users/classes/DB.php), so MySQL
        // would silently cut an oversized message ID. The stored ID would not
        // be Brevo's ID, and two IDs with the same first 255 bytes would be
        // one row. A 4xx is correct, because a retry fails the same way.
        if (strlen($messageId) > self::MAX_MESSAGE_ID_LENGTH) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo webhook: message-id exceeds storage width (%d bytes).',
                strlen($messageId)
            ));
            return ProcessingResult::MALFORMED;
        }

        $address = trim($email);
        if (!EmailEventMatcher::isAcceptableAddress($address)) {
            return ProcessingResult::MALFORMED;
        }

        $reason = $decodedPayload['reason'] ?? null;
        $reason = is_string($reason) ? $reason : null;

        [$occurredAt, $occurredAtSeconds, $timeFallback] = $this->resolveOccurredAt($decodedPayload);

        try {
            $match = $this->matcher->matchByMessageId($messageId, $address);
        } catch (CarDatabaseException $e) {
            $this->logFailure('match', $event, $e);
            return ProcessingResult::WRITE_FAILURE;
        } catch (\InvalidArgumentException $e) {
            // A bad stored row (for example a zero message time) fails on
            // every retry, so a 5xx would make Brevo resend it forever.
            $this->logDataFault('match', $event, $e, 'address hash ' . EmailEventMatcher::addressLogHash($address));
            return ProcessingResult::MALFORMED;
        }

        if ($match->isNotOurs()) {
            return ProcessingResult::IGNORED;
        }

        if ($match->isNoUser()) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo webhook: event "%s" matched no single user (address hash %s).',
                $event,
                EmailEventMatcher::addressLogHash($address)
            ));
            $this->recordOnPluginRow($messageId, $event, $occurredAtSeconds, $reason);
            return ProcessingResult::NO_USER_MATCH;
        }

        if ($timeFallback !== null) {
            // The event time orders the soft-bounce window, so say when it is a guess.
            logger($match->userId(), LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo webhook: event "%s" for user %d had %s; occurred_at is the receipt time.',
                $event,
                $match->userId(),
                $timeFallback
            ));
        }

        try {
            $this->applier->apply(
                $match,
                $address,
                $event,
                $reason,
                $messageId,
                $occurredAt,
                DeliverabilityFlagLog::SOURCE_WEBHOOK,
                EmailEventApplier::MODE_EVENT
            );
        } catch (CarDatabaseException $e) {
            // The applier rolled back the event row and the flag, so a
            // Brevo retry starts clean.
            $this->logFailure('apply', $event, $e, $match->userId());
            return ProcessingResult::WRITE_FAILURE;
        } catch (CarValidationException | \InvalidArgumentException $e) {
            $this->logDataFault('apply', $event, $e, "user {$match->userId()}", $match->userId());
            return ProcessingResult::MALFORMED;
        }

        $this->recordOnPluginRow($messageId, $event, $occurredAtSeconds, $reason);

        return ProcessingResult::MATCHED_AND_RECORDED;
    }

    /**
     * Tell whether a payload carries the verification-mail tag
     *
     * The endpoint counts an unmatched recipient only for verification mail.
     * A `tags` value that is present but not a list is logged, because it
     * shows a change of Brevo's payload contract. The counter would
     * otherwise stop with no sign.
     *
     * @param mixed $decodedPayload The decoded webhook payload
     * @return bool True when `tags` is a list that holds {@see AppConstants::VERIFICATION_EMAIL_TAG}
     */
    public static function hasVerificationTag(mixed $decodedPayload): bool
    {
        if (!is_array($decodedPayload)) {
            return false;
        }
        $tags = $decodedPayload['tags'] ?? null;
        if ($tags === null) {
            return false;
        }
        if (!is_array($tags)) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo webhook: "tags" was %s, expected a list. The payload contract may have changed.',
                get_debug_type($tags)
            ));
            return false;
        }

        return in_array(AppConstants::VERIFICATION_EMAIL_TAG, array_values($tags), true);
    }

    /**
     * Resolve the event's occurrence time from the payload
     *
     * Brevo sends a Unix timestamp in `ts_event` (or `ts` on some event
     * types). A missing, unparseable or out-of-range value becomes the receipt
     * time, and the third element names the problem for the log line. The
     * caller logs it after the match, because the line names the user.
     *
     * A value is only trusted if it parses as digits, falls at or below
     * {@see self::MAX_PLAUSIBLE_TIMESTAMP}, and is not more than
     * {@see self::MAX_FUTURE_SKEW_SECONDS} after receipt. An overflowing value
     * would reach the insert as an out-of-range DATETIME, which MySQL stores
     * as a zero date (`sql_mode = ''`).
     *
     * @param array<mixed> $payload The decoded payload
     * @return array{0: string, 1: int, 2: ?string} The time in
     *         AppConstants::DATETIME_FORMAT, the same time in Unix seconds,
     *         and the fallback reason or null
     */
    private function resolveOccurredAt(array $payload): array
    {
        $receivedAt = time();
        $ts = $payload['ts_event'] ?? $payload['ts'] ?? null;
        $fallback = 'no usable ts_event/ts (' . get_debug_type($ts) . ')';

        if (is_int($ts) || (is_string($ts) && ctype_digit($ts))) {
            $seconds = (int) $ts;
            if ($seconds > $receivedAt + self::MAX_FUTURE_SKEW_SECONDS && $seconds <= self::MAX_PLAUSIBLE_TIMESTAMP) {
                $fallback = 'a ts_event more than 5 minutes after receipt';
            } elseif ($seconds > 0 && $seconds <= self::MAX_PLAUSIBLE_TIMESTAMP) {
                return [date(AppConstants::DATETIME_FORMAT, $seconds), $seconds, null];
            }
        }

        return [date(AppConstants::DATETIME_FORMAT, $receivedAt), $receivedAt, $fallback];
    }

    /**
     * Put the event on the sendinblue plugin's message row, when the plugin can
     *
     * The plugin function is optional (#2355). A failure here is logged and
     * never changes the response. The registry's own work for the event is
     * already done: the applier committed, or the event matched no user and
     * has no registry row.
     */
    private function recordOnPluginRow(string $messageId, string $event, int $occurredAtSeconds, ?string $reason): void
    {
        if (!function_exists('sendinblueRecordEvent')) {
            return;
        }

        try {
            $recorded = sendinblueRecordEvent($messageId, $event, $occurredAtSeconds, $reason ?? '');
        } catch (\Throwable $e) {
            // The message can hold an address, so the log line has no message.
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo webhook: sendinblueRecordEvent() threw %s for event "%s".',
                ExceptionLogSummary::of($e),
                $event
            ));
            return;
        }

        if ($recorded === false) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo webhook: sendinblueRecordEvent() returned false for event "%s".',
                $event
            ));
        }
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

    /**
     * Log a database failure without its message
     *
     * CarRepository puts the address in its exception messages, so the line
     * holds the {@see ExceptionLogSummary} of the exception, not its message.
     */
    private function logFailure(string $step, string $event, CarDatabaseException $e, int $userId = 0): void
    {
        logger($userId, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
            'Brevo webhook: the %s step failed for event "%s"%s: %s.',
            $step,
            $event,
            $userId > 0 ? " and user {$userId}" : '',
            ExceptionLogSummary::of($e)
        ));
    }

    /**
     * Log a permanent data fault without its message
     *
     * The message can hold a stored value of the owner, so the line holds
     * the {@see ExceptionLogSummary} of the exception, not its message.
     *
     * @param string $subject "user N" or "address hash H", never an address
     */
    private function logDataFault(string $step, string $event, \Throwable $e, string $subject, int $userId = 0): void
    {
        logger($userId, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
            'Brevo webhook: the %s step rejected event "%s" for %s as malformed data: %s.',
            $step,
            $event,
            $subject,
            ExceptionLogSummary::of($e)
        ));
    }
}
