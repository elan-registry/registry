<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use Throwable;

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
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code (optional)
     * @param Throwable|null $previous Previous exception for chaining (optional)
     * @param string|null $userMessage User-friendly message (uses default if null)
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null,
        ?string $userMessage = null
    ) {
        parent::__construct($message, $code, $previous, $userMessage);
    }

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
