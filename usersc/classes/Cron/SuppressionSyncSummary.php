<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

/**
 * Outcome of one Brevo suppression-list sync run.
 *
 * Returned by the suppression sync job's `runFullBackfill()` and
 * `runNowWithSummary()` so a caller can report what a run did without
 * re-querying the database or re-deriving counts from the log. The manual
 * "run now" admin script renders these fields inline: the three contact
 * counts, the per-reason-code breakdown, and a warning when a full backfill
 * stopped early at its page cap.
 *
 * A contact counts as matched when its address resolved to at least one car;
 * as unmatched when it resolved to none (a suppression Brevo holds for an
 * address the registry does not know — expected, not an error); and as
 * already-flagged when every matching car was already in the target state,
 * so the run performed no write for it. The three buckets are disjoint, but
 * — unlike an earlier version of this docblock claimed — they are NOT
 * exhaustive: a contact with a malformed payload field, an unrecognized
 * reason code, an over-length synthetic value, a failed car lookup, or a
 * failed write on every matching car reaches none of the three. Each such
 * skip is logged individually by the job; {@see $skippedCount} is the only
 * place their *total* is visible without reading the log. A reader adding
 * all three buckets therefore gets the number of contacts the run *recorded
 * a flag decision for*, not the number Brevo returned — use
 * {@see $contactsExamined} for that.
 *
 * {@see $reasonCodeCounts} is keyed on Brevo's own raw reason-code strings
 * rather than a normalized internal vocabulary, so a code the job does not
 * yet map is still visible to whoever reads the summary instead of being
 * silently dropped; such codes are tallied under `'unrecognized'` (and also
 * counted in {@see $skippedCount}, since an unrecognized code reaches no
 * flag-decision bucket).
 *
 * A pure value object with no behavior: it is written by the job and read by
 * the admin script, and any interpretation of the numbers belongs to one of
 * those two rather than here.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1923
 */
final readonly class SuppressionSyncSummary
{
    /**
     * @param int                $matchedCount        Contacts matched to at least one car
     * @param int                $unmatchedCount      Contacts matching no car in the registry
     * @param int                $alreadyFlaggedCount Contacts whose car(s) were already in the target state — no write performed
     * @param array<string, int> $reasonCodeCounts    Contact counts keyed on Brevo's raw reason code (e.g. `hardBounce`,
     *                                                `contactFlaggedAsSpam`, `unsubscribedViaEmail`), plus an
     *                                                `'unrecognized'` bucket for codes this job does not map
     * @param int                $pagesFetched        Pages actually retrieved from the Brevo contacts API during this
     *                                                run — 0 only when the very first poll of the run failed
     * @param bool               $backfillCapped      True only when a full backfill hit its page-count safety cap
     *                                                before exhausting all pages, so the run is incomplete
     * @param int                $contactsExamined    Rows Brevo actually returned on this page/run, independent of
     *                                                how each was bucketed — the authoritative count `runFullBackfill()`'s
     *                                                end-of-list test compares against `PAGE_SIZE`, since the three
     *                                                buckets above are not exhaustive and undercounting a page that
     *                                                contained even one skipped contact would stop the walk early
     * @param int                $skippedCount        Contacts examined but reaching none of the three buckets above
     *                                                (malformed payload, unrecognized reason code, oversized value,
     *                                                failed lookup, or every matching car's write failed) — each is
     *                                                logged individually; this is their total, so an operator reading
     *                                                only the rendered summary still sees that something needs
     *                                                attention rather than a clean-looking run with a smaller number
     * @param bool               $pollFailed          True when a page request to Brevo failed partway through a
     *                                                `runFullBackfill()` walk, distinct from `backfillCapped`
     *                                                (which means the safety cap was hit, not that Brevo itself
     *                                                failed to answer) — both mean "incomplete, re-run me", but an
     *                                                operator diagnosing *why* needs to tell them apart
     */
    public function __construct(
        public int $matchedCount,
        public int $unmatchedCount,
        public int $alreadyFlaggedCount,
        public array $reasonCodeCounts,
        public int $pagesFetched,
        public bool $backfillCapped,
        public int $contactsExamined = 0,
        public int $skippedCount = 0,
        public bool $pollFailed = false,
    ) {
    }
}
