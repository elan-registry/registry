<?php

declare(strict_types=1);

/**
 * Exception thrown when car ownership transfer operations fail
 */
class CarTransferException extends Exception
{
    public function __construct(string $message = "Car transfer failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}