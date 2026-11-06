<?php

declare(strict_types=1);

namespace ElanRegistry\Email;

/**
 * DeliveryOutcomeResolver - choose the outcome event of one mail (#2102)
 *
 * The caller gives the event rows of ONE mail. The caller groups the rows by
 * Brevo message ID, never by address, so an event of another mail or of
 * another user with the same address cannot attach.
 *
 * The chosen event is the outcome event with the latest `occurred_at`. A tie
 * goes to the highest `event_id`. So a soft bounce followed by a delivery
 * gives Delivered, and a delivery followed by a bounce gives the bounce.
 * Rows whose event name has no outcome (`sent`, unknown names, or the null
 * event of a LEFT JOIN with no match) are ignored.
 *
 * Pure: no database and no clock.
 *
 * @package ElanRegistry\Email
 * @since v2.31.1
 * @see https://github.com/elan-registry/registry/issues/2102
 */
final class DeliveryOutcomeResolver
{
    /**
     * Choose the outcome event of one mail.
     *
     * Each row needs the keys `event_id`, `event` and `occurred_at`
     * (`Y-m-d H:i:s`), as {@see DeliveryLogRepository::loadWindow()} returns
     * them. Other keys come back unchanged in the chosen row.
     *
     * @param list<array<string, mixed>> $events The event rows of one mail
     * @return array<string, mixed>|null The chosen row, or null when no row has an outcome
     */
    public function resolve(array $events): ?array
    {
        $chosen = null;
        foreach ($events as $row) {
            $event = $row['event'] ?? null;
            if (!is_string($event) || DeliveryOutcome::fromEvent($event) === null) {
                continue;
            }
            if ($chosen === null || $this->isLater($row, $chosen)) {
                $chosen = $row;
            }
        }

        return $chosen;
    }

    /**
     * Tell whether row A comes after row B: later time, then higher ID.
     *
     * The `Y-m-d H:i:s` format sorts as text in time order.
     *
     * @param array<string, mixed> $a The candidate row
     * @param array<string, mixed> $b The row chosen so far
     * @return bool True when A comes after B
     */
    private function isLater(array $a, array $b): bool
    {
        $byTime = strcmp((string) ($a['occurred_at'] ?? ''), (string) ($b['occurred_at'] ?? ''));
        if ($byTime !== 0) {
            return $byTime > 0;
        }

        return (int) ($a['event_id'] ?? 0) > (int) ($b['event_id'] ?? 0);
    }
}
