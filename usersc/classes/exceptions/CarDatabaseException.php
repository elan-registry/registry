<?php

declare(strict_types=1);

/**
 * CarDatabaseException
 *
 * Exception thrown when car-related database operations fail.
 * Used for insert, update, delete, and transaction failures
 * in the Car class.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.14.0
 */
class CarDatabaseException extends CarException
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
        return "A database error occurred while processing the car record.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_DATABASE_ERROR;
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultHttpStatusCode(): int
    {
        return 500;
    }
}
