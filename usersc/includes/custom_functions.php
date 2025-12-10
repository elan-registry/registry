<?php
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

