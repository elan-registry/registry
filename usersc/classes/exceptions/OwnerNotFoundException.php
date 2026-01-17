<?php

declare(strict_types=1);

/**
 * OwnerNotFoundException
 *
 * Exception thrown when a requested owner cannot be found in the database.
 * Used throughout the ElanRegistryOwner class when operations are attempted
 * on non-existent owner records.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class OwnerNotFoundException extends ElanRegistryException
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
        return "The requested owner was not found.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_OWNER_ACTIONS;
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultHttpStatusCode(): int
    {
        return 404;
    }
}
