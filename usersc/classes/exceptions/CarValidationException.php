<?php

declare(strict_types=1);

/**
 * CarValidationException
 *
 * Exception thrown when car data validation fails.
 * Used when input data doesn't meet required validation rules
 * (chassis number format, required fields, etc.).
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class CarValidationException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code (optional)
     * @param Throwable|null $previous Previous exception for chaining (optional)
     */
    public function __construct(string $message = "Car validation failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
