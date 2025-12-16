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

if (!securePage($_SERVER['PHP_SELF'])) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'error' => 'Access denied']));
}

// Include the Enhanced Schema Manager
require_once '../classes/EnhancedSchemaManager.php';

// Set content type for JSON responses
header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? null;

    if (!$action) {
        throw new Exception('No action specified');
    }

    // Initialize the schema manager
    $schemaManager = new EnhancedSchemaManager($db, $settings, $user->data()->id);

    switch ($action) {
        case 'validate_schema':
            $validation = $schemaManager->validateSchema();
            echo json_encode([
                'success' => true,
                'validation' => $validation
            ]);
            break;

        case 'get_health_status':
            $health = $schemaManager->getHealthStatus();
            echo json_encode([
                'success' => true,
                'health' => $health
            ]);
            break;

        case 'ensure_settings_fields':
            $result = $schemaManager->ensureSettingsFields();
            echo json_encode([
                'success' => $result['success'],
                'result' => $result
            ]);
            break;

        case 'ensure_quality_tables':
            $result = $schemaManager->ensureQualityTables();
            echo json_encode([
                'success' => $result['success'],
                'result' => $result
            ]);
            break;

        case 'perform_maintenance':
            // CSRF token check for destructive operations
            if (!Token::check($_POST['csrf'] ?? '')) {
                throw new Exception('Invalid CSRF token');
            }

            $result = $schemaManager->performMaintenance();
            echo json_encode([
                'success' => $result['success'],
                'result' => $result
            ]);
            break;

        default:
            throw new Exception('Unknown action: ' . $action);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);

    // Log the error
    if (function_exists('logger')) {
        logger($user->data()->id ?? 0, 'SchemaOperationError', 'Schema operation failed: ' . $e->getMessage());
    }
}