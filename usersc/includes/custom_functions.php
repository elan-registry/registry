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

// Note: Custom class autoloader is loaded in users/init.php (after UserSpice autoloader)
// Note: phpdotenv is autoloaded via helpers.php (usersc/vendor/autoload.php)
// and parsed in users/init.php where environment variables are actually needed

// Override UserSpice email_body() variable whitelist to include custom template variables.
// UserSpice v6.05 restricts which $options keys are extracted into template scope.
// Without this, custom email templates receive null for non-whitelisted variables.
$email_field_whitelist = [
    // UserSpice defaults
    'fname', 'lname', 'email', 'vericode', 'user_id',
    'reset_vericode_expiry', 'join_vericode_expiry',
    'verification_code', 'passwordless_expiry', 'url',
    // Feedback form
    'name', 'accountId', 'comments',
    // Owner contact
    'from', 'to', 'message', 'content',
    // Car transfer templates
    'requester', 'currentOwner', 'previousOwner',
    'requesterDetails', 'currentOwnerDetails', 'newOwnerDetails',
    'requestDetails', 'decisionDetails', 'carDetails', 'carInfo', 'carContext',
    'transferRequest', 'nextSteps', 'adminNotes', 'qualityIssue',
    'approveUrl', 'denyUrl', 'reviewUrl', 'carUrl',
    'isApproved', 'statusMessage', 'statusTitle', 'statusText',
    'statusContent', 'statusStyle',
    // Admin contact
    'fromEmail', 'emailTemplate',
];

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
function getUserWithProfile(int $user_id): ?object {
    $db = DB::getInstance();

    $userQ = $db->query(
        "SELECT u.*, p.city, p.state, p.country, p.lat, p.lon, p.website
         FROM users u
         LEFT JOIN profiles p ON u.id = p.user_id
         WHERE u.id = ?",
        [$user_id]
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
 * @param int|string|null $userId User ID to check (defaults to current user)
 * @return bool True if user is Administrator (2) or Editor (3)
 */
function isRegistryAdmin(int|string|null $userId = null): bool {
    return hasPerm([2, 3], $userId);
}

/**
 * Get the base URL for the application using UserSpice server globals.
 *
 * Derives the URL from $current_origin (scheme + host) and $us_url_root
 * (the install path set by UserSpice from the actual filesystem location).
 * This is environment-aware without relying on a manually configured database
 * setting that can diverge from the real install path.
 *
 * Falls back to the email.verify_url database setting when server globals are
 * not populated (e.g., CLI scripts).
 *
 * @return string Base URL without trailing slash (e.g., 'https://elanregistry.org' or 'http://localhost:9999/elan-registry')
 */
function getBaseUrl(): string {
    global $scheme, $host, $us_url_root;

    if (!empty($scheme) && !empty($host) && !empty($us_url_root)) {
        // $host has the port stripped (Server::get uses stripPort=true).
        // Re-add non-standard ports so email URLs are correct on local dev.
        $port = Server::get('SERVER_PORT', 0);
        $defaultPort = ($scheme === 'https') ? 443 : 80;
        $portStr = ($port && $port !== $defaultPort) ? ':' . $port : '';
        return rtrim($scheme . '://' . $host . $portStr . $us_url_root, '/');
    }

    // Fallback for CLI or early-boot contexts where server globals are not set
    static $baseUrl = null;
    if ($baseUrl === null) {
        $db = DB::getInstance();
        $result = $db->query("SELECT verify_url FROM email")->first();
        $baseUrl = $result->verify_url ?? null;
        if ($baseUrl === null) {
            $defaultUrl = 'https://elanregistry.org';
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_SETTINGS,
                "getBaseUrl() falling back to hardcoded production URL — verify_url not configured in email settings; emails from this environment will link to $defaultUrl");
            $baseUrl = $defaultUrl;
        }
    }

    return rtrim($baseUrl, '/');
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
 * Extract an integer value from a database result object or scalar
 *
 * Handles PDO's inconsistent string/int returns for INTEGER columns
 * across different PHP versions and configurations.
 *
 * @param mixed $value Database result object or scalar value
 * @param string $property Property name to extract from objects (default: 'id')
 * @return int The integer value
 * @throws InvalidArgumentException If the value cannot be converted to int
 */
function dbInt(mixed $value, string $property = 'id'): int
{
    if (is_object($value)) {
        if (!isset($value->$property)) {
            throw new InvalidArgumentException("Property '$property' does not exist on object");
        }
        $value = $value->$property;
    }

    if ($value === null || $value === '') {
        throw new InvalidArgumentException("Cannot convert empty value to int (property: $property)");
    }

    if (!is_numeric($value)) {
        throw new InvalidArgumentException("Cannot convert non-numeric value to int (property: $property): $value");
    }

    return (int) $value;
}

/**
 * Extract a nullable integer value from a database result object or scalar
 *
 * Same as dbInt() but returns null for null/empty values instead of throwing.
 *
 * @param mixed $value Database result object or scalar value
 * @param string $property Property name to extract from objects (default: 'id')
 * @return int|null The integer value, or null if empty/null
 * @throws InvalidArgumentException If the value is non-null and non-numeric
 */
function dbIntOrNull(mixed $value, string $property = 'id'): ?int
{
    if (is_object($value)) {
        if (!isset($value->$property)) {
            return null;
        }
        $value = $value->$property;
    }

    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        throw new InvalidArgumentException("Cannot convert non-numeric value to int (property: $property): $value");
    }

    return (int) $value;
}

/**
 * Get the current logged-in user's ID as an integer
 *
 * Provides a type-safe shorthand for (int) $user->data()->id with
 * a login check to avoid errors when no user is authenticated.
 *
 * @return int The current user's ID
 * @throws RuntimeException If no user is logged in
 */
function currentUserId(): int
{
    global $user;

    if (!isset($user) || !$user->isLoggedIn()) {
        throw new RuntimeException('No user is currently logged in');
    }

    return (int) $user->data()->id;
}


// We need server globals in custom functions as it's used early in the load process.
require_once $abs_us_root . $us_url_root . 'usersc/includes/server_globals.php';
