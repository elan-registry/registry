<?php

/**
 * Exception thrown when car merge operations fail
 */
class CarMergeException extends Exception
{
    public function __construct(string $message = "Car merge failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}