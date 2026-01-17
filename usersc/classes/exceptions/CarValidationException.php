<?php

declare(strict_types=1);

/**
 * CarValidationException
 *
 * Exception thrown when car data validation fails.
 * Used when input data doesn't meet required validation rules
 * (chassis number format, required fields, etc.).
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class CarValidationException extends ElanRegistryException
{
    /**
     * Get default user message for car validation failures
     *
     * @return string User-friendly error message
     */
    protected function getDefaultUserMessage(): string
    {
        return "The car information you provided is incomplete or invalid. Please review and try again.";
    }

    /**
     * Get log category for car validation exceptions
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
