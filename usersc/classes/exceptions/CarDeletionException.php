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
class CarDeletionException extends ElanRegistryException
{
    /**
     * Get default user message for car deletion failures
     *
     * @return string User-friendly error message
     */
    protected function getDefaultUserMessage(): string
    {
        return "Unable to delete the car record. Please try again or contact support.";
    }

    /**
     * Get log category for car deletion exceptions
     *
     * @return string Log category constant
     */
    protected function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_CAR_DELETION;
    }
}
