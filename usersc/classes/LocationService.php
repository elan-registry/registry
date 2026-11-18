<?php
declare(strict_types=1);

namespace ElanRegistry;

use ElanRegistry\Exceptions\LocationServiceException;
use ElanRegistry\RateLimit\RateLimiterAdapter;

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
 * - Rate limiting (10 requests/minute — keyed by user ID when logged in,
 *   by IP address for anonymous callers) to prevent abuse
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
     * @var string Contact URL sent in User-Agent headers — always the live registry site
     */
    private const USER_AGENT_CONTACT = 'https://elanregistry.org';

    /**
     * @var string Rate-limit action key shared by searchLocation() and reverseGeocode()
     *             (configured in usersc/includes/rate_limits.php)
     */
    private const RATE_LIMIT_ACTION = 'location_search';

    /**
     * @var string|null Cached version string (read once per process from the VERSION file)
     */
    private static ?string $cachedVersion = null;

    /**
     * @var RateLimiterInterface Rate limiter used by searchLocation() and reverseGeocode()
     */
    private readonly RateLimiterInterface $rateLimiter;

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
            self::$cachedVersion = self::resolveVersion(__DIR__ . '/../../VERSION');
        }

        return 'ElanRegistry/' . self::$cachedVersion . ' (' . self::USER_AGENT_CONTACT . ')';
    }

    /**
     * Resolve the version string from a VERSION file path, falling back to
     * 'unknown' when the file is absent, unreadable, or empty.
     *
     * Extracted as a path-parameterized helper (#1602) so unit tests can drive
     * the real fallback branches with real temporary files instead of seeding
     * $cachedVersion directly — mirrors the extraction technique used by
     * AssetVersionResolver::resolve() (#1598); fallback semantics differ
     * ('unknown' vs. 'dev', no allow-list regex).
     *
     * @param string $versionFile Absolute path to the VERSION file.
     * @return string The trimmed version string, or 'unknown' as fallback.
     */
    private static function resolveVersion(string $versionFile): string
    {
        $raw = is_readable($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
        return ($raw !== '') ? $raw : 'unknown';
    }

    /**
     * Constructor
     *
     * @param RateLimiterInterface|null $rateLimiter Rate limiter to use; defaults to
     *                                                the real `RateLimiterAdapter`. Passing
     *                                                a fake here lets unit tests avoid the
     *                                                real database-backed rate limiter.
     */
    public function __construct(?RateLimiterInterface $rateLimiter = null)
    {
        $this->rateLimiter = $rateLimiter ?? new RateLimiterAdapter();
    }

    /**
     * Check whether an action is allowed, failing open (allowing the request)
     * if the underlying rate limiter throws.
     *
     * The real `RateLimiterAdapter` delegates to `checkRateLimit()`, which
     * lazily constructs `\RateLimit` on first use — that constructor opens a
     * database connection and can throw. Failing open here matches the
     * existing fail-open behavior on cache errors elsewhere in this class:
     * a broken rate limiter should not take down location search entirely.
     *
     * @param string $action Rate-limit action key
     * @param int|null $userId User ID, or null for an anonymous caller
     * @return bool True if allowed (including when the rate limiter itself fails)
     */
    private function rateLimiterAllows(string $action, ?int $userId): bool
    {
        try {
            return $this->rateLimiter->allow($action, $userId);
        } catch (\Throwable $e) {
            logger($userId ?? 0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Rate limiter check failed, failing open: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Record a rate-limit attempt, swallowing any failure from the underlying
     * rate limiter. See `rateLimiterAllows()` for why this fails open.
     *
     * @param string $action Rate-limit action key
     * @param bool $success Whether the attempt succeeded
     * @param int|null $userId User ID, or null for an anonymous caller
     */
    private function recordRateLimiterAttempt(string $action, bool $success, ?int $userId): void
    {
        try {
            $this->rateLimiter->record($action, $success, $userId);
        } catch (\Throwable $e) {
            logger($userId ?? 0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Rate limiter record failed: ' . $e->getMessage());
        }
    }

    /**
     * Search for locations via autocomplete
     *
     * Uses Photon API (primary) with automatic fallback to Nominatim.
     * Results are cached for 5 minutes to reduce API calls.
     *
     * @param string $query Search term (e.g., "Portland", "London", "Tokyo")
     * @param int|null $userId User ID for rate limiting, or null for an anonymous caller
     * @param int $limit Maximum number of results (default: 8)
     * @return array Array of location results with coordinates. Empty when a
     *               provider answered and found no match.
     * @throws LocationServiceException If rate limit exceeded or all services fail
     */
    public function searchLocation(string $query, ?int $userId, int $limit = 8): array
    {
        // Validate input
        $query = trim($query);
        if (strlen($query) < 2) {
            throw new LocationServiceException('Search query must be at least 2 characters');
        }

        // Check cache first
        $cacheKey = 'location_search_' . md5($query . '_' . $limit);
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Check rate limit
        if (!$this->rateLimiterAllows(self::RATE_LIMIT_ACTION, $userId)) {
            $this->recordRateLimiterAttempt(self::RATE_LIMIT_ACTION, false, $userId);
            logger($userId ?? 0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Rate limit exceeded for location search');
            throw new LocationServiceException('Rate limit exceeded. Please try again in a moment.');
        }

        // A provider that answers with an empty list is not an outage. Only
        // when both providers threw does the visitor get the retry text
        // (/flow:finish-milestone v2.34.0: the picker shows a 400 message
        // word for word, so a typo must not read as "temporarily unavailable").
        $providerAnswered = false;

        // Try Photon API first
        try {
            $results = $this->searchPhoton($query, $limit);
            $providerAnswered = true;
            if (!empty($results)) {
                $this->setCache($cacheKey, $results);
                $this->recordRateLimiterAttempt(self::RATE_LIMIT_ACTION, true, $userId);
                return $results;
            }
        } catch (LocationServiceException $e) {
            logger($userId ?? 0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Photon API failed: ' . $e->getMessage());
        }

        // Fallback to Nominatim
        try {
            $results = $this->searchNominatim($query, $limit);
            $providerAnswered = true;
            if (!empty($results)) {
                $this->setCache($cacheKey, $results);
                $this->recordRateLimiterAttempt(self::RATE_LIMIT_ACTION, true, $userId);
                return $results;
            }
        } catch (LocationServiceException $e) {
            logger($userId ?? 0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Nominatim API failed: ' . $e->getMessage());
        }

        if ($providerAnswered) {
            $this->recordRateLimiterAttempt(self::RATE_LIMIT_ACTION, true, $userId);
            return [];
        }

        // All services failed — record as a failed attempt so a genuine
        // upstream outage still caps retry storms, rather than letting every
        // request during the outage go uncounted against the budget.
        $this->recordRateLimiterAttempt(self::RATE_LIMIT_ACTION, false, $userId);
        logger($userId ?? 0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'All location search services failed for query: ' . $query);
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
     * @param int|null $userId User ID for rate limiting, or null for an anonymous caller
     * @return array Address components (city, state, country, lat, lon)
     * @throws LocationServiceException If coordinates invalid or service fails
     */
    public function reverseGeocode(float $lat, float $lon, ?int $userId): array
    {
        // Validate coordinates
        if (!$this->validateCoordinates($lat, $lon)) {
            throw new LocationServiceException('Invalid coordinates: lat must be -90 to 90, lon must be -180 to 180');
        }

        // Check cache
        $cacheKey = 'location_reverse_' . md5($lat . '_' . $lon);
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Check rate limit
        if (!$this->rateLimiterAllows(self::RATE_LIMIT_ACTION, $userId)) {
            $this->recordRateLimiterAttempt(self::RATE_LIMIT_ACTION, false, $userId);
            logger($userId ?? 0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Rate limit exceeded for reverse geocoding');
            throw new LocationServiceException('Rate limit exceeded. Please try again in a moment.');
        }

        // Call Nominatim reverse geocoding
        try {
            $result = $this->reverseGeocodeNominatim($lat, $lon);
            if (!empty($result)) {
                $this->setCache($cacheKey, $result);
                $this->recordRateLimiterAttempt(self::RATE_LIMIT_ACTION, true, $userId);
                return $result;
            }
        } catch (LocationServiceException $e) {
            // Record as a failed attempt — see the matching comment in
            // searchLocation() for why a genuine upstream failure still
            // counts against the caller's budget.
            $this->recordRateLimiterAttempt(self::RATE_LIMIT_ACTION, false, $userId);
            logger($userId ?? 0, LogCategories::LOG_CATEGORY_LOCATION_SERVICE, 'Nominatim reverse geocoding failed: ' . $e->getMessage());
            throw new LocationServiceException('Reverse geocoding temporarily unavailable. Please try again later.');
        }

        $this->recordRateLimiterAttempt(self::RATE_LIMIT_ACTION, false, $userId);
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
                return false;
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

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
        // Try APCu first, but fall through to the file cache on a miss rather
        // than returning null immediately: function_exists('apcu_fetch') only
        // proves the extension is loaded, not that it's actually functional.
        // apc.enable_cli=Off (PHP's default for the CLI SAPI — the SAPI this
        // code runs under during tests, and potentially in some CLI/cron
        // execution contexts in production) makes every apcu_fetch() report
        // failure even though the extension is present, which previously
        // caused this method to silently skip its documented file-based
        // fallback entirely.
        if (function_exists('apcu_fetch')) {
            $success = false;
            $value = apcu_fetch($key, $success);
            if ($success) {
                return $value;
            }
        }

        // Fallback to file cache. Intentional: on a healthy, functional APCu
        // host this still costs one extra file_exists() stat call per genuine
        // cache miss (setCache() never dual-writes to both APCu and the file
        // when APCu succeeds, so this check will always miss in that case) —
        // there's no cheap way to distinguish "APCu miss" from "APCu broken"
        // from here. Do not "optimize" this back to an early return; that's
        // exactly the bug #1470 fixed.
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

        // Try APCu first, but fall through to the file cache if the store
        // didn't actually succeed — see the matching comment in getCache()
        // for why function_exists() alone isn't sufficient (apc.enable_cli=Off
        // makes apcu_store() a silent no-op that returns false).
        if (function_exists('apcu_store') && apcu_store($key, $value, $ttl)) {
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
