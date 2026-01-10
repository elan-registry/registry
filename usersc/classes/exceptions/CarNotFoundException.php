<?php

/**
 * Exception thrown when a requested car cannot be found
 */
class CarNotFoundException extends Exception
{
    public function __construct(string $message = "Car not found", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}