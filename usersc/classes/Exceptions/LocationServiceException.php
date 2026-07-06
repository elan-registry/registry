<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

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
}
