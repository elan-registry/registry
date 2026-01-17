<?php

declare(strict_types=1);

/**
 * OwnerCreationException
 *
 * Exception thrown when owner creation operations fail.
 * Used when database insertion or validation errors occur during
 * new owner record creation.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class OwnerCreationException extends ElanRegistryException
{
    /**
     * Get default user message for owner creation failures
     *
     * @return string User-friendly error message
     */
    protected function getDefaultUserMessage(): string
    {
        return "Unable to create the owner profile. Please try again or contact support.";
    }

    /**
     * Get log category for owner creation exceptions
     *
     * @return string Log category constant
     */
    protected function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_OWNER_ACTIONS;
    }
}
