<?php

/**
 * Exception thrown when car data validation fails
 */
class CarValidationException extends Exception
{
    public function __construct(string $message = "Car data validation failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}