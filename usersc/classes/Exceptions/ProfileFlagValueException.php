<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

/**
 * ProfileFlagValueException
 *
 * Thrown when a stored profile deliverability flag (`email_opted_out`,
 * `email_blocked`, `email_bounced`) holds a value other than 0 or 1. The
 * message names the column and the user ID, never an address.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.30.8
 * @see https://github.com/elan-registry/registry/issues/2393
 */
class ProfileFlagValueException extends CarDatabaseException
{
}
