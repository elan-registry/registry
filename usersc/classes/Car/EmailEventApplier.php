<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;

/**
 * EmailEventApplier - Records one Brevo delivery-status event against one car
 * and applies whatever verification-flag escalation that event implies
 *
 * Extracted from {@see BrevoWebhookEventProcessor} (where it lived as a private
 * method) because a second, non-webhook caller now needs the identical mapping:
 * the nightly reconciliation job of issue #1889 replays events Brevo's webhook
 * never delivered (a dropped POST, an outage, a 5xx Brevo eventually gave up
 * retrying). Both call paths must escalate a car identically, and the only way
 * to guarantee that is for both to run this same code — the alternative would
 * be either a duplicated copy of the rules that silently drifts, or forcing the
 * cron job to synthesize fake webhook payloads just to reach
 * {@see BrevoWebhookEventProcessor::process()}, which also owns HTTP-payload
 * parsing and tag matching the job has no use for.
 *
 * This class is deliberately payload-agnostic: it takes already-parsed,
 * already-validated scalars, so each caller keeps ownership of how it
 * discovered the event (webhook POST body vs. Brevo API poll).
 *
 * ESCALATION RULES:
 * - `hard_bounce` / `blocked` / `invalid` / `invalid_email` immediately flag the
 *   car as bounced.
 * - `soft_bounce` does not immediately change anything. After the event row is
 *   recorded, {@see CarRepository::countSoftBouncesSinceLastDelivered()} is
 *   consulted; at {@see self::SOFT_BOUNCE_ESCALATION_THRESHOLD} or more distinct
 *   send cycles (distinct `brevo_message_id` values) since the email's last
 *   `delivered` event, the car is escalated to bounced via the same path as a
 *   hard bounce.
 * - `delivered` and `unique_opened` only ever record an event row. `delivered`
 *   implicitly resets the soft-bounce escalation window (the count query is
 *   scoped to occur after the most recent `delivered` row), so there is no
 *   separate "clear escalation" write. `unique_opened` never touches flags or
 *   the escalation count under any circumstance.
 * - `spam` and `unsubscribed` flag the car as suppressed (a distinct signal
 *   from a bounce). Each is recorded under its own event name so the two
 *   remain distinguishable in `er_email_events`.
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
     * escalates a car to a confirmed bounce.
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
     */
    private const HARD_BOUNCE_EVENTS = ['hard_bounce', 'blocked', 'invalid', 'invalid_email'];

    /**
     * Brevo event names known to have no flag/escalation effect. Anything
     * outside this list still records its event row (Brevo may add or rename
     * event types at any time) but is logged, since an unrecognized event is
     * more likely a payload-contract change than routine traffic.
     */
    private const KNOWN_INERT_EVENTS = ['delivered', 'unique_opened'];

    /**
     * Brevo event names that flag the car as suppressed rather than bounced.
     *
     * `unsubscribed` originates from the suppression-list import job (#1923)
     * rather than a webhook. It is recorded under its own event name — never
     * aliased to `spam` — so the two remain distinguishable in
     * `er_email_events` for later audit.
     */
    private const SUPPRESSION_EVENTS = ['spam', 'unsubscribed'];

    public function __construct(
        private CarRepository $repo,
        private CarVerificationManager $verificationManager,
    ) {}

    /**
     * Record the event for one matched car and apply any resulting escalation
     *
     * @throws CarDatabaseException If any write fails
     */
    public function apply(
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

        if (in_array($event, self::SUPPRESSION_EVENTS, true)) {
            $this->verificationManager->setSuppressed((object) ['id' => $carId]);
            return;
        }

        // 'delivered', 'unique_opened': the event row itself (already written
        // above) is the only effect. Anything else reaching here is a Brevo
        // event name this applier doesn't recognize — still recorded (Brevo
        // may add/rename event types at any time and a dropped row would be
        // worse), but logged, since an unrecognized event is more likely a
        // payload-contract change than routine traffic and would otherwise
        // silently disable bounce detection for that event type.
        if (!in_array($event, self::KNOWN_INERT_EVENTS, true)) {
            // The message is deliberately caller-agnostic ("Brevo:", not
            // "Brevo webhook:"): the webhook, the #1889 reconciliation job, and
            // the #1923 suppression sync job all reach this line, and naming
            // any one of them would send an operator investigating a
            // differently-discovered event off to check traffic that never
            // carried it.
            // LOG_CATEGORY_EMAIL_WEBHOOK is kept for the same reason — it
            // reads as "Brevo email event tracking" regardless of how the
            // event was discovered, so all three call paths' unrecognized events
            // land in one searchable category.
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, sprintf(
                'Brevo: unrecognized event "%s" recorded for car %d with no flag change'
                . ' — Brevo may have added or renamed an event type.',
                $event,
                $carId
            ));
        }
    }
}
