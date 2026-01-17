<?php

declare(strict_types=1);

/**
 * CarCreationException
 *
 * Exception thrown when car creation operations fail.
 * Used when database insertion or validation errors occur during
 * new car record creation.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class CarCreationException extends ElanRegistryException
{
    /**
     * Get default user message for car creation failures
     *
     * @return string User-friendly error message
     */
    protected function getDefaultUserMessage(): string
    {
        return "Unable to create the car record. Please try again or contact support.";
    }

    /**
     * Get log category for car creation exceptions
     *
     * @return string Log category constant
     */
    protected function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_CAR_CREATION;
    }
}