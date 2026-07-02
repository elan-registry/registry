<?php

declare(strict_types=1);

/**
 * chassis-availability.php
 * AJAX endpoint for checking if a chassis number is already registered
 *
 * Returns JSON response indicating whether the chassis number is taken.
 *
 * @author Elan Registry Team
 * @copyright 2025
 */

require_once '../../../users/init.php';

if (!Input::exists('post')) {
    ApiResponse::error('No data received', 400)->send();
}

$token = Input::get('csrf');
if (!Token::check($token)) {
    ApiResponse::forbidden('Invalid CSRF token')
        ->withLogging($user->data()?->id ?? 0, LogCategories::LOG_CATEGORY_SECURITY, 'Invalid CSRF token in chassis check')
        ->send();
}

$command = Input::get('command');
if ($command !== 'chassis_check') {
    ApiResponse::error('Invalid command', 400)->send();
}

try {
    $year = Input::raw('year');
    $model = Input::raw('model');
    $chassis = Input::raw('chassis');

    if (empty($year) || empty($model) || empty($chassis)) {
        ApiResponse::error('Missing required parameters: year, model, and chassis', 400)->send();
    }

    $modelParts = explode('|', $model);
    if (count($modelParts) !== 3) {
        ApiResponse::error('Invalid model format', 400)->send();
    }

    list($series, $variant, $type) = $modelParts;

    $db = DB::getInstance();
    $carQ = $db->query(
        'SELECT id FROM cars WHERE year = ? AND type = ? AND chassis = ?',
        [$year, $type, $chassis]
    );

    $isTaken = $carQ->count() > 0;

    ApiResponse::success($isTaken ? 'Chassis number is already registered' : 'Chassis number is available')
        ->withData('taken', $isTaken)
        ->withData('available', !$isTaken)
        ->send();
} catch (\Throwable $e) {
    ApiResponse::serverError('Failed to check chassis availability')
        ->withLogging(
            $user->data()?->id ?? 0,
            LogCategories::LOG_CATEGORY_DATABASE_ERROR,
            'Chassis check error: ' . $e->getMessage()
        )
        ->send();
}
