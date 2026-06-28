<?php
declare(strict_types=1);

use ElanRegistry\Exceptions\OwnerCreationException;
use ElanRegistry\Exceptions\OwnerUpdateException;
use ElanRegistry\Exceptions\OwnerValidationException;

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
    private $userTableName = 'users';
    private $profileTableName = 'profiles';

    /**
     * Instantiates the ElanRegistryOwner object.
     *
     * @param int|null $id Optional User ID. If given, the owner information will be populated.
     * @param object|null $db Optional DB instance for testing. If not provided, uses DB::getInstance().
     * @return void
     */
    public function __construct(?int $id = null, ?object $db = null)
    {
        $this->_db = $db ?? DB::getInstance();

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
     * @throws OwnerCreationException If validation fails or database operation fails
     */
    public function create(array $fields = []): bool
    {
        if (empty($fields)) {
            throw OwnerCreationException::withUserMessage(
                'No data provided for owner creation',
                'No data provided for owner creation.'
            );
        }

        // CSRF Protection
        if (!isset($fields['csrf']) || !Token::check($fields['csrf'])) {
            throw OwnerCreationException::withUserMessage(
                'Invalid CSRF token provided',
                'Your session may have expired. Please refresh the page and try again.'
            );
        }

        // Remove token from fields array after validation
        unset($fields['csrf']);

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
            $userFields['join_date'] = date(AppConstants::DATETIME_FORMAT);
            $userFields['vericode'] = randomstring(15);

            if (!$this->_db->insert($this->userTableName, $userFields)) {
                throw OwnerCreationException::withUserMessage(
                    'Database error during user creation: ' . $this->_db->errorString(),
                    'Failed to create owner account. Please try again.'
                );
            }

            $userId = $this->_db->lastId();

            // Create profile record
            $profileFields['user_id'] = $userId;
            $profileFields['ctime'] = date(AppConstants::DATETIME_FORMAT);

            if (!$this->_db->insert($this->profileTableName, $profileFields)) {
                throw OwnerCreationException::withUserMessage(
                    'Database error during profile creation: ' . $this->_db->errorString(),
                    'Failed to create owner profile. Please try again.'
                );
            }

            $this->_db->query("COMMIT");

            // Load the created owner data
            $this->find($userId);

            // Log successful creation
            logger($userId, LogCategories::LOG_CATEGORY_OWNER_ACTIONS, "Owner created: {$userFields['fname']} {$userFields['lname']} ({$userFields['email']})");

            return true;

        } catch (Exception $e) {
            $this->_db->query("ROLLBACK");
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Owner creation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update existing owner information
     *
     * @param array $fields Owner data to update
     * @return bool True if update succeeds
     * @throws OwnerValidationException If validation fails
     * @throws OwnerUpdateException If database operation fails
     */
    public function update(array $fields = []): bool
    {
        if (empty($fields) || !isset($fields['id'])) {
            logger($fields['id'] ?? 0, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Owner update failed: No data or ID provided');
            throw OwnerValidationException::withUserMessage(
                'No data or ID provided for owner update',
                'Unable to process update. Please try again.'
            );
        }

        // CSRF Protection
        if (!isset($fields['csrf']) || !Token::check($fields['csrf'])) {
            logger($fields['id'] ?? 0, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Owner update failed: Invalid CSRF token');
            throw OwnerValidationException::withUserMessage(
                'Invalid CSRF token provided',
                'Your session may have expired. Please refresh the page and try again.'
            );
        }

        // Remove token from fields array after validation
        unset($fields['csrf']);

        if (!is_numeric($fields['id']) || $fields['id'] <= 0) {
            throw OwnerValidationException::withUserMessage(
                'Invalid owner ID provided for update',
                'Unable to identify the owner record. Please try again.'
            );
        }

        $userId = (int)$fields['id'];

        // Validate and sanitize fields
        $fieldsToValidate = $fields;
        unset($fieldsToValidate['id']);
        if (!empty($fieldsToValidate)) {
            $validatedFields = $this->validateAndSanitizeFields($fieldsToValidate, false);
        } else {
            throw OwnerValidationException::withUserMessage(
                'No fields provided for update',
                'No changes were submitted. Please enter values to update.'
            );
        }

        // Start transaction for user + profile updates
        $this->_db->query("START TRANSACTION");

        try {
            // Split fields between user and profile tables
            $userFields = $this->extractUserFields($validatedFields);
            $profileFields = $this->extractProfileFields($validatedFields);

            // Update user fields if any
            if (!empty($userFields)) {
                // Note: users table doesn't have mtime field (UserSpice standard)
                if (!$this->_db->update($this->userTableName, $userId, $userFields)) {
                    throw OwnerUpdateException::withUserMessage(
                        'Database error during user update: ' . $this->_db->errorString(),
                        'Failed to update owner account. Please try again.'
                    );
                }
            }

            // Update profile fields if any
            if (!empty($profileFields)) {
                // Note: profiles table doesn't have mtime field (UserSpice standard)

                // UserSpice DB::update() uses array for custom WHERE: ['column' => 'value']
                $updateResult = $this->_db->update($this->profileTableName, ['user_id' => $userId], $profileFields);

                if (!$updateResult) {
                    throw OwnerUpdateException::withUserMessage(
                        'Database error during profile update: ' . $this->_db->errorString(),
                        'Failed to update owner profile. Please try again.'
                    );
                }
            }

            $this->_db->query("COMMIT");

            // Reload owner data
            $this->find($userId);

            // Log successful update
            $fieldsUpdated = array_merge(array_keys($userFields), array_keys($profileFields));
            logger($userId, LogCategories::LOG_CATEGORY_OWNER_ACTIONS, "Owner updated - fields: " . implode(', ', $fieldsUpdated));

            return true;

        } catch (Exception $e) {
            $this->_db->query("ROLLBACK");
            logger($userId, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Owner update failed: ' . $e->getMessage());
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
            "SELECT c.* FROM cars c
             INNER JOIN car_user cu ON c.id = cu.car_id
             WHERE cu.userid = ?
             ORDER BY c.model, c.year",
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
        $searchTerm = trim($searchTerm);
        if (empty($searchTerm)) {
            return [];
        }

        // Handle multi-word searches (e.g., "Greg Surcouf", "Portland Oregon")
        $searchWords = array_filter(explode(' ', strtolower($searchTerm)));

        if (count($searchWords) === 1) {
            // Single word search - use original OR logic
            $searchPattern = '%' . $searchWords[0] . '%';
            $sql = "SELECT u.id, u.fname, u.lname, u.email, p.city, p.state, p.country, p.lat, p.lon
                    FROM users u
                    LEFT JOIN profiles p ON u.id = p.user_id
                    WHERE LOWER(u.fname) LIKE ? OR LOWER(u.lname) LIKE ? OR LOWER(u.email) LIKE ?
                       OR LOWER(p.city) LIKE ? OR LOWER(p.state) LIKE ? OR LOWER(p.country) LIKE ?
                    ORDER BY u.lname, u.fname
                    LIMIT " . (int)$limit;

            $params = [$searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern];

        } else {
            // Multi-word search - use UNION to prioritize exact matches over partial matches
            $searchWords = array_filter(array_map(function($word) {
                return trim($word, ', ');
            }, $searchWords));

            if (count($searchWords) < 2) {
                // Fallback to single word search
                $searchPattern = '%' . $searchWords[0] . '%';
                $sql = "SELECT u.id, u.fname, u.lname, u.email, p.city, p.state, p.country, p.lat, p.lon
                        FROM users u
                        LEFT JOIN profiles p ON u.id = p.user_id
                        WHERE LOWER(u.fname) LIKE ? OR LOWER(u.lname) LIKE ? OR LOWER(u.email) LIKE ?
                           OR LOWER(p.city) LIKE ? OR LOWER(p.state) LIKE ? OR LOWER(p.country) LIKE ?
                        ORDER BY u.lname, u.fname
                        LIMIT " . (int)$limit;
                $params = [$searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern];
            } else {
                // Use UNION to prioritize exact matches
                $word1 = strtolower($searchWords[0]);
                $word2 = strtolower($searchWords[1]);

                $sql = "
                (SELECT u.id, u.fname, u.lname, u.email, p.city, p.state, p.country, p.lat, p.lon, 1 as priority
                 FROM users u LEFT JOIN profiles p ON u.id = p.user_id
                 WHERE (LOWER(u.fname) = ? AND LOWER(u.lname) = ?) OR (LOWER(u.fname) = ? AND LOWER(u.lname) = ?))
                UNION
                (SELECT u.id, u.fname, u.lname, u.email, p.city, p.state, p.country, p.lat, p.lon, 2 as priority
                 FROM users u LEFT JOIN profiles p ON u.id = p.user_id
                 WHERE ((LOWER(u.fname) = ? OR LOWER(u.lname) = ?) AND (LOWER(p.city) = ? OR LOWER(p.state) = ?))
                    OR ((LOWER(u.fname) = ? OR LOWER(u.lname) = ?) AND (LOWER(p.city) = ? OR LOWER(p.state) = ?)))
                UNION
                (SELECT u.id, u.fname, u.lname, u.email, p.city, p.state, p.country, p.lat, p.lon, 3 as priority
                 FROM users u LEFT JOIN profiles p ON u.id = p.user_id
                 WHERE (LOWER(p.city) = ? AND LOWER(p.state) = ?) OR (LOWER(p.city) = ? AND LOWER(p.state) = ?))
                ORDER BY priority, lname, fname
                LIMIT " . (int)$limit;

                $params = [
                    // First UNION: exact name matches
                    $word1, $word2,  // fname=word1 AND lname=word2
                    $word2, $word1,  // fname=word2 AND lname=word1
                    // Second UNION: name + location matches
                    $word1, $word1, $word2, $word2,  // (fname=word1 OR lname=word1) AND (city=word2 OR state=word2)
                    $word2, $word2, $word1, $word1,  // (fname=word2 OR lname=word2) AND (city=word1 OR state=word1)
                    // Third UNION: location pairs
                    $word1, $word2,  // city=word1 AND state=word2
                    $word2, $word1   // city=word2 AND state=word1
                ];
            }
        }

        $searchQuery = $this->_db->query($sql, $params);
        return $searchQuery->count() > 0 ? $searchQuery->results() : [];
    }

    /**
     * Update owner location fields
     *
     * @param array $locationData Array with city, state, country (and optionally lat, lon)
     * @return bool True if location updated successfully
     */
    public function updateLocation(array $locationData): bool
    {
        if (!$this->_data) {
            throw OwnerValidationException::withUserMessage(
                'Owner data not loaded',
                'Unable to retrieve owner data. Please try again.'
            );
        }

        $requiredFields = ['city', 'state', 'country'];
        foreach ($requiredFields as $field) {
            if (empty($locationData[$field])) {
                throw OwnerValidationException::withUserMessage(
                    "Required location field '{$field}' is missing",
                    "Required location field '{$field}' is missing."
                );
            }
        }

        $updateFields = $locationData;
        // Note: profiles table doesn't have mtime field (UserSpice standard)

        try {
            $this->_db->query("BEGIN");

            if (!$this->_db->update($this->profileTableName, $this->_data->id, $updateFields, 'user_id')) {
                $this->_db->query("ROLLBACK");
                logger($this->_data->id, LogCategories::LOG_CATEGORY_OWNER_ACTIONS, "updateLocation() DB update failed: " . $this->_db->errorString());
                return false;
            }

            $this->_db->query("COMMIT");
        } catch (Exception $e) {
            $this->_db->query("ROLLBACK");
            logger($this->_data->id, LogCategories::LOG_CATEGORY_OWNER_ACTIONS, "Location update failed: " . $e->getMessage());
            throw $e;
        }

        // Reload owner data
        $this->find($this->_data->id);

        logger($this->_data->id, LogCategories::LOG_CATEGORY_OWNER_ACTIONS, "Location updated: {$locationData['city']}, {$locationData['state']}, {$locationData['country']}");
        return true;
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
            'mtime' => date(AppConstants::DATETIME_FORMAT)
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
            logger($this->_data->id, LogCategories::LOG_CATEGORY_OWNER_ACTIONS, "Location synchronized to {$carsUpdated} car(s)");
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
                throw OwnerValidationException::withUserMessage(
                    "Required field '{$field}' is missing or empty",
                    "Required field '{$field}' is missing or empty."
                );
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
                            throw OwnerValidationException::withUserMessage(
                                "{$key} must be at least 1 character long",
                                'Name field must be at least 1 character long.'
                            );
                        }
                    } elseif ($requireAll) {
                        throw OwnerValidationException::withUserMessage(
                            "{$key} is required",
                            'A required name field is missing.'
                        );
                    }
                    break;

                case 'email':
                    if (!empty($value)) {
                        $email = filter_var(trim($value), FILTER_VALIDATE_EMAIL);
                        if ($email === false) {
                            throw OwnerValidationException::withUserMessage(
                                'Invalid email format',
                                'Invalid email format.'
                            );
                        }
                        $validatedFields[$key] = $email;
                    } elseif ($requireAll) {
                        throw OwnerValidationException::withUserMessage('Email is required', 'Email is required.');
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
                        $trimmed = trim($value);
                        if (!filter_var($trimmed, FILTER_VALIDATE_URL)) {
                            throw OwnerValidationException::withUserMessage(
                                'Website URL must start with http:// or https:// (e.g. https://example.com)',
                                'Website URL must start with http:// or https:// (e.g. https://example.com)'
                            );
                        }
                        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));
                        if (!in_array($scheme, ['http', 'https'], true)) {
                            throw OwnerValidationException::withUserMessage(
                                'Website URL must use http:// or https:// — other protocols are not allowed',
                                'Website URL must use http:// or https:// — other protocols are not allowed'
                            );
                        }
                        $validatedFields[$key] = $trimmed;
                    }
                    break;

                case 'password':
                    if (!empty($value)) {
                        // Basic password validation - UserSpice handles detailed requirements
                        if (strlen($value) < 6) {
                            throw OwnerValidationException::withUserMessage(
                                'Password must be at least 6 characters long',
                                'Password must be at least 6 characters long.'
                            );
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
        // Replace deprecated FILTER_SANITIZE_STRING with strip_tags and trim
        $sanitized = strip_tags(trim($input));
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

}
