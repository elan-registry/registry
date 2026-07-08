<?php

declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\Car\Car;
use ElanRegistry\Exceptions\CarException;
use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Exceptions\ValidationException;
use ElanRegistry\Input;
use ElanRegistry\LogCategories;

/**
 * Cars list DataTables endpoint
 *
 * Server-side processing endpoint for the car registry DataTable.
 * Replaces the table=cars branch of the legacy getDataTables.php dispatcher.
 *
 * @author Elan Registry Team
 * @copyright 2026
 */

require_once '../../../users/init.php';

if ($method !== 'POST') {
    ApiResponse::error('Method not allowed', 405)->send();
}

if (!Input::exists('post')) {
    ApiResponse::error('No data received')
        ->withDataArray([
            'draw' => 0,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
        ])
        ->send();
}

$token = Input::get('csrf');
if (!Token::check($token)) {
    ApiResponse::forbidden('Invalid CSRF token')
        ->withDataArray([
            'draw' => 0,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
        ])
        ->withLogging($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SECURITY, 'CSRF token validation failed in cars list endpoint')
        ->send();
}

$emptyDraw = [
    'draw'            => (int) Input::get('draw'),
    'recordsTotal'    => 0,
    'recordsFiltered' => 0,
    'data'            => [],
];

try {
    $request = [
        'draw'    => (int) Input::get('draw'),
        'start'   => (int) Input::get('start'),
        'length'  => (int) Input::get('length'),
        'search'  => ['value' => trim((string) ($_POST['search']['value'] ?? ''))],
        'order'   => is_array($_POST['order'] ?? null) ? $_POST['order'] : [],
        'columns' => is_array($_POST['columns'] ?? null) ? $_POST['columns'] : [],
    ];

    $car = new Car();
    $response = $car->getDataTablesData($request, 'cars');

    $json = json_encode($response, JSON_THROW_ON_ERROR);
    header('Content-Type: application/json');
    echo $json;
    exit;

} catch (ValidationException | CarException $e) {
    logger(
        $user->data()->id ?? 0,
        $e->getLogCategory(),
        'Cars list error: ' . $e->getMessage() . "\nTrace: " . $e->getTraceAsString()
    );
    ApiResponse::error($e->getUserMessage(), $e->getHttpStatusCode())
        ->withDataArray($emptyDraw)
        ->send();
} catch (ElanRegistryException $e) {
    logger(
        $user->data()->id ?? 0,
        LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        'Cars list error: ' . $e->getMessage() . "\nTrace: " . $e->getTraceAsString()
    );
    ApiResponse::serverError('Server error occurred')
        ->withDataArray($emptyDraw)
        ->send();
} catch (\Throwable $e) {
    logger(
        $user->data()->id ?? 0,
        LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        'Cars list unexpected error: ' . $e->getMessage() . ' — ' . get_class($e)
    );
    ApiResponse::serverError('An unexpected error occurred')
        ->withDataArray($emptyDraw)
        ->send();
}
