<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use Exception;
use Throwable;

/**
 * ElanRegistryException - Abstract base class for all ElanRegistry exceptions
 *
 * Provides consistent error handling with:
 * - User-friendly messages (safe to display to end users)
 * - Fixed log categories for centralized logging
 * - HTTP status codes for API responses
 *
 * All domain-specific exceptions MUST extend this class.
 *
 * @phpstan-consistent-constructor
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.12.0
 * @abstract
 */
abstract class ElanRegistryException extends Exception
{
    /**
     * User-friendly message safe to display in UI
     * @var string
     */
    protected string $userMessage;

    /**
     * Logger category for consistent audit trail
     * @var string
     */
    protected string $logCategory;

    /**
     * HTTP status code for API responses
     * @var int
     */
    protected int $httpStatusCode;

    /**
     * Constructor
     *
     * Preserves standard Exception constructor signature for backward compatibility.
     * Child classes define their own defaults via getDefault* methods.
     *
     * @param string $message Technical exception message (for logs/debugging)
     * @param int $code Exception code
     * @param Throwable|null $previous Previous exception for chaining
     * @param string|null $userMessage User-friendly message (uses default if null)
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null,
        ?string $userMessage = null
    ) {
        // If no message provided, use the default user message as technical message too
        if ($message === "") {
            $message = static::getDefaultUserMessage();
        }

        parent::__construct($message, $code, $previous);

        // Set user message (allow override, default to class-specific default)
        $this->userMessage = $userMessage ?? static::getDefaultUserMessage();

        // Fixed per exception type - not overridable at construction
        $this->logCategory = static::getDefaultLogCategory();
        $this->httpStatusCode = static::getDefaultHttpStatusCode();
    }

    /**
     * Get user-friendly message safe for display in UI
     *
     * @return string
     */
    public function getUserMessage(): string
    {
        return $this->userMessage;
    }

    /**
     * Get the logger category for this exception type
     *
     * @return string
     */
    public function getLogCategory(): string
    {
        return $this->logCategory;
    }

    /**
     * Get HTTP status code for API responses
     *
     * @return int
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * Get default user-friendly message for this exception type
     *
     * Child classes MUST override this method to provide sensible defaults.
     *
     * @return string
     */
    abstract protected static function getDefaultUserMessage(): string;

    /**
     * Get the default log category for this exception type
     *
     * Child classes MAY override this to provide domain-specific categories.
     * Default implementation returns 'SystemError'.
     *
     * @return string
     */
    protected static function getDefaultLogCategory(): string
    {
        return 'SystemError';
    }

    /**
     * Get the default HTTP status code for this exception type
     *
     * Child classes MAY override this for domain-specific status codes.
     * Default implementation returns 500 (Internal Server Error).
     *
     * @return int
     */
    protected static function getDefaultHttpStatusCode(): int
    {
        return 500;
    }

    /**
     * Create exception with custom user message while preserving technical details
     *
     * Factory method for cases where you need a different user message
     * than the default.
     *
     * @param string $technicalMessage Technical message for logs
     * @param string $userMessage Custom user-friendly message
     * @param int $code Exception code
     * @param Throwable|null $previous Previous exception
     * @return static
     */
    public static function withUserMessage(
        string $technicalMessage,
        string $userMessage,
        int $code = 0,
        ?Throwable $previous = null
    ): static {
        return new static($technicalMessage, $code, $previous, $userMessage);
    }
}
