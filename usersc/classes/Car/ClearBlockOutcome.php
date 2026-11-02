<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * ClearBlockOutcome - What one admin Clear Block did (#2357)
 *
 * `$kind` tells what happened; {@see ClearBlockKind} describes each kind.
 * Create an outcome only with the named constructors, so `$stillBounced` is
 * set only for a kind that cleared the flag. `$stillBounced` is true when
 * `email_bounced` still applies to the owner's current address after the
 * clear, read under the profiles lock. `$stillOptedOut` is true when
 * `email_opted_out` is still set, read under the same lock. The row then
 * shows Clear Opt-out before Clear Bounced.
 *
 * @package ElanRegistry\Car
 * @since v2.30.9
 * @see https://github.com/elan-registry/registry/issues/2357
 */
final readonly class ClearBlockOutcome
{
    /**
     * @param ClearBlockKind $kind          What the action did
     * @param bool           $stillBounced  True when `email_bounced` still applies after the clear
     * @param bool           $stillOptedOut True when `email_opted_out` is still set after the clear
     */
    private function __construct(
        public ClearBlockKind $kind,
        public bool $stillBounced,
        public bool $stillOptedOut = false,
    ) {}

    /**
     * The flag was already 0. No Brevo call and no write.
     */
    public static function notBlocked(): self
    {
        return new self(ClearBlockKind::NotBlocked, false);
    }

    /**
     * Brevo removed the address, or did not hold it, and the flag is cleared.
     *
     * @param bool $stillBounced  True when `email_bounced` still applies, read under the lock
     * @param bool $stillOptedOut True when `email_opted_out` is still set, read under the lock
     */
    public static function cleared(bool $stillBounced, bool $stillOptedOut = false): self
    {
        return new self(ClearBlockKind::Cleared, $stillBounced, $stillOptedOut);
    }

    /**
     * No address to unblock. No Brevo call, and the flag is cleared.
     *
     * @param bool $stillBounced  True when `email_bounced` still applies, read under the lock
     * @param bool $stillOptedOut True when `email_opted_out` is still set, read under the lock
     */
    public static function clearedNoAddress(bool $stillBounced, bool $stillOptedOut = false): self
    {
        return new self(ClearBlockKind::ClearedNoAddress, $stillBounced, $stillOptedOut);
    }

    /**
     * The blocked address is not the owner's address. No Brevo call, and the flag is cleared.
     *
     * @param bool $stillBounced  True when `email_bounced` still applies, read under the lock
     * @param bool $stillOptedOut True when `email_opted_out` is still set, read under the lock
     */
    public static function clearedAddressMoved(bool $stillBounced, bool $stillOptedOut = false): self
    {
        return new self(ClearBlockKind::ClearedAddressMoved, $stillBounced, $stillOptedOut);
    }

    /**
     * Another account uses the address. No Brevo call, and the flag is cleared.
     *
     * @param bool $stillBounced  True when `email_bounced` still applies, read under the lock
     * @param bool $stillOptedOut True when `email_opted_out` is still set, read under the lock
     */
    public static function clearedSharedAddress(bool $stillBounced, bool $stillOptedOut = false): self
    {
        return new self(ClearBlockKind::ClearedSharedAddress, $stillBounced, $stillOptedOut);
    }

    /**
     * The Brevo call failed. No write.
     */
    public static function brevoFailed(): self
    {
        return new self(ClearBlockKind::BrevoFailed, false);
    }

    /**
     * Brevo is not set up: no API key. No call and no write.
     */
    public static function brevoNotSetUp(): self
    {
        return new self(ClearBlockKind::BrevoNotSetUp, false);
    }

    /**
     * A key is set but the SDK is missing or did not load: a fault in the
     * Brevo plugin. No call and no write.
     */
    public static function brevoPluginFault(): self
    {
        return new self(ClearBlockKind::BrevoPluginFault, false);
    }

    /**
     * The stored block changed during this action. The transaction rolled back.
     */
    public static function addressChanged(): self
    {
        return new self(ClearBlockKind::AddressChanged, false);
    }
}
