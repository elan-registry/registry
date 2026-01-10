<?php

declare(strict_types=1);

/**
 * OwnerNotFoundException
 *
 * Exception thrown when a requested owner cannot be found in the database.
 * Used throughout the ElanRegistryOwner class when operations are attempted
 * on non-existent owner records.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class OwnerNotFoundException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code (optional)
     * @param Throwable|null $previous Previous exception for chaining (optional)
     */
    public function __construct(string $message = "Owner not found", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
