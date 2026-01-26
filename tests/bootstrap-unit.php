<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap File for Unit Tests
 *
 * Sets up the testing environment with MOCKS ONLY.
 * No UserSpice framework or database.
 * Use this for: tests/unit/* and tests/regression/*
 *
 * For integration tests, use: tests/bootstrap-integration.php
 */

// Set up testing environment - MOCKS ONLY
define('TESTING', true);
define('TESTING_UNIT_ONLY', true);
define('UNIT_TEST_SUITE', true);

// Prevent any integration test code from loading
if (defined('INTEGRATION_TEST_SUITE')) {
    die("ERROR: bootstrap-unit.php cannot be used with INTEGRATION_TEST_SUITE defined");
}

// Set up basic paths
$projectRoot = dirname(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = $projectRoot;
$_SERVER['PHP_SELF'] = '/tests/';

// Skip UserSpice initialization for now - use mocks instead
// The real framework requires database connection which isn't needed for unit tests

// Mock session for testing
if (!isset($_SESSION)) {
    $_SESSION = [];
}

// ============================================================================
// CRITICAL: Define mock Car class FIRST, before any autoloading
// This must happen before any code that might trigger the autoloader
// ============================================================================
if (!class_exists('Car')) {
    class Car {
        private $data;
        private static $nextId = 1000;

        /**
         * Constructor - matches real Car class signature
         * @param int|null $id Optional car ID to load
         */
        public function __construct(?int $id = null) {
            // Initialize default data
            if ($id === null) {
                // Generate new ID for unsaved car
                $id = self::$nextId++;
            }

            $this->data = (object) [
                'id' => $id,
                'user_id' => 1,
                'year' => '1973',
                'model' => 'Elan S4',
                'series' => 'S4',
                'variant' => 'SE',
                'type' => 'FHC',
                'chassis' => 'TEST123456',
                'color' => 'Red',
                'engine' => 'ABC123',
                'image' => null,
                'ctime' => date('Y-m-d H:i:s'),
                'mtime' => date('Y-m-d H:i:s')
            ];
        }

        public static function find(int $id): ?self {
            return new self($id);
        }

        /**
         * Get car data object
         * @return object|null Car data or null if not found
         */
        public function data(): ?object {
            return $this->data;
        }

        /**
         * Create car with data
         * @param array $data Car data
         * @return bool Success status
         */
        public function create(array $data): bool {
            foreach ($data as $key => $value) {
                $this->data->$key = $value;
            }
            return true;
        }

        /**
         * Update car with data
         * @param array $data Car data
         * @return bool Success status
         */
        public function update(array $data): bool {
            foreach ($data as $key => $value) {
                if ($key !== 'id') {  // Never update ID
                    $this->data->$key = $value;
                }
            }
            return true;
        }
    }
}

// For unit tests, we need to prevent loading real classes that require database
// We'll load the autoloader but prevent real class instantiation
// by defining mock classes before the autoloader tries to include them

// First, define mock classes BEFORE loading autoloader
// so autoloader won't try to load the real ones

// Mock classes for testing if they don't exist
if (!class_exists('Token')) {
    class Token {
        public static function generate() {
            return 'test_csrf_token_' . uniqid();
        }
        
        public static function check($token) {
            if ($token === null || $token === '') {
                return false;
            }
            return strpos($token, 'test_csrf_token_') === 0;
        }
    }
}

// Ensure required functions are available for file upload tests
if (!function_exists('generateSecureFilename')) {
    /**
     * Generate a cryptographically secure filename
     */
    function generateSecureFilename(string $extension): string {
        $randomBytes = random_bytes(16);
        return 'img_' . bin2hex($randomBytes) . '.' . $extension;
    }
}

if (!function_exists('getMimeType')) {
    /**
     * Get and validate MIME type of uploaded file
     */
    function getMimeType(string $filepath): string {
        if (!file_exists($filepath)) {
            throw new Exception('File does not exist');
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filepath);
        finfo_close($finfo);
        
        $allowedMimes = [
            'image/jpeg',
            'image/png', 
            'image/gif',
            'image/webp'
        ];
        
        if (!in_array($mimeType, $allowedMimes)) {
            throw new Exception('Invalid file type detected: ' . $mimeType);
        }
        
        return $mimeType;
    }
}

if (!function_exists('getExtension')) {
    /**
     * Get file extension based on MIME type
     */
    function getExtension(string $mimeType): string {
        $extensionMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        
        if (!isset($extensionMap[$mimeType])) {
            throw new Exception('Unsupported file type: ' . $mimeType);
        }
        
        return $extensionMap[$mimeType];
    }
}

if (!function_exists('validateFileUpload')) {
    /**
     * Validate file upload security
     */
    function validateFileUpload(array $file): bool {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error: ' . $file['error']);
        }
        
        // Check file size limits
        $maxSize = 5 * 1024 * 1024; // 5MB
        $minSize = 100; // 100 bytes
        
        if ($file['size'] > $maxSize) {
            throw new Exception('File too large. Maximum size is ' . ($maxSize / 1024 / 1024) . 'MB');
        }
        
        if ($file['size'] < $minSize) {
            throw new Exception('File too small. Minimum size is ' . $minSize . ' bytes');
        }
        
        // Validate that uploaded file exists
        if (!is_uploaded_file($file['tmp_name']) && !file_exists($file['tmp_name'])) {
            throw new Exception('Invalid file upload');
        }
        
        return true;
    }
}

/**
 * Mock DB class for testing to avoid database dependencies
 */
if (!class_exists('DB')) {
    class DB {
            private static $instance = null;
            private $recordsCreated = [];
            private $nextId = 1000;

            /**
             * Get singleton instance
             *
             * @return self
             */
            public static function getInstance(): self {
                if (self::$instance === null) {
                    self::$instance = new self();
                }
                return self::$instance;
            }

            /**
             * Execute a database query
             *
             * @param string $sql SQL query
             * @param array<mixed> $params Query parameters
             * @return object Query result
             */
            public function query(string $sql, array $params = []): object {
                global $mockUsers, $mockProfiles, $mockCarUser, $mockCars;

                // Handle noowner user lookup
                if (strpos($sql, 'SELECT id FROM users WHERE username = ?') !== false &&
                    isset($params[0]) && $params[0] === 'noowner') {
                    $noOwnerUsers = array_filter($mockUsers ?: [], function($user) {
                        return $user->username === 'noowner';
                    });
                    return new MockQueryResult(array_values($noOwnerUsers));
                }

                // Handle car_user queries
                if (strpos($sql, 'SELECT carid FROM car_user WHERE userid = ?') !== false) {
                    $userId = $params[0] ?? null;
                    $userCars = array_filter($mockCarUser ?: [], function($carUser) use ($userId) {
                        return $carUser->userid == $userId;
                    });
                    return new MockQueryResult(array_values($userCars));
                }

                // Handle profile queries
                if (strpos($sql, 'SELECT') !== false && strpos($sql, 'profiles') !== false) {
                    return new MockQueryResult($mockProfiles ?: []);
                }

                // Default response - return empty array for unrecognized queries
                return new MockQueryResult([]);
            }

            /**
             * Get a record by column/value
             *
             * @param string $table Table name
             * @param array $where Where conditions [column, operator, value]
             * @return object Query result
             */
            public function get(string $table, array $where): object {
                // Check if this record was created in this test
                $id = $where[2] ?? 1;
                if (isset($this->recordsCreated[$id])) {
                    return new MockQueryResult([$this->recordsCreated[$id]]);
                }

                // Return mock query result with a car object
                return new MockQueryResult([(object) [
                    'id' => $id,
                    'user_id' => 1,
                    'year' => '1973',
                    'series' => 'S4',
                    'variant' => 'SE',
                    'type' => 'FHC',
                    'chassis' => 'TEST123456',
                    'color' => 'Red',
                    'engine' => 'ABC123',
                    'image' => null
                ]]);
            }

            /**
             * Insert a record
             *
             * @param string $table Table name
             * @param array<string,mixed> $fields Field values
             * @return bool Success status (mock always returns true)
             */
            public function insert(string $table, array $fields): bool {
                // Track created record for later retrieval
                $id = $this->nextId++;
                $record = (object) array_merge($fields, ['id' => $id]);
                $this->recordsCreated[$id] = $record;
                return true;
            }

            /**
             * Update a record
             *
             * @param string $table Table name
             * @param int $id Record ID
             * @param array<string,mixed> $fields Field values
             * @return bool Success status
             */
            public function update(string $table, int $id, array $fields): bool {
                return true;
            }

            /**
             * Delete records
             *
             * @param string $table Table name
             * @param array<string,mixed> $where Where conditions
             * @return bool Success status
             */
            public function delete(string $table, array $where): bool {
                return true;
            }

            /**
             * Find record by ID
             *
             * @param int $id Record ID
             * @param string $table Table name
             * @return object|null Query result
             */
            public function findById(int $id, string $table): ?object {
                return new MockQueryResult();
            }

            /**
             * Get last insert ID
             *
             * @return int Last inserted ID
             */
            public function lastId(): int {
                return $this->nextId - 1;
            }

            /**
             * Check for database errors
             *
             * @return bool Whether there was an error
             */
            public function error(): bool {
                return false;
            }

            /**
             * Get error string
             *
             * @return string Error message
             */
            public function errorString(): string {
                return '';
            }
        }

        /**
         * Mock query result class
         */
        class MockQueryResult {
            /** @var array<object>|null */
            private $mockData;

            /**
             * @param array<object>|null $data Mock data
             */
            public function __construct($data = null) {
                $this->mockData = $data;
            }

            /**
             * Get all results
             *
             * @return array<object>
             */
            public function results(): array {
                if ($this->mockData !== null) {
                    return $this->mockData;
                }

                // Use global mock data if available
                global $mockUsers, $mockProfiles, $mockCarUser, $mockCars;

                // Default to user data if no specific mock is set
                return [(object) [
                    'id' => 1,
                    'fname' => 'Test',
                    'lname' => 'User',
                    'email' => 'test@example.com'
                ]];
            }

            /**
             * Get first result
             *
             * @return object|null
             */
            public function first(): ?object {
                $results = $this->results();
                return count($results) > 0 ? $results[0] : null;
            }

            /**
             * Get result count
             *
             * @return int
             */
            public function count(): int {
                return count($this->results());
            }
        }
    }

// Load unified autoloader for all custom classes and exceptions
// This must come AFTER mock classes are defined so the mocks take precedence
require_once $projectRoot . '/usersc/classes/class.autoloader.php';

/**
 * Mock user object and authentication system
 */
if (!isset($user) || !is_object($user)) {
    class MockUser {
        /** @var object */
        private $userData;

        /**
         * Constructor
         */
        public function __construct() {
            $this->userData = (object) [
                'id' => 1,
                'username' => 'testuser',
                'email' => 'test@example.com',
                'fname' => 'Test',
                'lname' => 'User'
            ];
        }

        /**
         * Get user data
         *
         * @return object
         */
        public function data(): object {
            return $this->userData;
        }

        /**
         * Check if user is logged in
         *
         * @return bool
         */
        public function isLoggedIn(): bool {
            return true;
        }
    }
    
    $user = new MockUser();
    $GLOBALS['user'] = $user;
}

// Mock securePage function - only for unit tests
if (!function_exists('securePage')) {
    function securePage($page) {
        return true; // Always allow access in tests
    }
}

// Mock Input class if not available
if (!class_exists('Input')) {
    class Input {
        private static $mockData = [];
        
        public static function get($key, $default = null) {
            if (!empty(self::$mockData)) {
                return self::$mockData[$key] ?? $default;
            }
            return $_POST[$key] ?? $_GET[$key] ?? $default;
        }
        
        public static function exists($method = 'post') {
            if (!empty(self::$mockData)) {
                return !empty(self::$mockData);
            }
            return $method === 'post' ? !empty($_POST) : !empty($_GET);
        }
        
        public static function setMockData($data) {
            self::$mockData = $data;
        }
        
        public static function clearMockData() {
            self::$mockData = [];
        }
    }
}

// Mock getUserWithProfile function for unit tests only
if (!function_exists('getUserWithProfile')) {
    /**
     * Mock getUserWithProfile function for testing
     */
    function getUserWithProfile($user_id) {
        global $mockUsers, $mockProfiles;

        // Find user by ID
        $user = null;
        if (is_array($mockUsers)) {
            foreach ($mockUsers as $mockUser) {
                if ($mockUser->id == $user_id) {
                    $user = $mockUser;
                    break;
                }
            }
        }

        if (!$user) {
            // Return null for invalid user IDs (don't create synthetic users)
            return null;
        }

        // Add mock profile data
        $user->city = 'Test City';
        $user->state = 'Test State';
        $user->country = 'Test Country';
        $user->website = '';
        $user->lat = null;
        $user->lon = null;

        return $user;
    }
}

// Mock functions for user deletion testing - only for unit tests
if (!function_exists('deleteUsers')) {
    /**
     * Mock deleteUsers function for testing
     */
    function deleteUsers($users) {
        global $mockDeletedUsers, $db;
        $mockDeletedUsers = $users;

        // Simulate calling after_user_deletion.php for each user
        foreach ($users as $id) {
            // Simulate the cleanup script logic
            mockUserDeletionCleanup($id);
        }

        return count($users);
    }
}

// Mock logger function - only for unit tests
if (!function_exists('logger')) {
    /**
     * Mock logger function for audit tracking
     */
    function logger($userId, $category, $message) {
        global $mockLogEntries;
        if (!isset($mockLogEntries)) {
            $mockLogEntries = [];
        }
        $mockLogEntries[] = [
            'user_id' => $userId,
            'category' => $category,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        return true;
    }
}

// Mock getSettings function - only for unit tests
if (!function_exists('getSettings')) {
    /**
     * Mock getSettings function for testing
     */
    function getSettings($id = 1) {
        // Return mock settings object
        return (object) [
            'id' => $id,
            'elan_image_dir' => '/userimages/',
            'elan_google_geo_key' => 'mock_api_key',
            'elan_datatables_js_cdn' => 'https://cdn.datatables.net/1.10.23/js/jquery.dataTables.min.js',
            'elan_datatables_css_cdn' => 'https://cdn.datatables.net/1.10.23/css/jquery.dataTables.min.css'
        ];
    }
}

/**
 * Mock user deletion cleanup process
 */
function mockUserDeletionCleanup($id) {
    global $mockLogEntries;
    $db = DB::getInstance();
    
    // Find the "no owner" user dynamically
    $noOwnerQuery = $db->query('SELECT id FROM users WHERE username = ?', ['noowner']);
    if ($noOwnerQuery->count() > 0) {
        $noOwnerUserId = $noOwnerQuery->first()->id;
        
        // Get list of cars owned by deleted user before cleanup
        $userCarsQuery = $db->query('SELECT carid FROM car_user WHERE userid = ?', [$id]);
        $userCars = $userCarsQuery->results();
        $carCount = count($userCars);
        
        // Clean up user profile record
        $db->query('DELETE FROM profiles WHERE user_id = ?', [$id]);
        
        // Clean up old car ownership records  
        $db->query('DELETE FROM car_user WHERE userid = ?', [$id]);
        
        // Reassign cars to noowner in car_user table
        foreach ($userCars as $car) {
            $db->query('INSERT INTO car_user (userid, carid) VALUES (?, ?)', 
                       [$noOwnerUserId, $car->carid]);
        }
        
        // Update primary car ownership
        $db->query('UPDATE cars SET user_id = ? WHERE user_id = ?', [$noOwnerUserId, $id]);
        
        // Log the cleanup for audit purposes
        logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, "Complete cleanup: reassigned $carCount cars to noowner user (ID: $noOwnerUserId)");
    } else {
        // Fallback if noowner doesn't exist
        $db->query('DELETE FROM profiles WHERE user_id = ?', [$id]);
        $db->query('DELETE FROM car_user WHERE userid = ?', [$id]);
        $db->query('UPDATE cars SET user_id = NULL WHERE user_id = ?', [$id]);
        
        logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, 'Fallback cleanup: noowner user not found, set cars to NULL');
    }
}
