<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use LogCategories;
use Throwable;

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
class CarValidationException extends CarException
{
    private bool $hasExplicitUserMessage;

    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code (optional)
     * @param Throwable|null $previous Previous exception for chaining (optional)
     * @param string|null $userMessage User-friendly message (uses getMessage() if null)
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null,
        ?string $userMessage = null
    ) {
        $this->hasExplicitUserMessage = ($userMessage !== null);
        parent::__construct($message, $code, $previous, $userMessage);
    }

    /**
     * Validation exception messages are user-safe descriptions of input failures
     * (e.g. "Purchase date must be between ..."). When no explicit user message
     * was provided, return the technical message directly rather than the generic
     * "invalid input" fallback used by other exception types.
     */
    public function getUserMessage(): string
    {
        if ($this->hasExplicitUserMessage) {
            return parent::getUserMessage();
        }
        return $this->getMessage();
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultUserMessage(): string
    {
        return "The car information provided is invalid. Please check your input.";
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
