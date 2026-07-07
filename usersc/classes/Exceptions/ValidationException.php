<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

/**
 * ValidationException
 *
 * Generic exception for validation failures not specific to Cars or Owners.
 * Use domain-specific exceptions (CarValidationException, OwnerValidationException)
 * when the context is clear.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.12.0
 */
class ValidationException extends ElanRegistryException
{
    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "The information provided is invalid. Please check your input.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return 'ValidationError';
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultHttpStatusCode(): int
    {
        return 422;
    }
}
