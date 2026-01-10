<?php

declare(strict_types=1);

/**
 * OwnerCreationException
 *
 * Exception thrown when owner creation operations fail.
 * Used when database insertion or validation errors occur during
 * new owner record creation.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class OwnerCreationException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code (optional)
     * @param Throwable|null $previous Previous exception for chaining (optional)
     */
    public function __construct(string $message = "Owner creation failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
