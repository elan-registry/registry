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
 * Members only (#2144). Each history row carries a past owner's first name,
 * location and website, so an anonymous caller must not receive it. This
 * reverses #1305, which treated car history as public by design.
 *
 * No CSRF token: the session cookie and the remember-me cookie are both
 * SameSite=Strict, so a cross-site request arrives with no session and gets the
 * 401 below. The endpoint also only reads. The `car_history` rate limit bounds
 * how much history one member can pull.
 *
 * @author Elan Registry Team
 * @copyright 2025
 *
 * @link https://github.com/elan-registry/registry/issues/2144
 * @link https://github.com/elan-registry/registry/issues/1305
 * @link https://github.com/elan-registry/registry/blob/main/docs/development/adr/ADR-019-no-csrf-on-public-read-only-endpoints.md
 */

require_once '../../../users/init.php';

if ($method !== 'POST') {
    ApiResponse::error('Method not allowed', 405)->send();
}

if (!Input::existsPost()) {
    ApiResponse::error('No data received')->send();
}

// History rows carry past owners' first name, location and website, so only
// members may read them (#2144). Checked before the rate limit so that
// anonymous calls neither use up the car_history bucket nor write
// us_rate_limits rows.
if (!$user->isLoggedIn()) {
    ApiResponse::unauthorized('Login required')
        ->withLogging(0, LogCategories::LOG_CATEGORY_ACCESS_DENIED, 'Unauthenticated request to car history endpoint')
        ->send();
}

// Always a logged-in member's ID: the check above sends a 401 otherwise.
$userId = (int) $user->data()->id;

$rateUserId = $userId;
if (!checkRateLimit('car_history', $rateUserId)) {
    ApiResponse::error('Too many requests. Please slow down.', 429)
        ->withLogging($userId, LogCategories::LOG_CATEGORY_SECURITY, 'Rate limit exceeded for car history endpoint')
        ->send();
}
recordRateLimit('car_history', true, $rateUserId);

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
