<?php

declare(strict_types=1);

/**
 * DocumentationException
 *
 * Exception thrown when documentation loading or rendering fails.
 * Used by MarkdownParser and DocumentConfig classes.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.12.0
 */
class DocumentationException extends ElanRegistryException
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
        return "Unable to load the requested documentation.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return 'SystemError';
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultHttpStatusCode(): int
    {
        return 500;
    }
}
