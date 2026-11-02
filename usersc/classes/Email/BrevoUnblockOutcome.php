<?php

declare(strict_types=1);

namespace ElanRegistry\Email;

/**
 * BrevoUnblockOutcome - The outcomes of one {@see BrevoBlocklistClient::unblock()} call
 *
 * The value is the text that the Clear Block log line holds.
 *
 * @package ElanRegistry\Email
 * @since v2.31.0
 * @see https://github.com/elan-registry/registry/issues/2357
 */
enum BrevoUnblockOutcome: string
{
    /** Brevo answered 2xx: it removed the address from its blocklist. */
    case Removed = 'removed';

    /** Brevo answered 404: the address was not on its blocklist. */
    case NotBlocked = 'notBlocked';

    /** The unblock did not happen. {@see BrevoUnblockResult::$reason} tells why. */
    case Failed = 'failed';
}
