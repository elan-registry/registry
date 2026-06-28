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
        ->withLogging(0, LogCategories::LOG_CATEGORY_SECURITY, 'Unauthorized schema operations access attempt')
        ->send();
}

if ($method !== 'POST') {
    ApiResponse::error('POST method required')->send();
}

if (!isset($_POST['csrf']) || !Token::check($_POST['csrf'])) {
    ApiResponse::forbidden('Invalid CSRF token')
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY, 'Invalid CSRF token in schema operations')
        ->send();
}

try {
    $action = preg_replace('/[^\w\-]/', '', $_POST['action'] ?? '') ?: null;

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
                ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE,
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
                ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE,
                    "Schema health check: {$health['overall']}")
                ->send();
            break;

        case 'ensure_settings_fields':
            $result = $schemaManager->ensureSettingsFields();
            $message = $result['success']
                ? "Created {$result['created_fields']} settings fields"
                : 'Settings fields check failed';

            if ($result['success']) {
                ApiResponse::success($message)
                    ->withDataArray([
                        'created_fields' => $result['created_fields'],
                        'results' => $result['results'],
                        'errors' => $result['errors']
                    ])
                    ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, $message)
                    ->send();
            } else {
                ApiResponse::serverError($message)
                    ->withDataArray([
                        'created_fields' => $result['created_fields'],
                        'results' => $result['results'],
                        'errors' => $result['errors']
                    ])
                    ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, $message)
                    ->send();
            }
            break;

        case 'ensure_quality_tables':
            $result = $schemaManager->ensureQualityTables();
            $message = $result['success']
                ? "Created {$result['created_tables']} quality tables"
                : 'Quality tables check failed';

            if ($result['success']) {
                ApiResponse::success($message)
                    ->withDataArray([
                        'created_tables' => $result['created_tables'],
                        'results' => $result['results'],
                        'errors' => $result['errors']
                    ])
                    ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, $message)
                    ->send();
            } else {
                ApiResponse::serverError($message)
                    ->withDataArray([
                        'created_tables' => $result['created_tables'],
                        'results' => $result['results'],
                        'errors' => $result['errors']
                    ])
                    ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, $message)
                    ->send();
            }
            break;

        case 'perform_maintenance':
            $result = $schemaManager->performMaintenance();
            $message = $result['success']
                ? 'Schema maintenance completed successfully'
                : 'Schema maintenance failed';

            if ($result['success']) {
                ApiResponse::success($message)
                    ->withDataArray([
                        'operations' => $result['operations'],
                        'backup_created' => $result['backup_created'],
                        'backup_path' => isset($result['backup_path']) ? basename($result['backup_path']) : null,
                        'validation_issues' => $result['validation_issues'] ?? []
                    ])
                    ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, $message)
                    ->send();
            } else {
                ApiResponse::serverError($message)
                    ->withDataArray([
                        'operations' => $result['operations'],
                        'backup_created' => $result['backup_created'],
                        'backup_path' => isset($result['backup_path']) ? basename($result['backup_path']) : null,
                        'validation_issues' => $result['validation_issues'] ?? []
                    ])
                    ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, $message)
                    ->send();
            }
            break;

        default:
            throw new SchemaException('Unknown action: ' . $action);
    }

} catch (Exception $e) {
    // Log full error details server-side; generic message returned to client
    $errorDetails = "Schema operation '" . ($action ?? 'unknown') . "' failed for user " . ($user->data()->username ?? 'unknown') . "\n";
    $errorDetails .= "Error: " . $e->getMessage() . "\n";
    $errorDetails .= "File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
    $errorDetails .= "Stack trace:\n" . $e->getTraceAsString();

    logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SCHEMA_OPERATION_ERROR, $errorDetails);

    ApiResponse::serverError('Schema operation failed')
        ->send();
}
