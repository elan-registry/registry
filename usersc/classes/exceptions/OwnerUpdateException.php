<?php
declare(strict_types=1);

/**
 * Exception thrown when owner update fails
 */
class OwnerUpdateException extends Exception
{
    public function __construct(string $message = "Owner update failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}