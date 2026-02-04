<?php
declare(strict_types=1);

/**
 * schema-operations.php
 * Schema Operations Handler
 *
 * Handles AJAX requests for schema management operations
 * Part of Phase 1D: Enhanced Database Management
 */

require_once '../../../../users/init.php';

if (!securePage($php_self)) {
    ApiResponse::forbidden('Access denied')
        ->withLogging(0, 'SecurityError', 'Unauthorized schema operations access attempt')
        ->send();
}

// CSRF protection for all POST operations
if ($method === 'POST' && (!isset($_POST['csrf']) || !Token::check($_POST['csrf']))) {
    ApiResponse::error('Invalid CSRF token', 400)
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY, 'Invalid CSRF token in schema operations')
        ->send();
}

// Set content type for JSON responses
header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? null;

    if (!$action) {
        throw new SchemaException('No action specified');
    }

    // Initialize the schema manager
    // Cast user ID to int for strict type safety across different PHP/database configurations
    $schemaManager = new EnhancedSchemaManager($db, (int)$user->data()->id);

    switch ($action) {
        case 'validate_schema':
            $validation = $schemaManager->validateSchema();
            ApiResponse::success('Schema validation completed')
                ->withDataArray([
                    'valid' => $validation['valid'],
                    'issues' => $validation['issues'] ?? [],
                    'recommendations' => $validation['recommendations'] ?? []
                ])
                ->withLogging($user->data()->id, 'DatabaseMaintenance',
                    'Schema validation: ' . ($validation['valid'] ? 'PASSED' : 'FAILED'))
                ->send();
            break;

        case 'get_health_status':
            $health = $schemaManager->getHealthStatus();
            ApiResponse::success('Health status retrieved')
                ->withDataArray([
                    'overall' => $health['overall'],
                    'components' => $health['components']
                ])
                ->withLogging($user->data()->id, 'DatabaseMaintenance',
                    "Schema health check: {$health['overall']}")
                ->send();
            break;

        case 'ensure_settings_fields':
            $result = $schemaManager->ensureSettingsFields();
            $message = $result['success']
                ? "Created {$result['created_fields']} settings fields"
                : 'Settings fields check failed';

            ApiResponse::success($message)
                ->withDataArray([
                    'created_fields' => $result['created_fields'],
                    'results' => $result['results'],
                    'errors' => $result['errors']
                ])
                ->withLogging($user->data()->id, 'DatabaseMaintenance', $message)
                ->send();
            break;

        case 'ensure_quality_tables':
            $result = $schemaManager->ensureQualityTables();
            $message = $result['success']
                ? "Created {$result['created_tables']} quality tables"
                : 'Quality tables check failed';

            ApiResponse::success($message)
                ->withDataArray([
                    'created_tables' => $result['created_tables'],
                    'results' => $result['results'],
                    'errors' => $result['errors']
                ])
                ->withLogging($user->data()->id, 'DatabaseMaintenance', $message)
                ->send();
            break;

        case 'perform_maintenance':
            // CSRF token check for destructive operations
            if (!Token::check($_POST['csrf'] ?? '')) {
                throw new SchemaException('Invalid CSRF token');
            }

            $result = $schemaManager->performMaintenance();
            $message = $result['success']
                ? 'Schema maintenance completed successfully'
                : 'Schema maintenance failed';

            ApiResponse::success($message)
                ->withDataArray([
                    'operations' => $result['operations'],
                    'backup_created' => $result['backup_created'],
                    'backup_path' => $result['backup_path'] ?? null,
                    'validation_issues' => $result['validation_issues'] ?? []
                ])
                ->withLogging($user->data()->id, 'DatabaseMaintenance', $message)
                ->send();
            break;

        default:
            throw new SchemaException('Unknown action: ' . $action);
    }

} catch (Exception $e) {
    // Keep existing logger() call for detailed error logging
    logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SCHEMA_OPERATION_ERROR,
        'Schema operation failed: ' . $e->getMessage());

    ApiResponse::serverError('Schema operation failed: ' . $e->getMessage())
        ->send();
}