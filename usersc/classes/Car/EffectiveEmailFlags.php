<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * EffectiveEmailFlags - The deliverability flags that apply to one owner now
 *
 * Returned by {@see CarRepository::effectiveEmailFlags()}. A stored
 * `email_blocked` or `email_bounced` applies only while its `_address`
 * matches the owner's current `users.email` under the matching rule. A flag
 * with a NULL or empty `_address` applies (fail closed). `email_opted_out`
 * applies whatever the address.
 *
 * The detail fields of a flag are filled only when that flag applies, so a
 * reader cannot show an old address or date for a flag that does not apply.
 * `hasProfile` is false when the owner has no `profiles` row or no `users`
 * row ({@see self::none()}). Such an owner has no flag. The caller must treat
 * `hasProfile = false` as "do not mail".
 *
 * `currentEmail` is the raw `users.email`, and null when `hasProfile` is
 * false. A reader names it when an applied flag has a NULL or empty
 * `_address`, because that flag applies to the current address.
 *
 * @package ElanRegistry\Car
 * @since v2.30.7
 * @see https://github.com/elan-registry/registry/issues/2356
 */
final readonly class EffectiveEmailFlags
{
    public bool $optedOut;
    public ?string $optedOutAt;
    public bool $blocked;
    public ?string $blockedAddress;
    public ?string $blockedAt;
    public ?string $blockedCause;
    public bool $bounced;
    public ?string $bouncedAddress;
    public ?string $bouncedAt;
    public ?string $currentEmail;

    /**
     * Each field is kept only when the owner has a profile. A flag detail
     * field also needs its flag to apply.
     *
     * @param bool        $hasProfile     True when the owner has a `profiles` row
     * @param bool        $optedOut       True when `email_opted_out` applies
     * @param string|null $optedOutAt     `email_opted_out_at`
     * @param bool        $blocked        True when `email_blocked` applies
     * @param string|null $blockedAddress `email_blocked_address`
     * @param string|null $blockedAt      `email_blocked_at`
     * @param string|null $blockedCause   `email_blocked_cause`
     * @param bool        $bounced        True when `email_bounced` applies
     * @param string|null $bouncedAddress `email_bounced_address`
     * @param string|null $bouncedAt      `email_bounced_at`
     * @param string|null $currentEmail   `users.email` as stored. It is dropped when `$hasProfile` is false.
     */
    public function __construct(
        public bool $hasProfile,
        bool $optedOut,
        ?string $optedOutAt,
        bool $blocked,
        ?string $blockedAddress,
        ?string $blockedAt,
        ?string $blockedCause,
        bool $bounced,
        ?string $bouncedAddress,
        ?string $bouncedAt,
        ?string $currentEmail,
    ) {
        $this->optedOut       = $hasProfile && $optedOut;
        $this->optedOutAt     = $this->optedOut ? $optedOutAt : null;
        $this->blocked        = $hasProfile && $blocked;
        $this->blockedAddress = $this->blocked ? $blockedAddress : null;
        $this->blockedAt      = $this->blocked ? $blockedAt : null;
        $this->blockedCause   = $this->blocked ? $blockedCause : null;
        $this->bounced        = $hasProfile && $bounced;
        $this->bouncedAddress = $this->bounced ? $bouncedAddress : null;
        $this->bouncedAt      = $this->bounced ? $bouncedAt : null;
        $this->currentEmail   = $hasProfile ? $currentEmail : null;
    }

    /**
     * Tell whether a stored flag applies to the normalized current address
     *
     * A set flag applies when its normalized address is empty (fail closed)
     * or equals `$current`. This is the PHP form of
     * `CarRepository::FLAG_ACTIVE_BLOCKED` and `CarRepository::FLAG_ACTIVE_BOUNCED`.
     * Change the two together.
     *
     * @param int         $flag    The stored flag, already checked to be 0 or 1
     * @param string|null $address The stored `_address` of the flag
     * @param string      $current The owner's `users.email`. The method
     *                             normalizes it, so a raw value gives the
     *                             same answer as a normalized one.
     * @return bool True when the flag is 1 and applies to `$current`
     */
    public static function flagApplies(int $flag, ?string $address, string $current): bool
    {
        if ($flag !== 1) {
            return false;
        }

        $stored = EmailAddress::normalize($address);

        return $stored === '' || $stored === EmailAddress::normalize($current);
    }

    /**
     * The queue row columns that {@see self::fromQueueRow()} needs
     */
    private const QUEUE_ROW_COLUMNS = [
        'has_profile',
        'email_opted_out',
        'email_blocked_active',
        'email_blocked_cause',
        'email_bounced_active',
    ];

    /**
     * Build the flags from one Verification-tab queue row
     *
     * The queue queries of {@see CarRepository::findVerificationQueue()} give
     * `has_profile`, `email_blocked_active`, `email_bounced_active`,
     * `email_opted_out` and `email_blocked_cause` (#2101, #2356). The
     * `_active` columns are 1 only while the flag applies to the owner's
     * current address, so the result matches
     * {@see CarRepository::effectiveEmailFlags()}. The row has no addresses
     * or dates, so those fields are null.
     *
     * A missing column is an error, not "no flag". Otherwise a query that
     * drops or misspells a column shows a blocked owner as eligible. An
     * Eligible preview row has no profile columns. Use {@see self::eligible()}
     * for it.
     *
     * @param object $row One row of the Verification-tab queue
     * @throws \InvalidArgumentException If the row has no column of {@see self::QUEUE_ROW_COLUMNS}
     */
    public static function fromQueueRow(object $row): self
    {
        foreach (self::QUEUE_ROW_COLUMNS as $column) {
            if (!property_exists($row, $column)) {
                throw new \InvalidArgumentException(
                    "EffectiveEmailFlags::fromQueueRow() received a queue row without the '{$column}' column."
                );
            }
        }

        $blockedCause = $row->email_blocked_cause;

        return new self(
            hasProfile: (int) $row->has_profile === 1,
            optedOut: (int) $row->email_opted_out === 1,
            optedOutAt: null,
            blocked: (int) $row->email_blocked_active === 1,
            blockedAddress: null,
            blockedAt: null,
            blockedCause: $blockedCause !== null ? (string) $blockedCause : null,
            bounced: (int) $row->email_bounced_active === 1,
            bouncedAddress: null,
            bouncedAt: null,
            currentEmail: null,
        );
    }

    /**
     * The state of an owner in the Eligible queue: a profile and no flag
     *
     * The eligibility query selects only owners with a `profiles` row and no
     * flag that applies, so its rows have no profile columns.
     */
    public static function eligible(): self
    {
        return new self(true, false, null, false, null, null, null, false, null, null, null);
    }

    /**
     * The state of an owner with no `profiles` row: no flag and no details
     */
    public static function none(): self
    {
        return new self(false, false, null, false, null, null, null, false, null, null, null);
    }
}
