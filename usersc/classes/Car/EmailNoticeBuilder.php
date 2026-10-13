<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DateTimeImmutable;
use ElanRegistry\AppConstants;
use ElanRegistry\Exceptions\CarDatabaseException;

/**
 * EmailNoticeBuilder - Collects the data for the email notice on account.php
 *
 * An owner whose registry email stopped gets no other signal that this
 * happened. This class reads the owner's effective deliverability state and
 * returns one plain data array that app/views/_email_paused_notice.php
 * renders. It returns raw, unescaped values. The partial escapes them.
 *
 * The state comes only from {@see CarRepository::effectiveEmailFlags()}, so
 * a flag stored for an old address does not show (#2356). The notice is
 * about the owner, not about a car: an owner with no car still gets it
 * (#2319). One state wins:
 * - `blocked`: `email_blocked_address`, dated `email_blocked_at`. A block
 *   wins over a bounce, because an admin must clear it.
 * - `bounced`: `email_bounced_address`, dated `email_bounced_at`.
 * - `opted_out`: only when no block or bounce applies, dated
 *   `email_opted_out_at`.
 *
 * With a block or a bounce, `alsoOptedOut` tells the partial to add the
 * opt-out line. A null `_at` column gives a null date, so the notice omits
 * the date instead of inventing one.
 *
 * @package ElanRegistry\Car
 * @since v2.30.5
 * @see https://github.com/elan-registry/registry/issues/1899
 * @see https://github.com/elan-registry/registry/issues/2356
 */
final class EmailNoticeBuilder
{
    public const KIND_BLOCKED   = 'blocked';
    public const KIND_BOUNCED   = 'bounced';
    public const KIND_OPTED_OUT = 'opted_out';

    public function __construct(private CarRepository $repo)
    {
    }

    /**
     * Build the email notice data for one owner
     *
     * `address` is the flagged address for `blocked` and `bounced`. When the
     * flag has no stored address, it is the owner's current `users.email`.
     * It is null for `opted_out`, and when both addresses are empty. `cause` is one
     * of the `CarRepository::BLOCKED_CAUSE_*` values, set only for `blocked`.
     * `optedOutDate` is set only when `alsoOptedOut` is true. Each date is
     * `Y-m-d`, or null when the profile has no time for the flag.
     * `contentHash` changes when any other field changes, so a dismissed
     * notice shows again when the state changes.
     *
     * @param int $ownerId Owner (users.id) whose state to check
     * @return array{
     *   kind: 'blocked'|'bounced'|'opted_out',
     *   address: ?string,
     *   date: ?string,
     *   cause: ?string,
     *   alsoOptedOut: bool,
     *   optedOutDate: ?string,
     *   contentHash: string
     * }|null Null when no flag applies
     * @throws CarDatabaseException If the repository query fails
     */
    public function buildForOwner(int $ownerId): ?array
    {
        $flags = $this->repo->effectiveEmailFlags($ownerId);

        if ($flags->blocked) {
            $cause = in_array($flags->blockedCause, CarRepository::BLOCKED_CAUSES, true) ? $flags->blockedCause : null;
            $state = self::state(self::KIND_BLOCKED, $flags->blockedAddress, $flags->blockedAt, $cause, $flags);
        } elseif ($flags->bounced) {
            $state = self::state(self::KIND_BOUNCED, $flags->bouncedAddress, $flags->bouncedAt, null, $flags);
        } elseif ($flags->optedOut) {
            $state = [
                'kind'         => self::KIND_OPTED_OUT,
                'address'      => null,
                'date'         => self::toDate($flags->optedOutAt),
                'cause'        => null,
                'alsoOptedOut' => false,
                'optedOutDate' => null,
            ];
        } else {
            return null;
        }

        $state['contentHash'] = hash(
            'sha256',
            json_encode($state, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)
        );

        return $state;
    }

    /**
     * Build the state for a block or a bounce, with the opt-out details
     *
     * @param 'blocked'|'bounced' $kind
     * @return array{kind: 'blocked'|'bounced', address: ?string, date: ?string, cause: ?string, alsoOptedOut: bool, optedOutDate: ?string}
     */
    private static function state(
        string $kind,
        ?string $address,
        ?string $at,
        ?string $cause,
        EffectiveEmailFlags $flags
    ): array {
        $address = trim($address ?? '');
        if ($address === '') {
            // A flag with no stored address applies to the current address (fail closed).
            $address = trim($flags->currentEmail ?? '');
        }

        return [
            'kind'         => $kind,
            'address'      => $address === '' ? null : $address,
            'date'         => self::toDate($at),
            'cause'        => $cause,
            'alsoOptedOut' => $flags->optedOut,
            'optedOutDate' => $flags->optedOut ? self::toDate($flags->optedOutAt) : null,
        ];
    }

    /**
     * Format a DATETIME column value as `Y-m-d`
     *
     * Returns null for null, malformed or zero-date values.
     */
    private static function toDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!' . AppConstants::DATETIME_FORMAT, $value);
        if ($parsed === false || $parsed->format(AppConstants::DATETIME_FORMAT) !== $value) {
            return null;
        }

        return $parsed->format('Y-m-d');
    }
}
