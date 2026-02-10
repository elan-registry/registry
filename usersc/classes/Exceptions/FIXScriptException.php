<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use LogCategories;
use Throwable;

/**
 * FIXScriptException
 *
 * Exception thrown when FIX script operations fail.
 * Used when database maintenance scripts encounter errors during schema creation,
 * data population, or validation operations.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.12 (Issue #577)
 */
class FIXScriptException extends ElanRegistryException
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
        return "A database maintenance script failed.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_FIX_SCRIPT;
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultHttpStatusCode(): int
    {
        return 500;
    }
}
