<?php

declare(strict_types=1);

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
class GeocodingException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code (optional)
     * @param Throwable|null $previous Previous exception for chaining (optional)
     */
    public function __construct(string $message = "Geocoding operation failed", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
