<?php

declare(strict_types=1);

/**
 * CarDeletionException
 *
 * Exception thrown when car deletion operations fail.
 * Used when database deletion errors occur or when attempting to
 * delete cars with active dependencies.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class CarDeletionException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code (optional)
     * @param Throwable|null $previous Previous exception for chaining (optional)
     */
    public function __construct(string $message = "Car deletion failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
