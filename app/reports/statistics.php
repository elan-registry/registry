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
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';
require_once $abs_us_root . $us_url_root . 'usersc/classes/StatisticsDataService.php';

if (!securePage($php_self)) {
    die();
}

// Initialize data service
$dataService = new StatisticsDataService($db);

// Build inlined map marker data — targeted query, deterministic jitter
$db->query(
    "SELECT id, year, series, chassis, variant, image, type, city, state, country, fname AS owner, lat, lon
     FROM cars WHERE lat != 0 AND lon != 0"
);
if ($db->error()) {
    logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Map marker query failed: ' . $db->errorString());
}
$markerRows = $db->results();

$mapMarkers = [];
foreach ($markerRows as $car) {
    $images = [];
    if (!empty($car->image)) {
        $decoded = json_decode($car->image, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $images = $decoded;
        } else {
            $images = explode(',', $car->image);
        }
    }
    $mapMarkers[] = [
        'id'      => (int)$car->id,
        'name'    => trim(($car->year ?? '') . '-' . ($car->series ?? '') . '-' . ($car->chassis ?? '')),
        'series'  => (string)($car->series ?? ''),
        'variant' => (string)($car->variant ?? ''),
        'image'   => !empty($images) ? ((int)$car->id . '/' . $images[0]) : '',
        'city'    => (string)($car->city ?? ''),
        'state'   => (string)($car->state ?? ''),
        'country' => (string)($car->country ?? ''),
        'owner'   => trim((string)($car->owner ?? '')),
        'lat'     => (float)$car->lat + sin((int)$car->id) * 0.01,
        'lon'     => (float)$car->lon + cos((int)$car->id) * 0.01,
    ];
}
?>

<style>
.chart-container { height: 400px; }
@media (max-width: 575.98px) { .chart-container { height: 250px; } }
.elan-legend-dot {
    display: inline-block; width: 12px; height: 12px;
    border-radius: 50%; margin-right: 3px; vertical-align: middle;
    border: 1px solid rgba(0,0,0,0.2);
}
.elan-marker { width:18px; height:18px; border-radius:50% 50% 50% 0; border:2px solid rgba(0,0,0,0.4); transform:rotate(-45deg); cursor:pointer; }
.elan-marker-wrapper { width:22px; height:22px; display:flex; align-items:center; justify-content:center; transform:rotate(45deg); }
.elan-marker.s1     { background:#e53e3e; }
.elan-marker.s2     { background:#3182ce; }
.elan-marker.s3     { background:#d69e2e; }
.elan-marker.s4     { background:#e2e8f0; border-color:rgba(0,0,0,0.5); }
.elan-marker.sprint  { background:#805ad5; }
.elan-marker.plus2   { background:#38a169; }
.elan-marker.elan1500{ background:#dd6b20; }
.elan-marker.r26     { background:#0bc5ea; }
.elan-marker.unknown { background:#718096; }
</style>

<link rel="stylesheet" href="<?= $us_url_root ?>usersc/css/maplibre-gl.css">
<script src="<?= $us_url_root ?>usersc/js/maplibre-gl.min.js"></script>

<div class="page-wrapper">
    <div class="container-fluid">
        <div class="page-container">
            <?= \ElanRegistry\Documentation\DocumentPortalTemplate::renderBreadcrumb('statistics', $us_url_root) ?>
            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1 class="h2 mb-0">Registry Analytics & Statistics</h1>
                            <p class="text-muted">Comprehensive analysis of car registry data with interactive visualizations</p>
                        </div>
                        <div class="text-end">
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
                                    <a class="nav-link active" id="overview-tab" data-bs-toggle="tab" href="#overview" role="tab" aria-controls="overview" aria-selected="true">
                                        <i class="fas fa-tachometer-alt"></i> Overview
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="geographic-tab" data-bs-toggle="tab" href="#geographic" role="tab" aria-controls="geographic" aria-selected="false">
                                        <i class="fas fa-globe-americas"></i> Geographic
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="production-tab" data-bs-toggle="tab" href="#production" role="tab" aria-controls="production" aria-selected="false">
                                        <i class="fas fa-industry"></i> Production
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="colors-tab" data-bs-toggle="tab" href="#colors" role="tab" aria-controls="colors" aria-selected="false">
                                        <i class="fas fa-palette"></i> Colors
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="quality-tab" data-bs-toggle="tab" href="#quality" role="tab" aria-controls="quality" aria-selected="false">
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
                                                <div class="card-header card-header-er-primary">
                                                    <h4 class="mb-0 card-header-er-primary-text">Global Car Distribution</h4>
                                                </div>
                                                <div class="card-body text-center">
                                                    <div class="map-container">
                                                        <div id="map"></div>
                                                    </div>
                                                    <div class="mt-2 d-flex flex-wrap justify-content-center gap-3" id="map-series-filter">
                                                        <div class="form-check form-check-inline mb-0">
                                                            <input class="form-check-input" type="checkbox" id="filter-all" checked>
                                                            <label class="form-check-label small" for="filter-all">All</label>
                                                        </div>
                                                        <div class="form-check form-check-inline mb-0">
                                                            <input class="form-check-input" type="checkbox" id="filter-s1" data-series="s1" checked>
                                                            <label class="form-check-label small" for="filter-s1"><span class="elan-legend-dot" style="background:#e53e3e;"></span> S1</label>
                                                        </div>
                                                        <div class="form-check form-check-inline mb-0">
                                                            <input class="form-check-input" type="checkbox" id="filter-s2" data-series="s2" checked>
                                                            <label class="form-check-label small" for="filter-s2"><span class="elan-legend-dot" style="background:#3182ce;"></span> S2</label>
                                                        </div>
                                                        <div class="form-check form-check-inline mb-0">
                                                            <input class="form-check-input" type="checkbox" id="filter-s3" data-series="s3" checked>
                                                            <label class="form-check-label small" for="filter-s3"><span class="elan-legend-dot" style="background:#d69e2e;"></span> S3</label>
                                                        </div>
                                                        <div class="form-check form-check-inline mb-0">
                                                            <input class="form-check-input" type="checkbox" id="filter-s4" data-series="s4" checked>
                                                            <label class="form-check-label small" for="filter-s4"><span class="elan-legend-dot" style="background:#e2e8f0; border:1px solid rgba(0,0,0,.4);"></span> S4</label>
                                                        </div>
                                                        <div class="form-check form-check-inline mb-0">
                                                            <input class="form-check-input" type="checkbox" id="filter-sprint" data-series="sprint" checked>
                                                            <label class="form-check-label small" for="filter-sprint"><span class="elan-legend-dot" style="background:#805ad5;"></span> Sprint</label>
                                                        </div>
                                                        <div class="form-check form-check-inline mb-0">
                                                            <input class="form-check-input" type="checkbox" id="filter-plus2" data-series="plus2" checked>
                                                            <label class="form-check-label small" for="filter-plus2"><span class="elan-legend-dot" style="background:#38a169;"></span> +2</label>
                                                        </div>
                                                        <div class="form-check form-check-inline mb-0">
                                                            <input class="form-check-input" type="checkbox" id="filter-elan1500" data-series="elan1500" checked>
                                                            <label class="form-check-label small" for="filter-elan1500"><span class="elan-legend-dot" style="background:#dd6b20;"></span> Elan 1500</label>
                                                        </div>
                                                        <div class="form-check form-check-inline mb-0">
                                                            <input class="form-check-input" type="checkbox" id="filter-r26" data-series="r26" checked>
                                                            <label class="form-check-label small" for="filter-r26"><span class="elan-legend-dot" style="background:#0bc5ea;"></span> 26R</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Charts -->
                                    <div class="row">
                                        <div class="col-lg-6 mb-4">
                                            <div class="card">
                                                <div class="card-header card-header-er-primary">
                                                    <h5 class="mb-0 card-header-er-primary-text">Registry Growth</h5>
                                                </div>
                                                <div class="card-body chart-container">
                                                    <canvas id="timelineChart"></canvas>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-4">
                                            <div class="card">
                                                <div class="card-header card-header-er-primary">
                                                    <h5 class="mb-0 card-header-er-primary-text">Recent Registrations</h5>
                                                </div>
                                                <div class="card-body chart-container">
                                                    <canvas id="recentActivityChart"></canvas>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Key Metrics -->
                                    <div class="row mb-4">
                                        <div class="col-md-3 mb-3">
                                            <div class="er-stat-tile text-center">
                                                <i class="fas fa-car fa-2x mb-2"></i>
                                                <div class="er-stat-number" id="totalCars">-</div>
                                                <div class="er-stat-label">Total Cars Registered</div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="er-stat-tile text-center">
                                                <i class="fas fa-globe fa-2x mb-2"></i>
                                                <div class="er-stat-number" id="totalCountries">-</div>
                                                <div class="er-stat-label">Countries Represented</div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="er-stat-tile text-center">
                                                <i class="fas fa-palette fa-2x mb-2"></i>
                                                <div class="er-stat-number" id="totalColors">-</div>
                                                <div class="er-stat-label">Unique Colors</div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="er-stat-tile text-center">
                                                <i class="fas fa-calendar fa-2x mb-2"></i>
                                                <div class="er-stat-number" id="registrationGrowth">-</div>
                                                <div class="er-stat-label">New This Year</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Geographic Tab -->
                                <div class="tab-pane fade" id="geographic" role="tabpanel" aria-labelledby="geographic-tab">
                                    <div class="card registry-card">
                                        <div class="card-header card-header-er-primary d-flex justify-content-between align-items-center">
                                            <h4 class="mb-0 card-header-er-primary-text"><i class="fas fa-globe-americas me-2"></i>Geographic Distribution Analysis</h4>
                                            <div class="spinner-border spinner-border-sm text-white" role="status" id="geographic-spinner" style="display: none;">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                        </div>
                                        <div class="card-body" id="geographic-content">
                                            <!-- Content will be loaded dynamically -->
                                        </div>
                                    </div>
                                </div>

                                <!-- Production Tab -->
                                <div class="tab-pane fade" id="production" role="tabpanel" aria-labelledby="production-tab">
                                    <div class="card registry-card">
                                        <div class="card-header card-header-er-primary d-flex justify-content-between align-items-center">
                                            <h4 class="mb-0 card-header-er-primary-text"><i class="fas fa-industry me-2"></i>Production Analysis</h4>
                                            <div class="spinner-border spinner-border-sm text-white" role="status" id="production-spinner" style="display: none;">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                        </div>
                                        <div class="card-body" id="production-content">
                                            <!-- Content will be loaded dynamically -->
                                        </div>
                                    </div>
                                </div>

                                <!-- Colors Tab -->
                                <div class="tab-pane fade" id="colors" role="tabpanel" aria-labelledby="colors-tab">
                                    <div class="card registry-card">
                                        <div class="card-header card-header-er-primary d-flex justify-content-between align-items-center">
                                            <h4 class="mb-0 card-header-er-primary-text"><i class="fas fa-palette me-2"></i>Color Analysis</h4>
                                            <div class="spinner-border spinner-border-sm text-white" role="status" id="colors-spinner" style="display: none;">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                        </div>
                                        <div class="card-body" id="colors-content">
                                            <!-- Content will be loaded dynamically -->
                                        </div>
                                    </div>
                                </div>

                                <!-- Data Quality Tab -->
                                <div class="tab-pane fade" id="quality" role="tabpanel" aria-labelledby="quality-tab">
                                    <div class="card registry-card">
                                        <div class="card-header card-header-er-primary d-flex justify-content-between align-items-center">
                                            <h4 class="mb-0 card-header-er-primary-text"><i class="fas fa-check-circle me-2"></i>Data Quality &amp; Completeness</h4>
                                            <div class="spinner-border spinner-border-sm text-white" role="status" id="quality-spinner" style="display: none;">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                        </div>
                                        <div class="card-body" id="quality-content">
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
</div>

<!-- Data for JavaScript -->
<script>
window.statisticsRawData = {
    // Overview data - loaded immediately
    timeline: <?= json_encode($dataService->getTimelineData()) ?>,

    // Basic counts for overview cards
    countriesCount: <?= count($dataService->getCountryData()) ?>,
    colorsCount: <?= count($dataService->getColorData()) ?>
};

// Configuration
window.statisticsConfig = {
    baseUrl: '<?= $us_url_root ?>app/reports/',
    statisticsDataUrl: '<?= $us_url_root ?>app/api/shared/statistics.php',
    imageUrl: '<?= $us_url_root . $settings->elan_image_dir ?>',
    versatileStyleUrl: '<?= $us_url_root ?>usersc/js/versatiles-colorful.json'
};
</script>

<?php
try {
    $mapMarkersJson = json_encode($mapMarkers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Failed to encode map markers: ' . $e->getMessage());
    $mapMarkersJson = '[]';
}
?>
<script>
window.elanMapMarkers = <?= $mapMarkersJson ?>;
</script>

<!-- Load Chart.js -->
<script src="<?=$us_url_root?>usersc/js/chart.umd.min.js"></script>

<!-- Load Statistics JavaScript first -->
<script src="<?= $us_url_root ?>app/assets/js/statistics.min.js?v=<?= filemtime($abs_us_root . $us_url_root . 'app/assets/js/statistics.min.js') ?>"></script>

<!-- Initialize MapLibre and Statistics -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart === 'undefined') {
        console.error('Chart.js failed to load');
        return;
    }

    if (window.statisticsInitMap) {
        window.statisticsInitMap();
    }
});
</script>
<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>