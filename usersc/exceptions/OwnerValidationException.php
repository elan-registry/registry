<?php
declare(strict_types=1);

/**
 * Exception thrown when owner data validation fails
 */
class OwnerValidationException extends Exception
{
    public function __construct(string $message = "Owner validation failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}