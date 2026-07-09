<?php

declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\Car\Car;
use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Input;
use ElanRegistry\LogCategories;

/**
 * history.php
 * AJAX endpoint for retrieving car modification history
 *
 * Returns DataTables-compatible JSON with the full audit trail
 * for a single car, keyed by car_id POST parameter.
 *
 * @author Elan Registry Team
 * @copyright 2025
 */

require_once '../../../users/init.php';

if ($method !== 'POST') {
    ApiResponse::error('Method not allowed', 405)->send();
}

if (!Input::existsPost()) {
    ApiResponse::error('No data received')->send();
}

$token = Input::get('csrf');
if (!Token::check($token)) {
    ApiResponse::forbidden('Invalid CSRF token')
        ->withLogging($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SECURITY, 'CSRF token validation failed in car history endpoint')
        ->send();
}

$draw = (int)Input::get('draw');
$carID = (int)Input::get('car_id');

if (empty($carID)) {
    ApiResponse::error('Car ID not provided', 400)
        ->withDataArray([
            'draw' => $draw,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'history' => []
        ])
        ->withLogging($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Car history requested without car ID')
        ->send();
}

try {
    $car = new Car($carID);
    if (!$car->exists()) {
        ApiResponse::notFound('Car not found')
            ->withDataArray([
                'draw' => $draw,
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'history' => []
            ])
            ->withLogging($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, "Car history requested for non-existent car ID: $carID")
            ->send();
    }

    $carHist = $car->history();
    $count   = count($carHist);

    ApiResponse::success('Car history retrieved')
        ->withDataArray([
            'draw' => $draw,
            'recordsTotal' => $count,
            'recordsFiltered' => $count,
            'history' => $carHist
        ])
        ->send();
} catch (ElanRegistryException $e) {
    ApiResponse::serverError('Failed to load car history')
        ->withDataArray([
            'draw' => $draw,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'history' => []
        ])
        ->withLogging($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "Failed to load car history for car ID $carID: " . $e->getMessage())
        ->send();
} catch (\Throwable $e) {
    ApiResponse::serverError('Failed to load car history')
        ->withDataArray([
            'draw' => $draw,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'history' => []
        ])
        ->withLogging($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, "Unexpected error loading car history for car ID $carID [" . get_class($e) . "]: " . $e->getMessage())
        ->send();
}
