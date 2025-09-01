<?php

/**
 * validateChassis.php
 * AJAX endpoint for real-time chassis validation
 * 
 * Provides centralized chassis validation via AJAX for frontend real-time feedback.
 * Returns JSON response with validation results and detailed error messages.
 * 
 * @author Elan Registry Team
 * @copyright 2025
 */

require_once '../../../users/init.php';

// Debug: Check if ChassisValidator class file exists
$validatorPath = '../../../usersc/classes/ChassisValidator.php';
if (!file_exists($validatorPath)) {
    error_log("ChassisValidator file not found at: " . realpath($validatorPath));
    http_response_code(500);
    echo json_encode([
        'valid' => false,
        'error' => 'ChassisValidator class file not found'
    ]);
    exit;
}

require_once $validatorPath;

// Ensure this is an AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(400);
    die('Bad Request: AJAX only');
}

// Check CSRF token for security
if (!Token::check(Input::get('csrf'))) {
    http_response_code(403);
    echo json_encode([
        'valid' => false,
        'error' => 'CSRF token validation failed'
    ]);
    exit;
}

// Get validation parameters
$chassis = Input::get('chassis', '');
$year = (int)Input::get('year', 0);
$model = Input::get('model', '');
$allowOverride = Input::get('allow_override', false) === 'true';

// Validate required parameters
if (empty($chassis) || $year === 0 || empty($model)) {
    echo json_encode([
        'valid' => false,
        'error_reason' => 'Missing required parameters: chassis, year, and model',
        'chassis' => $chassis,
        'format_type' => '',
        'override_used' => false
    ]);
    exit;
}

// Perform validation using centralized validator
try {
    $validator = new ChassisValidator();
    $result = $validator->validate($chassis, $year, $model, $allowOverride);
    
    // Debug info removed - validation working correctly
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($result);
} catch (Exception $e) {
    error_log("ChassisValidator error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'valid' => false,
        'error' => 'Validation error: ' . $e->getMessage(),
        'chassis' => $chassis,
        'format_type' => '',
        'override_used' => false
    ]);
}