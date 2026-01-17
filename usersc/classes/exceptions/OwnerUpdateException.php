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
class OwnerUpdateException extends ElanRegistryException
{
    /**
     * Get default user message for owner update failures
     *
     * @return string User-friendly error message
     */
    protected function getDefaultUserMessage(): string
    {
        return "Unable to update the owner profile. Please try again or contact support.";
    }

    /**
     * Get log category for owner update exceptions
     *
     * @return string Log category constant
     */
    protected function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_OWNER_ACTIONS;
    }
}
