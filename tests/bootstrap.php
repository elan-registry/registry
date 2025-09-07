<?php
/**
 * PHPUnit Bootstrap File for Elan Registry Tests
 * 
 * Sets up the testing environment with UserSpice framework
 * and mocks for comprehensive testing.
 */

// Set up testing environment
define('TESTING', true);

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

// Load actual classes for testing
if (file_exists($projectRoot . '/usersc/classes/Resize.php')) {
    require_once $projectRoot . '/usersc/classes/Resize.php';
}

// Mock classes for testing if they don't exist
if (!class_exists('Token')) {
    class Token {
        public static function generate() {
            return 'test_csrf_token_' . uniqid();
        }
        
        public static function check($token) {
            return strpos($token, 'test_csrf_token_') === 0;
        }
    }
}

// Ensure required functions are available for file upload tests
if (!function_exists('generateSecureFilename')) {
    /**
     * Generate a cryptographically secure filename
     */
    function generateSecureFilename($extension) {
        $randomBytes = random_bytes(16);
        return 'img_' . bin2hex($randomBytes) . '.' . $extension;
    }
}

if (!function_exists('getMimeType')) {
    /**
     * Get and validate MIME type of uploaded file
     */
    function getMimeType($filepath) {
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
    function getExtension($mimeType) {
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
    function validateFileUpload($file) {
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

// Mock Car class if not loaded from UserSpice
// Only use mock for pure unit tests to avoid conflicts
if (!class_exists('Car') && (defined('TESTING_UNIT_ONLY') || !file_exists(dirname(__DIR__) . '/usersc/classes/Car.php'))) {
    class Car {
        private $data;
        private static $nextId = 1000;
        
        public function __construct() {
            $this->data = (object) [
                'id' => self::$nextId++,
                'user_id' => 1,
                'year' => '1973',
                'series' => 'S4',
                'variant' => 'SE',
                'type' => 'FHC',
                'chassis' => 'TEST123456',
                'color' => 'Red',
                'engine' => 'ABC123',
                'email' => 'test@example.com',
                'fname' => 'Test',
                'lname' => 'User',
                'city' => 'Test City',
                'state' => 'Test State',
                'country' => 'Test Country'
            ];
        }
        
        public static function find($id) {
            $car = new self();
            $car->data->id = $id;
            return $car;
        }
        
        public function data() {
            return $this->data;
        }
        
        public function create($data) {
            foreach ($data as $key => $value) {
                $this->data->$key = $value;
            }
            return true;
        }
        
        public function update($data) {
            foreach ($data as $key => $value) {
                $this->data->$key = $value;
            }
            return true;
        }
    }
}

// Always use mock DB class for testing to avoid database dependencies
if (!class_exists('DB')) {
    class DB {
            private static $instance = null;
            
            public static function getInstance() {
                if (self::$instance === null) {
                    self::$instance = new self();
                }
                return self::$instance;
            }
            
            public function query($sql, $params = []) {
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
                
                // Default response
                return new MockQueryResult();
            }
            
            public function insert($table, $fields) {
                return rand(1, 1000); // Mock insert ID
            }
            
            public function update($table, $id, $fields) {
                return true;
            }
            
            public function delete($table, $where) {
                return true;
            }
            
            public function findById($id, $table) {
                return new MockQueryResult();
            }
        }
        
        class MockQueryResult {
            private $mockData;
            
            public function __construct($data = null) {
                $this->mockData = $data;
            }
            
            public function results() {
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
            
            public function first() {
                $results = $this->results();
                return count($results) > 0 ? $results[0] : null;
            }
            
            public function count() {
                return count($this->results());
            }
        }
    }

// Mock user object and authentication system
if (!isset($user) || !is_object($user)) {
    class MockUser {
        private $userData;
        
        public function __construct() {
            $this->userData = (object) [
                'id' => 1,
                'username' => 'testuser',
                'email' => 'test@example.com',
                'fname' => 'Test',
                'lname' => 'User'
            ];
        }
        
        public function data() {
            return $this->userData;
        }
        
        public function isLoggedIn() {
            return true;
        }
    }
    
    $user = new MockUser();
    $GLOBALS['user'] = $user;
}

// Mock securePage function
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

// Mock functions for user deletion testing
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
        logger($id, 'UserDeletion', "Complete cleanup: reassigned $carCount cars to noowner user (ID: $noOwnerUserId)");
    } else {
        // Fallback if noowner doesn't exist
        $db->query('DELETE FROM profiles WHERE user_id = ?', [$id]);
        $db->query('DELETE FROM car_user WHERE userid = ?', [$id]);
        $db->query('UPDATE cars SET user_id = NULL WHERE user_id = ?', [$id]);
        
        logger($id, 'UserDeletion', 'Fallback cleanup: noowner user not found, set cars to NULL');
    }
}