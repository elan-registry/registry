<?php

declare(strict_types=1);

namespace ElanRegistry\Exceptions;

use LogCategories;
use Throwable;

/**
 * GeocodingException
 *
 * Exception thrown when geocoding operations fail.
 * Used when Google Maps API calls fail, return invalid data,
 * or when network/API key issues occur.
 *
 * Also thrown when LocationGeocoder is instantiated directly
 * instead of through ElanRegistryOwner::geocodeAddress().
 *
 * @package ElanRegistry
 * @subpackage Exceptions
 * @since v2.11.0
 */
class GeocodingException extends ElanRegistryException
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
        return "Unable to geocode the location. Please verify your address.";
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_SYSTEM_ERROR;
    }

    /**
     * @inheritDoc
     */
    protected static function getDefaultHttpStatusCode(): int
    {
        return 500;
    }
}
