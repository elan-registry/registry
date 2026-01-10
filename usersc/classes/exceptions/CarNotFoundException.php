<?php

declare(strict_types=1);

/**
 * CarNotFoundException
 *
 * Exception thrown when a requested car cannot be found in the database.
 * Used throughout the Car class and related components when operations
 * are attempted on non-existent car records.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class CarNotFoundException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code (optional)
     * @param Throwable|null $previous Previous exception for chaining (optional)
     */
    public function __construct(string $message = "Car not found", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
