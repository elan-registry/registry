<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

/**
 * Outcome of one Brevo event reconciliation run.
 *
 * Returned by {@see BrevoEventReconciliationJob::runNowWithSummary()} so a
 * caller can report what a manual run did without re-querying the database or
 * re-deriving counts from the log. The manual "run now" admin script renders
 * these fields inline: the match/unmatch/skip counts, the per-event-type
 * breakdown, and a warning when the poll itself failed.
 *
 * COUNTING. Each count is per event. The matcher gives one user or none for
 * an event, so one event makes at most one write.
 * {@see $eventsExamined} is NOT the sum of the other counts: an event that
 * is not ours, and an unmatched event without the verification tag, add to
 * no count. `eventsExamined` is the only field here that answers "how many
 * events did Brevo return this run" — do not try to reconstruct it from the
 * other counts.
 *
 * A pure value object with no behavior: it is written by the job and read by
 * the admin script, and any interpretation of the numbers belongs to one of
 * those two rather than here.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/2061
 */
final readonly class ReconciliationSummary
{
    /**
     * @param int                $matchedCount      Events written for their user via
     *                                              {@see \ElanRegistry\Car\EmailEventApplier::apply()}
     * @param int                $unmatchedCount    Events of our verification mail (tag
     *                                              `AppConstants::VERIFICATION_EMAIL_TAG`) that
     *                                              matched no single user. Events with another tag
     *                                              that matched no user are not counted.
     * @param int                $skippedCount      Events skipped for a payload or database fault:
     *                                              a non-string field, an empty required field, an
     *                                              event of a type that the poll did not ask for,
     *                                              an oversized message ID, a failed user lookup, or
     *                                              a failed write
     * @param array<string, int> $eventTypeCounts   Written events keyed on the webhook event name
     *                                              (`hard_bounce`, `delivered`, ...)
     * @param int                $eventsExamined    Events returned by all `fetchEvents()` calls this
     *                                              run — NOT the sum of the other counts; see the
     *                                              class docblock
     * @param int                $invalidAddressCount Events skipped because the recipient address
     *                                              is longer than 155 bytes or fails
     *                                              `FILTER_VALIDATE_EMAIL`
     * @param int                $pagesFetched      Pages retrieved from Brevo's statistics API this
     *                                              run — one for each event type whose poll
     *                                              succeeded, so 0 to
     *                                              `count(BrevoEventReconciliationJob::EVENT_TYPES)`
     * @param bool               $pollFailed        True when at least one `fetchEvents()` call failed (mirrors
     *                                              {@see SuppressionSyncSummary}'s `pollFailed`)
     * @param int                $counterFailureCount Unmatched events whose dashboard
     *                                              unmatched-recipient counter increment did NOT
     *                                              land — a subset of `unmatchedCount`, never
     *                                              added to it. Once the first increment fails the
     *                                              job stops re-attempting the call for the rest of
     *                                              the run (see
     *                                              {@see BrevoEventReconciliationJob::countUnmatched()})
     *                                              but keeps tallying here, so this stays the true
     *                                              number of unmatched events missing from the
     *                                              counter even though only one failure was logged
     */
    public function __construct(
        public int $matchedCount,
        public int $unmatchedCount,
        public int $skippedCount,
        public array $eventTypeCounts,
        public int $eventsExamined,
        public int $invalidAddressCount,
        public int $pagesFetched,
        public bool $pollFailed = false,
        public int $counterFailureCount = 0,
    ) {
    }
}
