<?php

declare(strict_types=1);

/**
 * Get Models API Endpoint
 *
 * Provides dynamic model data from car_models table via CarModel reference class.
 * Replaces hardcoded cardefinition.js with server-driven model selection.
 *
 * Actions:
 * - getModelsByYear: Returns models available in a specific year
 * - getAllYearModels: Returns all models grouped by year (1963-1974)
 *
 * @author Elan Registry Team
 * @copyright 2025
 * @package ElanRegistry
 */

require_once '../../../users/init.php';

use ElanRegistry\Reference\CarModel;

// Validate AJAX request
if (strtolower(Server::get('HTTP_X_REQUESTED_WITH', '')) !== 'xmlhttprequest') {
    ApiResponse::error('Bad Request: AJAX only', 400)->send();
}

if (!Input::exists('post')) {
    ApiResponse::error('No data received', 400)->send();
}

// Validate CSRF token (required for all POST endpoints)
$token = Input::get('csrf');
if (!Token::check($token)) {
    ApiResponse::forbidden('Invalid CSRF token')->send();
}

$action = Input::get('action');
$carModel = new CarModel();

try {
    switch ($action) {
        case 'getModelsByYear':
            $year = (int)Input::get('year');

            if ($year < 1963 || $year > 1974) {
                ApiResponse::validationError(['year' => 'Year must be between 1963 and 1974'])
                    ->send();
            }

            $models = $carModel->getAvailableInYear($year);

            // Format for dropdown: [{value: "S4|FHC|36", text: "Coupe S4 (...)"}]
            $options = array_map(function($model) {
                return [
                    'value' => $model->model_value,
                    'text' => $model->display_name
                ];
            }, $models);

            ApiResponse::success('Models retrieved')
                ->withData('models', $options)
                ->send();
            /* @phpstan-ignore-next-line */
            break;

        case 'getAllYearModels':
            // Get all models grouped by year for caching client-side
            $grouped = $carModel->groupByYear();

            // Format similar to MENU array: {1963: [...], 1964: [...]}
            $formatted = [];
            foreach ($grouped as $year => $yearModels) {
                $formatted[$year] = array_map(function($model) {
                    return [
                        'value' => $model->model_value,
                        'text' => $model->display_name
                    ];
                }, $yearModels);
            }

            ApiResponse::success('All models retrieved')
                ->withData('yearModels', $formatted)
                ->send();
            /* @phpstan-ignore-next-line */
            break;

        default:
            ApiResponse::error('Invalid action', 400)->send();
    }

} catch (\Exception $e) {
    logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        "get-models.php error: {$e->getMessage()}");
    ApiResponse::error('Failed to retrieve models', 500)->send();
}
