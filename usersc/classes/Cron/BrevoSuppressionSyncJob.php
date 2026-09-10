<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

use ElanRegistry\AppConstants;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;

/**
 * BrevoSuppressionSyncJob - Imports Brevo's transactional suppression list
 * (blocked and unsubscribed contacts) into the registry's own email flags
 *
 * Brevo maintains its own list of addresses it will no longer deliver to:
 * hard-bounced addresses, contacts who marked a message as spam, and contacts
 * who unsubscribed through any of Brevo's several unsubscribe paths. Once an
 * address is on that list, every further send to it is silently dropped at
 * Brevo's end. The registry has no way to learn this from delivery events
 * alone — a suppressed address produces no bounce and no delivery, just
 * nothing — so without this job the registry keeps re-sending verification
 * mail to addresses that can never receive it, forever.
 *
 * This job closes that gap by reading `GET /smtp/blockedContacts` through
 * {@see BrevoSuppressionSyncClient} and applying each suppression through
 * {@see EmailEventApplier} — the *same* escalation code the webhook (#1887)
 * and the nightly event reconciliation job (#1889) run, so a car flagged by an
 * imported suppression is flagged identically to one flagged by a live event.
 *
 * TWO FETCH MODES, DELIBERATELY SEPARATED
 * ---------------------------------------
 * The unattended nightly path and the operator-triggered backfill have
 * genuinely different risk profiles, so they are different entry points:
 *
 *   - {@see self::execute()} — reached only via {@see AbstractCronJob::run()},
 *     after the guard claim. Fetches exactly ONE page over a narrow
 *     {@see self::LOOKBACK_HOURS} window. cron.php dispatches jobs in-process
 *     with no subprocess boundary (see {@see AbstractCronJob}), so an
 *     unbounded pagination walk here would hold the whole cron hit open and
 *     starve every job after it in that hit's `sort` order.
 *
 *   - {@see self::runFullBackfill()} — a NEW public method, deliberately
 *     **not reachable from `run()` or `execute()` at any depth**. This is the
 *     load-bearing design point of this class: the guarded nightly path must
 *     never be able to trigger an unbounded backfill by accident, so the
 *     backfill is not a mode flag on `execute()` but a separate method no
 *     scheduled code path calls. Only an operator, via the manual admin
 *     script, reaches it — and even then it is bounded by
 *     {@see self::MAX_BACKFILL_PAGES}.
 *
 * The incremental mode's under-fetching is safe for the same reason #1889's is:
 * the window is roughly twice the guard interval so every suppression is
 * offered to at least two runs, and re-applying an already-recorded
 * suppression is a no-op (`insertEmailEvent()` is `ON DUPLICATE KEY UPDATE`
 * and the flag writes are plain column UPDATEs). The synthetic message id
 * below is what makes that idempotence hold across re-runs.
 *
 * REASON-CODE MAPPING
 * -------------------
 * Brevo's reason codes are collapsed onto the three event names
 * {@see EmailEventApplier} already understands:
 *
 *   | Brevo reason code        | Applied event  | Effect                     |
 *   | ------------------------ | -------------- | -------------------------- |
 *   | `hardBounce`             | `blocked`      | flags the car as bounced   |
 *   | `contactFlaggedAsSpam`   | `spam`         | flags the car as suppressed|
 *   | `unsubscribedViaEmail`   | `unsubscribed` | flags the car as suppressed|
 *   | `unsubscribedViaMA`      | `unsubscribed` | flags the car as suppressed|
 *   | `unsubscribedViaApi`     | `unsubscribed` | flags the car as suppressed|
 *   | `adminBlocked`           | `unsubscribed` | flags the car as suppressed|
 *   | anything else            | (none)         | logged, tallied, skipped   |
 *
 * An unmapped code is never guessed at — it is tallied under `'unrecognized'`
 * in the summary's reason-code breakdown and the contact is skipped entirely,
 * because flagging a car on a code whose meaning is unknown is a worse error
 * than not flagging it. `adminBlocked` maps to `unsubscribed` rather than
 * `blocked` on purpose: it means a human deliberately suppressed the address
 * at Brevo, which is a suppression decision, not evidence the mailbox is dead.
 *
 * COUNT ACCURACY
 * --------------
 * {@see SuppressionSyncSummary}'s three contact buckets are disjoint, which
 * requires knowing whether a car was *already* in the target state before the
 * write. {@see CarRepository::findByEmail()} returns only `id` and `email`, so
 * this job spends one extra {@see CarRepository::findById()} primary-key read
 * per matched car to read `email_bounced`/`email_suppressed` first. That read
 * is deliberate rather than approximated away: an approximation would leave
 * `alreadyFlaggedCount` permanently zero and report every re-run of the
 * backfill as though it had newly flagged the entire suppression list, which
 * is exactly the number an operator watching the admin page is trying to
 * judge. The cost is bounded — matched contacts only, one indexed lookup each.
 *
 * If that pre-check read itself fails, the contact is still applied and
 * counted as matched (the write is the point; the bookkeeping is not), so a
 * degraded count never costs a suppression.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1923
 */
final class BrevoSuppressionSyncJob extends AbstractCronJob
{
    /** er_cron_job_runs.job_name / CronJobGuard::ALLOWED_JOB_NAMES value. */
    public const JOB_NAME = 'brevo_suppression_sync';

    /**
     * Minimum hours between claimed runs.
     *
     * 20, not 24, for the reason {@see BrevoEventReconciliationJob} gives: the
     * claim is checked on whichever cron hit lands first past the interval, so
     * a hard 24 would let the effective run time drift later by one transport
     * interval every night until it wrapped.
     */
    private const GUARD_INTERVAL_HOURS = 20;

    /**
     * How far back the incremental run asks Brevo for suppressions, in hours.
     *
     * Double the guard interval, so every suppression is covered by at least
     * two runs — one missed night cannot lose one. The window is applied at
     * *day* granularity by
     * {@see BrevoSuppressionSyncClient::fetchBlockedContacts()} (Brevo's
     * endpoint takes `YYYY-MM-DD`), so the real window is this rounded outward
     * to whole days. That over-fetching is harmless: reprocessing is
     * idempotent (see the class docblock).
     */
    private const LOOKBACK_HOURS = 48;

    /**
     * Contacts requested per page.
     *
     * Confirmed against the live API on 2026-09-09: unlike
     * {@see BrevoEventReconciliationJob::PAGE_SIZE}'s endpoint (documented
     * 2500 ceiling, silently truncated if exceeded), `GET /smtp/blockedContacts`
     * hard-rejects any `limit` over 100 with `InvalidArgumentException:
     * invalid value for "$limit" ... must be smaller than or equal to 100.` —
     * the generated SDK's PHPDoc states no maximum, which was not evidence of
     * there being none. A prior value of 1000 was caught this way: every call
     * failed at the SDK boundary before reaching Brevo, `fetchBlockedContacts()`
     * logged and returned `null` on each attempt (its documented never-throws
     * contract), and every run silently completed with 0 pages fetched — no
     * exception surfaced past the client, so the failure was visible only in
     * `CronJobFailure` log entries, not in the admin page's summary. If Brevo
     * ever lowers this further, the backfill's end-of-data test below still
     * behaves correctly on a genuine partial page — it compares the *returned*
     * row count against this constant — but a value that itself exceeds
     * Brevo's cap fails at the SDK boundary instead, which is what happened
     * here; recognize that failure mode by 0-pages-fetched runs together with
     * `CronJobFailure` log entries, not by a partial page.
     */
    private const PAGE_SIZE = 100;

    /**
     * The incremental run's page offset — always zero.
     *
     * This is one bounded page, not the first step of a walk. The client pages
     * newest-first (`sort = 'desc'`), so a nonzero offset would skip the newest
     * suppressions, which are exactly the ones not yet reflected locally.
     */
    private const PAGE_OFFSET = 0;

    /**
     * Hard ceiling on pages a single {@see self::runFullBackfill()} will walk.
     *
     * At {@see self::PAGE_SIZE} (100, confirmed against the live API — see
     * that constant's docblock) this bounds one backfill at 50,000 contacts —
     * far above the registry's real suppression list, so reaching it means
     * either an unexpectedly large list or a paging bug, and in both cases
     * stopping is better than walking forever inside an admin page request.
     * Raised from an earlier value of 50 when `PAGE_SIZE` dropped from 1000 to
     * 100, to keep the same overall contact ceiling rather than silently
     * shrinking it tenfold.
     */
    private const MAX_BACKFILL_PAGES = 500;

    /**
     * Wall-clock ceiling on a single {@see self::runFullBackfill()} run, in
     * seconds.
     *
     * `runFullBackfill()` is reached only via the admin script's
     * `runNowWithSummary()`, never via the guarded `run()` — so it never
     * passes through {@see AbstractCronJob::run()}'s own `set_time_limit()`
     * backstop, and `AbstractCronJob::runNow()`'s documented reason for
     * skipping that backstop (the web SAPI's own `max_execution_time`
     * already bounds an admin page request) was written for a one-page
     * `execute()`, not for a walk of up to `MAX_BACKFILL_PAGES` sequential
     * HTTP calls. At `MAX_BACKFILL_PAGES` pages and `BrevoSuppressionSyncClient
     * ::TIMEOUT_SECONDS` per call, the worst case is 500 × 30s — comfortably
     * past a default 30s `max_execution_time`, which is not catchable
     * (unlike a normal exception, a fatal from that limit skips every
     * `catch` block between here and the request's end, so neither this
     * loop's own logic nor the admin script's `try/catch(\Throwable)` would
     * ever run — the operator would see a blank or truncated page instead of
     * either a real summary or a real error).
     *
     * This constant is deliberately well under a typical PHP default (30s)
     * multiplied by any single slow page, checked *between* pages so the
     * loop can still finish the page in flight and return a real,
     * `backfillCapped` summary — degrading into the same "re-run me" path a
     * page-count cap produces, rather than risking the uncatchable fatal.
     * Not a substitute for raising `max_execution_time` in the admin script
     * itself (still worth doing, since a single very slow page could still
     * exceed a low limit) — this is the second, independent backstop for a
     * long *walk*, not a single request.
     */
    private const MAX_BACKFILL_SECONDS = 20;

    /** Matches er_email_events.event's column width (migration 20260907141817). */
    private const MAX_EVENT_LENGTH = 32;

    /** Matches er_email_events.brevo_message_id's column width (migration 20260907141817). */
    private const MAX_MESSAGE_ID_LENGTH = 255;

    /**
     * Latest Unix timestamp {@see self::resolveOccurredAt()} will accept
     * (9999-12-31 23:59:59 UTC).
     *
     * Same value and purpose as {@see BrevoEventReconciliationJob}'s constant
     * of the same name: Brevo's read API is external input, and an absurd date
     * string ("+100000 years") parses without error into a DATETIME string
     * MySQL rejects, which would fail every write for that contact.
     */
    private const MAX_PLAUSIBLE_TIMESTAMP = 253402300799;

    /** Reason-code bucket for codes {@see self::mapReasonCodeToEvent()} does not map. */
    private const UNRECOGNIZED_REASON_BUCKET = 'unrecognized';

    /**
     * Brevo's raw `reason.code` wire values (`GetTransacBlockedContactsReason`'s
     * `CODE_*` constants), duplicated here as plain strings rather than
     * referenced via the SDK class.
     *
     * A class-constant fetch resolves the class at the point the *code runs*,
     * unlike a type hint — so referencing
     * `\Brevo\Client\Model\GetTransacBlockedContactsReason::CODE_HARD_BOUNCE`
     * directly would throw whenever this method runs without the vendored SDK
     * loaded (unit tests; any future caller that constructs this job with a
     * client whose `loadSdk()` was never reached). These six values are
     * Brevo's documented, stable wire strings — the job already compares raw
     * strings from the decoded JSON payload everywhere else — so owning them
     * as literals removes the coupling entirely.
     */
    private const REASON_CODE_HARD_BOUNCE = 'hardBounce';
    private const REASON_CODE_CONTACT_FLAGGED_AS_SPAM = 'contactFlaggedAsSpam';
    private const REASON_CODE_UNSUBSCRIBED_VIA_EMAIL = 'unsubscribedViaEmail';
    private const REASON_CODE_UNSUBSCRIBED_VIA_MA = 'unsubscribedViaMA';
    private const REASON_CODE_UNSUBSCRIBED_VIA_API = 'unsubscribedViaApi';
    private const REASON_CODE_ADMIN_BLOCKED = 'adminBlocked';

    private readonly \DateTimeImmutable $now;

    /**
     * Collaborators are injected rather than constructed internally, matching
     * {@see BrevoEventReconciliationJob}'s convention — it keeps this class
     * unit-testable without a real database or the vendored Brevo SDK present.
     *
     * @param DatabaseInterface       $db  AbstractCronJob's enabled-check/guard connection
     * @param \DateTimeImmutable|null $now Fixes "now" for the incremental
     *        window; defaults to wall-clock time. Injectable so tests can
     *        assert exact boundaries rather than tolerances.
     */
    public function __construct(
        DatabaseInterface $db,
        private readonly CarRepository $repo,
        private readonly EmailEventApplier $applier,
        private readonly BrevoSuppressionSyncClient $client,
        ?\DateTimeImmutable $now = null,
    ) {
        parent::__construct($db);
        $this->now = $now ?? new \DateTimeImmutable();
    }

    protected function jobName(): string
    {
        return self::JOB_NAME;
    }

    protected function guardIntervalHours(): int
    {
        return self::GUARD_INTERVAL_HOURS;
    }

    /**
     * Import one page of recently-suppressed contacts.
     *
     * Reached only after {@see AbstractCronJob::run()} has won the guard claim,
     * so every entry here is a real run. Deliberately does NOT call
     * {@see self::runFullBackfill()} — see the class docblock's two-modes
     * section for why that separation is load-bearing rather than incidental.
     *
     * The return type stays `void` per {@see AbstractCronJob}'s contract, so
     * the page's {@see SuppressionSyncSummary} is logged and discarded rather
     * than returned: there is no caller here to hand it to, and the log line is
     * the only artifact an unattended run leaves behind.
     */
    protected function execute(): void
    {
        $summary = $this->syncPage(
            $this->now->modify('-' . self::LOOKBACK_HOURS . ' hours'),
            $this->now,
            self::PAGE_OFFSET
        );

        // Logged under EMAIL_WEBHOOK rather than either CRON_JOB_* category:
        // both of those are exception channels (a fault, or a deliberate
        // pause), and a successful run is neither — filing routine success
        // there would dilute the one category an operator filters on to find
        // broken jobs. EMAIL_WEBHOOK is where every other Brevo email-event
        // observation from this subsystem already lands, so a suppression run's
        // outcome sits alongside the events it acted on.
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
            'Brevo suppression sync: incremental run complete —'
            . ' %d matched, %d unmatched, %d already flagged, %d skipped, %d page(s) fetched.',
            $summary->matchedCount,
            $summary->unmatchedCount,
            $summary->alreadyFlaggedCount,
            $summary->skippedCount,
            $summary->pagesFetched
        ));
    }

    /**
     * Walk Brevo's entire suppression list, with no date window.
     *
     * The operator-triggered mode. Not reachable from {@see self::execute()} or
     * {@see AbstractCronJob::run()} — see the class docblock.
     *
     * Paging stops on the first of three conditions:
     *   1. a page came back with fewer than {@see self::PAGE_SIZE} contacts
     *      EXAMINED (not merely bucketed — see below), which at Brevo's
     *      `limit`/`offset` semantics means the list is exhausted (there is
     *      no partial page before the last one);
     *   2. the poll itself failed — {@see BrevoSuppressionSyncClient} returns
     *      null, already logged there. A failed poll is explicitly NOT treated
     *      as end-of-data (which is exactly why that client returns null rather
     *      than an empty page), but it is also not retried here: the loop stops
     *      and returns what has been applied so far, because retrying inside an
     *      admin page request would risk hanging on a Brevo outage. Distinct
     *      from case 1 in the returned summary via `pollFailed`, since an
     *      operator diagnosing an incomplete run needs to know whether Brevo
     *      itself failed to answer or the list was genuinely exhausted;
     *   3. {@see self::MAX_BACKFILL_PAGES} pages have been walked WITHOUT
     *      either of the above firing, which sets `backfillCapped` on the
     *      returned summary so the caller can tell the operator the import is
     *      incomplete and worth re-running. Checked only after the loop, not
     *      mid-loop against the current page index — an earlier version set
     *      the flag on the final permitted page unconditionally, which meant
     *      a suppression list that happened to end exactly on that page (full
     *      OR short) could be reported as capped when it was in fact
     *      exhausted.
     *
     * END-OF-LIST DETECTION. `syncPage()`'s returned summary carries
     * `contactsExamined` — the row count Brevo actually returned on that
     * page — separately from the three flag-decision buckets (matched /
     * unmatched / already-flagged), because those three are NOT exhaustive:
     * a contact with a malformed field, an unrecognized reason code, or a
     * write that failed on every matching car reaches none of them (see
     * SuppressionSyncSummary's docblock). An earlier version of this method
     * summed the three buckets and compared THAT against PAGE_SIZE — so a
     * full page containing even one such contact undercounted, looked like a
     * partial page, and silently stopped the walk with the rest of Brevo's
     * suppression list never imported and no warning shown anywhere. Testing
     * `contactsExamined` instead is correct regardless of how many contacts
     * on the page were skipped.
     *
     * @return SuppressionSyncSummary Aggregated over every page walked
     */
    public function runFullBackfill(): SuppressionSyncSummary
    {
        $matched = 0;
        $unmatched = 0;
        $alreadyFlagged = 0;
        $skipped = 0;
        $pages = 0;
        $exhausted = false;
        $pollFailed = false;
        /** @var array<string, int> $reasonCodeCounts */
        $reasonCodeCounts = [];

        $pagesWalked = 0;
        $deadline = microtime(true) + self::MAX_BACKFILL_SECONDS;
        $timedOut = false;

        for ($page = 0; $page < self::MAX_BACKFILL_PAGES; $page++) {
            if (microtime(true) >= $deadline) {
                // Stops BETWEEN pages, never mid-page — the page in flight
                // always finishes and its work is counted, so this cannot
                // itself produce a torn/half-applied page. See
                // MAX_BACKFILL_SECONDS's docblock for why this exists
                // alongside the page-count cap: an uncaught PHP execution
                // timeout would skip every catch block including the admin
                // script's, leaving the operator with neither a summary nor
                // an error. Reuses the same `backfillCapped` signal as the
                // page-count cap, since both mean the identical thing to an
                // operator: "incomplete, re-run me."
                $timedOut = true;
                break;
            }

            $pagesWalked = $page + 1;
            $pageSummary = $this->syncPage(null, null, $page * self::PAGE_SIZE);

            $matched += $pageSummary->matchedCount;
            $unmatched += $pageSummary->unmatchedCount;
            $alreadyFlagged += $pageSummary->alreadyFlaggedCount;
            $skipped += $pageSummary->skippedCount;
            $pages += $pageSummary->pagesFetched;

            foreach ($pageSummary->reasonCodeCounts as $code => $count) {
                $reasonCodeCounts[$code] = ($reasonCodeCounts[$code] ?? 0) + $count;
            }

            // pagesFetched is 0 exactly when the poll failed (syncPage()
            // reports a fetched page as 1 even when it contained no contacts),
            // so this is the stop-on-null case, distinct from a real empty
            // page below — see the class docblock's END-OF-LIST DETECTION note
            // for why contactsExamined, not the bucket sum, drives that test.
            if ($pageSummary->pagesFetched === 0) {
                $pollFailed = true;
                break;
            }

            if ($pageSummary->contactsExamined < self::PAGE_SIZE) {
                $exhausted = true;
                break;
            }
        }

        // Capped when the loop ran out of pages OR ran out of time, without
        // either of the other two stop conditions (exhaustion, poll failure)
        // firing first — see the method docblock's case 3 for why the
        // page-count check is evaluated after the loop rather than on the
        // final permitted page unconditionally, and MAX_BACKFILL_SECONDS's
        // docblock for why a wall-clock stop needs the identical signal.
        $capped = !$exhausted && !$pollFailed && ($timedOut || $pagesWalked >= self::MAX_BACKFILL_PAGES);

        if ($capped) {
            // No LogCategories constant fits this cleanly, and the closest one
            // is chosen rather than a new one invented. CRON_JOB_SKIPPED means
            // a deliberate operator pause and CRON_JOB_FAILURE means the job is
            // broken; this is neither — nothing failed, and the stop was this
            // job's own safety limit, not an operator's choice. EMAIL_WEBHOOK
            // is used for the same reason execute()'s success line uses it: it
            // is this subsystem's observation channel, and it keeps both
            // exception categories meaning what an operator filtering on them
            // expects. The condition is also surfaced structurally via the
            // summary's `backfillCapped` flag, which the admin script renders
            // — this line exists so the fact survives past that one page view,
            // not as its only route to the operator.
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo suppression sync: full backfill stopped at its %s safety cap after'
                . ' %d page(s) (%d contacts examined) — more pages likely remain.'
                . ' Re-run the backfill to continue; already-imported suppressions are'
                . ' re-applied harmlessly.',
                $timedOut
                    ? sprintf('%d-second', self::MAX_BACKFILL_SECONDS)
                    : sprintf('%d-page', self::MAX_BACKFILL_PAGES),
                $pagesWalked,
                $matched + $unmatched + $alreadyFlagged + $skipped
            ));
        }

        if ($skipped > 0) {
            // Distinct from the per-contact skip lines already emitted inside
            // syncPage(): this is the total, so an operator reading only the
            // rendered admin summary — not the log — still sees that
            // something needs attention rather than a clean-looking run with
            // smaller numbers.
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo suppression sync: %d contact(s) across this run were examined but'
                . ' reached no flag-decision bucket (malformed payload, unrecognized reason'
                . ' code, oversized value, failed lookup, or every matching car\'s write'
                . ' failed) — see the per-contact lines above for each reason.',
                $skipped
            ));
        }

        return new SuppressionSyncSummary(
            $matched,
            $unmatched,
            $alreadyFlagged,
            $reasonCodeCounts,
            $pages,
            $capped,
            $matched + $unmatched + $alreadyFlagged + $skipped,
            $skipped,
            $pollFailed
        );
    }

    /**
     * Run a full backfill immediately, for the manual "run now" admin path.
     *
     * Bypasses both the enabled check and the CronJobGuard claim, exactly as
     * {@see AbstractCronJob::runNow()} does and for the same reason: an
     * operator who explicitly triggers a run has already made the scheduling
     * decision the guard exists to make. This is a separate method rather than
     * an override because `runNow()` is `final` and calls `execute()`, which is
     * the one-page incremental mode.
     *
     * FAILURE HANDLING DIFFERS FROM `runNow()` ON PURPOSE. `runNow()` returns
     * void, so swallowing a \Throwable there costs the caller nothing it could
     * have used. Here the caller is an admin page that renders the returned
     * summary, and a caught-and-logged failure would have to be reported as
     * *some* summary — necessarily a fabricated all-zero one, which is
     * indistinguishable from a genuinely successful run over an empty
     * suppression list. Showing an operator "0 matched, 0 unmatched" when the
     * import in fact crashed is a worse outcome than showing them an error, so
     * this logs and then **rethrows**: the admin script's own try/catch decides
     * how to render the failure, and the log line survives regardless.
     *
     * Note that the ordinary failure modes never reach the rethrow. A Brevo
     * outage, a missing SDK, an unparseable date, a per-contact DB write
     * failure — all are handled and logged below the throw line, and produce a
     * real (partial) summary. Only something genuinely unexpected escapes here.
     *
     * @return SuppressionSyncSummary Aggregated over every page walked
     * @throws \Throwable Rethrown after logging, so the caller can distinguish
     *         a failed run from an empty successful one
     */
    public function runNowWithSummary(): SuppressionSyncSummary
    {
        try {
            return $this->runFullBackfill();
        } catch (\Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s' failed (manual backfill): %s: %s",
                self::JOB_NAME,
                get_class($e),
                $e->getMessage()
            ));
            throw $e;
        }
    }

    /**
     * Fetch one page of blocked contacts and apply each to its matching cars.
     *
     * The single worker shared by both modes; the only difference between them
     * is the arguments passed here.
     *
     * @param \DateTimeImmutable|null $startDate Window start, or null for no window
     * @param \DateTimeImmutable|null $endDate   Window end, or null for no window
     * @param int                     $offset    Zero-based offset into Brevo's result set
     * @return SuppressionSyncSummary This page's counts. `pagesFetched` is 1
     *         for any page Brevo actually answered — including an empty one —
     *         and 0 only when the poll failed, which is how
     *         {@see self::runFullBackfill()} tells the two apart.
     */
    private function syncPage(
        ?\DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        int $offset
    ): SuppressionSyncSummary {
        // fetchBlockedContacts() never throws — a poll failure returns null and
        // is already logged there.
        $result = $this->client->fetchBlockedContacts($startDate, $endDate, self::PAGE_SIZE, $offset);

        if ($result === null) {
            return new SuppressionSyncSummary(0, 0, 0, [], 0, false, 0, 0);
        }

        $matched = 0;
        $unmatched = 0;
        $alreadyFlagged = 0;
        $skipped = 0;
        /** @var array<string, int> $reasonCodeCounts */
        $reasonCodeCounts = [];

        // getContacts() is declared to return an array, but the real
        // generated SDK's deserializer leaves the underlying field null
        // whenever the response carries no `contacts` key at all — verified
        // directly against usersc/plugins/sendinblue/vendor/getbrevo/brevo-php/lib/Model/GetTransacBlockedContacts.php,
        // which sets `$this->container['contacts'] = isset($data['contacts'])
        // ? $data['contacts'] : null`. That is exactly the shape Brevo returns
        // for an empty or exhausted suppression list (`{"count":0}` with no
        // `contacts` key) — i.e. the routine end-of-list condition every full
        // backfill eventually reaches, and every quiet nightly run. Without
        // this guard, `count(null)` throws a TypeError here on precisely that
        // condition. The sibling BrevoEventReconciliationClient::fetchEvents()
        // already guards the equivalent case with `?? []`; this mirrors it.
        $contacts = $result->getContacts() ?? [];
        $contactsExamined = count($contacts);

        foreach ($contacts as $contact) {
            $rawEmail = $contact->getEmail();
            $rawBlockedAt = $contact->getBlockedAt();

            // The SDK's getters are untyped at the boundary (see stubs/brevo-sdk.php),
            // so a payload-contract change could hand us an array or an int
            // here. An unconditional (string) cast would turn an array into the
            // literal "Array" with a warning and record it as a real address.
            // Skip instead, logged under EMAIL_WEBHOOK for the same reason
            // BrevoEventReconciliationJob::applyEvent() does: it is a Brevo
            // payload problem, not a broken job, and the run continues.
            if (!is_string($rawEmail) || $rawEmail === '') {
                $skipped++;
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                    'Brevo suppression sync: unusable email in blocked-contact payload (%s)'
                    . ' — contact skipped.',
                    get_debug_type($rawEmail)
                ));
                continue;
            }

            $email = $rawEmail;

            // getReason() is declared as returning the Reason model, but the
            // generated SDK enforces nothing at deserialization time, so a
            // payload missing `reason` yields null. PHPStan sees the declared
            // type and would call an is_object() check redundant; a null check
            // is the guard that both satisfies the analyser and covers the real
            // failure mode.
            $reason = $contact->getReason();
            if ($reason === null) {
                $skipped++;
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                    'Brevo suppression sync: blocked contact %s carried no reason object'
                    . ' — contact skipped.',
                    $email
                ));
                continue;
            }

            $rawCode = $reason->getCode();
            if (!is_string($rawCode) || $rawCode === '') {
                $skipped++;
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                    'Brevo suppression sync: blocked contact %s carried a non-string reason code (%s)'
                    . ' — contact skipped.',
                    $email,
                    get_debug_type($rawCode)
                ));
                continue;
            }

            $code = $rawCode;
            $event = $this->mapReasonCodeToEvent($code);

            if ($event === null) {
                // Tallied, not silently dropped: an unmapped code is the signal
                // that Brevo has added a suppression reason this job does not
                // yet understand, and the summary's raw-code breakdown is the
                // one place an operator would see it. No apply() call is made —
                // flagging a car on a code of unknown meaning is worse than not
                // flagging it.
                $reasonCodeCounts[self::UNRECOGNIZED_REASON_BUCKET] =
                    ($reasonCodeCounts[self::UNRECOGNIZED_REASON_BUCKET] ?? 0) + 1;
                $skipped++;

                logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                    'Brevo suppression sync: unrecognized reason code "%s" for %s'
                    . ' — contact skipped, no flag change. Brevo may have added a'
                    . ' suppression reason this job does not map.',
                    $code,
                    $email
                ));
                continue;
            }

            $reasonMessage = $reason->getMessage();
            $reasonMessage = is_string($reasonMessage) && $reasonMessage !== '' ? $reasonMessage : null;

            $occurredAt = $this->resolveOccurredAt($rawBlockedAt, $email, $code);

            // Deterministic, so re-running the backfill produces the same id
            // for the same contact and collides with the existing row under
            // er_email_events' UNIQUE (car_id, brevo_message_id, event) — which
            // is what makes the import idempotent. Built from the raw
            // `blockedAt` rather than the resolved occurred_at so that a
            // contact whose date fell back to run time still gets a stable id
            // across runs.
            $messageId = 'suppression-import-' . md5($email . '|' . $code . '|' . $this->messageIdDatePart($rawBlockedAt));

            // Brevo's read API is external input, so it gets the same bound
            // check against er_email_events' real column widths that #1889's
            // job applies, keeping an oversized value from reaching
            // insertEmailEvent() and throwing under STRICT_TRANS_TABLES. Both
            // values are in-bounds by construction here — the mapped event
            // names are at most 12 characters and the synthetic id is always
            // 51 — so this is a defensive no-op today, kept so that changing
            // either scheme later cannot silently start failing writes.
            if (strlen($event) > self::MAX_EVENT_LENGTH || strlen($messageId) > self::MAX_MESSAGE_ID_LENGTH) {
                $skipped++;
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                    'Brevo suppression sync: event or message-id exceeds storage width'
                    . ' (event=%d bytes, message-id=%d bytes) — contact %s skipped.',
                    strlen($event),
                    strlen($messageId),
                    $email
                ));
                continue;
            }

            try {
                $matchedCars = $this->repo->findByEmail($email);
            } catch (CarDatabaseException $e) {
                $skipped++;
                logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                    'Brevo suppression sync: car lookup FAILED for %s (reason "%s") — contact skipped: %s',
                    $email,
                    $code,
                    $e->getMessage()
                ));
                continue;
            }

            if ($matchedCars === []) {
                // Not an error. Brevo's suppression list covers every address
                // this account has ever sent to, including owners who have
                // since been removed and addresses that were never registry
                // cars at all.
                $unmatched++;
                continue;
            }

            $contactCounted = false;
            $contactAlreadyFlagged = true;

            foreach ($matchedCars as $car) {
                $carId = (int) $car->id;
                $wasFlagged = $this->isAlreadyInTargetState($carId, $event);

                try {
                    $this->applier->apply($carId, $email, $event, $reasonMessage, $messageId, $occurredAt);
                } catch (CarDatabaseException $e) {
                    // Log and continue rather than aborting the page: there is
                    // no caller to retry, the import is idempotent so the next
                    // run covers this contact, and letting one poisoned row
                    // abort would starve every contact behind it.
                    logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                        'Brevo suppression sync: write FAILED for car %d, event "%s" (%s) —'
                        . ' skipped, will retry next run: %s',
                        $carId,
                        $event,
                        $email,
                        $e->getMessage()
                    ));
                    continue;
                }

                $contactCounted = true;
                if (!$wasFlagged) {
                    $contactAlreadyFlagged = false;
                }
            }

            // Counted per contact, not per car, so the three buckets sum to the
            // number of contacts examined as SuppressionSyncSummary documents.
            // A contact counts as already-flagged only when EVERY matching car
            // was already in the target state — if even one car needed the
            // flag, the run did real work for that contact.
            if (!$contactCounted) {
                // Every car's write failed; already logged per-car above, and
                // tallied here too so the operator sees SOMETHING went wrong
                // in the rendered summary even if they never open the log —
                // a wholly-failed contact must not render as indistinguishable
                // from a clean run just because its count sums smaller.
                // Counting it as matched would overstate what the run
                // achieved, and as unmatched would be a lie about why. Left
                // out of all three buckets deliberately — the failure detail
                // is in the log, and the summary should not imply the contact
                // was handled.
                $skipped++;
                continue;
            }

            if ($contactAlreadyFlagged) {
                $alreadyFlagged++;
            } else {
                $matched++;
            }

            $reasonCodeCounts[$code] = ($reasonCodeCounts[$code] ?? 0) + 1;
        }

        return new SuppressionSyncSummary(
            $matched,
            $unmatched,
            $alreadyFlagged,
            $reasonCodeCounts,
            1,
            false,
            $contactsExamined,
            $skipped
        );
    }

    /**
     * Whether this car already carries the flag the given event would set.
     *
     * The extra primary-key read that makes {@see SuppressionSyncSummary}'s
     * `alreadyFlaggedCount` bucket real rather than always-zero — see the class
     * docblock's count-accuracy note for why that read is worth paying for.
     *
     * Fails *open* (returns false, i.e. "not already flagged"): if the car row
     * cannot be read, the apply() call still happens and the contact is counted
     * as newly matched. Overstating new matches is the right way to be wrong
     * here — the alternative, treating an unreadable car as already handled,
     * would hide from the operator that the read is broken.
     *
     * @param string $event One of the mapped event names
     */
    private function isAlreadyInTargetState(int $carId, string $event): bool
    {
        try {
            $car = $this->repo->findById($carId);
        } catch (CarDatabaseException $e) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                'Brevo suppression sync: could not read current flags for car %d'
                . ' — suppression still applied, but the already-flagged count for this'
                . ' run is understated: %s',
                $carId,
                $e->getMessage()
            ));
            return false;
        }

        if ($car === null) {
            // findByEmail() returned this car id moments earlier, so a null
            // here means the row vanished between the two reads — a deleted
            // car, or a read-consistency problem, not the routine "no row"
            // case findById() otherwise returns silently for. Logged (unlike
            // the routine case) because the apply() call that follows may
            // then fail against a car id that no longer exists.
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                'Brevo suppression sync: car %d matched by email lookup but vanished before'
                . ' the flag pre-check — row deleted mid-run, or a read-consistency problem.'
                . ' Treating as not-already-flagged; the write below may fail.',
                $carId
            ));
            return false;
        }

        // 'blocked' is the only mapped event EmailEventApplier treats as a hard
        // bounce; 'spam' and 'unsubscribed' both set the suppression flag.
        $column = $event === 'blocked' ? 'email_bounced' : 'email_suppressed';

        return (bool) ($car->{$column} ?? false);
    }

    /**
     * Map a Brevo suppression reason code onto an EmailEventApplier event name.
     *
     * See the class docblock for the full table and the rationale behind
     * `adminBlocked` mapping to `unsubscribed` rather than `blocked`.
     *
     * @param string $code Brevo's raw reason code
     * @return string|null The event name, or null if the code is unrecognized
     */
    private function mapReasonCodeToEvent(string $code): ?string
    {
        return match ($code) {
            self::REASON_CODE_HARD_BOUNCE => 'blocked',
            self::REASON_CODE_CONTACT_FLAGGED_AS_SPAM => 'spam',
            self::REASON_CODE_UNSUBSCRIBED_VIA_EMAIL,
            self::REASON_CODE_UNSUBSCRIBED_VIA_MA,
            self::REASON_CODE_UNSUBSCRIBED_VIA_API,
            self::REASON_CODE_ADMIN_BLOCKED => 'unsubscribed',
            default => null,
        };
    }

    /**
     * The `blockedAt` component of the synthetic message id.
     *
     * Deliberately the *raw* value, normalized only enough to be a stable
     * string — not the output of {@see self::resolveOccurredAt()}. That method
     * falls back to run time on an unparseable date, and folding that fallback
     * into the id would give the same contact a different id on every run,
     * defeating the UNIQUE-constraint idempotence the synthetic id exists to
     * provide. A non-string raw value collapses to a fixed marker for the same
     * reason: constant across runs.
     */
    private function messageIdDatePart(mixed $rawBlockedAt): string
    {
        return is_string($rawBlockedAt) && $rawBlockedAt !== '' ? $rawBlockedAt : 'no-date';
    }

    /**
     * Normalize Brevo's `blockedAt` into an er_email_events DATETIME string.
     *
     * Duplicated from {@see BrevoEventReconciliationJob::resolveOccurredAt()}
     * rather than extracted to a shared helper. The two are the same six lines
     * today, but they parse different fields of different endpoints with
     * different log wording, and this codebase's convention is against
     * extracting a shared abstraction from two similar call sites before a
     * third arrives (see CLAUDE.md — "Three similar lines are better than a
     * premature abstraction"). If a third Brevo date-parsing caller appears,
     * that is the point to extract.
     *
     * The defensive posture is identical: an absent or unparseable value falls
     * back to now rather than discarding an otherwise valid suppression, and
     * the fallback is logged because it skews the `occurred_at` ordering
     * {@see CarRepository::countSoftBouncesSinceLastDelivered()} depends on.
     *
     * The value is rendered with `date()`, not `$parsed->format()`, for the
     * reason that method spells out: `er_email_events.occurred_at` is a naive
     * DATETIME with no stored offset, written in PHP's default timezone by the
     * webhook and the reconciliation job alike, so this path must write the
     * same clock or rows from the three paths sort against each other hours
     * apart.
     *
     * A parsed value is trusted only when its Unix timestamp is positive and at
     * or below {@see self::MAX_PLAUSIBLE_TIMESTAMP} — an unbounded parse
     * accepts strings like "+100000 years", producing a DATETIME MySQL rejects.
     *
     * @param mixed  $rawBlockedAt The SDK's `blockedAt` field, untyped at the boundary
     * @param string $email        Recipient address, for the fallback log line
     * @param string $code         Brevo's raw reason code, for the fallback log line
     */
    private function resolveOccurredAt(mixed $rawBlockedAt, string $email, string $code): string
    {
        // Carries the specific reason the fallback below fires — not just the
        // debug type, which for the interesting cases (unparseable string,
        // implausible date) is always "string" and would make every failure
        // mode look identical in the log.
        $detail = get_debug_type($rawBlockedAt);

        if (is_string($rawBlockedAt) && $rawBlockedAt !== '') {
            try {
                $parsed = new \DateTimeImmutable($rawBlockedAt);
                $seconds = $parsed->getTimestamp();
                if ($seconds > 0 && $seconds <= self::MAX_PLAUSIBLE_TIMESTAMP) {
                    return date(AppConstants::DATETIME_FORMAT, $seconds);
                }
                $detail = sprintf('implausible timestamp %d parsed from "%s"', $seconds, $rawBlockedAt);
            } catch (\Exception $e) {
                $detail = sprintf('unparseable "%s" (%s: %s)', $rawBlockedAt, get_class($e), $e->getMessage());
            }
        }

        // EMAIL_WEBHOOK, not CRON_JOB_FAILURE: a data-hygiene warning about
        // Brevo's payload, not a job fault — the run continues and the
        // suppression is still applied. Matches the category both
        // BrevoWebhookEventProcessor and BrevoEventReconciliationJob use for
        // the identical condition, so all three paths' date problems land in
        // one searchable place.
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
            'Brevo suppression sync: contact %s (reason "%s") carried no usable blocked-at date (%s);'
            . ' occurred_at defaulted to run time — event ordering may be skewed.',
            $email,
            $code,
            $detail
        ));

        return $this->now->format(AppConstants::DATETIME_FORMAT);
    }
}
