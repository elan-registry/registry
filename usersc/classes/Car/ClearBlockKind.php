<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * ClearBlockKind - The kinds of {@see ClearBlockOutcome} (#2357)
 *
 * An enum, so a `match` on the kind without a `default` arm is exhaustive:
 * PHPStan reports a new case that a `match` does not handle. The value is the
 * text that the admin Clear Block log line holds.
 *
 * @package ElanRegistry\Car
 * @since v2.31.0
 * @see https://github.com/elan-registry/registry/issues/2357
 */
enum ClearBlockKind: string
{
    /** The flag was 0, or another request cleared it during this action. No write. */
    case NotBlocked = 'not_blocked';

    /** Brevo removed the address, or Brevo did not hold it. The flag is cleared. */
    case Cleared = 'cleared';

    /** No address to unblock. No Brevo call. The flag is cleared. */
    case ClearedNoAddress = 'cleared_no_address';

    /** The blocked address is not the owner's address. No Brevo call. The flag is cleared. */
    case ClearedAddressMoved = 'cleared_address_moved';

    /** Another account uses the address. No Brevo call. The flag is cleared. */
    case ClearedSharedAddress = 'cleared_shared_address';

    /** The Brevo call failed, or the API key query failed. No write. */
    case BrevoFailed = 'brevo_failed';

    /** No Brevo API key. No call, no write. */
    case BrevoNotSetUp = 'brevo_not_set_up';

    /** A key is set but the SDK is missing or did not load. No call, no write. */
    case BrevoPluginFault = 'brevo_plugin_fault';

    /** The stored block changed during this action. The transaction rolled back. */
    case AddressChanged = 'address_changed';

    /**
     * Tell whether this kind cleared the registry flag
     *
     * @return bool True for the four `Cleared*` kinds
     */
    public function isCleared(): bool
    {
        return match ($this) {
            self::Cleared,
            self::ClearedNoAddress,
            self::ClearedAddressMoved,
            self::ClearedSharedAddress => true,
            self::NotBlocked,
            self::BrevoFailed,
            self::BrevoNotSetUp,
            self::BrevoPluginFault,
            self::AddressChanged => false,
        };
    }
}
