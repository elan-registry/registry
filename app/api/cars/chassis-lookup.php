<?php

declare(strict_types=1);

use ElanRegistry\Input;

/**
 * Chassis lookup endpoint
 *
 * Looks up a car registry entry by chassis number and returns the car ID.
 * Replaces the table=findCarByChassis branch of the legacy getDataTables.php dispatcher.
 *
 * @author Elan Registry Team
 * @copyright 2026
 */

require_once '../../../users/init.php';

if ($method !== 'POST') {
    ApiResponse::error('Method not allowed', 405)->send();
}

if (!Input::exists('post')) {
    ApiResponse::error('No data received')->send();
}

$token = Input::get('csrf');
if (!Token::check($token)) {
    ApiResponse::forbidden('Invalid CSRF token')
        ->withLogging($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SECURITY, 'CSRF token validation failed in chassis lookup endpoint')
        ->send();
}

try {
    $chassis = Input::raw('chassis');
    if (empty($chassis)) {
        ApiResponse::error('Chassis number required')->send();
    }

    $carQuery = $db->query('SELECT id FROM cars WHERE chassis = ? LIMIT 1', [$chassis]);
    if ($carQuery->count() > 0) {
        ApiResponse::success('Car found')
            ->withData('car_id', $carQuery->first()->id)
            ->send();
    } else {
        ApiResponse::success('No car found for this chassis number')
            ->withData('car_id', null)
            ->send();
    }
} catch (\Throwable $e) {
    logger(
        $user->data()->id ?? 0,
        LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        'Chassis lookup unexpected error: ' . $e->getMessage() . ' — ' . get_class($e)
    );
    ApiResponse::serverError('An unexpected error occurred')->send();
}
