<?php

declare(strict_types=1);

/**
 * CarPermissionException
 *
 * Exception thrown when a car operation is denied due to
 * insufficient permissions or authentication failures.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.14.0
 */
class CarPermissionException extends CarException
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
        return "You do not have permission to perform this car operation.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_ACCESS_DENIED;
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultHttpStatusCode(): int
    {
        return 403;
    }
}
