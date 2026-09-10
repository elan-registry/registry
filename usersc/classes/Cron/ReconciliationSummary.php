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
 * COUNTING GRANULARITY — READ BEFORE ADDING UP THESE FIELDS. Unlike
 * {@see SuppressionSyncSummary}, which counts one bucket per *contact*, this
 * summary counts {@see $matchedCount} and {@see $skippedCount} at *car-write*
 * granularity, because one Brevo event can match several cars (the same
 * verified email address can be registered to more than one car), and each
 * car's write is applied and can fail independently through
 * {@see \ElanRegistry\Car\EmailEventApplier::apply()}. A single event that
 * matches 3 cars, where 2 writes succeed and 1 throws, contributes +2 to
 * `matchedCount` AND +1 to `skippedCount` at the same time — it is not "the
 * event counted as matched" or "the event counted as skipped", both happen
 * for that one event. As a direct consequence, {@see $eventsExamined} is NOT
 * `matchedCount + unmatchedCount + skippedCount + ignoredByTagCount`: the
 * left side counts events, the first two terms on the right count car-writes,
 * so they are not even the same unit of measure. `eventsExamined` is the only
 * field here that answers "how many events did Brevo return this run" — do
 * not try to reconstruct it from the other counts.
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
     * @param int                $matchedCount      Car-writes that succeeded via
     *                                              {@see \ElanRegistry\Car\EmailEventApplier::apply()} —
     *                                              counted per car, not per event; see the class
     *                                              docblock's counting-granularity note
     * @param int                $unmatchedCount    Events whose recipient address matched zero cars
     *                                              via {@see \ElanRegistry\Car\CarRepository::findByEmail()}
     * @param int                $skippedCount      Events or car-writes skipped for any other reason:
     *                                              a non-string payload field, an empty required field,
     *                                              an oversized value, a failed `findByEmail()` lookup, or
     *                                              a failed per-car write — counted per car for the
     *                                              per-car write failure, per event for everything else;
     *                                              see the class docblock's counting-granularity note
     * @param array<string, int> $eventTypeCounts   Car-write counts keyed on Brevo's raw event name
     *                                              (`hard_bounce`, `delivered`, ...), incremented once
     *                                              per successfully-applied car-write
     * @param int                $eventsExamined    Rows returned by `fetchEvents()` this run, independent
     *                                              of how each was bucketed — NOT the sum of the other
     *                                              counts; see the class docblock
     * @param int                $ignoredByTagCount Events skipped because their tag was not
     *                                              `AppConstants::VERIFICATION_EMAIL_TAG` — routine (most
     *                                              Brevo traffic isn't verification mail), kept separate
     *                                              from `skippedCount` so that count stays a signal worth
     *                                              an operator's attention
     * @param int                $pagesFetched      Pages actually retrieved from Brevo's statistics API
     *                                              during this run — this job fetches at most one page
     *                                              per invocation, so this is always 0 or 1
     * @param bool               $pollFailed        True when `fetchEvents()` itself failed (mirrors
     *                                              {@see SuppressionSyncSummary}'s `pollFailed`)
     */
    public function __construct(
        public int $matchedCount,
        public int $unmatchedCount,
        public int $skippedCount,
        public array $eventTypeCounts,
        public int $eventsExamined,
        public int $ignoredByTagCount,
        public int $pagesFetched,
        public bool $pollFailed = false,
    ) {
    }
}
