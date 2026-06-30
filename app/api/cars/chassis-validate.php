<?php

declare(strict_types=1);

/**
 * chassis-validate.php
 * AJAX endpoint for real-time chassis validation
 *
 * Provides centralized chassis validation via AJAX for frontend real-time feedback.
 * Returns JSON response with validation results and detailed error messages.
 *
 * NOTE: This endpoint implements the ApiResponse pattern correctly and serves as
 * the reference implementation for other endpoint migrations. See Issue #445 for
 * details on the ApiResponse pattern standardization effort.
 *
 * @author Elan Registry Team
 * @copyright 2025
 */

require_once '../../../users/init.php';

// Check if ChassisValidator class file exists
$validatorPath = '../../../usersc/classes/ChassisValidator.php';
if (!file_exists($validatorPath)) {
    ApiResponse::serverError('ChassisValidator class file not found')
        ->withLogging($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, "ChassisValidator file not found at: " . realpath($validatorPath))
        ->send();
}

require_once $validatorPath;

// Ensure this is an AJAX request
if (strtolower(Server::get('HTTP_X_REQUESTED_WITH', '')) !== 'xmlhttprequest') {
    ApiResponse::error('Bad Request: AJAX only', 400)->send();
}

// Check CSRF token for security
if (!Token::check(Input::get('csrf'))) {
    ApiResponse::forbidden('Invalid CSRF token')
        ->withLogging($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SECURITY, 'Invalid CSRF token in chassis validation')
        ->send();
}

// Get validation parameters
$chassis = Input::get('chassis', '');
$year = (int)Input::get('year', 0);
$model = Input::get('model', '');
$allowOverride = Input::get('allow_override', false) === 'true';

// Validate required parameters
if (empty($chassis) || $year === 0 || empty($model)) {
    ApiResponse::error('Missing required parameters: chassis, year, and model', 400)
        ->withDataArray([
            'valid' => false,
            'error_reason' => 'Missing required parameters: chassis, year, and model',
            'chassis' => $chassis,
            'format_type' => '',
            'override_used' => false
        ])
        ->send();
}

// Perform validation using centralized validator
try {
    $validator = new ChassisValidator();
    $result = $validator->validate($chassis, $year, $model, $allowOverride);

    ApiResponse::success('Chassis validation completed')
        ->withDataArray($result)
        ->send();
} catch (Throwable $e) {
    ApiResponse::serverError('Validation error: ' . $e->getMessage())
        ->withDataArray([
            'valid' => false,
            'error' => 'Validation error: ' . $e->getMessage(),
            'chassis' => $chassis,
            'format_type' => '',
            'override_used' => false
        ])
        ->withLogging($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, "ChassisValidator error: " . $e->getMessage())
        ->send();
}