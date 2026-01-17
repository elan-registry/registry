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
class CarNotFoundException extends ElanRegistryException
{
    /**
     * Get default user message for car not found
     *
     * @return string User-friendly error message
     */
    protected function getDefaultUserMessage(): string
    {
        return "The car you're looking for could not be found. It may have been deleted or is no longer available.";
    }

    /**
     * Get log category for car not found exceptions
     *
     * @return string Log category constant
     */
    protected function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_CAR_ERRORS;
    }

    /**
     * Get HTTP status code for not found errors
     *
     * @return int HTTP 404 Not Found
     */
    protected function getDefaultHttpStatusCode(): int
    {
        return 404;
    }
}
