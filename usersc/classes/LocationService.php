<?php
declare(strict_types=1);

namespace ElanRegistry;

use ElanRegistry\Exceptions\LocationServiceException;

/**
 * LocationService - Modern location collection using OpenStreetMap services
 *
 * Provides unified interface for location autocomplete and reverse geocoding
 * using free Photon and Nominatim APIs (OpenStreetMap-based services).
 *
 * Replaces Google Geocoding API (Issue #245) with zero-cost solution.
 *
 * Features:
 * - Location autocomplete via Photon (primary) with Nominatim fallback
 * - Reverse geocoding (GPS coordinates → address) via Nominatim
 * - English language preference (accept-language=en) for consistent results
 * - Prevents multilingual names like "België / Belgique / Belgien"
 * - Server-side caching (5-minute TTL) to reduce API calls
 * - Rate limiting (10 requests/minute per user) to prevent abuse
 * - Automatic fallback strategy: Photon → Nominatim → cached results
 *
 * @package ElanRegistry
 * @since 2.11.0
 * @link https://github.com/unibrain1/elanregistry/issues/245
 */

/**
 * LocationService class for modern location collection
 */
class LocationService
{
    /**
     * @var string Photon API base URL
     */
    private const PHOTON_API_URL = 'https://photon.komoot.io/api';

    /**
     * @var string Nominatim API base URL
     */
    private const NOMINATIM_API_URL = 'https://nominatim.openstreetmap.org';

    /**
     * @var int Cache TTL in seconds (5 minutes)
     */
    private const CACHE_TTL = 300;

    /**
     * @var int Rate limit: max requests per minute per user
     */
    private const RATE_LIMIT_PER_MINUTE = 10;

    /**
     * @var string Contact URL sent in User-Agent headers — always the live registry site
     */
    private const USER_AGENT_CONTACT = 'https://elanregistry.org';

    /**
     * @var string|null Cached version string (read once per process from the VERSION file)
     */
    private static ?string $cachedVersion = null;

    /**
     * Build the User-Agent string for geocoding API requests.
     *
     * Reads the application version from the VERSION file (generated
     * automatically on each release via `git describe`) and caches it for
     * the lifetime of the PHP process. Falls back to 'unknown' if the file
     * cannot be read or is empty.
     *
     * @return string User-Agent header value, e.g. 'ElanRegistry/v2.25.3 (https://elanregistry.org)'
     */
    private static function getUserAgent(): string
    {
        if (self::$cachedVersion === null) {
            $versionFile = __DIR__ . '/../../VERSION';
            $raw = is_readable($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
            self::$cachedVersion = ($raw !== '') ? $raw : 'unknown';
        }

        return 'ElanRegistry/' . self::$cachedVersion . ' (' . self::USER_AGENT_CONTACT . ')';
    }

    /**
     * Constructor
     */
    public function __construct()
    {
    }

    /**
     * Search for locations via autocomplete
     *
     * Uses Photon API (primary) with automatic fallback to Nominatim.
     * Results are cached for 5 minutes to reduce API calls.
     *
     * @param string $query Search term (e.g., "Portland", "London", "Tokyo")
     * @param int $userId User ID for rate limiting
     * @param int $limit Maximum number of results (default: 8)
     * @return array Array of location results with coordinates
     * @throws LocationServiceException If rate limit exceeded or all services fail
     */
    public function searchLocation(string $query, int $userId, int $limit = 8): array
    {
        // Validate input
        $query = trim($query);
        if (strlen($query) < 2) {
            throw new LocationServiceException('Search query must be at least 2 characters');
        }

        // Check rate limit
        if (!$this->checkRateLimit($userId)) {
            logger($userId, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Rate limit exceeded for location search');
            throw new LocationServiceException('Rate limit exceeded. Please try again in a moment.');
        }

        // Check cache first
        $cacheKey = 'location_search_' . md5($query . '_' . $limit);
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Try Photon API first
        try {
            $results = $this->searchPhoton($query, $limit);
            if (!empty($results)) {
                $this->setCache($cacheKey, $results);
                $this->logRateLimitRequest($userId);
                return $results;
            }
        } catch (LocationServiceException $e) {
            logger($userId, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Photon API failed: ' . $e->getMessage());
        }

        // Fallback to Nominatim
        try {
            $results = $this->searchNominatim($query, $limit);
            if (!empty($results)) {
                $this->setCache($cacheKey, $results);
                $this->logRateLimitRequest($userId);
                return $results;
            }
        } catch (LocationServiceException $e) {
            logger($userId, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Nominatim API failed: ' . $e->getMessage());
        }

        // All services failed
        logger($userId, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'All location search services failed for query: ' . $query);
        throw new LocationServiceException('Location search temporarily unavailable. Please try again later.');
    }

    /**
     * Reverse geocode coordinates to address
     *
     * Converts GPS coordinates (lat/lon) to standardized address format
     * using Nominatim reverse geocoding API.
     *
     * @param float $lat Latitude (-90 to 90)
     * @param float $lon Longitude (-180 to 180)
     * @param int $userId User ID for rate limiting
     * @return array Address components (city, state, country, lat, lon)
     * @throws LocationServiceException If coordinates invalid or service fails
     */
    public function reverseGeocode(float $lat, float $lon, int $userId): array
    {
        // Validate coordinates
        if (!$this->validateCoordinates($lat, $lon)) {
            throw new LocationServiceException('Invalid coordinates: lat must be -90 to 90, lon must be -180 to 180');
        }

        // Check rate limit
        if (!$this->checkRateLimit($userId)) {
            logger($userId, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Rate limit exceeded for reverse geocoding');
            throw new LocationServiceException('Rate limit exceeded. Please try again in a moment.');
        }

        // Check cache
        $cacheKey = 'location_reverse_' . md5($lat . '_' . $lon);
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Call Nominatim reverse geocoding
        try {
            $result = $this->reverseGeocodeNominatim($lat, $lon);
            if (!empty($result)) {
                $this->setCache($cacheKey, $result);
                $this->logRateLimitRequest($userId);
                return $result;
            }
        } catch (LocationServiceException $e) {
            logger($userId, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Nominatim reverse geocoding failed: ' . $e->getMessage());
            throw new LocationServiceException('Reverse geocoding temporarily unavailable. Please try again later.');
        }

        throw new LocationServiceException('Could not determine address from coordinates');
    }

    /**
     * Validate latitude and longitude coordinates
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @return bool True if valid
     */
    public function validateCoordinates(float $lat, float $lon): bool
    {
        return ($lat >= -90 && $lat <= 90) && ($lon >= -180 && $lon <= 180);
    }

    /**
     * Search using Photon API
     *
     * @param string $query Search term
     * @param int $limit Maximum results
     * @return array Location results
     * @throws LocationServiceException If API request fails
     */
    private function searchPhoton(string $query, int $limit): array
    {
        $url = self::PHOTON_API_URL . '?q=' . urlencode($query) . '&limit=' . $limit . '&lang=en';

        $response = $this->makeHttpRequest($url, self::getUserAgent());
        if (!$response) {
            throw new LocationServiceException('Photon API request failed');
        }

        $data = json_decode($response, true);
        if (!isset($data['features']) || !is_array($data['features'])) {
            throw new LocationServiceException('Invalid Photon API response');
        }

        return $this->formatPhotonResults($data['features']);
    }

    /**
     * Search using Nominatim API
     *
     * @param string $query Search term
     * @param int $limit Maximum results
     * @return array Location results
     * @throws LocationServiceException If API request fails
     */
    private function searchNominatim(string $query, int $limit): array
    {
        $url = self::NOMINATIM_API_URL . '/search?' .
            'q=' . urlencode($query) .
            '&format=json' .
            '&addressdetails=1' .
            '&limit=' . $limit .
            '&accept-language=en';

        $response = $this->makeHttpRequest($url, self::getUserAgent());
        if (!$response) {
            throw new LocationServiceException('Nominatim API request failed');
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new LocationServiceException('Invalid Nominatim API response');
        }

        return $this->formatNominatimResults($data);
    }

    /**
     * Reverse geocode using Nominatim API
     *
     * @param float $lat Latitude
     * @param float $lon Longitude
     * @return array Address components
     * @throws LocationServiceException If API request fails
     */
    private function reverseGeocodeNominatim(float $lat, float $lon): array
    {
        $url = self::NOMINATIM_API_URL . '/reverse?' .
            'lat=' . $lat .
            '&lon=' . $lon .
            '&format=json' .
            '&addressdetails=1' .
            '&accept-language=en';

        $response = $this->makeHttpRequest($url, self::getUserAgent());
        if (!$response) {
            throw new LocationServiceException('Nominatim reverse geocoding request failed');
        }

        $data = json_decode($response, true);
        if (!isset($data['address'])) {
            throw new LocationServiceException('Invalid Nominatim reverse geocoding response');
        }

        $addr = $data['address'];

        // Extract city, state, country
        $city = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['suburb'] ?? '';
        $state = $addr['state'] ?? $addr['region'] ?? '';
        $country = $addr['country'] ?? '';

        // Build simple display name (City, State, Country only)
        $displayParts = array_filter([$city, $state, $country]);
        $display = implode(', ', $displayParts);

        return [
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'lat' => round((float)$lat, 4),
            'lon' => round((float)$lon, 4),
            'display' => $display
        ];
    }

    /**
     * Format Photon API results to standard format
     *
     * @param array $features Photon features array
     * @return array Formatted location results
     */
    private function formatPhotonResults(array $features): array
    {
        $results = [];

        foreach ($features as $feature) {
            if (!isset($feature['properties']) || !isset($feature['geometry'])) {
                continue;
            }

            $props = $feature['properties'];
            $coords = $feature['geometry']['coordinates'];

            $results[] = [
                'city' => $props['city'] ?? $props['name'] ?? '',
                'state' => $props['state'] ?? $props['county'] ?? '',
                'country' => $props['country'] ?? '',
                'lat' => round((float)$coords[1], 4),
                'lon' => round((float)$coords[0], 4),
                'display' => $this->buildDisplayName($props),
                'source' => 'photon'
            ];
        }

        return $results;
    }

    /**
     * Format Nominatim API results to standard format
     *
     * @param array $items Nominatim items array
     * @return array Formatted location results
     */
    private function formatNominatimResults(array $items): array
    {
        $results = [];

        foreach ($items as $item) {
            if (!isset($item['address'])) {
                continue;
            }

            $addr = $item['address'];

            $results[] = [
                'city' => $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['hamlet'] ?? '',
                'state' => $addr['state'] ?? $addr['region'] ?? '',
                'country' => $addr['country'] ?? '',
                'lat' => round((float)$item['lat'], 4),
                'lon' => round((float)$item['lon'], 4),
                'display' => $item['display_name'] ?? '',
                'source' => 'nominatim'
            ];
        }

        return $results;
    }

    /**
     * Build display name from Photon properties
     *
     * @param array $props Properties array
     * @return string Formatted display name
     */
    private function buildDisplayName(array $props): string
    {
        $parts = [];
        if (!empty($props['name'])) $parts[] = $props['name'];
        if (!empty($props['state'])) $parts[] = $props['state'];
        if (!empty($props['country'])) $parts[] = $props['country'];
        return implode(', ', $parts);
    }

    /**
     * Make HTTP request to external API
     *
     * @param string $url URL to request
     * @param string|null $userAgent Optional user agent
     * @return string|false Response body or false on failure
     */
    private function makeHttpRequest(string $url, ?string $userAgent = null): string|false
    {
        // Try cURL first
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            if ($userAgent) {
                curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
            }

            $response = curl_exec($ch);

            if ($response === false) {
                logger(0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE,
                    'LocationService: cURL error (' . curl_errno($ch) . '): ' . curl_error($ch));
                curl_close($ch);
                return false;
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                logger(0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE,
                    'LocationService: unexpected HTTP ' . $httpCode . ' from ' . $url);
                return false;
            }

            return $response;
        }

        // Fallback to file_get_contents
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $userAgent ? "User-Agent: $userAgent\r\n" : '',
                'timeout' => 10
            ]
        ]);

        $response = file_get_contents($url, false, $context);
        if ($response === false) {
            $lastError = error_get_last();
            logger(0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE,
                'LocationService: file_get_contents fallback failed: ' . ($lastError['message'] ?? 'unknown error'));
        }
        return $response;
    }

    /**
     * Check if user is within rate limit
     *
     * @param int $userId User ID
     * @return bool True if within limit
     */
    private function checkRateLimit(int $userId): bool
    {
        $cacheKey = 'rate_limit_' . $userId;
        $requests = $this->getCache($cacheKey) ?? [];

        // Remove requests older than 1 minute
        $oneMinuteAgo = time() - 60;
        $requests = array_filter($requests, function ($timestamp) use ($oneMinuteAgo) {
            return $timestamp > $oneMinuteAgo;
        });

        return count($requests) < self::RATE_LIMIT_PER_MINUTE;
    }

    /**
     * Log a rate limit request
     *
     * @param int $userId User ID
     */
    private function logRateLimitRequest(int $userId): void
    {
        $cacheKey = 'rate_limit_' . $userId;
        $requests = $this->getCache($cacheKey) ?? [];

        // Add current timestamp
        $requests[] = time();

        // Remove requests older than 1 minute
        $oneMinuteAgo = time() - 60;
        $requests = array_filter($requests, function ($timestamp) use ($oneMinuteAgo) {
            return $timestamp > $oneMinuteAgo;
        });

        $this->setCache($cacheKey, $requests, 60);
    }

    /**
     * Get value from cache
     *
     * Uses APCu if available, otherwise file-based cache.
     *
     * @param string $key Cache key
     * @return mixed|null Cached value or null if not found
     * @suppress PhanUndeclaredFunction APCu functions only called when available
     */
    private function getCache(string $key): mixed
    {
        // Try APCu first
        if (function_exists('apcu_fetch')) {
            $success = false;
            $value = apcu_fetch($key, $success);
            if ($success) {
                return $value;
            }
            return null;
        }

        // Fallback to file cache
        global $abs_us_root, $us_url_root;
        $cacheDir = $abs_us_root . $us_url_root . 'usersc/cache/';
        $cacheFile = $cacheDir . md5($key) . '.cache';

        if (!file_exists($cacheFile)) {
            return null;
        }

        $raw = file_get_contents($cacheFile);
        if ($raw === false) {
            logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, 'LocationService: failed to read cache file: ' . $cacheFile);
            return null;
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, 'LocationService: corrupt cache file (' . json_last_error_msg() . '): ' . $cacheFile);
            $realCacheFile = realpath($cacheFile);
            $realCacheDir = realpath($cacheDir);
            if ($realCacheFile !== false && $realCacheDir !== false && str_starts_with($realCacheFile, $realCacheDir . '/')) {
                if (!unlink($realCacheFile)) { // nosemgrep: php.lang.security.unlink-use.unlink-use -- path verified within cache directory
                    logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, 'LocationService: failed to delete corrupt cache file: ' . $realCacheFile);
                }
            }
            return null;
        }
        if (!$data || !isset($data['expires']) || $data['expires'] < time()) {
            $realCacheFile = realpath($cacheFile);
            $realCacheDir = realpath($cacheDir);
            if ($realCacheFile !== false && $realCacheDir !== false && str_starts_with($realCacheFile, $realCacheDir . '/')) {
                if (!unlink($realCacheFile)) { // nosemgrep: php.lang.security.unlink-use.unlink-use -- path verified within cache directory
                    logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, 'LocationService: failed to delete expired cache file: ' . $realCacheFile);
                }
            }
            return null;
        }

        return $data['value'] ?? null;
    }

    /**
     * Set value in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl TTL in seconds (default: CACHE_TTL)
     * @suppress PhanUndeclaredFunction APCu functions only called when available
     */
    private function setCache(string $key, mixed $value, ?int $ttl = null): void
    {
        $ttl = $ttl ?? self::CACHE_TTL;

        // Try APCu first
        if (function_exists('apcu_store')) {
            apcu_store($key, $value, $ttl);
            return;
        }

        // Fallback to file cache
        global $abs_us_root, $us_url_root;
        $cacheDir = $abs_us_root . $us_url_root . 'usersc/cache/';

        // Double is_dir() guards against a TOCTOU race where another process creates the directory
        // between the first check and mkdir(); a false return in that case is not a real failure.
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, 'LocationService: failed to create cache directory: ' . $cacheDir);
            return;
        }

        $cacheFile = $cacheDir . md5($key) . '.cache';
        $data = [
            'value' => $value,
            'expires' => time() + $ttl
        ];

        $encoded = json_encode($data);
        if ($encoded === false) {
            logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR,
                'LocationService: json_encode() failed for cache key: ' . $key);
            return;
        }
        if (file_put_contents($cacheFile, $encoded) === false) {
            logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, 'LocationService: failed to write cache file: ' . $cacheFile);
        }
    }
}
