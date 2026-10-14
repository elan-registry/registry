<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

use ElanRegistry\AppConstants;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\DeliverabilityFlagLog;
use ElanRegistry\Car\EmailAddress;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventMatch;
use ElanRegistry\Car\EmailEventMatcher;
use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ExceptionLogSummary;
use ElanRegistry\LogCategories;

/**
 * BrevoEventReconciliationJob - Nightly backfill of Brevo delivery events the
 * webhook never delivered, plus retention pruning of er_email_events
 *
 * The webhook (`app/api/webhooks/brevo.php`, #1887) is the primary path for
 * delivery-status events; it is also lossy by nature — a dropped POST, a
 * Brevo outage, a 5xx Brevo eventually stops retrying. This job closes that
 * gap by re-reading the same events from Brevo's statistics API and applying
 * them through {@see EmailEventMatcher} and {@see EmailEventApplier}, the
 * *same* match and escalation code the webhook runs, so a backfilled event
 * flags an owner identically to a live one.
 *
 * ALL REGISTRY MAIL (#2359). The job applies events of every message that
 * this environment sent, not only verification mail. The matcher decides
 * whether a message is ours by its message ID. Test and production share
 * one Brevo account, so most events of the other environment are "not
 * ours" and the job skips them silently.
 *
 * ONE PAGE PER EVENT TYPE. The job asks Brevo for each delivery event type
 * separately ({@see self::EVENT_TYPES}), one bounded page each. A single
 * mixed page would let a busy day of `delivered` events push a bounce out of
 * the page. It never walks more pages. cron.php dispatches jobs in-process
 * with no subprocess boundary (see {@see AbstractCronJob}), so an unbounded
 * walk over a large backlog would hold the whole cron hit open. A bounded
 * page is safe to under-fetch because the work is self-healing:
 *
 *   - the 48-hour window is more than twice the 20-hour claim interval
 *     ({@see self::GUARD_INTERVAL_HOURS}), so every event is offered to at
 *     least two runs before it ages out of the window;
 *   - a replayed event changes no stored row and sets no flag again
 *     ({@see EmailEventApplier::apply()});
 *   - events are fetched newest-first, so the freshest signal is never the
 *     part that gets dropped.
 *
 * Anything this run doesn't reach is therefore simply picked up next time.
 * That is also why per-event write failures are logged and skipped rather
 * than aborting the page: the retry model already covers them, and letting
 * one poisoned row abort the run would starve every event behind it.
 *
 * NO ADDRESS IN LOGS. Log lines name the user ID, or, with no user, the
 * first 8 hex characters of the SHA-256 of the normalized address. Catch
 * blocks log {@see ExceptionLogSummary}, not the message, because CarRepository
 * puts the address in its exception messages.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1889
 * @see https://github.com/elan-registry/registry/issues/2359
 */
final class BrevoEventReconciliationJob extends AbstractCronJob
{
    /** er_cron_job_runs.job_name / CronJobGuard::ALLOWED_JOB_NAMES value. */
    public const JOB_NAME = 'brevo_reconciliation';

    /**
     * The event types that the job fetches, as Brevo's statistics API names
     * them, mapped to the webhook's event name.
     *
     * The job records each event under the webhook's name. Then a backfilled
     * row and a webhook row of the same event are one row (the unique key
     * includes the event name), and the applier sees the event names that it
     * knows.
     *
     * @var array<string, string>
     */
    public const EVENT_TYPES = [
        'hardBounces'  => 'hard_bounce',
        'softBounces'  => 'soft_bounce',
        'blocked'      => 'blocked',
        'invalid'      => 'invalid_email',
        'spam'         => 'spam',
        'unsubscribed' => 'unsubscribed',
        'delivered'    => 'delivered',
    ];

    /**
     * Minimum hours between claimed runs.
     *
     * 20, not 24: the claim is checked on whichever cron hit happens to land
     * first past the interval, so a hard 24 would let the effective run time
     * drift later by one transport interval every night until it wrapped. 20
     * leaves slack for the nightly run to re-anchor, while staying well
     * inside the 48-hour fetch window so consecutive runs still overlap.
     */
    private const GUARD_INTERVAL_HOURS = 20;

    /**
     * How far back to ask Brevo for events, in hours.
     *
     * More than double the guard interval, so every event is covered by at
     * least two runs — one missed or partially-completed night cannot lose an event.
     * Note the window is applied at *day* granularity by
     * {@see BrevoEventReconciliationClient::fetchEvents()} (Brevo's endpoint
     * takes `YYYY-MM-DD`), so the real window is this rounded outward to whole
     * days. That over-fetching is harmless — reprocessing is idempotent.
     */
    private const LOOKBACK_HOURS = 48;

    /**
     * Events requested per event type. Brevo caps this at 2500; 1000 is a
     * deliberate step below that ceiling, comfortably above the registry's
     * real nightly volume of each type while keeping the run within a single
     * cron hit.
     */
    private const PAGE_SIZE = 1000;

    /**
     * Always zero — see the class docblock. This is one bounded page, not the
     * first step of a walk; a nonzero offset would skip the newest events,
     * which are the ones most likely still missing from er_email_events.
     */
    private const PAGE_OFFSET = 0;

    /**
     * How long the prune keeps a terminal event row (hard bounce, block,
     * spam report and the like).
     */
    private const TERMINAL_RETENTION_MONTHS = 24;

    /**
     * How long the prune keeps every other event row. Each car's latest
     * `sent` row stays. It loses its address only when it is older than
     * 90 days.
     */
    private const SHORT_RETENTION_DAYS = 90;

    /** Matches er_email_events.brevo_message_id's column width (migration 20260907141817). */
    private const MAX_MESSAGE_ID_LENGTH = 255;

    /**
     * Latest event date the job trusts, in seconds after the run time.
     *
     * A later date is clock skew or a bad payload. As `occurred_at` it would
     * sort after every real event, and a far-future `delivered` row would
     * stop the soft-bounce count
     * ({@see CarRepository::countSoftBouncesSinceLastDelivered()}) for good.
     */
    private const MAX_FUTURE_SECONDS = 300;

    private readonly \DateTimeImmutable $now;

    /**
     * Set once {@see self::countUnmatched()} has seen the unmatched-recipient
     * counter increment fail, to stop re-attempting it for the remainder of
     * this run — see that method's own comment for why. Scoped to the job
     * instance because each invocation is one bounded run; nothing resets
     * it, and nothing needs to.
     */
    private bool $unmatchedCounterFailed = false;

    /**
     * Collaborators are injected rather than constructed internally, matching
     * {@see \ElanRegistry\Car\BrevoWebhookEventProcessor}'s convention — it
     * keeps this class unit-testable without a real database or the vendored
     * Brevo SDK present.
     *
     * @param DatabaseInterface $db AbstractCronJob's enabled-check/guard connection
     * @param CarRepository $repo Event-row store for the retention prune
     * @param EmailEventMatcher $matcher Finds the user of each event by its message ID
     * @param EmailEventApplier $applier Records each matched event and applies its flag
     * @param BrevoEventReconciliationClient $client Brevo statistics API poll
     * @param \DateTimeImmutable|null $now Fixes "now" for the window and
     *        retention cutoffs; defaults to wall-clock time. Injectable so
     *        tests can assert exact boundaries rather than tolerances.
     */
    public function __construct(
        DatabaseInterface $db,
        private readonly CarRepository $repo,
        private readonly EmailEventMatcher $matcher,
        private readonly EmailEventApplier $applier,
        private readonly BrevoEventReconciliationClient $client,
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
     * Backfill one page of each event type, then prune expired event rows.
     *
     * Reached only after {@see AbstractCronJob::run()} has won the guard
     * claim, so every entry here is a real run — there is no separate
     * "should this actually do work" check to make.
     *
     * A backfill failure goes on to `run()` after the prune
     * ({@see self::backfillThenPrune()}).
     */
    protected function execute(): void
    {
        $summary = $this->backfillThenPrune();

        // Logged under EMAIL_WEBHOOK rather than either CRON_JOB_* category:
        // both of those are exception channels (a fault, or a deliberate
        // pause), and a successful run is neither — filing routine success
        // there would dilute the one category an operator filters on to find
        // broken jobs. Matches BrevoSuppressionSyncJob::execute()'s identical
        // choice for its own incremental-run summary line.
        //
        // The pollFailed suffix matters here specifically: without it, a
        // failed poll logs a line that reads as a quiet night with nothing
        // to backfill. fetchEvents() already logs the specific cause under
        // CRON_JOB_FAILURE. This suffix only stops the summary line from
        // reading as a false all-clear.
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
            'Brevo event reconciliation: incremental run complete —'
            . ' %d matched, %d unmatched, %d skipped, %d invalid address(es),'
            . ' %d page(s) fetched%s%s.',
            $summary->matchedCount,
            $summary->unmatchedCount,
            $summary->skippedCount,
            $summary->invalidAddressCount,
            $summary->pagesFetched,
            $summary->pollFailed
                ? ' — a Brevo poll failed, the events of that type were not backfilled this cycle'
                : '',
            // Without this clause the line above reads as a clean run while the
            // dashboard's unmatched-recipient counter silently under-reports.
            // Only the first failure logs its own cause (see countUnmatched()), so
            // this is the only place the run's true scale is recorded.
            $summary->counterFailureCount > 0
                ? sprintf(
                    ' — %d of the %d unmatched event(s) were NOT recorded in the dashboard'
                    . ' unmatched-recipient counter; see VerificationConfigWarning log entries',
                    $summary->counterFailureCount,
                    $summary->unmatchedCount
                )
                : ''
        ));
    }

    /**
     * Run a backfill immediately, for the manual "run now" admin path.
     *
     * Bypasses both the enabled check and the CronJobGuard claim, exactly as
     * {@see AbstractCronJob::runNow()} does and for the same reason: an
     * operator who explicitly triggers a run has already made the scheduling
     * decision the guard exists to make. This is a separate method rather
     * than an override because `runNow()` is `final` and calls `execute()`,
     * which logs and discards its summary rather than returning it.
     *
     * FAILURE HANDLING DIFFERS FROM `runNow()` ON PURPOSE, mirroring
     * {@see BrevoSuppressionSyncJob::runNowWithSummary()}. `runNow()` returns
     * void, so swallowing a \Throwable there costs the caller nothing it
     * could have used. Here the caller is an admin page that renders the
     * returned summary, and a caught-and-logged failure would have to be
     * reported as *some* summary — necessarily a fabricated all-zero one,
     * indistinguishable from a genuinely successful run with nothing to
     * report. Showing an operator "0 matched, 0 unmatched" when the run in
     * fact crashed is a worse outcome than showing them an error, so this
     * logs and then **rethrows**: the admin script's own try/catch decides
     * how to render the failure, and the log line survives regardless.
     *
     * A poll failure (`fetchEvents()` returning null) is NOT one of these
     * unexpected failures — {@see self::backfillEvents()} already handles it
     * and returns a real summary with `pollFailed` set, matching
     * `BrevoSuppressionSyncJob::syncPage()`'s identical null-poll handling.
     * Only something genuinely unexpected escapes here.
     *
     * The prune runs as in {@see self::execute()}
     * ({@see self::backfillThenPrune()}).
     *
     * @return ReconciliationSummary
     * @throws \Throwable Rethrown after logging, so the caller can distinguish
     *         a failed run from an empty successful one
     */
    public function runNowWithSummary(): ReconciliationSummary
    {
        try {
            return $this->backfillThenPrune();
        } catch (\Throwable $e) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                "Cron job '%s' failed (manual run): %s",
                self::JOB_NAME,
                ExceptionLogSummary::of($e)
            ));
            throw $e;
        }
    }

    /**
     * Backfill one page of each event type, then prune expired event rows.
     *
     * The prune runs in `finally`, so a failed backfill does not stop it.
     * The backfill exception then goes on to the caller.
     */
    private function backfillThenPrune(): ReconciliationSummary
    {
        try {
            return $this->backfillEvents();
        } finally {
            $this->pruneExpiredEvents();
        }
    }

    /**
     * Fetch one page of each event type and apply each event to its user.
     */
    private function backfillEvents(): ReconciliationSummary
    {
        $windowStart = $this->now->modify('-' . self::LOOKBACK_HOURS . ' hours');

        /** @var array<string, int> $counts */
        $counts = [
            'matched' => 0,
            'unmatched' => 0,
            'skipped' => 0,
            'invalidAddress' => 0,
            'counterFailures' => 0,
        ];
        /** @var array<string, int> $eventTypeCounts */
        $eventTypeCounts = [];
        $eventsExamined = 0;
        $pagesFetched = 0;
        $pollFailed = false;

        foreach (self::EVENT_TYPES as $apiEventType => $eventName) {
            // fetchEvents() never throws. It logs a poll failure itself and
            // returns null. One failed type does not stop the other types.
            $events = $this->client->fetchEvents(
                $windowStart,
                $this->now,
                self::PAGE_SIZE,
                self::PAGE_OFFSET,
                $apiEventType
            );
            if ($events === null) {
                $pollFailed = true;
                continue;
            }

            $pagesFetched++;
            $eventsExamined += count($events);
            foreach ($events as $event) {
                $this->applyEvent($event, $apiEventType, $eventName, $counts, $eventTypeCounts);
            }
        }

        return new ReconciliationSummary(
            matchedCount: $counts['matched'],
            unmatchedCount: $counts['unmatched'],
            skippedCount: $counts['skipped'],
            eventTypeCounts: $eventTypeCounts,
            eventsExamined: $eventsExamined,
            invalidAddressCount: $counts['invalidAddress'],
            pagesFetched: $pagesFetched,
            pollFailed: $pollFailed,
            counterFailureCount: $counts['counterFailures'],
        );
    }

    /**
     * Match one Brevo event to its user and apply it.
     *
     * Threads run counts through `$counts` (keys: `matched`, `unmatched`,
     * `skipped`, `invalidAddress`, `counterFailures`) and `$eventTypeCounts`
     * (keyed on the webhook event name) rather than returning them, so the
     * counting logic sits directly alongside the branch it is counting.
     *
     * @param \Brevo\Client\Model\GetEmailEventReportEvents $event
     * @param string $apiEventType The type that the poll asked for
     * @param string $eventName The webhook name of that type
     * @param array<string, int> $counts
     * @param array<string, int> $eventTypeCounts
     */
    private function applyEvent(
        object $event,
        string $apiEventType,
        string $eventName,
        array &$counts,
        array &$eventTypeCounts
    ): void {
        $rawEmail = $event->getEmail();
        $rawEventType = $event->getEvent();
        $rawMessageId = $event->getMessageId();

        // The SDK's getters are untyped at the boundary, so a payload-contract
        // change could hand us an array or an int here. An unconditional
        // (string) cast would turn an array into the literal "Array" (with a
        // warning) and an int into a numeric string. Skip instead, and log
        // it under EMAIL_WEBHOOK: it is a Brevo payload problem, not a
        // broken job.
        if (!is_string($rawEmail) || !is_string($rawEventType) || !is_string($rawMessageId)) {
            $counts['skipped']++;
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo event reconciliation: non-string field in event payload'
                . ' (email=%s, event=%s, message-id=%s) — event skipped.',
                get_debug_type($rawEmail),
                get_debug_type($rawEventType),
                get_debug_type($rawMessageId)
            ));
            return;
        }

        if ($rawEmail === '' || $rawEventType === '' || $rawMessageId === '') {
            $counts['skipped']++;
            // Every skip has a log entry: the admin script tells the
            // operator to look for one under EmailWebhook or CronJobFailure.
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo event reconciliation: empty required field in event payload'
                . ' (email=%s, event=%s, message-id=%s) — event skipped.',
                $rawEmail === '' ? '(empty)' : '(set)',
                $rawEventType === '' ? '(empty)' : '(set)',
                $rawMessageId === '' ? '(empty)' : '(set)'
            ));
            return;
        }

        // The job records the event under the name of the type that it asked
        // for. An event of another type means that Brevo did not apply the
        // filter. Recording it under the asked-for name would turn, for
        // example, a delivery into a bounce.
        if ($rawEventType !== $apiEventType) {
            $counts['skipped']++;
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo event reconciliation: the poll for "%s" returned an event of another type'
                . ' (%d bytes) — event skipped.',
                $apiEventType,
                strlen($rawEventType)
            ));
            return;
        }

        // Brevo's read API is external input exactly as the webhook body is.
        // The connection sets sql_mode '' (users/classes/DB.php), so MySQL
        // would silently cut an oversized message ID, and the stored ID
        // would not be Brevo's ID.
        if (strlen($rawMessageId) > self::MAX_MESSAGE_ID_LENGTH) {
            $counts['skipped']++;
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo event reconciliation: message-id exceeds storage width'
                . ' (event=%s, message-id=%d bytes) — event skipped.',
                $eventName,
                strlen($rawMessageId)
            ));
            return;
        }

        $normalized = EmailAddress::normalize($rawEmail);
        if (!EmailEventMatcher::isAcceptableAddress($normalized)) {
            $counts['invalidAddress']++;
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo event reconciliation: invalid recipient address (hash %s, event "%s") — event skipped.',
                EmailEventMatcher::addressLogHash($normalized),
                $eventName
            ));
            return;
        }

        try {
            $match = $this->matcher->matchByMessageId($rawMessageId, $normalized);
        } catch (CarDatabaseException $e) {
            $counts['skipped']++;
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                'Brevo event reconciliation: user lookup FAILED (hash %s, event "%s") — event skipped: %s',
                EmailEventMatcher::addressLogHash($normalized),
                $eventName,
                ExceptionLogSummary::of($e)
            ));
            return;
        } catch (\InvalidArgumentException $e) {
            // A bad stored row fails the same way on every run, so it must
            // not stop the events behind it.
            $counts['skipped']++;
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                'Brevo event reconciliation: user lookup REJECTED bad stored data (hash %s, event "%s")'
                . ' — event skipped: %s',
                EmailEventMatcher::addressLogHash($normalized),
                $eventName,
                ExceptionLogSummary::of($e)
            ));
            return;
        }

        // Mail of the other environment on the shared Brevo account, or mail
        // sent before message IDs were stored. Routine, so no count and no log.
        if ($match->isNotOurs()) {
            return;
        }

        if (!$match->isMatched()) {
            $this->countUnmatched($event->getTag(), $counts);
            return;
        }

        $userId = $match->userId();
        $reason = $event->getReason();
        $reason = is_string($reason) && $reason !== '' ? $reason : null;
        $occurredAt = $this->resolveOccurredAt($event->getDate(), $userId, $eventName);

        try {
            $this->applier->apply(
                $match,
                $normalized,
                $eventName,
                $reason,
                $rawMessageId,
                $occurredAt,
                DeliverabilityFlagLog::SOURCE_RECONCILIATION,
                EmailEventApplier::MODE_EVENT
            );
        } catch (CarDatabaseException $e) {
            // Log and continue, unlike the webhook (which fails the whole
            // request so Brevo retries). There is no caller to retry here,
            // and this run's own retry model — the 48-hour window plus
            // idempotent writes — already covers the skipped event on the
            // next claim. Aborting instead would let one bad row starve
            // every event behind it in this page.
            $counts['skipped']++;
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                'Brevo event reconciliation: write FAILED for owner %d, event "%s" —'
                . ' skipped, will retry next cycle: %s',
                $userId,
                $eventName,
                ExceptionLogSummary::of($e)
            ));
            return;
        } catch (CarValidationException | \InvalidArgumentException $e) {
            // A bad stored value (for example a zero message time) fails
            // the same way on every run, so the next run does not fix it.
            $counts['skipped']++;
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                'Brevo event reconciliation: write REJECTED bad data for owner %d, event "%s" — skipped: %s',
                $userId,
                $eventName,
                ExceptionLogSummary::of($e)
            ));
            return;
        }

        $counts['matched']++;
        $eventTypeCounts[$eventName] = ($eventTypeCounts[$eventName] ?? 0) + 1;
    }

    /**
     * Count an event of our mail that matched no single user.
     *
     * Only verification mail goes to car addresses that an owner may not
     * hold. The dashboard counter shows those, so only a verification-tagged
     * event counts. Any other unmatched event is routine and stays silent.
     *
     * @param mixed $rawTag The SDK's `tag` field, untyped at the boundary
     * @param array<string, int> $counts
     */
    private function countUnmatched(mixed $rawTag, array &$counts): void
    {
        if ($rawTag !== AppConstants::VERIFICATION_EMAIL_TAG) {
            return;
        }

        $counts['unmatched']++;

        // incrementUnmatchedRecipientCounter() never throws and logs its
        // own failure — but the return value still has to be honored, or
        // execute()'s summary line would silently imply every unmatched
        // event reached the dashboard counter.
        //
        // After the first failure the call itself is not re-attempted for
        // the rest of this run: the failure is a settings-row-level fault,
        // not a per-event one, so retrying would write one identical
        // warning row per unmatched event — thousands on a bad run. The
        // tally keeps counting regardless, so the summary reports the real
        // number of unmatched events missing from the counter.
        if ($this->unmatchedCounterFailed) {
            $counts['counterFailures']++;
        } elseif (!(new VerificationSettings($this->db))->incrementUnmatchedRecipientCounter()) {
            $this->unmatchedCounterFailed = true;
            $counts['counterFailures']++;
        }
    }

    /**
     * Delete event rows past the two retention cutoffs.
     *
     * The prune has its own try/catch, so its failure gets its own log line
     * and does not read as a backfill failure. It runs from `finally` after
     * the backfill, so a throw here would also hide a backfill exception.
     */
    private function pruneExpiredEvents(): void
    {
        $terminalCutoff = $this->now->modify('-' . self::TERMINAL_RETENTION_MONTHS . ' months');
        $shortCutoff = $this->now->modify('-' . self::SHORT_RETENTION_DAYS . ' days');

        try {
            $changed = $this->repo->deleteEmailEventsOlderThan($terminalCutoff, $shortCutoff);
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo event reconciliation: retention prune deleted or cleared %d er_email_events rows',
                $changed
            ));
        } catch (CarDatabaseException | \PDOException $e) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                'Brevo event reconciliation: retention prune FAILED for terminal cutoff %s'
                . ' and short cutoff %s (the backfill is a separate step): %s',
                $terminalCutoff->format(AppConstants::DATETIME_FORMAT),
                $shortCutoff->format(AppConstants::DATETIME_FORMAT),
                ExceptionLogSummary::of($e)
            ));
        }
    }

    /**
     * Normalize Brevo's event date into an er_email_events DATETIME string.
     *
     * The statistics API returns `date` as a UTC date-time *string* (unlike
     * the webhook's Unix `ts_event`), so this parses rather than formats an
     * integer — but it keeps the webhook's defensive posture: an absent or
     * unparseable value falls back to the run time rather than discarding an
     * otherwise valid event, and the fallback is logged because it skews the
     * `occurred_at` ordering
     * {@see CarRepository::countSoftBouncesSinceLastDelivered()} depends on.
     *
     * The UTC-ness is the *input's* only. `er_email_events.occurred_at` is a
     * naive DATETIME with no stored offset, and the webhook writes it in PHP's
     * default timezone, so the stored value here must be rendered in that same
     * timezone rather than preserving Brevo's UTC offset — otherwise rows from
     * the two paths sort against each other hours apart.
     *
     * A parsed value is only trusted when its Unix timestamp is positive and
     * not more than {@see self::MAX_FUTURE_SECONDS} after the run time. A
     * value outside that range takes the same logged fallback as an
     * unparseable one. Both mean "Brevo gave us no usable date".
     *
     * @param mixed $rawDate The SDK's `date` field, untyped at the boundary
     * @param int $userId The matched user, for the log line
     * @param string $eventName The webhook event name, for the log line
     */
    private function resolveOccurredAt(mixed $rawDate, int $userId, string $eventName): string
    {
        if (is_string($rawDate) && $rawDate !== '') {
            try {
                $parsed = new \DateTimeImmutable($rawDate);
                $seconds = $parsed->getTimestamp();
                if ($seconds > 0 && $seconds <= $this->now->getTimestamp() + self::MAX_FUTURE_SECONDS) {
                    // date(), not $parsed->format(): date() renders in PHP's
                    // default timezone, matching
                    // BrevoWebhookEventProcessor::resolveOccurredAt().
                    // er_email_events.occurred_at is a naive DATETIME written by
                    // both paths and compared across them by
                    // CarRepository::countSoftBouncesSinceLastDelivered(), so
                    // both must write the same clock — formatting the parsed
                    // value directly would store Brevo's UTC offset and skew
                    // backfilled rows against webhook-written ones.
                    return date(AppConstants::DATETIME_FORMAT, $seconds);
                }
            } catch (\Exception) {
                // Fall through to the logged fallback below.
            }
        }

        // EMAIL_WEBHOOK, not CRON_JOB_FAILURE: this is a data-hygiene warning
        // about Brevo's payload, not a job fault — the run continues and the
        // event is still recorded. It matches the category
        // BrevoWebhookEventProcessor::resolveOccurredAt() uses for the
        // identical condition, so both call paths' date problems land in one
        // searchable place.
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
            'Brevo event reconciliation: event "%s" for user %d carried no usable date (%s, missing,'
            . ' unparseable or more than %d seconds after the run time); occurred_at defaulted to'
            . ' run time — soft-bounce windowing may be skewed.',
            $eventName,
            $userId,
            get_debug_type($rawDate),
            self::MAX_FUTURE_SECONDS
        ));

        return $this->now->format(AppConstants::DATETIME_FORMAT);
    }
}
