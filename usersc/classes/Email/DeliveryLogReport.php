<?php

declare(strict_types=1);

namespace ElanRegistry\Email;

use DateTimeImmutable;

/**
 * DeliveryLogReport - one page of the admin email delivery tab (#2102)
 *
 * {@see self::build()} takes the rows of
 * {@see DeliveryLogRepository::loadWindow()}, groups them by Brevo message ID,
 * chooses each mail's outcome with {@see DeliveryOutcomeResolver}, applies the
 * outcome filter, counts each outcome, sorts newest first and cuts one page.
 *
 * The counts cover all filtered mail, on every page, not only the page rows.
 *
 * Pure: no database, and the caller gives the time.
 *
 * @package ElanRegistry\Email
 * @since v2.31.1
 * @see https://github.com/elan-registry/registry/issues/2102
 */
final readonly class DeliveryLogReport
{
    /** Mail per page. */
    public const PER_PAGE = 50;

    /**
     * @param list<DeliveryLogEntry> $entries   The mail of this page, newest first
     * @param array<string, int>     $counts    Mail per outcome over the filtered set,
     *                                          keyed by {@see DeliveryOutcome} value, every case present
     * @param int                    $total     Mail in the filtered set
     * @param int                    $page      This page, 1 to $pageCount
     * @param int                    $pageCount Pages in the filtered set, 1 or more (1 for an empty set)
     */
    private function __construct(
        public array $entries,
        public array $counts,
        public int $total,
        public int $page,
        public int $pageCount,
    ) {
    }

    /**
     * Build the report.
     *
     * The `stale` filter keeps a mail with outcome None that Brevo accepted
     * before `$now` minus the filter's hours (strictly older). A page past the
     * last page gives the last page.
     *
     * @param list<array<string, mixed>> $rows   The rows of DeliveryLogRepository::loadWindow()
     * @param DeliveryLogFilter          $filter The validated filters
     * @param DateTimeImmutable          $now    The current time, in the time zone of `accepted_at`
     * @return self The report
     */
    public static function build(array $rows, DeliveryLogFilter $filter, DateTimeImmutable $now): self
    {
        $resolver = new DeliveryOutcomeResolver();
        $staleBefore = $now->modify("-{$filter->hours} hours");

        $entries = [];
        $counts = array_fill_keys(array_column(DeliveryOutcome::cases(), 'value'), 0);
        foreach (self::groupByMessage($rows) as $mail) {
            $entry = self::toEntry($mail['message'], $resolver->resolve($mail['events']));
            if (self::matches($entry, $filter, $staleBefore)) {
                $entries[] = $entry;
                $counts[$entry->outcome->value]++;
            }
        }

        usort(
            $entries,
            static fn(DeliveryLogEntry $a, DeliveryLogEntry $b): int
                => [$b->acceptedAt, $b->id] <=> [$a->acceptedAt, $a->id]
        );

        $total = count($entries);
        $pageCount = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($filter->page, $pageCount);

        return new self(
            array_slice($entries, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            $counts,
            $total,
            $page,
            $pageCount,
        );
    }

    /**
     * Group the joined rows by message ID.
     *
     * The mail fields repeat on each row of a mail. A row with a null
     * `event_id` is the LEFT JOIN row of a mail with no event.
     *
     * @param list<array<string, mixed>> $rows The joined rows
     * @return list<array{message: array<string, mixed>, events: list<array<string, mixed>>}> One item per mail
     */
    private static function groupByMessage(array $rows): array
    {
        $mail = [];
        foreach ($rows as $row) {
            $key = (string) ($row['message_id'] ?? '');
            if (!isset($mail[$key])) {
                $mail[$key] = ['message' => $row, 'events' => []];
            }
            if (($row['event_id'] ?? null) !== null) {
                $mail[$key]['events'][] = $row;
            }
        }

        return array_values($mail);
    }

    /**
     * Build the entry of one mail.
     *
     * @param array<string, mixed>      $message A row of the mail
     * @param array<string, mixed>|null $event   The chosen outcome event, or null
     * @return DeliveryLogEntry The entry
     */
    private static function toEntry(array $message, ?array $event): DeliveryLogEntry
    {
        $outcome = $event === null
            ? DeliveryOutcome::None
            : (DeliveryOutcome::fromEvent((string) $event['event']) ?? DeliveryOutcome::None);

        return new DeliveryLogEntry(
            (int) $message['id'],
            (string) $message['message_id'],
            (string) ($message['recipient_email'] ?? ''),
            isset($message['tags']) ? (string) $message['tags'] : null,
            new DateTimeImmutable((string) $message['accepted_at']),
            $outcome,
            $event === null ? null : new DateTimeImmutable((string) $event['occurred_at']),
            isset($event['reason']) ? (string) $event['reason'] : null,
            self::positiveIdOrNull($event['user_id'] ?? null),
            self::positiveIdOrNull($event['car_id'] ?? null),
        );
    }

    /**
     * Give an owner or car ID, or null when the row has no real one.
     *
     * An event for an address that no single user holds can carry
     * `user_id` 0 (#2611). The tab must show no link for it, not "owner #0".
     *
     * @param mixed $value The `user_id` or `car_id` value of the event row
     * @return int|null The ID when it is a positive integer, else null
     */
    private static function positiveIdOrNull(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /**
     * Tell whether an entry passes the outcome filter.
     *
     * @param DeliveryLogEntry  $entry       The entry
     * @param DeliveryLogFilter $filter      The filters
     * @param DateTimeImmutable $staleBefore A `stale` mail was accepted before this time
     * @return bool True when the entry passes
     */
    private static function matches(DeliveryLogEntry $entry, DeliveryLogFilter $filter, DateTimeImmutable $staleBefore): bool
    {
        return match ($filter->outcome) {
            DeliveryLogFilter::OUTCOME_ALL => true,
            DeliveryLogFilter::OUTCOME_STALE => $entry->outcome === DeliveryOutcome::None
                && $entry->acceptedAt < $staleBefore,
            default => $entry->outcome->value === $filter->outcome,
        };
    }
}
