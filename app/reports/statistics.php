<?php

declare(strict_types=1);

/**
 * statistics.php
 * Comprehensive analytics dashboard for the Elan Registry
 *
 * Displays statistics and analytics including geographic data, production patterns,
 * color trends, data quality metrics, and registry growth analytics.
 * Uses Chart.js for data visualization with Bootstrap-themed tabbed interface.
 * Includes lazy loading for performance optimization.
 *
 * @author Elan Registry Analytics Team
 * @copyright 2025
 */
require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
require_once $abs_us_root . $us_url_root . 'app/classes/StatisticsDataService.php';

if (!securePage($php_self)) {
    die();
}

// Initialize data service
$dataService = new StatisticsDataService($db);
?>

<div class="page-wrapper">
    <div class="container-fluid">
        <div class="page-container">
            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1 class="h2 mb-0">Registry Analytics & Statistics</h1>
                            <p class="text-muted">Comprehensive analysis of car registry data with interactive visualizations</p>
                        </div>
                        <div class="text-right">
                            <small class="text-muted">
                                <i class="fas fa-chart-bar"></i>
                                Live analytics data
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Navigation Tabs -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header p-0">
                            <ul class="nav nav-tabs card-header-tabs" id="statisticsTabs" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" id="overview-tab" data-toggle="tab" href="#overview" role="tab" aria-controls="overview" aria-selected="true">
                                        <i class="fas fa-tachometer-alt"></i> Overview
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="geographic-tab" data-toggle="tab" href="#geographic" role="tab" aria-controls="geographic" aria-selected="false">
                                        <i class="fas fa-globe-americas"></i> Geographic
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="production-tab" data-toggle="tab" href="#production" role="tab" aria-controls="production" aria-selected="false">
                                        <i class="fas fa-industry"></i> Production
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="colors-tab" data-toggle="tab" href="#colors" role="tab" aria-controls="colors" aria-selected="false">
                                        <i class="fas fa-palette"></i> Colors
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="quality-tab" data-toggle="tab" href="#quality" role="tab" aria-controls="quality" aria-selected="false">
                                        <i class="fas fa-check-circle"></i> Data Quality
                                    </a>
                                </li>
                            </ul>
                        </div>

                        <div class="card-body">
                            <div class="tab-content" id="statisticsTabContent">
                                <!-- Overview Tab -->
                                <div class="tab-pane fade show active" id="overview" role="tabpanel" aria-labelledby="overview-tab">
                                    <!-- Map Section -->
                                    <div class="row mb-4">
                                        <div class="col-12">
                                            <div class="card">
                                                <div class="card-header">
                                                    <h4 class="mb-0">Global Car Distribution</h4>
                                                </div>
                                                <div class="card-body text-center">
                                                    <div class="map-container">
                                                        <div id="map"></div>
                                                    </div>
                                                    <div class="mt-3">
                                                        <small class="text-muted">
                                                            26 <img alt="yellow pin" src="https://maps.gstatic.com/mapfiles/ridefinder-images/mm_20_yellow.png" /> |
                                                            36 <img alt="white pin" src="https://maps.gstatic.com/mapfiles/ridefinder-images/mm_20_white.png" /> |
                                                            45 <img alt="red pin" src="https://maps.gstatic.com/mapfiles/ridefinder-images/mm_20_red.png" /> |
                                                            50 <img alt="blue pin" src="https://maps.gstatic.com/mapfiles/ridefinder-images//mm_20_blue.png" /> |
                                                            26R <img alt="purple pin" src="https://maps.gstatic.com/mapfiles/ridefinder-images/mm_20_purple.png" />
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Key Metrics Cards -->
                                    <div class="row mb-4">
                                        <div class="col-md-3 mb-3">
                                            <div class="card bg-primary text-white">
                                                <div class="card-body text-center">
                                                    <i class="fas fa-car fa-2x mb-2"></i>
                                                    <h3 class="mb-0" id="totalCars">-</h3>
                                                    <small>Total Cars Registered</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="card bg-success text-white">
                                                <div class="card-body text-center">
                                                    <i class="fas fa-globe fa-2x mb-2"></i>
                                                    <h3 class="mb-0" id="totalCountries">-</h3>
                                                    <small>Countries Represented</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="card bg-info text-white">
                                                <div class="card-body text-center">
                                                    <i class="fas fa-palette fa-2x mb-2"></i>
                                                    <h3 class="mb-0" id="totalColors">-</h3>
                                                    <small>Unique Colors</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="card bg-warning text-white">
                                                <div class="card-body text-center">
                                                    <i class="fas fa-calendar fa-2x mb-2"></i>
                                                    <h3 class="mb-0" id="registrationGrowth">-</h3>
                                                    <small>New This Year</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Essential Charts -->
                                    <div class="row">
                                        <div class="col-lg-6 mb-4">
                                            <div class="card">
                                                <div class="card-header">
                                                    <h5 class="mb-0">Registry Timeline</h5>
                                                </div>
                                                <div class="card-body" style="height: 400px;">
                                                    <canvas id="timelineChart"></canvas>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-4">
                                            <div class="card">
                                                <div class="card-header">
                                                    <h5 class="mb-0">Recent Registration Activity</h5>
                                                </div>
                                                <div class="card-body" style="height: 400px;">
                                                    <canvas id="ageChart"></canvas>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Geographic Tab -->
                                <div class="tab-pane fade" id="geographic" role="tabpanel" aria-labelledby="geographic-tab">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h4>Geographic Distribution Analysis</h4>
                                        <div class="spinner-border text-primary" role="status" id="geographic-spinner" style="display: none;">
                                            <span class="sr-only">Loading...</span>
                                        </div>
                                    </div>
                                    <div id="geographic-content">
                                        <!-- Content will be loaded dynamically -->
                                    </div>
                                </div>

                                <!-- Production Tab -->
                                <div class="tab-pane fade" id="production" role="tabpanel" aria-labelledby="production-tab">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h4>Production Analysis</h4>
                                        <div class="spinner-border text-primary" role="status" id="production-spinner" style="display: none;">
                                            <span class="sr-only">Loading...</span>
                                        </div>
                                    </div>
                                    <div id="production-content">
                                        <!-- Content will be loaded dynamically -->
                                    </div>
                                </div>

                                <!-- Colors Tab -->
                                <div class="tab-pane fade" id="colors" role="tabpanel" aria-labelledby="colors-tab">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h4>Color Analysis Dashboard</h4>
                                        <div class="spinner-border text-primary" role="status" id="colors-spinner" style="display: none;">
                                            <span class="sr-only">Loading...</span>
                                        </div>
                                    </div>
                                    <div id="colors-content">
                                        <!-- Content will be loaded dynamically -->
                                    </div>
                                </div>

                                <!-- Data Quality Tab -->
                                <div class="tab-pane fade" id="quality" role="tabpanel" aria-labelledby="quality-tab">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h4>Data Quality & Completeness</h4>
                                        <div class="spinner-border text-primary" role="status" id="quality-spinner" style="display: none;">
                                            <span class="sr-only">Loading...</span>
                                        </div>
                                    </div>
                                    <div id="quality-content">
                                        <!-- Content will be loaded dynamically -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Data for JavaScript -->
<script>
window.statisticsRawData = {
    // Overview data - loaded immediately
    timeline: <?= json_encode($dataService->getTimelineData()) ?>,
    age: <?= json_encode($dataService->getAgeData()) ?>,

    // Basic counts for overview cards
    countriesCount: <?= count($dataService->getCountryData()) ?>,
    colorsCount: <?= count($dataService->getColorData()) ?>
};

// Configuration
window.statisticsConfig = {
    mapsApiKey: '<?= $settings->elan_google_maps_key ?? "" ?>',
    baseUrl: '<?= $us_url_root ?>app/reports/',
    imageUrl: '<?= $us_url_root . $settings->elan_image_dir ?>'
};
</script>

<!-- Load Chart.js -->
<?php
// Chart.js CDN - loaded from database setting with SRI hash
echo isset($settings->elan_chartjs_cdn) ? html_entity_decode($settings->elan_chartjs_cdn) : '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js" integrity="sha384-FcQlsUOd0TJjROrBxhJdUhXTUgNJQxTMcxZe6nHbaEfFL1zjQ+bq/uRoBQxb0KMo" crossorigin="anonymous"></script>';
?>

<!-- Load Statistics JavaScript first -->
<script src="<?= $us_url_root ?>app/assets/js/statistics.js?v=2.8.4-debug"></script>

<!-- Initialize Google Maps and Statistics -->
<script>
// Global initMap function for Google Maps callback
function initMap() {
    // Wait for statisticsInitMap to be available
    function tryInitMap() {
        if (window.statisticsInitMap) {
            window.statisticsInitMap();
        } else {
            setTimeout(tryInitMap, 100); // Retry every 100ms
        }
    }

    tryInitMap();
}

// Wait for DOM and Chart.js to be ready
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart === 'undefined') {
        console.error('Chart.js failed to load');
        return;
    }


    // Wait a moment for statistics.js to fully load before loading Google Maps
    setTimeout(() => {
        // Load Google Maps API dynamically with proper loading parameter
        const mapsScript = document.createElement('script');
        mapsScript.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent('<?= $settings->elan_google_maps_key ?? '' ?>')}&callback=initMap&libraries=geometry,places&loading=async`;
        mapsScript.async = true;
        mapsScript.defer = true;
        document.head.appendChild(mapsScript);
    }, 500); // Give statistics.js 500ms to fully load
});
</script>