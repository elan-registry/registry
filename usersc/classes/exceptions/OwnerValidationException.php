<?php

declare(strict_types=1);

/**
 * OwnerValidationException
 *
 * Exception thrown when owner data validation fails.
 * Used when input data doesn't meet required validation rules
 * (email format, required fields, profile data, etc.).
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class OwnerValidationException extends ElanRegistryException
{
    /**
     * Get default user message for owner validation failures
     *
     * @return string User-friendly error message
     */
    protected function getDefaultUserMessage(): string
    {
        return "The owner information you provided is incomplete or invalid. Please review and try again.";
    }

    /**
     * Get log category for owner validation exceptions
     *
     * @return string Log category constant
     */
    protected function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_VALIDATION_ERROR;
    }

    /**
     * Get HTTP status code for validation errors
     *
     * @return int HTTP 422 Unprocessable Entity
     */
    protected function getDefaultHttpStatusCode(): int
    {
        return 422;
    }
}
