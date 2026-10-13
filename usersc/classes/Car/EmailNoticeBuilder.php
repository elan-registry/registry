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
 * An owner whose verification emails are paused gets no other signal that
 * this happened. This class reads the owner's deliverability state and
 * returns one plain data array that app/views/_email_paused_notice.php
 * renders. It returns raw, unescaped values. The partial escapes them.
 *
 * The owner's `profiles` row is the system of record (#2101). The flags
 * come from {@see CarRepository::findVerificationStateByOwner()}, which
 * gives the profile columns on each car row. The car rows give only the
 * car addresses that an opt-out names. Each flag uses its own address and
 * date:
 * - `email_bounced`: `email_bounced_address`, dated `email_bounced_at`.
 * - `email_blocked` ({@see self::CAUSE_BREVO_COMPLAINT}, whatever
 *   `email_blocked_cause` says): `email_blocked_address`, dated
 *   `email_blocked_at`.
 * - `email_opted_out` ({@see self::CAUSE_OWNER_OPTOUT}): the opt-out applies
 *   to the person, not to one address, so the notice names each distinct
 *   car `email` of the owner, dated `email_opted_out_at`.
 *
 * Entries are grouped by address, case-insensitively. When one address is
 * both blocked and opted out, the block wins, because Resume does not clear
 * it. A null `_at` column gives a null date, so the notice omits the date
 * instead of inventing one. An owner with no cars gets no notice, because
 * the notice is about car-verification emails.
 *
 * #2356 replaces this notice.
 *
 * @package ElanRegistry\Car
 * @since v2.30.5
 * @see https://github.com/elan-registry/registry/issues/1899
 * @see https://github.com/elan-registry/registry/issues/2101
 */
final class EmailNoticeBuilder
{
    public const CAUSE_OWNER_OPTOUT    = 'owner_optout';
    public const CAUSE_BREVO_COMPLAINT = 'brevo_complaint';

    /** Maximum number of addresses the notice names. The rest go into overflowCount. */
    public const MAX_ADDRESSES = 3;

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
     * null when the profile has no time for the flag.
     *
     * @param int $ownerId Owner (users.id) whose state to check
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
     * }|null Null when the owner has no cars, no profile flag is set, or no
     *       flagged address is usable
     * @throws CarDatabaseException If a repository query fails
     */
    public function buildForOwner(int $ownerId): ?array
    {
        $rows = $this->repo->findVerificationStateByOwner($ownerId);
        if ($rows === []) {
            return null;
        }
        // The profile columns are the same on every row.
        $state = $rows[0];

        /** @var array<string, array{address: string, suppressed: array{cause: 'owner_optout'|'brevo_complaint', date: ?string}|null, bounced: array{date: ?string}|null}> $byAddress */
        $byAddress = [];

        if (self::isFlagSet($state->email_opted_out ?? null)) {
            $entry      = ['cause' => self::CAUSE_OWNER_OPTOUT, 'date' => self::toDate($state->email_opted_out_at ?? null)];
            $carAddress = false;
            foreach ($rows as $car) {
                $address = self::toNonEmptyString($car->email ?? null);
                if ($address === null) {
                    continue;
                }
                $carAddress = true;
                $key        = strtolower($address);
                $byAddress[$key] ??= ['address' => $address, 'suppressed' => null, 'bounced' => null];
                $byAddress[$key]['suppressed'] = self::mergeSuppressed($byAddress[$key]['suppressed'], $entry);
            }
            if (!$carAddress) {
                self::logMissingAddress($ownerId, 'opted out');
            }
        }

        if (self::isFlagSet($state->email_blocked ?? null)) {
            $address = self::toNonEmptyString($state->email_blocked_address ?? null);
            if ($address === null) {
                self::logMissingAddress($ownerId, 'blocked');
            } else {
                $key = strtolower($address);
                $byAddress[$key] ??= ['address' => $address, 'suppressed' => null, 'bounced' => null];
                $byAddress[$key]['suppressed'] = self::mergeSuppressed(
                    $byAddress[$key]['suppressed'],
                    ['cause' => self::CAUSE_BREVO_COMPLAINT, 'date' => self::toDate($state->email_blocked_at ?? null)]
                );
            }
        }

        if (self::isFlagSet($state->email_bounced ?? null)) {
            $address = self::toNonEmptyString($state->email_bounced_address ?? null);
            if ($address === null) {
                self::logMissingAddress($ownerId, 'bounced');
            } else {
                $key = strtolower($address);
                $byAddress[$key] ??= ['address' => $address, 'suppressed' => null, 'bounced' => null];
                $byAddress[$key]['bounced'] = ['date' => self::toDate($state->email_bounced_at ?? null)];
            }
        }

        if ($byAddress === []) {
            return null;
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
     * Merge a second suppression entry for the same address
     *
     * A Brevo complaint (a block) wins over an owner opt-out, because Resume
     * does not clear it. For the same cause, the later date wins.
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

    /**
     * Log a flag that has no address to name. A flagged profile must hold an
     * address (#2101), so this is anomalous data, not an expected case.
     */
    private static function logMissingAddress(int $ownerId, string $flag): void
    {
        logger(
            $ownerId,
            LogCategories::LOG_CATEGORY_EMAIL_ERROR,
            "EmailNoticeBuilder: owner {$ownerId} is {$flag} but has no usable email address"
        );
    }

    private static function laterDate(?string $a, ?string $b): ?string
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return max($a, $b);
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
     * Format a DATETIME column value as `Y-m-d`
     *
     * Returns null for null, non-string, malformed or zero-date values.
     */
    private static function toDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!' . AppConstants::DATETIME_FORMAT, $value);
        if ($parsed === false || $parsed->format(AppConstants::DATETIME_FORMAT) !== $value) {
            return null;
        }

        return $parsed->format('Y-m-d');
    }
}
