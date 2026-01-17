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
class CarMergeException extends ElanRegistryException
{
    /**
     * Get default user message for car merge failures
     *
     * @return string User-friendly error message
     */
    protected function getDefaultUserMessage(): string
    {
        return "Unable to merge the car records. Please try again or contact support.";
    }

    /**
     * Get log category for car merge exceptions
     *
     * @return string Log category constant
     */
    protected function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_CAR_MERGE;
    }
}
