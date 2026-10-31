<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * ClearBlockOutcome - What one admin Clear Block did (#2357)
 *
 * `$kind` is one of the `self::*` constants. Create an outcome only with the
 * named constructors, so `$stillBounced` is set only for a kind that cleared
 * the flag. `$stillBounced` is true when `email_bounced` still applies to the
 * owner's current address after the clear, read under the profiles lock.
 * `$stillOptedOut` is true when `email_opted_out` is still set, read under
 * the same lock. The row then shows Clear Opt-out before Clear Bounced.
 *
 * - NOT_BLOCKED: the flag was 0, or another request cleared it during this action. No write.
 * - CLEARED: Brevo removed the address, or Brevo did not hold it. The flag is cleared.
 * - CLEARED_NO_ADDRESS: no address to unblock. No Brevo call. The flag is cleared.
 * - CLEARED_ADDRESS_MOVED: the blocked address is not the owner's address. No Brevo call. The flag is cleared.
 * - CLEARED_SHARED_ADDRESS: another account uses the address. No Brevo call. The flag is cleared.
 * - BREVO_FAILED: the Brevo call failed, or the API key query failed. No write.
 * - BREVO_NOT_SET_UP: no Brevo API key, or no SDK autoloader file. No call, no write.
 * - BREVO_PLUGIN_FAULT: the SDK is installed but did not load. No call, no write.
 * - ADDRESS_CHANGED: the stored block changed during this action. The transaction rolled back.
 *
 * @package ElanRegistry\Car
 * @since v2.30.9
 * @see https://github.com/elan-registry/registry/issues/2357
 */
final readonly class ClearBlockOutcome
{
    public const NOT_BLOCKED            = 'not_blocked';
    public const CLEARED                = 'cleared';
    public const CLEARED_NO_ADDRESS     = 'cleared_no_address';
    public const CLEARED_ADDRESS_MOVED  = 'cleared_address_moved';
    public const CLEARED_SHARED_ADDRESS = 'cleared_shared_address';
    public const BREVO_FAILED           = 'brevo_failed';
    public const BREVO_NOT_SET_UP       = 'brevo_not_set_up';
    public const BREVO_PLUGIN_FAULT     = 'brevo_plugin_fault';
    public const ADDRESS_CHANGED        = 'address_changed';

    /**
     * @param string $kind          One of the `self::*` constants
     * @param bool   $stillBounced  True when `email_bounced` still applies after the clear
     * @param bool   $stillOptedOut True when `email_opted_out` is still set after the clear
     */
    private function __construct(
        public string $kind,
        public bool $stillBounced,
        public bool $stillOptedOut = false,
    ) {}

    /**
     * The flag was already 0. No Brevo call and no write.
     */
    public static function notBlocked(): self
    {
        return new self(self::NOT_BLOCKED, false);
    }

    /**
     * Brevo removed the address, or did not hold it, and the flag is cleared.
     *
     * @param bool $stillBounced  True when `email_bounced` still applies, read under the lock
     * @param bool $stillOptedOut True when `email_opted_out` is still set, read under the lock
     */
    public static function cleared(bool $stillBounced, bool $stillOptedOut = false): self
    {
        return new self(self::CLEARED, $stillBounced, $stillOptedOut);
    }

    /**
     * No address to unblock. No Brevo call, and the flag is cleared.
     *
     * @param bool $stillBounced  True when `email_bounced` still applies, read under the lock
     * @param bool $stillOptedOut True when `email_opted_out` is still set, read under the lock
     */
    public static function clearedNoAddress(bool $stillBounced, bool $stillOptedOut = false): self
    {
        return new self(self::CLEARED_NO_ADDRESS, $stillBounced, $stillOptedOut);
    }

    /**
     * The blocked address is not the owner's address. No Brevo call, and the flag is cleared.
     *
     * @param bool $stillBounced  True when `email_bounced` still applies, read under the lock
     * @param bool $stillOptedOut True when `email_opted_out` is still set, read under the lock
     */
    public static function clearedAddressMoved(bool $stillBounced, bool $stillOptedOut = false): self
    {
        return new self(self::CLEARED_ADDRESS_MOVED, $stillBounced, $stillOptedOut);
    }

    /**
     * Another account uses the address. No Brevo call, and the flag is cleared.
     *
     * @param bool $stillBounced  True when `email_bounced` still applies, read under the lock
     * @param bool $stillOptedOut True when `email_opted_out` is still set, read under the lock
     */
    public static function clearedSharedAddress(bool $stillBounced, bool $stillOptedOut = false): self
    {
        return new self(self::CLEARED_SHARED_ADDRESS, $stillBounced, $stillOptedOut);
    }

    /**
     * The Brevo call failed. No write.
     */
    public static function brevoFailed(): self
    {
        return new self(self::BREVO_FAILED, false);
    }

    /**
     * Brevo is not set up: no API key, or no SDK autoloader file. No call and no write.
     */
    public static function brevoNotSetUp(): self
    {
        return new self(self::BREVO_NOT_SET_UP, false);
    }

    /**
     * The SDK is installed but did not load: a fault in the Brevo plugin.
     * No call and no write.
     */
    public static function brevoPluginFault(): self
    {
        return new self(self::BREVO_PLUGIN_FAULT, false);
    }

    /**
     * The stored block changed during this action. The transaction rolled back.
     */
    public static function addressChanged(): self
    {
        return new self(self::ADDRESS_CHANGED, false);
    }

    /**
     * Tell whether this outcome cleared the registry flag
     */
    public function isCleared(): bool
    {
        return in_array($this->kind, [
            self::CLEARED,
            self::CLEARED_NO_ADDRESS,
            self::CLEARED_ADDRESS_MOVED,
            self::CLEARED_SHARED_ADDRESS,
        ], true);
    }
}
