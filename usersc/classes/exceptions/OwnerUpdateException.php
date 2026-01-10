<?php

declare(strict_types=1);

/**
 * OwnerUpdateException
 *
 * Exception thrown when owner update operations fail.
 * Used when database update errors occur or validation fails during
 * owner record modifications.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class OwnerUpdateException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code (optional)
     * @param Throwable|null $previous Previous exception for chaining (optional)
     */
    public function __construct(string $message = "Owner update failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
