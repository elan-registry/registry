<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use LogCategories;

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
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "The owner information provided is invalid. Please check your input.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_VALIDATION_ERROR;
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultHttpStatusCode(): int
    {
        return 422;
    }
}
