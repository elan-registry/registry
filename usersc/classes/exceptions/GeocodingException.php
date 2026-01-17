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
class GeocodingException extends ElanRegistryException
{
    /**
     * Get default user message for geocoding failures
     *
     * @return string User-friendly error message
     */
    protected function getDefaultUserMessage(): string
    {
        return "Unable to process the location information. Please try again or contact support.";
    }

    /**
     * Get log category for geocoding exceptions
     *
     * @return string Log category constant
     */
    protected function getDefaultLogCategory(): string
    {
        return LogCategories::LOG_CATEGORY_SYSTEM_ERROR;
    }
}
