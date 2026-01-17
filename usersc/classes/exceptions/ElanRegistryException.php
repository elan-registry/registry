<?php

declare(strict_types=1);

/**
 * ElanRegistryException Base Class
 *
 * Abstract base class for all custom application exceptions.
 * Provides standardized exception handling with integrated logging support.
 *
 * All domain-specific exceptions must extend this base class and override
 * getDefaultLogCategory() to specify their logging category.
 *
 * Features:
 * - Standardized logging with LogCategories constants
 * - User-friendly error messages for UI display
 * - HTTP status codes for API responses
 * - Exception chaining support
 *
 * Usage:
 *   try {
 *       throw new CarCreationException("Database insert failed");
 *   } catch (ElanRegistryException $e) {
 *       logger($userId, $e->getLogCategory(), $e->getMessage());
 *       usError($e->getUserMessage());
 *   }
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.12.0
 */
abstract class ElanRegistryException extends Exception
{
    /**
     * User-friendly message for displaying to end users
     * This message should not contain technical details or system information
     * @var string
     */
    protected string $userMessage;

    /**
     * Log category for error tracking and audit trails
     * Must be one of the LogCategories constants
     * @var string
     */
    protected string $logCategory;

    /**
     * HTTP status code for API responses
     * @var int
     */
    protected int $httpStatusCode = 500;

    /**
     * Constructor
     *
     * @param string $message Technical message for logging/debugging
     * @param int $code Exception code (optional)
     * @param Throwable|null $previous Previous exception for chaining (optional)
     * @param string|null $userMessage User-friendly message for display (optional)
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null,
        ?string $userMessage = null
    ) {
        parent::__construct($message, $code, $previous);

        // Set user message
        $this->userMessage = $userMessage ?? $this->getDefaultUserMessage();

        // Set log category from child class
        $this->logCategory = $this->getDefaultLogCategory();

        // Set HTTP status code from child class
        $this->httpStatusCode = $this->getDefaultHttpStatusCode();
    }

    /**
     * Factory method for creating exceptions with custom user messages
     *
     * @param string $technicalMessage Message for logging (technical details)
     * @param string $userMessage Message for displaying to users
     * @param int $code Exception code (optional)
     * @param Throwable|null $previous Previous exception for chaining (optional)
     * @return static New exception instance
     */
    public static function withUserMessage(
        string $technicalMessage,
        string $userMessage,
        int $code = 0,
        ?Throwable $previous = null
    ): static {
        return new static($technicalMessage, $code, $previous, $userMessage);
    }

    /**
     * Get user-friendly error message (safe for UI display)
     *
     * This message should never contain technical details, database errors,
     * file paths, or other sensitive system information. It should be
     * appropriate for displaying directly to end users.
     *
     * @return string User-friendly message
     */
    public function getUserMessage(): string
    {
        return $this->userMessage;
    }

    /**
     * Get log category for error tracking
     *
     * This category is used with the logger() function to categorize
     * errors in the audit trail.
     *
     * @return string LogCategories constant value
     */
    public function getLogCategory(): string
    {
        return $this->logCategory;
    }

    /**
     * Get HTTP status code for API responses
     *
     * @return int HTTP status code (default: 500)
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * Get default user message for this exception type
     *
     * Child classes must override this method to provide a default
     * user-friendly error message.
     *
     * @return string Default user message
     */
    abstract protected function getDefaultUserMessage(): string;

    /**
     * Get default log category for this exception type
     *
     * Child classes may override this method to specify a custom log category.
     * Must return one of the LogCategories constants.
     *
     * Default implementation returns SystemError. Override to customize.
     *
     * @return string LogCategories constant value (default: 'SystemError')
     */
    protected function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_SYSTEM_ERROR;
    }

    /**
     * Get default HTTP status code for this exception type
     *
     * Child classes may override this method to specify a custom HTTP status code.
     *
     * Default implementation returns 500 (Internal Server Error).
     * Override to use appropriate status for specific exception types:
     * - 400: Validation errors, bad input
     * - 401: Authentication failures
     * - 403: Authorization/permission failures
     * - 404: Resource not found
     * - 422: Unprocessable entity (validation)
     * - 500: Internal server error (default)
     *
     * @return int HTTP status code (default: 500)
     */
    protected function getDefaultHttpStatusCode(): int
    {
        return 500;
    }
}
