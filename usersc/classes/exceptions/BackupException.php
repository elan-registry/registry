<?php

declare(strict_types=1);

/**
 * BackupException
 *
 * Exception thrown when backup operations fail.
 * Used when backup creation, restoration, or cleanup operations encounter errors.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.9.2
 */
class BackupException extends ElanRegistryException
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
        return "A backup operation failed. Please contact support.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_BACKUP_ERROR;
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultHttpStatusCode(): int
    {
        return 500;
    }
}
