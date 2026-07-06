<?php

declare(strict_types=1);

/**
 * statistics.php (formerly statistics-data.php)
 * Public API endpoint for lazy-loading statistics tab data
 *
 * Returns JSON data for specific tab content to enable progressive loading
 * and improve page performance. CSRF token required (POST only). The statistics
 * page requires login and is the sole source of the CSRF token; the API itself
 * does not independently verify authentication.
 *
 * @author Elan Registry Development Team
 * @copyright 2025
 */

require_once '../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/classes/StatisticsDataService.php';

$userId = 0;
$tab    = '';

try {
    if ($method !== 'POST') {
        ApiResponse::error('Method not allowed', 405)->send();
    }

    $userId = $user->isLoggedIn() ? (int) $user->data()->id : 0;

    if (!isset($_POST['csrf']) || !Token::check($_POST['csrf'])) {
        ApiResponse::forbidden('Invalid CSRF token')
            ->withLogging($userId, LogCategories::LOG_CATEGORY_SECURITY, 'Invalid CSRF token in statistics API request')
            ->send();
    }

    $rateUserId = $userId ?: null; // null is the framework's "no user" sentinel; 0 is not a valid user ID
    if (!checkRateLimit('statistics_request', $rateUserId)) {
        recordRateLimit('statistics_request', false, $rateUserId);
        ApiResponse::error('Too many requests. Please slow down.', 429)
            ->withLogging($userId, LogCategories::LOG_CATEGORY_SECURITY, 'Rate limit exceeded for statistics API')
            ->send();
    }
    recordRateLimit('statistics_request', true, $rateUserId);

    $tab = Input::get('tab');

    if (empty($tab)) {
        ApiResponse::error('Tab parameter required', 400)
            ->withLogging($userId, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Statistics API called without tab parameter')
            ->send();
    }

    if (!isset($db)) {
        ApiResponse::serverError('Database connection not available')
            ->withLogging($userId, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Statistics API: Database connection not available')
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
                ->withData('valid_tabs', ['geographic', 'production', 'colors', 'quality'])
                ->withLogging($userId, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, "Statistics API: Invalid tab '{$tab}'")
                ->send();
    }

    ApiResponse::success('Statistics data loaded')
        ->withData('tab', $tab)
        ->withData('data', $data)
        ->send();

} catch (Throwable $e) {
    ApiResponse::serverError('Data retrieval failed')
        ->withData('tab', $tab)
        ->withLogging(
            $userId,
            LogCategories::LOG_CATEGORY_DATABASE_ERROR,
            "Statistics API error for tab '{$tab}': " . $e->getMessage()
        )
        ->send();
}