<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DateTimeImmutable;
use ElanRegistry\AppConstants;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;

/**
 * EmailNoticeBuilder - Collects the data for the "email paused" notice on account.php
 *
 * An owner whose car emails are suppressed or bounced gets no verification
 * emails and no other signal that this happened. This class finds the
 * owner's flagged cars and returns one plain data array that
 * app/views/_email_paused_notice.php renders. It returns raw, unescaped
 * values. The partial escapes them.
 *
 * The notice is about delivery addresses, not cars. Entries are grouped by
 * each car's own `email` column (not the profile email), because a car's
 * registered address can differ from the account address.
 *
 * Suppression cause comes from data that already exists:
 * - The car has a `spam`/`unsubscribed` er_email_events row
 *   ({@see EmailEventApplier::SUPPRESSION_EVENTS}): Brevo caused it
 *   ({@see self::CAUSE_BREVO_COMPLAINT}). The date is that event's
 *   `occurred_at`, because the webhook path writes no `EMAIL SUPPRESSED`
 *   cars_hist row. The lookup is filtered to suppression events only
 *   ({@see CarRepository::findLatestEmailEventsByCarIdsAndEvents()}), not
 *   the car's latest event of any type — a later, unrelated event (`opened`,
 *   `click`, `delivered`) must not outrank the actual suppression event and
 *   cause a misattributed cause.
 * - The event wins only when it is not older than the latest
 *   `EMAIL SUPPRESSED` cars_hist row. Without this check, a car that was
 *   Brevo-suppressed, then had the owner clear it (`clear_suppression` or
 *   the owner's own "Resume" self-service, both of which write only a
 *   cars_hist row, no er_email_events row), then was opted out again by the
 *   owner, would still show the stale, since-cleared Brevo event as the
 *   cause of the *current* suppression — the event is retained for 24
 *   months and is never deleted by a clear. An event this old is treated as
 *   if it did not exist.
 * - Otherwise: the owner clicked the opt-out link
 *   ({@see self::CAUSE_OWNER_OPTOUT}). The opt-out handler in
 *   verify_car.php writes no er_email_events row. The date is the latest
 *   `EMAIL SUPPRESSED` cars_hist row. Legacy data with no such row, and a
 *   suppression event older than the 24-month retention window
 *   (pruned by `BrevoEventReconciliationJob::pruneExpiredEvents()`), both
 *   get a null date, so the notice omits the date instead of inventing one.
 *
 * The bounce date is the later of the latest `EMAIL BOUNCED` cars_hist row
 * (admin path) and the latest hard-bounce er_email_events row (webhook
 * path, filtered the same way as the suppression lookup) — not simply
 * "hist, else event" — because a car can be marked bounced, cleared, and
 * then hard-bounce again via the webhook (which writes no history row), in
 * which case the newer event date must win over the older, since-cleared
 * history date.
 *
 * @package ElanRegistry\Car
 * @since v2.30.5
 * @see https://github.com/elan-registry/registry/issues/1899
 */
final class EmailNoticeBuilder
{
    public const CAUSE_OWNER_OPTOUT    = 'owner_optout';
    public const CAUSE_BREVO_COMPLAINT = 'brevo_complaint';

    /** Maximum number of addresses the notice names. The rest go into overflowCount. */
    public const MAX_ADDRESSES = 3;

    private const OPERATION_SUPPRESSED = 'EMAIL SUPPRESSED';
    private const OPERATION_BOUNCED    = 'EMAIL BOUNCED';

    public function __construct(private CarRepository $repo)
    {
    }

    /**
     * Build the email-paused notice data for one owner
     *
     * `addresses` is sorted by lowercased address and capped at
     * {@see self::MAX_ADDRESSES}. `overflowCount` is the number of distinct
     * addresses left out. `hasSuppressed`, `hasBounced` and `contentHash`
     * cover the full set, not only the addresses shown. `contentHash`
     * changes when any address, cause or date changes, so a dismissed
     * notice shows again when the data changes. Each `date` is `Y-m-d`, or
     * null when no audit row gives one.
     *
     * @param int $ownerId Owner (users.id) whose cars to check
     * @return array{
     *   addresses: list<array{
     *     address: string,
     *     suppressed: array{cause: 'owner_optout'|'brevo_complaint', date: ?string}|null,
     *     bounced: array{date: ?string}|null
     *   }>,
     *   overflowCount: int,
     *   hasSuppressed: bool,
     *   hasBounced: bool,
     *   contentHash: string
     * }|null Null when no car the owner has is suppressed or bounced
     * @throws CarDatabaseException If a repository query fails
     */
    public function buildForOwner(int $ownerId): ?array
    {
        $flagged = [];
        foreach ($this->repo->findVerificationStateByOwner($ownerId) as $car) {
            $carId      = self::toInt($car->id ?? null);
            $suppressed = self::isFlagSet($car->email_suppressed ?? null);
            $bounced    = self::isFlagSet($car->email_bounced ?? null);
            if ($carId === null || (!$suppressed && !$bounced)) {
                continue;
            }

            $address = self::toNonEmptyString($car->email ?? null);
            if ($address === null && $bounced) {
                $address = self::toNonEmptyString($car->email_bounced_address ?? null);
            }
            if ($address === null) {
                // No address to name, so the owner has nothing to act on here.
                // This is anomalous data (a flagged car should have an
                // address) rather than an expected case, so it is logged.
                logger(
                    $ownerId,
                    LogCategories::LOG_CATEGORY_EMAIL_ERROR,
                    "EmailNoticeBuilder: car {$carId} is flagged (suppressed=" . ($suppressed ? 1 : 0)
                        . ', bounced=' . ($bounced ? 1 : 0) . ') but has no usable email address'
                );
                continue;
            }

            $flagged[$carId] = ['address' => $address, 'suppressed' => $suppressed, 'bounced' => $bounced];
        }

        if ($flagged === []) {
            return null;
        }

        $suppressedIds = array_keys(array_filter($flagged, static fn (array $f): bool => $f['suppressed']));
        $bouncedIds    = array_keys(array_filter($flagged, static fn (array $f): bool => $f['bounced']));

        $suppressionEvents = $this->repo->findLatestEmailEventsByCarIdsAndEvents(
            $suppressedIds,
            EmailEventApplier::SUPPRESSION_EVENTS
        );
        $hardBounceEvents = $this->repo->findLatestEmailEventsByCarIdsAndEvents(
            $bouncedIds,
            EmailEventApplier::HARD_BOUNCE_EVENTS
        );
        $suppressedHist = $this->repo->findLatestHistoryOperationByCarIds($suppressedIds, [self::OPERATION_SUPPRESSED]);
        $bouncedHist    = $this->repo->findLatestHistoryOperationByCarIds($bouncedIds, [self::OPERATION_BOUNCED]);

        /** @var array<string, array{address: string, suppressed: array{cause: 'owner_optout'|'brevo_complaint', date: ?string}|null, bounced: array{date: ?string}|null}> $byAddress */
        $byAddress = [];
        foreach ($flagged as $carId => $flags) {
            $key = strtolower($flags['address']);
            $byAddress[$key] ??= ['address' => $flags['address'], 'suppressed' => null, 'bounced' => null];

            if ($flags['suppressed']) {
                $eventAt = self::toDateTime($suppressionEvents[$carId]->occurred_at ?? null);
                $histAt  = self::toDateTime($suppressedHist[$carId]->timestamp ?? null);
                // The event wins only if it is not older than the latest
                // EMAIL SUPPRESSED row — otherwise it is a stale event from
                // before an intervening clear-and-resuppress cycle. Compared
                // at full datetime precision, not the truncated display
                // date, so a same-day clear-and-resuppress is not missed.
                $eventIsCurrent = $eventAt !== null && ($histAt === null || $eventAt >= $histAt);
                $entry = $eventIsCurrent
                    ? ['cause' => self::CAUSE_BREVO_COMPLAINT, 'date' => $eventAt->format('Y-m-d')]
                    : ['cause' => self::CAUSE_OWNER_OPTOUT, 'date' => $histAt?->format('Y-m-d')];
                $byAddress[$key]['suppressed'] = self::mergeSuppressed($byAddress[$key]['suppressed'], $entry);
            }

            if ($flags['bounced']) {
                // The later of the two: a webhook hard-bounce can arrive
                // after the latest admin-marked 'EMAIL BOUNCED' history row
                // (e.g. an admin cleared an earlier bounce and the car
                // bounced again via webhook, which writes no history row),
                // so neither source is reliably the most recent on its own.
                // Compared at full datetime precision for the same reason
                // as the suppression cause above.
                $histAt  = self::toDateTime($bouncedHist[$carId]->timestamp ?? null);
                $eventAt = self::toDateTime($hardBounceEvents[$carId]->occurred_at ?? null);
                $date    = self::laterDateTime($histAt, $eventAt)?->format('Y-m-d');
                $byAddress[$key]['bounced'] = ['date' => self::laterDate($byAddress[$key]['bounced']['date'] ?? null, $date)];
            }
        }

        ksort($byAddress, SORT_STRING);
        $all = array_values($byAddress);

        $hasSuppressed = false;
        $hasBounced    = false;
        foreach ($all as $entry) {
            $hasSuppressed = $hasSuppressed || $entry['suppressed'] !== null;
            $hasBounced    = $hasBounced || $entry['bounced'] !== null;
        }

        return [
            'addresses'     => array_slice($all, 0, self::MAX_ADDRESSES),
            'overflowCount' => max(0, count($all) - self::MAX_ADDRESSES),
            'hasSuppressed' => $hasSuppressed,
            'hasBounced'    => $hasBounced,
            'contentHash'   => hash(
                'sha256',
                json_encode($all, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)
            ),
        ];
    }

    /**
     * Merge the suppression entry of a second car that shares an address
     *
     * A Brevo complaint wins over an owner opt-out, because it is the
     * specific signal. For the same cause, the later date wins.
     *
     * @param array{cause: 'owner_optout'|'brevo_complaint', date: ?string}|null $current
     * @param array{cause: 'owner_optout'|'brevo_complaint', date: ?string} $next
     * @return array{cause: 'owner_optout'|'brevo_complaint', date: ?string}
     */
    private static function mergeSuppressed(?array $current, array $next): array
    {
        if ($current === null) {
            return $next;
        }
        if ($current['cause'] !== $next['cause']) {
            return $current['cause'] === self::CAUSE_BREVO_COMPLAINT ? $current : $next;
        }

        return ['cause' => $current['cause'], 'date' => self::laterDate($current['date'], $next['date'])];
    }

    private static function laterDate(?string $a, ?string $b): ?string
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return max($a, $b);
    }

    private static function laterDateTime(?DateTimeImmutable $a, ?DateTimeImmutable $b): ?DateTimeImmutable
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a >= $b ? $a : $b;
    }

    /**
     * Read a 0/1 flag column. The DB driver can return int, numeric string or null.
     */
    private static function isFlagSet(mixed $value): bool
    {
        return self::toInt($value) > 0;
    }

    private static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private static function toNonEmptyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Parse a DATETIME column value, for a recency comparison at full
     * precision
     *
     * The returned `Y-m-d`-only values in the built array are too coarse to
     * tell apart a clear and a re-suppression made on the same calendar
     * day, so the cause/bounce-date recency checks in
     * {@see self::buildForOwner()} compare these objects directly, before
     * either side is formatted (`->format('Y-m-d')`) for the returned array.
     *
     * Returns null for null, non-string, malformed or zero-date values.
     */
    private static function toDateTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!' . AppConstants::DATETIME_FORMAT, $value);
        if ($parsed === false || $parsed->format(AppConstants::DATETIME_FORMAT) !== $value) {
            return null;
        }

        return $parsed;
    }
}
