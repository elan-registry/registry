<?php
declare(strict_types=1);

/*
UserSpice 4
An Open Source PHP User Management System
by the UserSpice Team at http://UserSpice.com

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

//Put your custom functions in this file and they will be automatically included.

// Get encrypted environment variables
// Need to include now because the init calls usersc/vendor/autoload.php after custom_functions.php
if (file_exists($abs_us_root . $us_url_root . 'vendor/autoload.php')) {
    require_once $abs_us_root . $us_url_root . 'vendor/autoload.php';
}

use SecureEnvPHP\SecureEnvPHP;

(new SecureEnvPHP())->parse($abs_us_root . $us_url_root . '.env.enc', $abs_us_root . $us_url_root . '.env.key');

// Include classes in usersc

include_once $abs_us_root . $us_url_root . 'usersc/classes/Car.php';
include_once $abs_us_root . $us_url_root . 'usersc/classes/ElanRegistryOwner.php';
include_once $abs_us_root . $us_url_root . 'usersc/classes/Resize.php';
include_once $abs_us_root . $us_url_root . 'usersc/classes/CarView.php';

// Include Car exception autoloader
include_once $abs_us_root . $us_url_root . 'usersc/includes/car_exceptions_autoloader.php';

/**
 * Get user details with profile information (city, state, country, location, website)
 *
 * This is a common operation across the application - getting complete user information
 * including location data from the profiles table for car ownership transfers,
 * reassignments, and display purposes.
 *
 * @param int $user_id The user ID to fetch
 * @return object|null User object with profile data, or null if not found
 */
function getUserWithProfile($user_id) {
    $db = DB::getInstance();

    $userQ = $db->query(
        "SELECT u.*, p.city, p.state, p.country, p.lat, p.lon, p.website
         FROM users u
         LEFT JOIN profiles p ON u.id = p.user_id
         WHERE u.id = ?",
        [(int)$user_id]
    );

    if ($userQ->count() > 0) {
        $user = $userQ->first();

        // Ensure all expected fields exist with defaults
        $user->city = $user->city ?? '';
        $user->state = $user->state ?? '';
        $user->country = $user->country ?? '';
        $user->website = $user->website ?? '';
        $user->lat = $user->lat ?? null;
        $user->lon = $user->lon ?? null;

        return $user;
    }

    return null;
}

/**
 * Check if user has Registry admin or editor permissions
 *
 * @param int|null $userId User ID to check (defaults to current user)
 * @return bool True if user is Administrator (2) or Editor (3)
 */
function isRegistryAdmin($userId = null) {
    return hasPerm([2, 3], $userId);
}

/**
 * Get the base URL from database email settings with static caching
 *
 * Retrieves the base URL for the application from the email.verify_url database setting.
 * Uses static caching to avoid repeated database queries per request.
 * This ensures environment-aware URLs in emails and API calls.
 *
 * @return string Base URL (e.g., 'https://elanregistry.org' or 'http://localhost')
 */
function getBaseUrl() {
    static $baseUrl = null;

    if ($baseUrl === null) {
        $db = DB::getInstance();
        $result = $db->query("SELECT verify_url FROM email")->first();
        $baseUrl = $result->verify_url ?? 'https://elanregistry.org'; // Fallback to production
    }

    return rtrim($baseUrl, '/'); // Remove trailing slash for consistency
}

/**
 * Get admin email address(es) from settings
 *
 * Returns the configured admin email address(es) from the database settings,
 * with a fallback to the default registrar email if not configured.
 *
 * @return string Admin email address(es), comma-separated if multiple
 */
function getAdminEmails(): string {
    global $settings;
    return $settings->elan_admin_emails ?? 'registrar@elanregistry.org';
}

/**
 * Get feedback email address from settings
 *
 * Returns the configured feedback form email address from the database settings,
 * with a fallback to the default registrar email if not configured.
 *
 * @return string Feedback email address
 */
function getFeedbackEmail(): string {
    global $settings;
    return $settings->elan_feedback_email ?? 'registrar@elanregistry.org';
}

/**
 * Validate and normalize a website URL
 *
 * Centralized URL validation function used across the application for both user
 * profiles and car listings. Handles:
 * - URLs without schemes (auto-prepends https://)
 * - URLs with http:// or https:// schemes
 * - Empty/null values (allowed for optional website fields)
 * - Invalid URL format detection
 * - Character sanitization
 *
 * Examples:
 * - Input: "example.com" → Output: "https://example.com"
 * - Input: "www.example.com" → Output: "https://www.example.com"
 * - Input: "https://example.com" → Output: "https://example.com"
 * - Input: "http://example.com" → Output: "http://example.com"
 * - Input: "" → Output: null (empty field allowed)
 * - Input: "invalid!url" → Error message returned
 *
 * @param string $url The website URL to validate and normalize
 * @return array Returns associative array with keys:
 *               - 'valid' (bool): Whether the URL is valid
 *               - 'url' (string|null): Normalized URL if valid, null if empty or invalid
 *               - 'error' (string|null): Error message if invalid, null if valid
 */
function validateAndNormalizeUrl(string $url): array {
    // Trim whitespace
    $url = trim($url);
    
    // Allow empty values (optional field)
    if ($url === '') {
        return [
            'valid' => true,
            'url' => null,
            'error' => null,
        ];
    }
    
    // Sanitize: remove or encode problematic characters
    $url = preg_replace('/[<>"]/', '', $url);
    
    // Check if URL has a scheme (http:// or https://)
    // Use regex pattern to detect schemes at the start
    if (!preg_match('~^https?://~i', $url)) {
        // No scheme detected, auto-prepend https://
        $url = 'https://' . $url;
    }
    
    // Validate the URL using PHP's built-in filter
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return [
            'valid' => false,
            'url' => null,
            'error' => "Invalid website URL: '" . htmlspecialchars(trim($url, 'https://')) . "'. Please enter a valid website (e.g., example.com or https://example.com).",
        ];
    }
    
    // URL is valid and normalized
    return [
        'valid' => true,
        'url' => $url,
        'error' => null,
    ];
}

