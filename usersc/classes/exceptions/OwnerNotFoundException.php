<?php
declare(strict_types=1);

/**
 * Exception thrown when owner/user is not found
 */
class OwnerNotFoundException extends Exception
{
    public function __construct(string $message = "Owner not found", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}