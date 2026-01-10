<?php

declare(strict_types=1);

/**
 * CarMergeException
 *
 * Exception thrown when car merge operations fail.
 * Used when attempting to merge duplicate car records encounters
 * errors or data conflicts.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class CarMergeException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code (optional)
     * @param Throwable|null $previous Previous exception for chaining (optional)
     */
    public function __construct(string $message = "Car merge failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
