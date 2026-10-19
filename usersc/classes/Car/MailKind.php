<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * MailKind - The kinds of non-account mail that {@see SendPolicy} controls
 *
 * An enum and not a string, so a typo in a caller is a fatal error and
 * cannot let a send through.
 *
 * @package ElanRegistry\Car
 * @since v2.30.8
 * @see https://github.com/elan-registry/registry/issues/2360
 */
enum MailKind: string
{
    /** The verification email from the admin tool and the cron job */
    case Verification = 'verification';

    /** A message from one owner to another (send-owner-email.php) */
    case OwnerContact = 'owner_contact';

    /** A message from an admin to an owner (process-admin-contact.php) */
    case AdminContact = 'admin_contact';
}
