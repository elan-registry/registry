<?php

declare(strict_types=1);

/**
 * statistics-data.php
 * API endpoint for lazy-loading statistics tab data
 *
 * Returns JSON data for specific tab content to enable progressive loading
 * and improve page performance.
 *
 * @author Elan Registry Development Team
 * @copyright 2025
 */

require_once '../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'app/classes/StatisticsDataService.php';

// Security check
if (!securePage($php_self)) {
    ApiResponse::forbidden('Unauthorized access')
        ->withLogging(0, 'SecurityError', 'Unauthorized statistics-data.php access attempt')
        ->send();
}

// Get requested tab
$tab = $_GET['tab'] ?? '';

if (empty($tab)) {
    ApiResponse::error('Tab parameter required', 400)
        ->withLogging($user->data()->id ?? 0, 'ValidationError', 'Statistics API called without tab parameter')
        ->send();
}

// Initialize data service
try {
    if (!isset($db)) {
        ApiResponse::serverError('Database connection not available')
            ->withLogging($user->data()->id ?? 0, 'DatabaseError', 'Statistics API: Database connection not available')
            ->send();
    }
    $dataService = new StatisticsDataService($db);

    switch ($tab) {
        case 'geographic':
            $data = [
                'country' => $dataService->getCountryData(),
                'countryDistribution' => $dataService->getCountryDistribution(),
                'usStates' => $dataService->getUSStateDistribution()
            ];
            break;

        case 'production':
            $data = [
                'type' => $dataService->getTypeData(),
                'series' => $dataService->getSeriesData(),
                'variant' => $dataService->getVariantData(),
                'productionByYear' => $dataService->getProductionByYear(),
                'earlyVsLate' => $dataService->getEarlyVsLateProduction(),
                'seriesCounts' => $dataService->getSeriesCounts(),
                'seriesNotes' => $dataService->getSeriesNotes()
            ];
            break;

        case 'colors':
            $data = [
                'colors' => $dataService->getColorData(),
                'colorByYear' => $dataService->getColorByYear(),
                'colorBySeries' => $dataService->getColorBySeries()
            ];
            break;

        case 'quality':
            $data = [
                'completeness' => $dataService->getDataCompleteness()
            ];
            break;

        default:
            ApiResponse::error('Invalid tab parameter', 400)
                ->withData('tab', $tab)
                ->withData('valid_tabs', ['geographic', 'production', 'colors', 'quality'])
                ->withLogging($user->data()->id ?? 0, 'ValidationError', "Statistics API: Invalid tab '{$tab}'")
                ->send();
    }

    ApiResponse::success('Statistics data loaded')
        ->withData('tab', $tab)
        ->withData('data', $data)
        ->send();

} catch (Throwable $e) {
    ApiResponse::serverError('Data retrieval failed')
        ->withData('tab', $tab ?? 'unknown')
        ->withLogging(
            $user->data()->id ?? 0,
            'DatabaseError',
            "Statistics API error for tab '{$tab}': " . $e->getMessage()
        )
        ->send();
}