<?php

declare(strict_types=1);

use ElanRegistry\Exceptions\CarDeletionException;
use ElanRegistry\Exceptions\CarMergeException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\LogCategories;

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

// Backup retention constants (normally from usersc/includes/config.php)
if (!defined('BACKUP_RETENTION_AUTOMATED')) {
    define('BACKUP_RETENTION_AUTOMATED', 7);
    define('BACKUP_RETENTION_MANUAL', 30);
    define('BACKUP_RETENTION_ROLLBACK', 30);
    define('BACKUP_WARNING_THRESHOLD_DAYS', 7);
}

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
// Test-only mock — real class is ElanRegistry\Car\Car at usersc/classes/Car/Car.php.
if (!class_exists('Car')) {
    class Car {
        private $data;
        private $history;
        private static $nextId = 1000;
        private static $cars = [];

        /**
         * Constructor - matches real Car class signature
         * @param int|null $id Optional car ID to load
         */
        public function __construct(?int $id = null) {
            $this->history = [];

            if ($id === null) {
                // Create new empty car with default data but NO ID (unsaved car)
                $this->data = (object) [
                    'user_id' => '1',
                    'year' => '1973',
                    'model' => 'Elan S4',
                    'series' => 'S4',
                    'variant' => 'SE',
                    'type' => 'FHC',
                    'chassis' => 'TEST123456',
                    'color' => 'Red',
                    'engine' => 'ABC123',
                    'image' => null,
                    'verification_code' => null,
                    'last_verified' => null,
                    'solddate' => null,
                    'ctime' => date('Y-m-d H:i:s'),
                    'mtime' => date('Y-m-d H:i:s')
                ];
            } else {
                // Try to load existing car from mock database
                if (isset(self::$cars[$id])) {
                    $this->data = self::$cars[$id];
                } else {
                    // Car doesn't exist - leave data as null
                    $this->data = null;
                }
            }
        }

        /**
         * Find car by ID (instance method)
         */
        public function find(?int $id = null): bool {
            if ($id === null) {
                return $this->findAll();
            }

            if (!isset(self::$cars[$id])) {
                $this->data = null;
                return false;
            }

            $this->data = self::$cars[$id];
            return true;
        }

        /**
         * Find all cars
         */
        public function findAll(): bool {
            if (empty(self::$cars)) {
                return false;
            }
            $this->data = reset(self::$cars);
            return true;
        }

        /**
         * Check if car exists
         */
        public function exists(): bool {
            return $this->data !== null && isset($this->data->id);
        }

        /**
         * Get car data object
         * @return object|null Car data or null if not found
         */
        public function data(): ?object {
            return $this->data;
        }

        /**
         * Get car history
         */
        public function history(): array {
            return $this->history;
        }

        /**
         * Get factory data
         */
        public function factory(): ?object {
            return null;
        }

        /**
         * Get owner data
         *
         * @return array<mixed>
         */
        public function owner(): array {
            return [];
        }

        /**
         * Get car images
         */
        public function images(): array {
            if ($this->data && $this->data->image) {
                return json_decode($this->data->image, true) ?: [];
            }
            return [];
        }

        /**
         * Remove image
         */
        public function removeImage(string $filename): bool {
            if (!$filename || !$this->exists()) {
                return false;
            }

            // Check if image actually exists in the car's images
            $images = $this->images();
            foreach ($images as $image) {
                if (isset($image['basename']) && $image['basename'] === $filename) {
                    // Image found - remove it
                    return true;
                }
            }

            // Image not found in car's images
            return false;
        }

        /**
         * Get DataTables data
         */
        public function getDataTablesData(array $request, string $table = 'cars'): array {
            return [
                'draw' => $request['draw'] ?? 1,
                'recordsTotal' => 10,
                'recordsFiltered' => 10,
                'data' => []
            ];
        }

        /**
         * Create car with data
         * @param array $data Car data
         * @return bool Success status
         */
        public function create(array $data): bool {
            // Assign ID if not already set
            if (!isset($this->data->id)) {
                $this->data->id = (string) self::$nextId++;
            }

            foreach ($data as $key => $value) {
                if ($key !== 'token') {
                    $this->data->$key = $value;
                }
            }
            self::$cars[$this->data->id] = $this->data;
            $userId = (int) ($this->data->user_id ?? 0);
            logger($userId, LogCategories::LOG_CATEGORY_CAR_ACTIONS, "Car ID {$this->data->id} created and assigned to owner (user ID: $userId)");
            return true;
        }

        /**
         * Update car with data
         * @param array $data Car data
         * @return bool Success status
         */
        public function update(array $data): bool {
            // Validate CSRF token
            if (!isset($data['token']) || !Token::check($data['token'])) {
                throw new CarValidationException('Invalid CSRF token provided');
            }

            // Validate ID if provided
            if (isset($data['id']) && (!is_int($data['id']) || $data['id'] <= 0)) {
                throw new CarValidationException('Invalid car ID provided');
            }

            // Initialize data if null
            if ($this->data === null) {
                $this->data = (object) [
                    'id' => (string) ($data['id'] ?? self::$nextId++),
                    'user_id' => '1',
                    'year' => '1973',
                    'model' => 'Elan S4',
                    'series' => 'S4',
                    'variant' => 'SE',
                    'type' => 'FHC',
                    'chassis' => 'TEST123456',
                    'color' => 'Red',
                    'engine' => 'ABC123',
                    'image' => null,
                    'verification_code' => null,
                    'last_verified' => null,
                    'solddate' => null,
                    'ctime' => date('Y-m-d H:i:s'),
                    'mtime' => date('Y-m-d H:i:s')
                ];
            }

            foreach ($data as $key => $value) {
                if ($key !== 'token') {
                    $this->data->$key = $value;
                }
            }
            if (isset($data['id'])) {
                self::$cars[$data['id']] = $this->data;
            }
            return true;
        }

        /**
         * Delete car
         */
        public function delete(string $reason, string $token): bool {
            if (!Token::check($token)) {
                throw new CarDeletionException('Invalid CSRF token provided');
            }

            if (!$this->exists()) {
                throw new CarNotFoundException('Car not found');
            }

            $id = $this->data->id;
            unset(self::$cars[$id]);
            $this->data = null;
            return true;
        }

        /**
         * Transfer car to new owner
         */
        public function transfer(int $newUserId, string $reason = 'Administrative transfer', string $operationType = 'NEWOWNER'): bool {
            if (!$this->exists()) {
                throw new CarNotFoundException('Car not found');
            }

            $this->data->user_id = $newUserId;
            self::$cars[$this->data->id] = $this->data;
            $this->history[] = ['operation' => $operationType, 'reason' => $reason];
            return true;
        }

        /**
         * Merge with another car
         */
        public function merge(int $oldCarId, string $reason = 'Administrative merge'): bool {
            if (!$this->exists()) {
                throw new CarMergeException('Car not found');
            }

            if ($oldCarId === $this->data->id) {
                throw new CarMergeException('Cannot merge car with itself');
            }

            if (!isset(self::$cars[$oldCarId])) {
                throw new CarMergeException('Source car not found');
            }

            unset(self::$cars[$oldCarId]);
            $this->history[] = ['operation' => 'MERGE', 'reason' => $reason];
            return true;
        }

        /**
         * Set verification code
         */
        public function setVerificationCode(string $verificationCode): bool {
            if (strlen($verificationCode) < 5) {
                throw new CarValidationException('Verification code too short');
            }

            if (!$this->exists()) {
                throw new CarNotFoundException('Car not found');
            }

            $this->data->verification_code = $verificationCode;
            self::$cars[$this->data->id] = $this->data;
            return true;
        }

        /**
         * Mark car as verified
         */
        public function markVerified(): bool {
            if (!$this->exists()) {
                throw new CarNotFoundException('Car not found');
            }

            $this->data->last_verified = date('Y-m-d H:i:s');
            self::$cars[$this->data->id] = $this->data;
            return true;
        }

        /**
         * Mark car as sold
         */
        public function markSold(?string $soldDate = null): bool {
            if (!$this->exists()) {
                throw new CarNotFoundException('Car not found');
            }

            $soldDate = $soldDate ?: date('Y-m-d');
            if (!strtotime($soldDate)) {
                throw new CarValidationException('Invalid date format');
            }

            $this->data->solddate = $soldDate . ' ' . date('H:i:s');
            self::$cars[$this->data->id] = $this->data;
            return true;
        }

        /**
         * Find car by verification code (static)
         */
        public static function findByVerificationCode(string $verificationCode): ?Car {
            // Return null for empty verification code (matches real behavior)
            if (empty($verificationCode)) {
                return null;
            }

            foreach (self::$cars as $car) {
                if (isset($car->verification_code) && $car->verification_code === $verificationCode) {
                    $instance = new self();
                    $instance->data = $car;
                    return $instance;
                }
            }
            return null;
        }

        /**
         * Find cars by owner (static)
         */
        public static function findByOwner(int $ownerID): array {
            if ($ownerID <= 0) {
                return [];
            }

            $cars = [];
            foreach (self::$cars as $car) {
                if ($car->user_id == $ownerID) {
                    $instance = new self();
                    $instance->data = $car;
                    $cars[] = $instance;
                }
            }
            return $cars;
        }

        /**
         * Reset mock database (for test isolation)
         */
        public static function resetMockDatabase(): void {
            self::$cars = [];
            self::$nextId = 1000;
            self::initializeTestData();
        }

        /**
         * Initialize test data in mock database
         */
        private static function initializeTestData(): void {
            // Create some test cars for user ID 1 (commonly used in tests)
            for ($i = 1; $i <= 5; $i++) {
                // Car ID 1 has an image for carousel tests
                $imageData = null;
                if ($i === 1) {
                    $imageData = json_encode([
                        ['path' => '/userimages/cars/test-car-1.jpg', 'basename' => 'test-car-1.jpg']
                    ]);
                }

                $car = (object)[
                    'id' => (string) $i,
                    'user_id' => '1',
                    'year' => (string) (1970 + $i),
                    'model' => 'Elan S' . $i,
                    'series' => 'S' . $i,
                    'variant' => 'SE',
                    'type' => 'FHC',
                    'chassis' => 'TEST' . str_pad((string)$i, 5, '0', STR_PAD_LEFT),
                    'color' => 'Red',
                    'engine' => 'ENG' . str_pad((string)$i, 3, '0', STR_PAD_LEFT),
                    'image' => $imageData,
                    'verification_code' => null,
                    'last_verified' => null,
                    'solddate' => null,
                    'ctime' => date('Y-m-d H:i:s'),
                    'mtime' => date('Y-m-d H:i:s')
                ];
                self::$cars[$i] = $car;
            }
        }
    }
}

// Initialize test data when the mock Car class is loaded
if (class_exists('Car')) {
    Car::resetMockDatabase();
}

// For unit tests, we need to prevent loading real classes that require database
// We'll load the autoloader but prevent real class instantiation
// by defining mock classes before the autoloader tries to include them

// First, define mock classes BEFORE loading autoloader
// so autoloader won't try to load the real ones

// Mock classes for testing if they don't exist
if (!class_exists('Token')) {
    class Token {
        public static function generate(): string {
            return 'test_csrf_token_' . uniqid();
        }

        public static function check(mixed $token): bool {
            if ($token === null || $token === '') {
                return false;
            }
            return strpos($token, 'test_csrf_token_') === 0;
        }
    }
}

// Define exception classes for testing
// Exception classes and LogCategories are now real classes loaded via autoloader
// No longer using mock implementations - allows tests to verify actual exception behavior

// Mock logger function — tracks calls in $mockLogEntries for test assertions
if (!function_exists('logger')) {
    function logger($userId, $category, $message): void {
        global $mockLogEntries;
        if (!isset($mockLogEntries)) {
            $mockLogEntries = [];
        }
        $mockLogEntries[] = [
            'user_id' => $userId,
            'category' => $category,
            'message' => $message,
        ];
    }
}

// Mock DB class
if (!class_exists('DB')) {
    /**
     * Mock DB class for unit tests (simple variant)
     */
    class DB {
        /** @var self|null */
        private static $instance;

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
         * Execute a query
         *
         * @param string $sql SQL query
         * @param array<mixed> $params Query parameters
         * @return QueryResult
         */
        public function query(string $sql, array $params = []): QueryResult {
            // Owner::find() runs a users LEFT JOIN profiles WHERE u.id = ? query.
            // Return a standard mock user row so tests that need a valid owner work.
            // The WHERE-clause guard prevents matching Owner::searchOwners() and other
            // queries that join users+profiles but use different WHERE conditions.
            if (stripos($sql, 'profiles') !== false
                && stripos($sql, 'users') !== false
                && stripos($sql, 'WHERE u.id') !== false) {
                $userId = $params[0] ?? 1;
                return new QueryResult([(object) [
                    'id'         => (string) $userId,
                    'email'      => 'test@example.com',
                    'fname'      => 'Test',
                    'lname'      => 'User',
                    'join_date'  => '2024-01-01 00:00:00',
                    'last_login' => '2024-01-01 00:00:00',
                    'city'       => 'Test City',
                    'state'      => 'TS',
                    'country'    => 'US',
                    'lat'        => null,
                    'lon'        => null,
                    'website'    => '',
                ]]);
            }
            return new QueryResult([]);
        }

        /**
         * Get a record by column/value
         *
         * @param string $table Table name
         * @param array<mixed> $where Where conditions
         * @return QueryResult
         */
        public function get(string $table, array $where): QueryResult {
            $id = $where[2] ?? 1;
            return new QueryResult([(object) [
                'id' => (string) $id,
                'user_id' => '1',
                'year' => '1973',
                'model' => 'Elan S4',
                'series' => 'S4',
                'variant' => 'SE',
                'type' => 'FHC',
                'chassis' => 'TEST123456',
                'color' => 'Red',
                'engine' => 'ABC123',
                'image' => null,
                'email' => 'test@example.com',
                'fname' => 'Test',
                'lname' => 'User',
                'join_date' => '2024-01-01',
                'city' => 'Test City',
                'state' => 'TS',
                'country' => 'US',
                'lat' => '0.0',
                'lon' => '0.0',
                'vericode' => null,
                'last_verified' => null,
                'solddate' => null,
                'purchasedate' => null,
                'ctime' => date('Y-m-d H:i:s'),
                'mtime' => date('Y-m-d H:i:s'),
                'website' => '',
                'comments' => ''
            ]]);
        }

        /**
         * Find all records in a table
         *
         * @param string $table Table name
         * @return QueryResult
         */
        public function findAll(string $table): QueryResult {
            return new QueryResult([]);
        }

        /**
         * Insert a record
         *
         * @param string $table Table name
         * @param array<string, mixed> $data Field values
         * @return bool
         */
        public function insert(string $table, array $data): bool {
            return true;
        }

        /**
         * Update a record
         *
         * @param string $table Table name
         * @param int $id Record ID
         * @param array<string, mixed> $data Field values
         * @return bool
         */
        public function update(string $table, int $id, array $data): bool {
            return true;
        }

        /**
         * Check for database errors
         *
         * @return bool
         */
        public function error(): bool {
            return false;
        }

        /**
         * Get error string
         *
         * @return string
         */
        public function errorString(): string {
            return '';
        }

        /**
         * Get last insert ID
         *
         * @return int
         */
        public function lastId(): int {
            return 1;
        }

        public function count(): int {
            return 0;
        }

        public function deleteById(string $table, int $id): bool {
            return true;
        }

        public function beginTransaction(): void {}
        public function commit(): void {}
        public function rollBack(): void {}
        public function inTransaction(): bool { return false; }
    }
}

if (!class_exists('QueryResult')) {
    /**
     * Mock query result class for unit tests (simple variant)
     */
    class QueryResult {
        /** @var array<mixed> */
        private array $results;

        /**
         * @param array<mixed> $results Mock data
         */
        public function __construct(array $results) {
            $this->results = $results;
        }

        /**
         * Get result count
         *
         * @return int
         */
        public function count(): int {
            return count($this->results);
        }

        /**
         * Get first result
         *
         * @return object|false
         */
        public function first(): object|false {
            return reset($this->results);
        }

        /**
         * Get all results
         *
         * @return array<mixed>
         */
        public function results(): array {
            return $this->results;
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
            throw new ImageProcessingException('File does not exist');
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filepath);
        
        $allowedMimes = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp'
        ];
        
        if (!in_array($mimeType, $allowedMimes)) {
            throw new ImageProcessingException('Invalid file type detected: ' . $mimeType);
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
            throw new ImageProcessingException('Unsupported file type: ' . $mimeType);
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
            throw new ImageProcessingException('File upload error: ' . $file['error']);
        }
        
        // Check file size limits
        $maxSize = 5 * 1024 * 1024; // 5MB
        $minSize = 100; // 100 bytes
        
        if ($file['size'] > $maxSize) {
            throw new ImageProcessingException('File too large. Maximum size is ' . ($maxSize / 1024 / 1024) . 'MB');
        }
        
        if ($file['size'] < $minSize) {
            throw new ImageProcessingException('File too small. Minimum size is ' . $minSize . ' bytes');
        }
        
        // Validate that uploaded file exists
        if (!is_uploaded_file($file['tmp_name']) && !file_exists($file['tmp_name'])) {
            throw new ImageProcessingException('Invalid file upload');
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
                global $mockUsers, $mockProfiles, $mockCars;

                // Handle noowner user lookup
                if (strpos($sql, 'SELECT id FROM users WHERE username = ?') !== false &&
                    isset($params[0]) && $params[0] === 'noowner') {
                    $noOwnerUsers = array_filter($mockUsers ?: [], function($user) {
                        return $user->username === 'noowner';
                    });
                    return new MockQueryResult(array_values($noOwnerUsers));
                }

                // Handle findByOwner queries (CarRepository::findByOwner)
                if (strpos($sql, 'SELECT id FROM cars WHERE user_id = ?') !== false) {
                    $userId = $params[0] ?? null;
                    $userCars = array_filter($mockCars ?: [], function($car) use ($userId) {
                        return $car->user_id == $userId;
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
                    'id' => (string) $id,
                    'user_id' => '1',
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
                $record = (object) array_merge($fields, ['id' => (string) $id]);
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

            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollBack(): void {}
            public function inTransaction(): bool { return false; }
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
                global $mockUsers, $mockProfiles, $mockCars;

                // Default to user data if no specific mock is set
                return [(object) [
                    'id' => '1',
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

// Load type helper functions (dbInt, currentUserId)
// Defined here directly since custom_functions.php requires server_globals.php
// which depends on the Server class and full framework initialization
if (!function_exists('dbInt')) {
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
}

if (!function_exists('currentUserId')) {
    function currentUserId(): int
    {
        global $user;
        if (!isset($user) || !$user->isLoggedIn()) {
            throw new RuntimeException('No user is currently logged in');
        }
        return (int) $user->data()->id;
    }
}

// ============================================================
// Mock CarModel Reference Data Class
// ============================================================
// CRITICAL: Must be defined BEFORE autoloader to prevent loading real CarModel
// Provides test data for valid model combinations without requiring database

// Use eval to create the class in the correct namespace
// This is a special case for unit testing - allows us to mock a namespaced class
eval('
namespace ElanRegistry\Reference;

/**
 * Mock CarModel class for unit tests
 * Returns valid test data for known model combinations
 */
class CarModel {
    /**
     * Valid model combinations for testing
     * @var array<string, bool>
     */
    private static array $validModels = [
        "S4|FHC|36" => true,
        "S4|DHC|45" => true,
        "Sprint|FHC|36" => true,
        "Sprint|DHC|45" => true,
        "S3|FHC|36" => true,
        "S3|DHC|45" => true,
        "+2|FHC|50" => true,
        "+2S|FHC|50" => true,
        "+2S/130|FHC|50" => true,
    ];

    /**
     * Check if a model combination exists
     */
    public function exists(string $series, string $variant, string $typeCode): bool {
        $series = trim($series);
        $variant = trim($variant);
        $typeCode = trim($typeCode);

        $modelValue = "{$series}|{$variant}|{$typeCode}";
        return isset(self::$validModels[$modelValue]);
    }

    /**
     * Get model by composite value
     */
    public function byValue(string $value): ?object {
        if (isset(self::$validModels[$value])) {
            $parts = explode("|", $value);
            return (object)[
                "model_value" => $value,
                "series_normalized" => $parts[0],
                "variant" => $parts[1],
                "type_code" => $parts[2],
            ];
        }
        return null;
    }
}
');

// Load Composer autoloader for all custom classes and exceptions
// This must come AFTER mock classes are defined so the mocks take precedence
require_once $projectRoot . '/vendor/autoload.php';

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
                'id' => '1',
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
    function securePage($page): bool {
        return true; // Always allow access in tests
    }
}

// Mock Input class if not available
if (!class_exists('Input')) {
    class Input {
        private static $mockData = [];
        
        public static function get($key, $default = null): mixed {
            if (!empty(self::$mockData)) {
                return self::$mockData[$key] ?? $default;
            }
            return $_POST[$key] ?? $_GET[$key] ?? $default;
        }
        
        public static function exists($method = 'post'): bool {
            if (!empty(self::$mockData)) {
                return !empty(self::$mockData);
            }
            return $method === 'post' ? !empty($_POST) : !empty($_GET);
        }
        
        public static function setMockData($data): void {
            self::$mockData = $data;
        }
        
        public static function clearMockData(): void {
            self::$mockData = [];
        }
    }
}

// Mock functions for user deletion testing - only for unit tests
if (!function_exists('deleteUsers')) {
    /**
     * Mock deleteUsers function for testing
     */
    function deleteUsers($users): int {
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

// Mock getSettings function - only for unit tests
if (!function_exists('getSettings')) {
    /**
     * Mock getSettings function for testing
     */
    function getSettings($id = 1): object {
        // Return mock settings object
        return (object) [
            'id' => (string) $id,
            'elan_image_dir' => '/userimages/'
        ];
    }
}

// Mock getBaseUrl function - needed by EmailTemplate
if (!function_exists('getBaseUrl')) {
    /**
     * Mock getBaseUrl function for testing
     * Returns a test base URL
     */
    function getBaseUrl(): string
    {
        return 'https://test.elanregistry.org';
    }
}

// Mock getFeedbackEmail function - needed by verify-new-email template
if (!function_exists('getFeedbackEmail')) {
    /**
     * Mock getFeedbackEmail function for testing
     * Returns 'registrar@elanregistry.org' — mirrors the production fallback in
     * custom_functions.php so that render assertions reflect real-world behavior.
     */
    function getFeedbackEmail(): string
    {
        return 'registrar@elanregistry.org';
    }
}

// Mock getAdminEmails function - needed by transfer email templates
if (!function_exists('getAdminEmails')) {
    /**
     * Mock getAdminEmails function for testing.
     * Set $GLOBALS['mockAdminEmails'] to override the default in a test.
     */
    function getAdminEmails(): string
    {
        global $mockAdminEmails;
        if (isset($mockAdminEmails)) {
            return $mockAdminEmails;
        }
        return 'admin@elanregistry.org';
    }
}

// Mock isRegistryAdmin function - needed by admin permission checks
if (!function_exists('isRegistryAdmin')) {
    /**
     * Mock isRegistryAdmin function for testing
     * Uses global $mockIsRegistryAdmin to control behavior
     */
    function isRegistryAdmin(int $userId): bool
    {
        global $mockIsRegistryAdmin;

        // If explicitly set in test, use that value
        if (isset($mockIsRegistryAdmin)) {
            return (bool) $mockIsRegistryAdmin;
        }

        // Default: user ID 1 is admin
        return $userId === 1;
    }
}

/**
 * Mock user deletion cleanup process
 */
function mockUserDeletionCleanup($id): void {
    global $mockUsers, $mockCars;

    $noOwnerUsers = array_values(array_filter($mockUsers ?? [], fn($u) => $u->username === 'noowner'));
    if (count($noOwnerUsers) > 0) {
        $noOwnerUserId = $noOwnerUsers[0]->id;

        $userCars = array_values(array_filter($mockCars ?? [], fn($c) => $c->user_id === $id));
        $carCount = count($userCars);

        foreach ($userCars as $car) {
            logger($id, LogCategories::LOG_CATEGORY_CAR_ACTIONS, "User deletion: car ID {$car->id} reassigned from user $id to noowner (ID: $noOwnerUserId)");
        }

        logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, "Complete cleanup: reassigned $carCount cars to noowner user (ID: $noOwnerUserId)");
    } else {
        logger($id, LogCategories::LOG_CATEGORY_USER_DELETION, 'Fallback cleanup: noowner user not found, set cars to NULL');
    }
}

// RegressionTestCase is not in the PSR-4 autoloaded path, so it must be
// explicitly required before PHPUnit loads test classes that extend it.
require_once __DIR__ . '/regression/RegressionTestCase.php';
