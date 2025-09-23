<?php
declare(strict_types=1);

/**
 * Exception thrown when owner creation fails
 */
class OwnerCreationException extends Exception
{
    public function __construct(string $message = "Owner creation failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}