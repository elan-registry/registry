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
     * The state of an owner with no `profiles` row: no flag and no details
     */
    public static function none(): self
    {
        return new self(false, false, null, false, null, null, null, false, null, null, null);
    }
}
