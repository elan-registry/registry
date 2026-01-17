<?php

declare(strict_types=1);

/**
 * OwnerNotFoundException
 *
 * Exception thrown when a requested owner cannot be found in the database.
 * Used throughout the ElanRegistryOwner class when operations are attempted
 * on non-existent owner records.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class OwnerNotFoundException extends ElanRegistryException
{
    /**
     * Get default user message for owner not found
     *
     * @return string User-friendly error message
     */
    protected function getDefaultUserMessage(): string
    {
        return "The owner profile you're looking for could not be found. It may have been deleted or is no longer available.";
    }

    /**
     * Get log category for owner not found exceptions
     *
     * @return string Log category constant
     */
    protected function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_OWNER_ACTIONS;
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
