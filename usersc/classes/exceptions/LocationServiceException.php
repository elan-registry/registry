<?php

declare(strict_types=1);

/**
 * LocationServiceException
 *
 * Exception thrown when location service operations fail.
 * Used for OpenStreetMap/Photon API failures, rate limiting, and network issues.
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.12.0
 */
class LocationServiceException extends ElanRegistryException
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
        return "Location service unavailable. Please try again.";
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
