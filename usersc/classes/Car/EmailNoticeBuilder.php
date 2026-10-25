<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DateTimeImmutable;
use ElanRegistry\AppConstants;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\ExceptionLogSummary;
use ElanRegistry\LogCategories;

/**
 * EmailNoticeBuilder - Collects the data for the email notice on account.php
 *
 * An owner whose registry email stopped gets no other signal that this
 * happened. This class reads the owner's effective deliverability state and
 * returns one plain data array that app/views/_email_paused_notice.php
 * renders. It returns raw, unescaped values. The partial escapes them.
 *
 * The state comes from {@see CarRepository::effectiveEmailFlags()}, so a
 * flag stored for an old address does not show (#2356). The notice is
 * about the owner, not about a car: an owner with no car still gets it
 * (#2319). One state wins, in the order blocked, bounced, opted out. Both
 * SendPolicy::verificationReason() and VerificationTabStatus::chip() use this
 * order (#2461):
 * - `blocked`: `email_blocked_address`, dated `email_blocked_at`. A block
 *   wins over a bounce. A block can have more than one cause (#2500), but
 *   the profile holds only one. So the builder also reads the suppression
 *   events for the address from {@see CarRepository::ownerBlockEventCauses()},
 *   after the last admin Clear Block
 *   ({@see CarRepository::latestFlagClearAt()}).
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
 * @see https://github.com/elan-registry/registry/issues/2500
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
     * It is null for `opted_out`, and when both addresses are empty.
     * `causes` holds `CarRepository::BLOCKED_CAUSE_*` values, each once: the
     * profile cause first, then the event causes in
     * `CarRepository::BLOCKED_CAUSES` order. It is empty for `bounced`,
     * `opted_out`, and a block with no known cause.
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
     *   causes: list<string>,
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
            $address = self::flaggedAddress($flags->blockedAddress, $flags);
            $state = self::state(
                self::KIND_BLOCKED,
                $address,
                $flags->blockedAt,
                $this->blockCauses($ownerId, $flags->blockedCause, $address),
                $flags
            );
        } elseif ($flags->bounced) {
            $state = self::state(self::KIND_BOUNCED, self::flaggedAddress($flags->bouncedAddress, $flags), $flags->bouncedAt, [], $flags);
        } elseif ($flags->optedOut) {
            $state = [
                'kind'         => self::KIND_OPTED_OUT,
                'address'      => null,
                'date'         => self::toDate($flags->optedOutAt),
                'causes'       => [],
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
     * Collect the causes of a block, each once
     *
     * The profile cause comes first. The event causes follow in
     * `CarRepository::BLOCKED_CAUSES` order. An admin block writes an
     * `unsubscribed` event, so a `blocklist` profile cause drops that event.
     * Otherwise the notice tells the owner that they unsubscribed.
     *
     * The profile flags already show the block. So when the event lookup
     * fails, the builder logs it and returns the profile cause only. The
     * notice still shows, with less detail.
     *
     * @param int $ownerId Owner (users.id)
     * @param string|null $profileCause `profiles.email_blocked_cause`
     * @param string|null $address The blocked address the notice shows. Null issues no event query.
     * @return list<string>
     */
    private function blockCauses(int $ownerId, ?string $profileCause, ?string $address): array
    {
        $causes = in_array($profileCause, CarRepository::BLOCKED_CAUSES, true) ? [$profileCause] : [];
        if ($causes === [] && $profileCause !== null && $profileCause !== '') {
            // Log the bad value, so it does not stay hidden behind the generic text.
            logger($ownerId, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, sprintf(
                'EmailNoticeBuilder: unknown email_blocked_cause "%s" for owner %d',
                $profileCause,
                $ownerId
            ));
        }

        if ($address === null) {
            return $causes;
        }

        try {
            $eventCauses = $this->repo->ownerBlockEventCauses(
                $ownerId,
                $address,
                $this->repo->latestFlagClearAt($ownerId, DeliverabilityFlagLog::FLAG_BLOCKED, DeliverabilityFlagLog::SOURCE_ADMIN)
            );
        } catch (CarDatabaseException $e) {
            logger($ownerId, LogCategories::LOG_CATEGORY_DATABASE_ERROR, sprintf(
                'EmailNoticeBuilder: block cause lookup failed for owner %d: %s',
                $ownerId,
                // The driver text can echo the bound address, so log no message (#2420).
                ExceptionLogSummary::of($e)
            ));
            return $causes;
        }
        if ($profileCause === CarRepository::BLOCKED_CAUSE_BLOCKLIST) {
            $eventCauses = array_diff($eventCauses, [CarRepository::BLOCKED_CAUSE_UNSUBSCRIBED]);
        }
        // array_intersect() keeps the BLOCKED_CAUSES order and drops unknown events.
        return array_values(array_unique([...$causes, ...array_intersect(CarRepository::BLOCKED_CAUSES, $eventCauses)]));
    }

    /**
     * Resolve the address that a block or a bounce applies to
     *
     * A flag with no stored address applies to the current address (fail
     * closed). Null when both are empty.
     */
    private static function flaggedAddress(?string $address, EffectiveEmailFlags $flags): ?string
    {
        $address = trim($address ?? '');
        if ($address === '') {
            $address = trim($flags->currentEmail ?? '');
        }

        return $address === '' ? null : $address;
    }

    /**
     * Build the state for a block or a bounce, with the opt-out details
     *
     * @param 'blocked'|'bounced' $kind
     * @param ?string $address From {@see self::flaggedAddress()}
     * @param list<string> $causes Block causes. Empty for a bounce.
     * @return array{kind: 'blocked'|'bounced', address: ?string, date: ?string, causes: list<string>, alsoOptedOut: bool, optedOutDate: ?string}
     */
    private static function state(
        string $kind,
        ?string $address,
        ?string $at,
        array $causes,
        EffectiveEmailFlags $flags
    ): array {
        return [
            'kind'         => $kind,
            'address'      => $address,
            'date'         => self::toDate($at),
            'causes'       => $causes,
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
