<?php

/**
 * Exception thrown when car deletion operations fail
 */
class CarDeletionException extends Exception
{
    public function __construct(string $message = "Car deletion failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}