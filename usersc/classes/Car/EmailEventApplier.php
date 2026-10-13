<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
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
 * The flags belong to the owner's profile, not to the car (#2101). The event
 * row is still recorded against the car. {@see CarVerificationManager} finds
 * the car's owner and writes the profile, with the flag's address and time.
 *
 * ESCALATION RULES:
 * - `hard_bounce` / `blocked` / `invalid` / `invalid_email` immediately set
 *   the owner's `email_bounced`, with `email_bounced_at` = the event time.
 * - `soft_bounce` does not immediately change anything. After the event row is
 *   recorded, {@see CarRepository::countSoftBouncesSinceLastDelivered()} is
 *   consulted; at {@see self::SOFT_BOUNCE_ESCALATION_THRESHOLD} or more distinct
 *   send cycles (distinct `brevo_message_id` values) since the email's last
 *   `delivered` event and the owner's last Clear Bounced, the owner is
 *   escalated to bounced via the same path as a hard bounce, with
 *   `email_bounced_at` = the time of the soft bounce that escalated.
 * - `delivered` and `unique_opened` only ever record an event row. `delivered`
 *   implicitly resets the soft-bounce escalation window (the count query is
 *   scoped to occur after the most recent `delivered` row), so there is no
 *   separate "clear escalation" write. `unique_opened` never touches flags or
 *   the escalation count under any circumstance.
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
     *
     * Public for the same reason as {@see self::SUPPRESSION_EVENTS}: read-only
     * consumers need this vocabulary to tell whether a stored
     * `er_email_events.event` value is the bounce that set a car's flag (the
     * admin user form only dates its Bounced column from a matching event).
     * This class stays the single owner of the list.
     */
    public const HARD_BOUNCE_EVENTS = ['hard_bounce', 'blocked', 'invalid', 'invalid_email'];

    /**
     * Brevo event names known to have no flag/escalation effect. Anything
     * outside this list still records its event row (Brevo may add or rename
     * event types at any time) but is logged, since an unrecognized event is
     * more likely a payload-contract change than routine traffic.
     */
    private const KNOWN_INERT_EVENTS = ['delivered', 'unique_opened'];

    /**
     * Brevo event names that set `email_blocked` on the car owner's profile,
     * not `email_bounced`.
     *
     * `unsubscribed` originates from the suppression-list import job (#1923)
     * rather than a webhook. It is recorded under its own event name — never
     * aliased to `spam` — so the two remain distinguishable in
     * `er_email_events` for later audit.
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

    public function __construct(
        private CarRepository $repo,
        private CarVerificationManager $verificationManager,
    ) {}

    /**
     * Record the event for one matched car and apply any resulting escalation
     *
     * @param string $source Who found the event: one of {@see DeliverabilityFlagLog::SOURCES},
     *                       normally `webhook`, `reconciliation` or `import`
     * @throws \InvalidArgumentException If $source is not a permitted value (nothing is written)
     * @throws CarValidationException If the address is empty or the time is not valid
     * @throws CarDatabaseException If any write fails
     */
    public function apply(
        int $carId,
        string $email,
        string $event,
        ?string $reason,
        string $messageId,
        string $occurredAt,
        string $source
    ): void {
        DeliverabilityFlagLog::assertValidSource($source);

        $this->repo->insertEmailEvent($carId, $email, $event, $reason, $messageId, $occurredAt);

        if (in_array($event, self::HARD_BOUNCE_EVENTS, true)) {
            $this->verificationManager->setBounced($carId, $email, $occurredAt, $source);
            return;
        }

        if ($event === 'soft_bounce') {
            $cycles = $this->repo->countSoftBouncesSinceLastDelivered($email, $carId);
            if ($cycles >= self::SOFT_BOUNCE_ESCALATION_THRESHOLD) {
                $this->verificationManager->setBounced($carId, $email, $occurredAt, $source);
            }
            return;
        }

        if (isset(self::SUPPRESSION_EVENT_CAUSES[$event])) {
            $this->verificationManager->setSuppressed(
                $carId,
                $email,
                self::SUPPRESSION_EVENT_CAUSES[$event],
                $occurredAt,
                $source
            );
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
