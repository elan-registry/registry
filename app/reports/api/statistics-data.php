<?php
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

// Clean output buffer to prevent any HTML from interfering with JSON
ob_clean();

// Suppress warnings/notices that might interfere with JSON output
error_reporting(E_ERROR);
ini_set('display_errors', 0);

require_once '../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'app/classes/StatisticsDataService.php';

// Security check
if (!securePage($_SERVER['PHP_SELF'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

// Set JSON header
header('Content-Type: application/json');

// Get requested tab
$tab = $_GET['tab'] ?? '';

if (empty($tab)) {
    http_response_code(400);
    die(json_encode(['error' => 'Tab parameter required']));
}

// Initialize data service
try {
    if (!isset($db)) {
        throw new Exception('Database connection not available');
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
            http_response_code(400);
            die(json_encode(['error' => 'Invalid tab parameter']));
    }

    echo json_encode([
        'success' => true,
        'data' => $data,
        'tab' => $tab
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Data retrieval failed: ' . $e->getMessage()
    ]);
}