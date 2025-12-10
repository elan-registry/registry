<?php
declare(strict_types=1);

/**
 * EnhancedSchemaManager.php
 * Enhanced Database Schema Management
 *
 * Extends the existing auto-creation system beyond settings to comprehensive table management
 * Part of Phase 1D: FIX System Integration and Enhanced Database Management
 */

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

    // New table management capabilities
    private $requiredTables = [
        'duplicate_detection_results' => [
            'id' => 'INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'detection_type' => 'ENUM("email", "profile", "car") NOT NULL',
            'primary_record_id' => 'INT(11) NOT NULL',
            'duplicate_record_id' => 'INT(11) NOT NULL',
            'confidence_score' => 'DECIMAL(3,2) DEFAULT 0.00',
            'status' => 'ENUM("pending", "resolved", "dismissed") DEFAULT "pending"',
            'detected_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'resolved_at' => 'TIMESTAMP NULL',
            'admin_notes' => 'TEXT NULL'
        ],
        'user_profile_audit' => [
            'id' => 'INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'user_id' => 'INT(11) NOT NULL',
            'field_name' => 'VARCHAR(100) NOT NULL',
            'old_value' => 'TEXT NULL',
            'new_value' => 'TEXT NULL',
            'change_type' => 'ENUM("create", "update", "delete") NOT NULL',
            'admin_id' => 'INT(11) NULL',
            'change_reason' => 'VARCHAR(255) NULL',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'ip_address' => 'VARCHAR(45) NULL'
        ],
        'data_quality_metrics' => [
            'id' => 'INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'metric_type' => 'VARCHAR(50) NOT NULL',
            'metric_name' => 'VARCHAR(100) NOT NULL',
            'metric_value' => 'DECIMAL(10,2) NOT NULL',
            'threshold_warning' => 'DECIMAL(10,2) NULL',
            'threshold_critical' => 'DECIMAL(10,2) NULL',
            'status' => 'ENUM("normal", "warning", "critical") DEFAULT "normal"',
            'measured_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'context_data' => 'JSON NULL'
        ]
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

    public function __construct($database, $userSettings, $userId = null) {
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

            } catch (Exception $e) {
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

            } catch (Exception $e) {
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
            } catch (Exception $e) {
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
            } catch (Exception $e) {
                $results['issues'][] = "Error checking indexes for {$table}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Get schema health status
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
            } catch (Exception $e) {
                ($this->logger)(1, 'SchemaError', 'Backup creation failed: ' . $e->getMessage());
                throw $e;
            }
        }

        throw new Exception('Backup system not available');
    }

    /**
     * Execute comprehensive schema maintenance
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

        } catch (Exception $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            ($this->logger)(1, 'SchemaError', 'Schema maintenance failed: ' . $e->getMessage());
        }

        return $results;
    }
}