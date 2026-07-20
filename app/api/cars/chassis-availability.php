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

use ElanRegistry\ApiResponse;
use ElanRegistry\Input;
use ElanRegistry\LogCategories;
use ElanRegistry\Car\CarValidator;
use ElanRegistry\Exceptions\CarValidationException;

if (!Input::existsPost()) {
    ApiResponse::error('No data received', 400)->send();
}

// $user->data() is null for guests; pre-resolve before passing to withLogging().
$logUserId = $user->isLoggedIn() ? (int) $user->data()->id : 0;

$token = Input::get('csrf');
if (!Token::check($token)) {
    ApiResponse::forbidden('Invalid CSRF token')
        ->withLogging($logUserId, LogCategories::LOG_CATEGORY_SECURITY, 'Invalid CSRF token in chassis check')
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

    if (strlen($chassis) > 15) {
        ApiResponse::error('Chassis number must be 15 characters or less', 400)->send();
    }
    if (!ctype_digit((string) $year) || (int) $year < 1963 || (int) $year > 1974) {
        ApiResponse::error('Year must be between 1963 and 1974', 400)->send();
    }
    if (strlen($model) > 30) {
        ApiResponse::error('Model must be 30 characters or less', 400)->send();
    }

    try {
        [, , $type] = CarValidator::parseModel($model);
    } catch (CarValidationException $e) {
        ApiResponse::error('Invalid model format', 400)->send();
    }

    $db = DB::getInstance();
    $carQ = $db->query(
        'SELECT id FROM cars WHERE year = ? AND type = ? AND chassis = ?',
        [$year, $type, $chassis]
    );

    if ($carQ->error()) {
        ApiResponse::serverError('Unable to verify chassis availability')
            ->withLogging(
                $logUserId,
                LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                'chassis-availability: DB error on chassis lookup: ' . $db->errorString()
            )
            ->send();
    }

    $isTaken = $carQ->count() > 0;

    ApiResponse::success($isTaken ? 'Chassis number is already registered' : 'Chassis number is available')
        ->withData('taken', $isTaken)
        ->withData('available', !$isTaken)
        ->send();
} catch (\Throwable $e) {
    ApiResponse::serverError('Failed to check chassis availability')
        ->withLogging(
            $logUserId,
            LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            'Chassis check error: ' . $e->getMessage()
        )
        ->send();
}
