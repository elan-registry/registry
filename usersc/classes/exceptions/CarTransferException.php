<?php

declare(strict_types=1);

/**
 * CarTransferException
 *
 * Exception thrown when car ownership transfer operations fail.
 * Used during the car transfer workflow when database updates,
 * validation, or user permission checks fail.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class CarTransferException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code (optional)
     * @param Throwable|null $previous Previous exception for chaining (optional)
     */
    public function __construct(string $message = "Car transfer failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
