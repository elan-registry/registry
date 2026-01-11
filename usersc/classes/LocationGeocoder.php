<?php

declare(strict_types=1);

/**
 * LocationGeocoder
 *
 * @internal This class is an internal implementation detail of ElanRegistryOwner.
 *           DO NOT instantiate directly. Use ElanRegistryOwner::geocodeAddress() instead.
 *
 * Provides geocoding services using Google Maps Geocoding API.
 * Supports both forward geocoding (address → coordinates) and
 * reverse geocoding (coordinates → address).
 *
 * @author Jim Boone
 * @version $Revision: 1.0 $
 * @access public
 * @since v2.11.0
 */
class LocationGeocoder
{
    private string $_apiKey;
    private int $_timeout;
    private int $_decimalPlaces;

    /**
     * Constructor
     *
     * @internal This class should ONLY be instantiated by ElanRegistryOwner::geocodeAddress()
     *
     * @param string $apiKey Google Maps API key
     * @param int $timeout Request timeout in seconds (default: 10)
     * @param int $decimalPlaces Coordinate precision (default: 4, ~11m accuracy)
     * @throws GeocodingException If instantiated from outside ElanRegistryOwner or if API key is empty
     */
    public function __construct(string $apiKey, int $timeout = 10, int $decimalPlaces = 4)
    {
        // Runtime enforcement: Only allow instantiation from ElanRegistryOwner
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $backtrace[1]['class'] ?? '';

        if ($caller !== 'ElanRegistryOwner') {
            throw new GeocodingException(
                'LocationGeocoder is an internal implementation detail. ' .
                'Use ElanRegistryOwner::geocodeAddress() instead.'
            );
        }

        if (empty($apiKey)) {
            throw new GeocodingException('Google Maps API key is required');
        }

        $this->_apiKey = $apiKey;
        $this->_timeout = $timeout;
        $this->_decimalPlaces = $decimalPlaces;
    }

    /**
     * Geocode an address to coordinates (forward geocoding)
     *
     * @param string $city City name
     * @param string $state State/province name
     * @param string $country Country name
     * @return array|null Array with 'lat' and 'lon' keys, or null on failure
     */
    public function geocode(string $city, string $state, string $country): ?array
    {
        // Input validation
        if (empty(trim($city)) || empty(trim($country))) {
            logger(0, 'ValidationError', 'LocationGeocoder: City and country are required for geocoding');
            return null;
        }

        // Build address string
        $address = trim($city . ',' . $state . ',' . $country);
        $address = htmlspecialchars($address, ENT_QUOTES, 'UTF-8');
        $address = urlencode($address);

        logger(0, 'Geocode', "LocationGeocoder: Attempting to geocode: {$address}");

        // Make API request
        $result = $this->makeApiRequest('address', $address);

        if ($result === null) {
            return null;
        }

        // Extract coordinates
        $lat = $result['results'][0]['geometry']['location']['lat'] ?? null;
        $lon = $result['results'][0]['geometry']['location']['lng'] ?? null;

        if ($lat === null || $lon === null) {
            logger(0, 'Geocode', 'LocationGeocoder: API returned OK but coordinates are missing');
            return null;
        }

        // Round coordinates to specified precision
        $lat = round($lat, $this->_decimalPlaces);
        $lon = round($lon, $this->_decimalPlaces);

        logger(0, 'Geocode', "LocationGeocoder: Successfully geocoded to: {$lat}, {$lon}");

        return [
            'lat' => $lat,
            'lon' => $lon
        ];
    }

    /**
     * Reverse geocode coordinates to address (reverse geocoding)
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @return array|null Array with 'city', 'state', 'country' keys, or null on failure
     */
    public function reverseGeocode(float $lat, float $lon): ?array
    {
        // Input validation
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            logger(0, 'ValidationError', 'LocationGeocoder: Invalid coordinates for reverse geocoding');
            return null;
        }

        $latlng = "{$lat},{$lon}";
        logger(0, 'Geocode', "LocationGeocoder: Attempting reverse geocode: {$latlng}");

        // Make API request
        $result = $this->makeApiRequest('latlng', $latlng);

        if ($result === null) {
            return null;
        }

        // Extract address components
        $addressComponents = $result['results'][0]['address_components'] ?? [];

        if (empty($addressComponents)) {
            logger(0, 'Geocode', 'LocationGeocoder: API returned OK but address components are missing');
            return null;
        }

        // Parse address components
        $location = $this->parseAddressComponents($addressComponents);

        if ($location === null) {
            logger(0, 'Geocode', 'LocationGeocoder: Failed to parse address components');
            return null;
        }

        logger(0, 'Geocode', "LocationGeocoder: Successfully reverse geocoded to: {$location['city']}, {$location['state']}, {$location['country']}");

        return $location;
    }

    /**
     * Make Google Maps API request
     *
     * @param string $paramName Parameter name ('address' or 'latlng')
     * @param string $paramValue Parameter value
     * @return array|null API response array or null on failure
     */
    private function makeApiRequest(string $paramName, string $paramValue): ?array
    {
        $url = "https://maps.googleapis.com/maps/api/geocode/json?{$paramName}={$paramValue}&key={$this->_apiKey}";

        // Use cURL if available
        if (function_exists('curl_init')) {
            $resp_json = $this->makeCurlRequest($url);
        } else {
            $resp_json = $this->makeFileGetContentsRequest($url);
        }

        if ($resp_json === false) {
            return null;
        }

        // Decode JSON response
        $resp = json_decode($resp_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            logger(0, 'Geocode', 'LocationGeocoder: JSON decode error: ' . json_last_error_msg());
            return null;
        }

        // Check API response status
        if (!isset($resp['status']) || $resp['status'] !== 'OK') {
            $error_msg = 'LocationGeocoder: API request failed with status: ' . ($resp['status'] ?? 'UNKNOWN');
            if (isset($resp['error_message'])) {
                $error_msg .= ', error: ' . $resp['error_message'];
            }
            logger(0, 'Geocode', $error_msg);
            return null;
        }

        return $resp;
    }

    /**
     * Make cURL request
     *
     * @param string $url API URL
     * @return string|false Response JSON or false on failure
     */
    private function makeCurlRequest(string $url): string|false
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->_timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ElanRegistry/2.11');
        curl_setopt($ch, CURLOPT_REFERER, getBaseUrl() . '/');

        $resp_json = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($resp_json === false) {
            logger(0, 'Geocode', 'LocationGeocoder: cURL request failed: ' . $curl_error);
            return false;
        }

        if ($http_code !== 200) {
            logger(0, 'Geocode', 'LocationGeocoder: HTTP error: ' . $http_code);
            return false;
        }

        return $resp_json;
    }

    /**
     * Make file_get_contents request (fallback)
     *
     * @param string $url API URL
     * @return string|false Response JSON or false on failure
     */
    private function makeFileGetContentsRequest(string $url): string|false
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->_timeout,
                'user_agent' => 'ElanRegistry/2.11',
                'header' => "Referer: " . getBaseUrl() . "/\r\n"
            ]
        ]);

        $resp_json = @file_get_contents($url, false, $context);

        if ($resp_json === false) {
            logger(0, 'Geocode', 'LocationGeocoder: file_get_contents failed for geocoding request');
            return false;
        }

        return $resp_json;
    }

    /**
     * Parse Google Maps address components
     *
     * @param array $addressComponents Address components from API response
     * @return array|null Array with city, state, country or null on failure
     */
    private function parseAddressComponents(array $addressComponents): ?array
    {
        $city = '';
        $state = '';
        $country = '';

        foreach ($addressComponents as $component) {
            $types = $component['types'] ?? [];

            // Extract city (locality or postal_town)
            if (in_array('locality', $types) || in_array('postal_town', $types)) {
                $city = $component['long_name'] ?? '';
            }

            // Extract state (administrative_area_level_1)
            if (in_array('administrative_area_level_1', $types)) {
                $state = $component['long_name'] ?? '';
            }

            // Extract country
            if (in_array('country', $types)) {
                $country = $component['long_name'] ?? '';
            }
        }

        // Require at least country
        if (empty($country)) {
            return null;
        }

        return [
            'city' => $city,
            'state' => $state,
            'country' => $country
        ];
    }
}
