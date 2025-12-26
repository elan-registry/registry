<?php
declare(strict_types=1);

/**
 * EnhancedSchemaManager.php
 * Enhanced Database Schema Management
 *
 * Extends the existing auto-creation system beyond settings to comprehensive table management
 * Part of Phase 1D: FIX System Integration and Enhanced Database Management
 */

require_once __DIR__ . '/SchemaException.php';

class EnhancedSchemaManager {
    private $db;
    private $logger;
    private $settings;

    // Existing settings auto-creation (preserved and enhanced)
    private $settingsFields = [
        // Enhanced settings fields with validation
        'elan_image_upload_max_size' => ['type' => 'DECIMAL(4,2)', 'default' => '2.00', 'description' => 'Maximum upload file size in MB'],
        'elan_image_display_max_size' => ['type' => 'INT(11)', 'default' => '2048', 'description' => 'Maximum display image width in pixels'],
        'elan_image_thumbnail_sizes' => ['type' => 'TEXT', 'default' => '100,300,768,1024,2048', 'description' => 'Comma-separated thumbnail sizes in pixels']
    ];

    // New table management capabilities.  Will be created if missing when maintenance is run via ensureQualityTables
    private $requiredTables = [
        'car_transfer_requests' => [
            'id'  => 'INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'existing_car_id' => 'INT(11) NOT NULL',
            'requested_by_user_id' => 'INT(11) NOT NULL',
            'request_date'  => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'status' => 'enum(\'pending\',\'approved\',\'denied\',\'completed\',\'expired\') NOT NULL DEFAULT \'pending\'',
            'security_token' => 'VARCHAR(64) NOT NULL',
            'expires_at' => 'TIMESTAMP NOT NULL DEFAULT \'0000-00-00 00:00:00\'',
            'admin_notes' => 'TEXT NULL',
            'current_owner_response_date' => 'TIMESTAMP NULL DEFAULT NULL',
            'completed_date' => 'TIMESTAMP NULL DEFAULT NULL',
            'denial_reason' => 'TEXT NULL',
            'submitted_model'=> 'VARCHAR(30) NOT NULL',
            'submitted_series'=> 'VARCHAR(12) NOT NULL',
            'submitted_variant'=> 'VARCHAR(15) NOT NULL',
            'submitted_year'=> 'VARCHAR(4) NOT NULL',
            'submitted_type' => 'CHAR(3) NOT NULL',
            'submitted_chassis'=> 'VARCHAR(15) NOT NULL',
            'submitted_color'=> 'VARCHAR(25) DEFAULT NULL',
            'submitted_engine'=> 'VARCHAR(15) DEFAULT NULL',
            'submitted_purchasedate' => 'DATE DEFAULT NULL',
            'submitted_solddate' => 'DATE DEFAULT NULL',
            'submitted_comments'=> 'TEXT NULL',
            'submitted_image' => 'TEXT NULL',
            'submitted_email'=> 'VARCHAR(155) DEFAULT NULL',
            'submitted_fname'=> 'VARCHAR(155) DEFAULT NULL',
            'submitted_lname'=> 'VARCHAR(155) DEFAULT NULL',
            'submitted_city'=> 'VARCHAR(100) DEFAULT NULL',
            'submitted_state'=> 'VARCHAR(100) DEFAULT NULL',
            'submitted_country'=> 'VARCHAR(100) DEFAULT NULL',
            'submitted_website' => 'varchar(100) DEFAULT NULL',
            'created_by' => 'INT(11) NOT NULL',
            'modified_date' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ],
    ];

    // Schema validation rules
    private $validationRules = [
        'required_core_tables' => ['users', 'cars', 'car_user', 'profiles', 'settings'],
        'required_indexes' => [
            'users' => ['email', 'active'],
            'cars' => ['chassis', 'user_id'],
            'car_user' => ['userid', 'car_id'],
            'profiles' => ['user_id']
        ],
        'required_foreign_keys' => [
            'car_user' => ['userid' => 'users(id)', 'car_id' => 'cars(id)'],
            'profiles' => ['user_id' => 'users(id)']
        ]
    ];

    /**
     * Constructor
     *
     * Note: PHP constructors cannot have return type declarations per language specification
     * @see https://www.php.net/manual/en/language.oop5.decon.php
     *
     * @param mixed $database Database connection object
     * @param mixed $userSettings Settings object
     * @param int|null $userId User ID for logging (optional)
     * @return void Constructors do not return values
     */
    // phpcs:ignore Squiz.Commenting.FunctionComment.MissingReturn
    // @codingStandardsIgnoreLine - Constructors cannot have return type declarations
    public function __construct($database, $userSettings, ?int $userId = null) {
        $this->db = $database;
        $this->settings = $userSettings;
        $this->logger = function($level, $category, $message) use ($userId) {
            if (function_exists('logger')) {
                logger($userId ?? 0, $category, $message);
            }
        };
    }

    /**
     * Enhanced settings auto-creation (extends existing functionality)
     *
     * @return array Array containing results with 'created', 'errors', and 'success' keys
     */
    public function ensureSettingsFields(): array {
        $results = [];
        $createdFields = 0;
        $errors = [];

        foreach ($this->settingsFields as $fieldName => $fieldConfig) {
            try {
                // Check if field exists
                $checkQuery = $this->db->query("SHOW COLUMNS FROM settings LIKE ?", [$fieldName]);

                if ($checkQuery->count() === 0) {
                    // Field doesn't exist, create it
                    $sql = "ALTER TABLE settings ADD COLUMN {$fieldName} {$fieldConfig['type']} DEFAULT '{$fieldConfig['default']}'";
                    $this->db->query($sql);
                    $createdFields++;

                    ($this->logger)(1, 'SchemaManager', "Created settings field: {$fieldName}");
                    $results[] = "Created field: {$fieldName}";
                }

                // Ensure default value is set for existing NULL entries
                $this->db->query("UPDATE settings SET {$fieldName} = ? WHERE {$fieldName} IS NULL", [$fieldConfig['default']]);

            } catch (SchemaException $e) {
                $error = "Failed to create/update field {$fieldName}: " . $e->getMessage();
                $errors[] = $error;
                ($this->logger)(1, 'SchemaError', $error);
            }
        }

        return [
            'success' => empty($errors),
            'created_fields' => $createdFields,
            'results' => $results,
            'errors' => $errors
        ];
    }

    /**
     * Create quality tracking tables
     *
     * @return array Array containing results with 'success', 'created_tables', 'results', and 'errors' keys
     */
    public function ensureQualityTables(): array {
        $results = [];
        $createdTables = 0;
        $errors = [];

        foreach ($this->requiredTables as $tableName => $schema) {
            try {
                // Check if table exists
                $checkQuery = $this->db->query("SHOW TABLES LIKE ?", [$tableName]);

                if ($checkQuery->count() === 0) {
                    // Table doesn't exist, create it
                    $columns = [];
                    foreach ($schema as $columnName => $columnDefinition) {
                        $columns[] = "{$columnName} {$columnDefinition}";
                    }

                    $sql = "CREATE TABLE {$tableName} (" . implode(', ', $columns) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
                    $this->db->query($sql);
                    $createdTables++;

                    ($this->logger)(1, 'SchemaManager', "Created table: {$tableName}");
                    $results[] = "Created table: {$tableName}";
                }

            } catch (SchemaException $e) {
                $error = "Failed to create table {$tableName}: " . $e->getMessage();
                $errors[] = $error;
                ($this->logger)(1, 'SchemaError', $error);
            }
        }

        return [
            'success' => empty($errors),
            'created_tables' => $createdTables,
            'results' => $results,
            'errors' => $errors
        ];
    }

    /**
     * Validate database schema integrity
     *
     * @return array Array containing validation results with 'valid', 'issues', and 'health_score' keys
     */
    public function validateSchema(): array {
        $results = [
            'valid' => true,
            'issues' => [],
            'recommendations' => []
        ];

        // Check required core tables
        foreach ($this->validationRules['required_core_tables'] as $table) {
            try {
                $checkQuery = $this->db->query("SHOW TABLES LIKE ?", [$table]);
                if ($checkQuery->count() === 0) {
                    $results['valid'] = false;
                    $results['issues'][] = "Missing required table: {$table}";
                }
            } catch (SchemaException $e) {
                $results['valid'] = false;
                $results['issues'][] = "Error checking table {$table}: " . $e->getMessage();
            }
        }

        // Check required indexes (simplified check)
        foreach ($this->validationRules['required_indexes'] as $table => $columns) {
            try {
                foreach ($columns as $column) {
                    $indexQuery = $this->db->query("SHOW INDEX FROM {$table} WHERE Column_name = ?", [$column]);
                    if ($indexQuery->count() === 0) {
                        $results['recommendations'][] = "Consider adding index on {$table}.{$column} for better performance";
                    }
                }
            } catch (SchemaException $e) {
                $results['issues'][] = "Error checking indexes for {$table}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Get schema health status
     *
     * @return array Array containing health status with 'score', 'grade', 'issues', and 'last_maintenance' keys
     */
    public function getHealthStatus(): array {
        $status = [
            'overall' => 'healthy',
            'components' => []
        ];

        // Check settings auto-creation
        try {
            $settingsCheck = $this->ensureSettingsFields();
            $status['components']['settings_autocreation'] = [
                'status' => $settingsCheck['success'] ? 'healthy' : 'warning',
                'details' => $settingsCheck['success'] ? 'All fields present' : 'Issues detected',
                'created_fields' => $settingsCheck['created_fields'] ?? 0
            ];
        } catch (SchemaException $e) {
            $status['components']['settings_autocreation'] = [
                'status' => 'error',
                'details' => 'Check failed: ' . $e->getMessage()
            ];
            $status['overall'] = 'warning';
        }

        // Check quality tables
        try {
            $tablesCheck = $this->ensureQualityTables();
            $status['components']['quality_tables'] = [
                'status' => $tablesCheck['success'] ? 'healthy' : 'warning',
                'details' => $tablesCheck['success'] ? 'All tables present' : 'Issues detected',
                'created_tables' => $tablesCheck['created_tables'] ?? 0
            ];
        } catch (SchemaException $e) {
            $status['components']['quality_tables'] = [
                'status' => 'error',
                'details' => 'Check failed: ' . $e->getMessage()
            ];
            $status['overall'] = 'warning';
        }

        // Schema validation
        try {
            $validation = $this->validateSchema();
            $status['components']['schema_validation'] = [
                'status' => $validation['valid'] ? 'healthy' : 'warning',
                'details' => $validation['valid'] ? 'Schema is valid' : count($validation['issues']) . ' issues found',
                'issues' => $validation['issues'] ?? [],
                'recommendations' => $validation['recommendations'] ?? []
            ];

            if (!$validation['valid']) {
                $status['overall'] = 'warning';
            }
        } catch (SchemaException $e) {
            $status['components']['schema_validation'] = [
                'status' => 'error',
                'details' => 'Validation failed: ' . $e->getMessage()
            ];
            $status['overall'] = 'warning';
        }

        return $status;
    }

    /**
     * Create backup before schema changes (integration with FIX backup system)
     *
     * @param string $operation Operation name (e.g., "Schema Maintenance")
     * @return string Path to the created backup file
     * @throws SchemaException If backup creation fails
     */
    public function createSchemaBackup(string $operation): string {

        
        if (function_exists('createStandardizedBackup')) {
            try {
                return createStandardizedBackup(
                    'schema-' . strtolower(str_replace(' ', '-', $operation)),
                    ['settings', 'users', 'cars', 'car_user', 'profiles'],
                    'automated',
                    'development'
                );
            } catch (SchemaException $e) {
                ($this->logger)(1, 'SchemaError', 'Backup creation failed: ' . $e->getMessage());
                throw $e;
            }
        }

        throw new SchemaException('Backup system not available');
    }

    /**
     * Execute comprehensive schema maintenance
     *
     * @return array Array containing maintenance results with 'success', 'operations', 'backup_created',
     *               'backup_path', 'created_fields', 'created_tables', and any errors
     * @throws SchemaException If maintenance operations fail
     */
    public function performMaintenance(): array {
        $results = [
            'success' => true,
            'operations' => [],
            'backup_created' => false
        ];

        try {
            // Create backup before maintenance
            $backupPath = $this->createSchemaBackup('Schema Maintenance');
            $results['backup_created'] = true;
            $results['backup_path'] = $backupPath;
            $results['operations'][] = 'Created maintenance backup';

            // Perform settings auto-creation
            $settingsResult = $this->ensureSettingsFields();
            $results['operations'][] = 'Settings fields: ' . ($settingsResult['success'] ? 'OK' : 'Issues detected');
            if ($settingsResult['created_fields'] > 0) {
                $results['operations'][] = "Created {$settingsResult['created_fields']} new settings fields";
            }

            // Ensure quality tracking tables
            $tablesResult = $this->ensureQualityTables();
            $results['operations'][] = 'Quality tables: ' . ($tablesResult['success'] ? 'OK' : 'Issues detected');
            if ($tablesResult['created_tables'] > 0) {
                $results['operations'][] = "Created {$tablesResult['created_tables']} new quality tables";
            }

            // Validate schema
            $validation = $this->validateSchema();
            $results['operations'][] = 'Schema validation: ' . ($validation['valid'] ? 'PASSED' : 'ISSUES FOUND');

            if (!$validation['valid']) {
                $results['success'] = false;
                $results['validation_issues'] = $validation['issues'];
            }

            ($this->logger)(1, 'SchemaManager', 'Schema maintenance completed successfully');

        } catch (SchemaException $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            ($this->logger)(1, 'SchemaError', 'Schema maintenance failed: ' . $e->getMessage());
        }

        return $results;
    }
}