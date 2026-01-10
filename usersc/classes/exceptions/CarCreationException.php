<?php

/**
 * Exception thrown when car creation fails
 */
class CarCreationException extends Exception
{
    public function __construct(string $message = "Car creation failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}