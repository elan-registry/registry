<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\AppConstants;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\LogCategories;

/**
 * EmailEventApplier - Records one Brevo delivery-status event for one owner
 * and applies whatever verification-flag escalation that event implies
 *
 * The webhook, the reconciliation job (#1889) and the suppression import
 * (#1923) all call this class, so all three escalate an owner the same way.
 *
 * The class takes parsed and checked values, not a payload. Each caller
 * keeps its own parsing (webhook POST body, Brevo API poll or blocklist).
 *
 * {@see EmailEventMatcher} finds the owner first. This class then records the
 * event and applies its flag in one transaction that starts with a lock on
 * the owner's `profiles` row (#2359). The steps are on {@see apply()}.
 *
 * The flags belong to the owner's profile, not to the car (#2101). The event
 * row belongs to the owner too (#2358). It has the car of the matched
 * verification `sent` row, or `car_id` NULL when the event names no car. A
 * user that does not exist, or the `noowner` system account, gets no row.
 * {@see CarVerificationManager} writes the profile, with the flag's address
 * and time.
 *
 * ESCALATION RULES:
 * - `hard_bounce` / `blocked` / `invalid` / `invalid_email` immediately set
 *   the owner's `email_bounced`, with `email_bounced_at` = the event time.
 * - `soft_bounce` does not immediately change anything. After the event row is
 *   recorded, {@see CarRepository::countSoftBouncesSinceLastDelivered()} is
 *   consulted; at {@see self::SOFT_BOUNCE_ESCALATION_THRESHOLD} or more distinct
 *   send cycles (distinct `brevo_message_id` values) since the email's last
 *   `delivered` event, the owner's last Clear Bounced and the owner's last
 *   address change (`profiles.email_address_since`), the owner is
 *   escalated to bounced via the same path as a hard bounce, with
 *   `email_bounced_at` = the time of the soft bounce that escalated.
 * - `delivered` only records an event row. It resets the soft-bounce
 *   escalation window, because the count query starts after the most recent
 *   `delivered` row. So there is no separate "clear escalation" write.
 * - `unique_opened` records an event row and never touches a flag or the
 *   escalation count. No caller passes it now. It stays a known event so
 *   that a call from an older caller is not logged as unrecognized.
 * - `spam` and `unsubscribed` set the owner's `email_blocked` (a distinct
 *   signal from a bounce), with cause `spam` or `unsubscribed`. Each is
 *   recorded under its own event name so the two remain distinguishable in
 *   `er_email_events`.
 *
 * Each caller passes its source (`webhook`, `reconciliation` or `import`),
 * which goes into the flag-change audit row ({@see DeliverabilityFlagLog}).
 *
 * Not `final`, for the same reason {@see CarRepository} and
 * {@see CarVerificationManager} are not: it is an injected collaborator of
 * {@see BrevoWebhookEventProcessor},
 * {@see \ElanRegistry\Cron\BrevoEventReconciliationJob}, and
 * {@see \ElanRegistry\Cron\BrevoSuppressionSyncJob}, whose unit tests must
 * substitute it. Subclassing outside of test doubles is not intended — the
 * escalation rules above are meant to have exactly one implementation, which
 * is precisely why all three callers share this class.
 *
 * @package ElanRegistry\Car
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1887 Original webhook caller
 * @see https://github.com/elan-registry/registry/issues/1889 Reconciliation job caller
 * @see https://github.com/elan-registry/registry/issues/1923 Suppression sync caller
 */
class EmailEventApplier
{
    /**
     * Number of distinct soft-bounce send cycles since the last delivery that
     * escalates an owner to a confirmed bounce.
     */
    private const SOFT_BOUNCE_ESCALATION_THRESHOLD = 3;

    /**
     * Brevo event names that immediately count as a confirmed bounce.
     *
     * Both `invalid` and `invalid_email` are listed: the spike behind this
     * issue (#1871) never actually observed this event live, and
     * EMAIL_SYSTEM.md's own "Design deltas" section names `invalid_email` as
     * the expected wire value while this project's original issue text used
     * `invalid` — neither has been confirmed against real Brevo traffic.
     * Accepting both costs nothing (they're mutually exclusive event names)
     * and avoids a silent miss on whichever one Brevo actually sends.
     *
     * Public for the same reason as {@see self::SUPPRESSION_EVENTS}: read-only
     * consumers need this vocabulary to tell whether a stored
     * `er_email_events.event` value is the bounce that set a car's flag (the
     * admin user form only dates its Bounced column from a matching event).
     * This class stays the single owner of the list.
     */
    public const HARD_BOUNCE_EVENTS = ['hard_bounce', 'blocked', 'invalid', 'invalid_email'];

    /**
     * Brevo event names known to have no flag or escalation effect
     *
     * An event in no known list (inert, bounce or suppression) still records
     * its row, because Brevo can add or rename an event type at any time. It
     * is logged once, when its row is new. An unknown event is more likely a
     * change of the payload contract than routine traffic.
     */
    private const KNOWN_INERT_EVENTS = ['delivered', 'unique_opened'];

    /**
     * Brevo event names that set `email_blocked` on the car owner's profile,
     * not `email_bounced`.
     *
     * `unsubscribed` comes from the webhook, the event reconciliation job
     * and the suppression-list import job (#1923). It is recorded under its own event name and never
     * as `spam`, so an audit can tell the two apart in `er_email_events`.
     *
     * Public because read-only consumers outside this class need the same
     * vocabulary to interpret stored `er_email_events.event` values (e.g.
     * CarRepository's Status chip query). This class remains the single owner
     * of the list. Callers read it and never redefine it.
     */
    public const SUPPRESSION_EVENTS = ['spam', 'unsubscribed'];

    /**
     * The `profiles.email_blocked_cause` that each suppression event sets.
     *
     * @var array<string, string>
     */
    private const SUPPRESSION_EVENT_CAUSES = [
        'spam'         => CarRepository::BLOCKED_CAUSE_SPAM,
        'unsubscribed' => CarRepository::BLOCKED_CAUSE_UNSUBSCRIBED,
    ];

    /**
     * Event names whose message time must not be before `email_bounce_cleared_at`.
     *
     * @var list<string>
     */
    private const BOUNCE_EVENTS = [...self::HARD_BOUNCE_EVENTS, 'soft_bounce'];

    /**
     * Mode of the webhook and the reconciliation job. The flag changes only
     * for a new event row.
     */
    public const MODE_EVENT = 'event';

    /**
     * Mode of the suppression import. Brevo's blocklist is a state, not an
     * event, so the flag changes when it is clear or names another address.
     */
    public const MODE_STATE = 'state';

    /** Longest `reason` that the event row keeps, in characters. */
    private const REASON_MAX_CHARS = 255;

    public function __construct(
        private CarRepository $repo,
        private CarVerificationManager $verificationManager,
    ) {}

    /**
     * Record one matched event for its owner and apply its flag, in one transaction
     *
     * The steps:
     *
     * 1. Lock the owner's `profiles` row, then read `users.email`,
     *    `join_date`, `email_address_since` and `email_bounce_cleared_at`
     *    ({@see CarRepository::lockEventGuardState()}).
     * 2. No `users` row: record nothing, and log the user ID.
     * 3. Date guards. {@see self::MODE_EVENT}: the message time must not be
     *    before `join_date` or `email_address_since`. For a bounce event it
     *    must also not be before `email_bounce_cleared_at`.
     *    {@see self::MODE_STATE}: Brevo's blocklist is the current state, and
     *    the import matched the user by the current address, so the
     *    `join_date` and `email_address_since` guards do not apply. A bounce
     *    event uses `$occurredAt` (Brevo's `blockedAt`) for the
     *    `email_bounce_cleared_at` guard. A NULL column sets no bound. A
     *    failed guard records nothing, and logs the user ID and the guard.
     * 4. Insert the event row. A duplicate changes nothing and is not new.
     * 5. No `profiles` row: keep the row, log the user ID, set no flag.
     * 6. The flag changes only when the event address is the current
     *    `users.email`. {@see self::MODE_EVENT}: only for a new row.
     *    {@see self::MODE_STATE}: when the flag is clear or names another
     *    address (the profile writers skip a repeat of the same address).
     * 7. `email_blocked` changes only when `$occurredAt` is later than the
     *    latest admin Clear Block ({@see CarRepository::latestFlagClearAt()}),
     *    in both modes. The compare uses wall time. `$blockedCause`, when
     *    set, replaces the cause that the event name gives.
     * 8. Commit. A failure rolls back the event row, the flag and its audit
     *    row, then the exception goes to the caller.
     *
     * @param EmailEventMatch $match A {@see EmailEventMatch::matched()} result
     * @param string $address Recipient address from the event or the import
     * @param string $event Event name (for example `hard_bounce`)
     * @param string|null $reason Reason text. Cut to 255 characters of valid UTF-8.
     * @param string $messageId Brevo's message ID, or the import's own ID
     * @param string $occurredAt Event time (Brevo's `blockedAt` for the import),
     *                           in AppConstants::DATETIME_FORMAT
     * @param string $source One of {@see DeliverabilityFlagLog::SOURCES},
     *                       normally `webhook`, `reconciliation` or `import`
     * @param string $mode {@see self::MODE_EVENT} or {@see self::MODE_STATE}
     * @param string|null $blockedCause One of {@see CarRepository::BLOCKED_CAUSES}.
     *                                  Only for a suppression event in
     *                                  {@see self::MODE_STATE}. The import uses
     *                                  it for Brevo's `adminBlocked` (`blocklist`),
     *                                  which it records as `unsubscribed`.
     * @return bool True when the call wrote a new event row
     * @throws \InvalidArgumentException If the match is not a matched result,
     *                                   the source or mode is not permitted, or
     *                                   `$blockedCause` is not permitted here (nothing is written)
     * @throws CarValidationException If `$occurredAt` or the message time is
     *                                not a valid datetime (nothing is written)
     * @throws CarDatabaseException If a read or a write fails (all writes are rolled back)
     */
    public function apply(
        EmailEventMatch $match,
        string $address,
        string $event,
        ?string $reason,
        string $messageId,
        string $occurredAt,
        string $source,
        string $mode,
        ?string $blockedCause = null
    ): bool {
        DeliverabilityFlagLog::assertValidSource($source);
        if ($mode !== self::MODE_EVENT && $mode !== self::MODE_STATE) {
            throw new \InvalidArgumentException("EmailEventApplier::apply: unknown mode '{$mode}'");
        }
        if ($blockedCause !== null) {
            if ($mode !== self::MODE_STATE || !isset(self::SUPPRESSION_EVENT_CAUSES[$event])) {
                throw new \InvalidArgumentException(
                    'EmailEventApplier::apply: a block cause is permitted only for a suppression event in state mode'
                );
            }
            if (!in_array($blockedCause, CarRepository::BLOCKED_CAUSES, true)) {
                throw new \InvalidArgumentException("EmailEventApplier::apply: unknown block cause '{$blockedCause}'");
            }
        }
        if (!$match->isMatched()) {
            throw new \InvalidArgumentException('EmailEventApplier::apply: the match must be a matched result');
        }
        $messageTime = self::wallTime($match->messageTime(), 'message_time');
        $eventTime = self::wallTime($occurredAt, 'occurred_at');
        if ($reason !== null) {
            $reason = mb_substr(mb_scrub($reason, 'UTF-8'), 0, self::REASON_MAX_CHARS);
        }

        $this->repo->beginTransaction();
        try {
            $isNew = $this->applyLocked(
                $match,
                $address,
                $event,
                $reason,
                $messageId,
                $eventTime,
                $mode === self::MODE_STATE ? null : $messageTime,
                $source,
                $mode,
                $blockedCause
            );
            $this->repo->commit();
        } catch (\Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }

        return $isNew;
    }

    /**
     * Run steps 1 to 7 of {@see apply()} inside its transaction
     *
     * @param string|null $messageTime Null in {@see self::MODE_STATE}: no
     *                                 join or address guard applies
     * @return bool True when the call wrote a new event row
     * @throws CarDatabaseException If a read or a write fails
     */
    private function applyLocked(
        EmailEventMatch $match,
        string $address,
        string $event,
        ?string $reason,
        string $messageId,
        string $eventTime,
        ?string $messageTime,
        string $source,
        string $mode,
        ?string $blockedCause
    ): bool {
        $userId = $match->userId();
        $state = $this->repo->lockEventGuardState($userId);
        $failedGuard = $state === null ? 'no users row' : self::failedGuard($state, $event, $messageTime, $eventTime);
        if ($failedGuard !== null) {
            // apply() commits on this path, so the line stays even though it
            // is written inside the transaction.
            logger($userId, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo: event "%s" for owner %d not recorded: %s.',
                $event,
                $userId,
                // MODE_STATE has no message time, so its bounce guard compares the event time.
                match (true) {
                    $state === null => $failedGuard,
                    $messageTime === null => "the event is before {$failedGuard}",
                    default => "the message is before {$failedGuard}",
                }
            ));
            return false;
        }

        $isNew = $this->repo->insertOwnerEmailEvent(
            $userId,
            $address,
            $event,
            $reason,
            $messageId,
            $eventTime,
            $match->carId()
        ) === 1;

        if ($isNew && !self::isKnownEvent($event)) {
            // The message names no caller, because all three event paths
            // reach this line. An unknown event is more likely a change of
            // Brevo's payload than routine traffic, and it would otherwise
            // turn off bounce detection for that event type with no sign.
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo: unrecognized event "%s" recorded for owner %d with no flag change'
                . ' — Brevo may have added or renamed an event type.',
                $event,
                $userId
            ));
        }

        if (!$state['has_profile']) {
            logger($userId, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo: event "%s" recorded for owner %d with no flag change: the owner has no profiles row.',
                $event,
                $userId
            ));
            return $isNew;
        }

        $normalized = EmailAddress::normalize($address);
        if ($normalized === '' || $normalized !== EmailAddress::normalize($state['email'])) {
            // A flag for an old address would replace the flag of the current one.
            return $isNew;
        }
        if ($mode === self::MODE_EVENT && !$isNew) {
            return $isNew;
        }

        $this->applyFlag($userId, $address, $event, $eventTime, $source, $blockedCause);

        return $isNew;
    }

    /**
     * Set the flag that an event implies (steps 6 and 7 of {@see apply()})
     *
     * @throws CarDatabaseException If a read or a write fails
     */
    private function applyFlag(
        int $userId,
        string $address,
        string $event,
        string $eventTime,
        string $source,
        ?string $blockedCause
    ): void {
        if (in_array($event, self::HARD_BOUNCE_EVENTS, true)) {
            $this->verificationManager->setBouncedFromEvent($userId, $address, $eventTime, $source);
            return;
        }

        if ($event === 'soft_bounce') {
            $cycles = $this->repo->countSoftBouncesSinceLastDelivered($userId, $address);
            if ($cycles >= self::SOFT_BOUNCE_ESCALATION_THRESHOLD) {
                $this->verificationManager->setBouncedFromEvent($userId, $address, $eventTime, $source);
            }
            return;
        }

        if (!isset(self::SUPPRESSION_EVENT_CAUSES[$event])) {
            return;
        }

        // Read after the profile lock, so a Clear Block that committed
        // before the lock is visible here.
        $clearedAt = $this->repo->latestFlagClearAt($userId, DeliverabilityFlagLog::FLAG_BLOCKED, DeliverabilityFlagLog::SOURCE_ADMIN);
        if ($clearedAt !== null && strcmp($eventTime, self::wallTime($clearedAt, 'cleared_at')) <= 0) {
            return;
        }

        $this->verificationManager->setSuppressedFromEvent(
            $userId,
            $address,
            $blockedCause ?? self::SUPPRESSION_EVENT_CAUSES[$event],
            $eventTime,
            $source
        );
    }

    /**
     * Check the owner's date guards (step 3 of {@see apply()})
     *
     * @param array{email: string, join_date: ?string, email_address_since: ?string,
     *              email_bounce_cleared_at: ?string, has_profile: bool} $state
     * @param string|null $messageTime Null in {@see self::MODE_STATE}
     * @param string $eventTime `$occurredAt` of {@see apply()}, normalized
     * @return string|null The column name of the first guard that fails, or null when all pass
     */
    private static function failedGuard(array $state, string $event, ?string $messageTime, string $eventTime): ?string
    {
        $checks = [];
        if ($messageTime !== null) {
            $checks['join_date'] = $messageTime;
            $checks['email_address_since'] = $messageTime;
        }
        if (in_array($event, self::BOUNCE_EVENTS, true)) {
            $checks['email_bounce_cleared_at'] = $messageTime ?? $eventTime;
        }

        foreach ($checks as $column => $time) {
            $boundTime = self::storedWallTime($state[$column]);
            if ($boundTime !== null && strcmp($time, $boundTime) < 0) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Tell whether an event name is one that this class knows
     */
    private static function isKnownEvent(string $event): bool
    {
        return in_array($event, self::BOUNCE_EVENTS, true)
            || isset(self::SUPPRESSION_EVENT_CAUSES[$event])
            || in_array($event, self::KNOWN_INERT_EVENTS, true);
    }

    /**
     * Normalize a datetime to AppConstants::DATETIME_FORMAT, so two values compare as strings
     *
     * A string compare is a wall-time compare. It gives the same result in
     * the repeated hour of a DST change as the stored values do in SQL.
     *
     * @throws CarValidationException If the value is not a valid datetime
     */
    private static function wallTime(string $value, string $label): string
    {
        return CarRepository::parseTimestamp($value, $label)->format(AppConstants::DATETIME_FORMAT);
    }

    /**
     * Normalize a stored guard date, or return null when it sets no bound
     */
    private static function storedWallTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return self::wallTime($value, 'guard_date');
        } catch (CarValidationException) {
            // sql_mode = '' lets MySQL store a zero date. A zero date is
            // earlier than every message, so it sets no bound.
            return null;
        }
    }
}
