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
 * BrevoEventReconciliationJob - Nightly backfill of Brevo delivery events the
 * webhook never delivered, plus retention pruning of er_email_events
 *
 * The webhook (`app/api/webhooks/brevo.php`, #1887) is the primary path for
 * delivery-status events; it is also lossy by nature — a dropped POST, a
 * Brevo outage, a 5xx Brevo eventually stops retrying. This job closes that
 * gap by re-reading the same events from Brevo's statistics API and applying
 * them through {@see EmailEventApplier}, the *same* escalation code the
 * webhook runs, so a backfilled event flags a car identically to a live one.
 *
 * BOUNDED WORK PER INVOCATION. This job fetches exactly one page of events
 * per run — never a pagination loop. cron.php dispatches jobs in-process with
 * no subprocess boundary (see {@see AbstractCronJob}), so an unbounded walk
 * over a large backlog would hold the whole cron hit open. A single page is
 * safe to under-fetch because the work is inherently self-healing:
 *
 *   - the 48-hour window is twice the ~24-hour claim interval, so every event
 *     is offered to at least two runs before it ages out of the window;
 *   - `CarRepository::insertEmailEvent()` is `ON DUPLICATE KEY UPDATE`, and
 *     the flag writes are plain column UPDATEs, so re-applying an event
 *     already recorded (by the webhook or by an earlier run) is a no-op;
 *   - events are fetched newest-first, so the freshest signal is never the
 *     part that gets dropped.
 *
 * Anything this run doesn't reach is therefore simply picked up next time.
 * That is also why per-event write failures are logged and skipped rather
 * than aborting the page: the retry model already covers them, and letting
 * one poisoned row abort the run would starve every event behind it.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1889
 */
final class BrevoEventReconciliationJob extends AbstractCronJob
{
    /** er_cron_job_runs.job_name / CronJobGuard::ALLOWED_JOB_NAMES value. */
    private const JOB_NAME = 'reconciliation';

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
     * Double the guard interval, so every event is covered by at least two
     * runs — one missed or partially-completed night cannot lose an event.
     * Note the window is applied at *day* granularity by
     * {@see BrevoEventReconciliationClient::fetchEvents()} (Brevo's endpoint
     * takes `YYYY-MM-DD`), so the real window is this rounded outward to whole
     * days. That over-fetching is harmless — reprocessing is idempotent.
     */
    private const LOOKBACK_HOURS = 48;

    /**
     * Events requested per run. Brevo caps this at 2500; 1000 is a deliberate
     * step below that ceiling, comfortably above the registry's real nightly
     * event volume while keeping one page's processing (a `findByEmail()` plus
     * up to a few writes per event) within a single cron hit.
     */
    private const PAGE_SIZE = 1000;

    /**
     * Always zero — see the class docblock. This is one bounded page, not the
     * first step of a walk; a nonzero offset would skip the newest events,
     * which are the ones most likely still missing from er_email_events.
     */
    private const PAGE_OFFSET = 0;

    /** How long delivery-event rows are retained before nightly pruning. */
    private const RETENTION_MONTHS = 24;

    /** Matches er_email_events.event's column width (migration 20260907141817). */
    private const MAX_EVENT_LENGTH = 32;

    /** Matches er_email_events.brevo_message_id's column width (migration 20260907141817). */
    private const MAX_MESSAGE_ID_LENGTH = 255;

    /**
     * Latest Unix timestamp {@see self::resolveOccurredAt()} will accept
     * (9999-12-31 23:59:59 UTC).
     *
     * Same value and purpose as
     * {@see \ElanRegistry\Car\BrevoWebhookEventProcessor}'s constant of the
     * same name: Brevo's statistics API is external input, and an absurd date
     * string ("+100000 years", "9999999-01-01") parses without error into a
     * DATETIME string MySQL rejects, which would fail every write for that
     * event. Clamping here keeps the poisoned value from ever reaching
     * {@see \ElanRegistry\Car\CarRepository::insertEmailEvent()}.
     */
    private const MAX_PLAUSIBLE_TIMESTAMP = 253402300799;

    private readonly \DateTimeImmutable $now;

    /**
     * Collaborators are injected rather than constructed internally, matching
     * {@see \ElanRegistry\Car\BrevoWebhookEventProcessor}'s convention — it
     * keeps this class unit-testable without a real database or the vendored
     * Brevo SDK present.
     *
     * @param DatabaseInterface $db AbstractCronJob's enabled-check/guard connection
     * @param \DateTimeImmutable|null $now Fixes "now" for the window and
     *        retention cutoff; defaults to wall-clock time. Injectable so
     *        tests can assert exact boundaries rather than tolerances.
     */
    public function __construct(
        DatabaseInterface $db,
        private readonly CarRepository $repo,
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
     * Backfill one page of Brevo events, then prune expired event rows.
     *
     * Reached only after {@see AbstractCronJob::run()} has won the guard
     * claim, so every entry here is a real run — there is no separate
     * "should this actually do work" check to make.
     */
    protected function execute(): void
    {
        $this->backfillEvents();

        // Independent of the backfill, and deliberately outside its error
        // handling: a Brevo outage must not stop retention pruning, and a
        // failed prune must not be mistaken for a failed backfill. Both retry
        // on their own next cycle.
        $this->pruneExpiredEvents();
    }

    /**
     * Fetch one page of events and apply each tag-matching one to its cars.
     */
    private function backfillEvents(): void
    {
        // fetchEvents() never throws — a poll failure returns [] and is
        // already logged there under LOG_CATEGORY_CRON_JOB_FAILURE.
        $events = $this->client->fetchEvents(
            $this->now->modify('-' . self::LOOKBACK_HOURS . ' hours'),
            $this->now,
            self::PAGE_SIZE,
            self::PAGE_OFFSET
        );

        foreach ($events as $event) {
            $this->applyEvent($event);
        }
    }

    /**
     * Apply one Brevo event to every car registered to its recipient address.
     *
     * @param \Brevo\Client\Model\GetEmailEventReportEvents $event
     */
    private function applyEvent(object $event): void
    {
        // Same gate BrevoWebhookEventProcessor::process() applies to the
        // webhook's `tags` array — but the statistics API returns a single
        // `tag` string per event, not a list.
        if ((string) $event->getTag() !== AppConstants::VERIFICATION_EMAIL_TAG) {
            return;
        }

        $rawEmail = $event->getEmail();
        $rawEventName = $event->getEvent();
        $rawMessageId = $event->getMessageId();

        // The SDK's getters are untyped at the boundary, so a payload-contract
        // change could hand us an array or an int here. An unconditional
        // (string) cast would turn an array into the literal "Array" (with a
        // warning) and an int into a numeric string, either of which would then
        // be recorded as a genuine event name and fed to
        // EmailEventApplier::apply()'s escalation logic. Skip instead, and log
        // it under EMAIL_WEBHOOK for the same reason the bound check below
        // does: it is a Brevo payload problem, not a broken job.
        if (!is_string($rawEmail) || !is_string($rawEventName) || !is_string($rawMessageId)) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo event reconciliation: non-string field in event payload'
                . ' (email=%s, event=%s, message-id=%s) — event skipped.',
                get_debug_type($rawEmail),
                get_debug_type($rawEventName),
                get_debug_type($rawMessageId)
            ));
            return;
        }

        $email = $rawEmail;
        $eventName = $rawEventName;
        $messageId = $rawMessageId;

        if ($email === '' || $eventName === '' || $messageId === '') {
            return;
        }

        // Brevo's read API is external input exactly as the webhook body is,
        // so it gets the same bound check against er_email_events' real column
        // widths. Skipping here keeps an oversized value from reaching
        // insertEmailEvent() and throwing under STRICT_TRANS_TABLES.
        //
        // Logged under EMAIL_WEBHOOK rather than CRON_JOB_FAILURE: an
        // oversized value is a possible Brevo payload-contract change worth
        // investigating, but the job itself is healthy and the run continues.
        // BrevoWebhookEventProcessor logs its identical bound check under the
        // same category, so both call paths' payload problems are searchable
        // together, and CRON_JOB_FAILURE stays the category an operator can
        // filter on to find genuinely broken jobs.
        if (strlen($eventName) > self::MAX_EVENT_LENGTH || strlen($messageId) > self::MAX_MESSAGE_ID_LENGTH) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo event reconciliation: event or message-id exceeds storage width'
                . ' (event=%d bytes, message-id=%d bytes) — event skipped.',
                strlen($eventName),
                strlen($messageId)
            ));
            return;
        }

        $reason = $event->getReason();
        $reason = is_string($reason) && $reason !== '' ? $reason : null;

        $occurredAt = $this->resolveOccurredAt($event->getDate(), $email, $eventName);

        try {
            $matchedCars = $this->repo->findByEmail($email);
        } catch (CarDatabaseException $e) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                'Brevo event reconciliation: car lookup FAILED for %s (event "%s") — event skipped: %s',
                $email,
                $eventName,
                $e->getMessage()
            ));
            return;
        }

        foreach ($matchedCars as $car) {
            try {
                $this->applier->apply((int) $car->id, $email, $eventName, $reason, $messageId, $occurredAt);
            } catch (CarDatabaseException $e) {
                // Log and continue, unlike the webhook (which fails the whole
                // request so Brevo retries). There is no caller to retry here,
                // and this run's own retry model — the 48-hour window plus
                // idempotent writes — already covers the skipped event on the
                // next claim. Aborting instead would let one bad row starve
                // every event behind it in this page.
                logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                    'Brevo event reconciliation: write FAILED for car %s, event "%s" (%s) —'
                    . ' skipped, will retry next cycle: %s',
                    (string) $car->id,
                    $eventName,
                    $email,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Delete event rows past the retention horizon.
     *
     * Wrapped in its own try/catch so a prune failure is logged as such rather
     * than escaping to {@see AbstractCronJob::run()}'s catch-all, where it
     * would read as a generic job failure and obscure whether the backfill
     * (which has already completed by this point) succeeded.
     */
    private function pruneExpiredEvents(): void
    {
        $cutoff = $this->now->modify('-' . self::RETENTION_MONTHS . ' months');

        try {
            $this->repo->deleteEmailEventsOlderThan($cutoff);
        } catch (CarDatabaseException $e) {
            logger(0, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, sprintf(
                'Brevo event reconciliation: retention prune FAILED for cutoff %s'
                . ' (backfill for this run was unaffected): %s',
                $cutoff->format(AppConstants::DATETIME_FORMAT),
                $e->getMessage()
            ));
        }
    }

    /**
     * Normalize Brevo's event date into an er_email_events DATETIME string.
     *
     * The statistics API returns `date` as a UTC date-time *string* (unlike
     * the webhook's Unix `ts_event`), so this parses rather than formats an
     * integer — but it keeps the webhook's defensive posture: an absent or
     * unparseable value falls back to now rather than discarding an otherwise
     * valid event, and the fallback is logged because it skews the
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
     * at or below {@see self::MAX_PLAUSIBLE_TIMESTAMP} — an unbounded parse
     * accepts strings like "+100000 years", producing a DATETIME MySQL
     * rejects. Out-of-range values take the same logged "now" fallback as an
     * unparseable one; both mean "Brevo gave us no usable date".
     *
     * @param mixed $rawDate The SDK's `date` field, untyped at the boundary
     */
    private function resolveOccurredAt(mixed $rawDate, string $email, string $event): string
    {
        if (is_string($rawDate) && $rawDate !== '') {
            try {
                $parsed = new \DateTimeImmutable($rawDate);
                $seconds = $parsed->getTimestamp();
                if ($seconds > 0 && $seconds <= self::MAX_PLAUSIBLE_TIMESTAMP) {
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
            } catch (\Exception $e) {
                // Fall through to the logged fallback below.
            }
        }

        // EMAIL_WEBHOOK, not CRON_JOB_FAILURE: this is a data-hygiene warning
        // about Brevo's payload, not a job fault — the run continues and the
        // event is still recorded. It matches the category
        // BrevoWebhookEventProcessor::resolveOccurredAt() uses for the
        // identical condition, so both call paths' date problems land in one
        // searchable place, and keeps CRON_JOB_FAILURE meaning "the job
        // itself is broken".
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
            'Brevo event reconciliation: event "%s" for %s carried no usable date (%s);'
            . ' occurred_at defaulted to run time — soft-bounce windowing may be skewed.',
            $event,
            $email,
            get_debug_type($rawDate)
        ));

        return $this->now->format(AppConstants::DATETIME_FORMAT);
    }
}
