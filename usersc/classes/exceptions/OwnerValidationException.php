<?php

declare(strict_types=1);

/**
 * OwnerValidationException
 *
 * Exception thrown when owner data validation fails.
 * Used when input data doesn't meet required validation rules
 * (email format, required fields, profile data, etc.).
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class OwnerValidationException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code (optional)
     * @param Throwable|null $previous Previous exception for chaining (optional)
     */
    public function __construct(string $message = "Owner validation failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
