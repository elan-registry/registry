<?php
declare(strict_types=1);

/**
 * ElanRegistryOwner is a class for managing Owner data
 *
 * ElanRegistryOwner is a class that isolates read/write operations for user/owner
 * information in the ElanRegistry context, providing a clean separation between
 * UserSpice user management and ElanRegistry owner business logic.
 *
 * @author Jim Boone
 * @version $Revision: 1.0 $
 * @access public
 */

class ElanRegistryOwner
{
    private $_db;
    private $_data;
    private $_profileData;
    private $userTableName = 'users';
    private $profileTableName = 'profiles';

    /**
     * Instantiates the ElanRegistryOwner object.
     *
     * @param int|null $id Optional User ID. If given, the owner information will be populated.
     * @return void
     */
    public function __construct(?int $id = null)
    {
        $this->_db = DB::getInstance();

        if ($id) {
            $this->find($id);
        }
    }

    /**
     * Find and load owner data by user ID
     *
     * @param int $userId The user ID to load
     * @return bool True if owner found and loaded
     */
    public function find(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        // Use existing custom function for combined user+profile data
        $ownerData = getUserWithProfile($userId);

        if ($ownerData) {
            $this->_data = $ownerData;
            return true;
        }

        return false;
    }

    /**
     * Get current owner data
     *
     * @return object|null Owner data object or null if not loaded
     */
    public function data(): ?object
    {
        return $this->_data;
    }

    /**
     * Static method to get owner profile using existing custom function
     *
     * @param int $userId The user ID to fetch
     * @return object|null Owner object with profile data, or null if not found
     */
    public static function getOwnerProfile(int $userId): ?object
    {
        return getUserWithProfile($userId);
    }

    /**
     * Create a new owner (user + profile)
     *
     * @param array $fields Key value pairs for owner data
     * @return bool True if owner is created
     * @throws Exception If validation fails or database operation fails
     */
    public function create(array $fields = []): bool
    {
        if (empty($fields)) {
            throw new OwnerCreationException('No data provided for owner creation');
        }

        // CSRF Protection
        if (!isset($fields['token']) || !Token::check($fields['token'])) {
            throw new OwnerCreationException('Invalid CSRF token provided');
        }

        // Remove token from fields array after validation
        unset($fields['token']);

        // Validate required fields for both user and profile
        $this->validateRequiredFields($fields, ['fname', 'lname', 'email']);

        // Validate and sanitize all fields
        $fields = $this->validateAndSanitizeFields($fields);

        // Start transaction for user + profile creation
        $this->_db->query("START TRANSACTION");

        try {
            // Split fields between user and profile tables
            $userFields = $this->extractUserFields($fields);
            $profileFields = $this->extractProfileFields($fields);

            // Create user record
            $userFields['join_date'] = date('Y-m-d G:i:s');
            $userFields['vericode'] = randomstring(15);

            if (!$this->_db->insert($this->userTableName, $userFields)) {
                throw new OwnerCreationException('Database error during user creation: ' . $this->_db->errorString());
            }

            $userId = $this->_db->lastId();

            // Create profile record
            $profileFields['user_id'] = $userId;
            $profileFields['ctime'] = date('Y-m-d G:i:s');

            // Apply geocoding if location data provided
            if (!empty($profileFields['city']) && !empty($profileFields['state']) && !empty($profileFields['country'])) {
                $geoFields = $this->applyGeocoding($profileFields['city'], $profileFields['state'], $profileFields['country']);
                $profileFields = array_merge($profileFields, $geoFields);
            }

            if (!$this->_db->insert($this->profileTableName, $profileFields)) {
                throw new OwnerCreationException('Database error during profile creation: ' . $this->_db->errorString());
            }

            $this->_db->query("COMMIT");

            // Load the created owner data
            $this->find($userId);

            // Log successful creation
            logger($userId, 'OwnerActions', "Owner created: {$userFields['fname']} {$userFields['lname']} ({$userFields['email']})");

            return true;

        } catch (Exception $e) {
            $this->_db->query("ROLLBACK");
            logger(0, 'DatabaseError', 'Owner creation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update existing owner information
     *
     * @param array $fields Owner data to update
     * @return bool True if update succeeds
     * @throws Exception If validation fails or database operation fails
     */
    public function update(array $fields = []): bool
    {
        if (empty($fields) || !isset($fields['id'])) {
            logger($fields['id'] ?? 0, 'ValidationError', 'Owner update failed: No data or ID provided');
            throw new OwnerValidationException('No data or ID provided for owner update');
        }

        // CSRF Protection
        if (!isset($fields['token']) || !Token::check($fields['token'])) {
            logger($fields['id'] ?? 0, 'ValidationError', 'Owner update failed: Invalid CSRF token');
            throw new OwnerValidationException('Invalid CSRF token provided');
        }

        // Remove token from fields array after validation
        unset($fields['token']);

        if (!is_numeric($fields['id']) || $fields['id'] <= 0) {
            throw new OwnerValidationException('Invalid owner ID provided for update');
        }

        $userId = (int)$fields['id'];

        // Validate and sanitize fields
        $fieldsToValidate = $fields;
        unset($fieldsToValidate['id']);
        if (!empty($fieldsToValidate)) {
            $validatedFields = $this->validateAndSanitizeFields($fieldsToValidate, false);
        } else {
            throw new OwnerValidationException('No fields provided for update');
        }

        // Start transaction for user + profile updates
        $this->_db->query("START TRANSACTION");

        try {
            // Split fields between user and profile tables
            $userFields = $this->extractUserFields($validatedFields);
            $profileFields = $this->extractProfileFields($validatedFields);

            // Update user fields if any
            if (!empty($userFields)) {
                $userFields['mtime'] = date('Y-m-d G:i:s');
                if (!$this->_db->update($this->userTableName, $userId, $userFields)) {
                    throw new OwnerUpdateException('Database error during user update: ' . $this->_db->errorString());
                }
            }

            // Update profile fields if any
            if (!empty($profileFields)) {
                $profileFields['mtime'] = date('Y-m-d G:i:s');

                // Apply geocoding if location data changed
                if (!empty($profileFields['city']) || !empty($profileFields['state']) || !empty($profileFields['country'])) {
                    // Get current location data for missing fields
                    $currentOwner = $this->data();
                    $city = $profileFields['city'] ?? $currentOwner->city ?? '';
                    $state = $profileFields['state'] ?? $currentOwner->state ?? '';
                    $country = $profileFields['country'] ?? $currentOwner->country ?? '';

                    if (!empty($city) && !empty($state) && !empty($country)) {
                        $geoFields = $this->applyGeocoding($city, $state, $country);
                        $profileFields = array_merge($profileFields, $geoFields);
                    }
                }

                if (!$this->_db->update($this->profileTableName, $userId, $profileFields, 'user_id')) {
                    throw new OwnerUpdateException('Database error during profile update: ' . $this->_db->errorString());
                }
            }

            $this->_db->query("COMMIT");

            // Reload owner data
            $this->find($userId);

            // Log successful update
            $fieldsUpdated = array_merge(array_keys($userFields), array_keys($profileFields));
            logger($userId, 'OwnerActions', "Owner updated - fields: " . implode(', ', $fieldsUpdated));

            return true;

        } catch (Exception $e) {
            $this->_db->query("ROLLBACK");
            logger($userId, 'DatabaseError', 'Owner update failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get all cars owned by this owner
     *
     * @return array Array of car objects owned by this owner
     */
    public function getCarsOwned(): array
    {
        if (!$this->_data) {
            return [];
        }

        $carsQuery = $this->_db->query(
            "SELECT * FROM cars WHERE user_id = ? ORDER BY model, year",
            [$this->_data->id]
        );

        return $carsQuery->count() > 0 ? $carsQuery->results() : [];
    }

    /**
     * Get ownership history for this owner
     *
     * @return array Array of ownership history records
     */
    public function getOwnershipHistory(): array
    {
        if (!$this->_data) {
            return [];
        }

        $historyQuery = $this->_db->query(
            "SELECT ch.*, c.chassis, c.model, c.year
             FROM cars_hist ch
             LEFT JOIN cars c ON ch.car_id = c.id
             WHERE ch.user_id = ?
             ORDER BY ch.ctime DESC",
            [$this->_data->id]
        );

        return $historyQuery->count() > 0 ? $historyQuery->results() : [];
    }

    /**
     * Get profile completeness score
     *
     * @return float Profile completeness percentage (0-100)
     */
    public function getProfileQualityScore(): float
    {
        if (!$this->_data) {
            return 0.0;
        }

        $totalFields = 7;
        $completedFields = 0;

        if (!empty($this->_data->fname)) $completedFields++;
        if (!empty($this->_data->lname)) $completedFields++;
        if (!empty($this->_data->email)) $completedFields++;
        if (!empty($this->_data->city)) $completedFields++;
        if (!empty($this->_data->state)) $completedFields++;
        if (!empty($this->_data->country)) $completedFields++;
        if (!empty($this->_data->lat) && !empty($this->_data->lon)) $completedFields++;

        return round(($completedFields / $totalFields) * 100, 1);
    }

    /**
     * Validate profile completeness and return missing fields
     *
     * @return array Array of missing or incomplete field names
     */
    public function validateProfileCompleteness(): array
    {
        $missingFields = [];

        if (!$this->_data) {
            return ['Owner data not loaded'];
        }

        if (empty($this->_data->fname)) $missingFields[] = 'First Name';
        if (empty($this->_data->lname)) $missingFields[] = 'Last Name';
        if (empty($this->_data->email)) $missingFields[] = 'Email';
        if (empty($this->_data->city)) $missingFields[] = 'City';
        if (empty($this->_data->state)) $missingFields[] = 'State';
        if (empty($this->_data->country)) $missingFields[] = 'Country';
        if (empty($this->_data->lat) || empty($this->_data->lon)) $missingFields[] = 'Location Coordinates';

        return $missingFields;
    }

    /**
     * Search owners by various criteria
     *
     * @param string $searchTerm Search term to match against name, email, or location
     * @param int $limit Maximum number of results (default 50)
     * @return array Array of owner search results
     */
    public function searchOwners(string $searchTerm, int $limit = 50): array
    {
        $searchTerm = '%' . trim($searchTerm) . '%';

        $searchQuery = $this->_db->query(
            "SELECT u.id, u.fname, u.lname, u.email, p.city, p.state, p.country, p.lat, p.lon
             FROM users u
             LEFT JOIN profiles p ON u.id = p.user_id
             WHERE u.fname LIKE ? OR u.lname LIKE ? OR u.email LIKE ?
                OR p.city LIKE ? OR p.state LIKE ? OR p.country LIKE ?
             ORDER BY u.lname, u.fname
             LIMIT ?",
            [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]
        );

        return $searchQuery->count() > 0 ? $searchQuery->results() : [];
    }

    /**
     * Update location with geocoding integration
     *
     * @param array $locationData Array with city, state, country
     * @return bool True if location updated successfully
     */
    public function updateLocation(array $locationData): bool
    {
        if (!$this->_data) {
            throw new OwnerValidationException('Owner data not loaded');
        }

        $requiredFields = ['city', 'state', 'country'];
        foreach ($requiredFields as $field) {
            if (empty($locationData[$field])) {
                throw new OwnerValidationException("Required location field '{$field}' is missing");
            }
        }

        // Apply geocoding
        $geoFields = $this->applyGeocoding($locationData['city'], $locationData['state'], $locationData['country']);
        $updateFields = array_merge($locationData, $geoFields);
        $updateFields['mtime'] = date('Y-m-d G:i:s');

        if ($this->_db->update($this->profileTableName, $this->_data->id, $updateFields, 'user_id')) {
            // Reload owner data
            $this->find($this->_data->id);

            logger($this->_data->id, 'OwnerActions', "Location updated: {$locationData['city']}, {$locationData['state']}, {$locationData['country']}");
            return true;
        }

        return false;
    }

    /**
     * Sync owner location to all owned cars
     *
     * @return int Number of cars updated
     */
    public function syncLocationToCars(): int
    {
        if (!$this->_data) {
            return 0;
        }

        $carsUpdated = 0;
        $ownedCars = $this->getCarsOwned();

        if (empty($ownedCars)) {
            return 0;
        }

        $locationFields = [
            'city' => $this->_data->city,
            'state' => $this->_data->state,
            'country' => $this->_data->country,
            'lat' => $this->_data->lat,
            'lon' => $this->_data->lon,
            'mtime' => date('Y-m-d G:i:s')
        ];

        foreach ($ownedCars as $car) {
            if ($this->_db->update('cars', $car->id, $locationFields)) {
                $carsUpdated++;

                // Add history record for location sync
                $historyFields = $locationFields;
                $historyFields['car_id'] = $car->id;
                $historyFields['operation'] = 'LOCATION_SYNC';
                $historyFields['comments'] = "Car location synchronized with owner profile update. City: {$this->_data->city}, State: {$this->_data->state}, Country: {$this->_data->country}";
                $historyFields['ctime'] = $locationFields['mtime'];
                $this->_db->insert('cars_hist', $historyFields);
            }
        }

        if ($carsUpdated > 0) {
            logger($this->_data->id, 'OwnerActions', "Location synchronized to {$carsUpdated} car(s)");
        }

        return $carsUpdated;
    }

    /**
     * Validate required fields
     *
     * @param array $fields Fields to check
     * @param array $requiredFields List of required field names
     * @return void
     * @throws OwnerValidationException If required fields are missing
     */
    private function validateRequiredFields(array $fields, array $requiredFields): void
    {
        foreach ($requiredFields as $field) {
            if (!isset($fields[$field]) || empty(trim($fields[$field]))) {
                throw new OwnerValidationException("Required field '{$field}' is missing or empty");
            }
        }
    }

    /**
     * Validate and sanitize owner fields
     *
     * @param array $fields Fields to validate and sanitize
     * @param bool $requireAll Whether all validations are required (create) or optional (update)
     * @return array Validated and sanitized fields
     * @throws OwnerValidationException If validation fails
     */
    private function validateAndSanitizeFields(array $fields, bool $requireAll = true): array
    {
        $validatedFields = [];

        foreach ($fields as $key => $value) {
            switch ($key) {
                case 'fname':
                case 'lname':
                    if (!empty($value)) {
                        $validatedFields[$key] = $this->sanitizeString($value, 25);
                        if (strlen($validatedFields[$key]) < 1) {
                            throw new OwnerValidationException("{$key} must be at least 1 character long");
                        }
                    } elseif ($requireAll) {
                        throw new OwnerValidationException("{$key} is required");
                    }
                    break;

                case 'email':
                    if (!empty($value)) {
                        $email = filter_var(trim($value), FILTER_VALIDATE_EMAIL);
                        if ($email === false) {
                            throw new OwnerValidationException('Invalid email format');
                        }
                        $validatedFields[$key] = $email;
                    } elseif ($requireAll) {
                        throw new OwnerValidationException('Email is required');
                    }
                    break;

                case 'city':
                case 'state':
                case 'country':
                    if (!empty($value)) {
                        $validatedFields[$key] = $this->sanitizeString($value, 50);
                    }
                    break;

                case 'website':
                    if (!empty($value)) {
                        // Sanitize URL by removing illegal characters
                        $sanitized = preg_replace('/[^a-zA-Z0-9\-._~:/?#[\]@!$&\'()*+,;=%]/', '', trim($value));
                        if (filter_var($sanitized, FILTER_VALIDATE_URL)) {
                            $validatedFields[$key] = $sanitized;
                        } else {
                            throw new OwnerValidationException('Invalid website URL format');
                        }
                    }
                    break;

                case 'password':
                    if (!empty($value)) {
                        // Basic password validation - UserSpice handles detailed requirements
                        if (strlen($value) < 6) {
                            throw new OwnerValidationException('Password must be at least 6 characters long');
                        }
                        $validatedFields[$key] = password_hash($value, PASSWORD_BCRYPT, ['cost' => 12]);
                    }
                    break;

                default:
                    // Pass through other fields without validation (for flexibility)
                    if (!empty($value)) {
                        $validatedFields[$key] = $value;
                    }
                    break;
            }
        }

        return $validatedFields;
    }

    /**
     * Sanitize string input
     *
     * @param string $input Input string to sanitize
     * @param int $maxLength Maximum allowed length
     * @return string Sanitized string
     */
    private function sanitizeString(string $input, int $maxLength = 255): string
    {
        $sanitized = filter_var(trim($input), FILTER_SANITIZE_STRING);
        return substr($sanitized, 0, $maxLength);
    }

    /**
     * Extract user table fields from input array
     *
     * @param array $fields Input fields array
     * @return array Fields that belong to users table
     */
    private function extractUserFields(array $fields): array
    {
        $userFieldNames = ['fname', 'lname', 'email', 'username', 'password', 'active', 'permissions'];
        return array_intersect_key($fields, array_flip($userFieldNames));
    }

    /**
     * Extract profile table fields from input array
     *
     * @param array $fields Input fields array
     * @return array Fields that belong to profiles table
     */
    private function extractProfileFields(array $fields): array
    {
        $profileFieldNames = ['city', 'state', 'country', 'lat', 'lon', 'website'];
        return array_intersect_key($fields, array_flip($profileFieldNames));
    }

    /**
     * Apply geocoding to location data
     *
     * @param string $city City name
     * @param string $state State name
     * @param string $country Country name
     * @return array Array with lat/lon fields if geocoding successful
     */
    private function applyGeocoding(string $city, string $state, string $country): array
    {
        global $abs_us_root, $us_url_root;

        // Set variables required by geocoding script
        $GLOBALS['city'] = $city;
        $GLOBALS['state'] = $state;
        $GLOBALS['country'] = $country;

        // Include geocoding system (sets $fields array)
        $fields = [];
        include($abs_us_root . $us_url_root . 'app/views/_geolocate.php');

        // Return geocoding results or empty array if failed
        return !empty($fields) ? $fields : [];
    }
}