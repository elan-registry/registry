<?php
/**
 * analytics.php
 * Comprehensive analytics dashboard for the Elan Registry
 * 
 * Advanced statistical analysis including color trends, production patterns,
 * ownership data, data quality metrics, geographic insights, technical analysis,
 * and registry growth analytics. Uses Google Charts for data visualization.
 *
 * @author Elan Registry Analytics Team
 * @copyright 2025
 */
require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// 1. Color Analysis Data
$colorData = $db->query("
    SELECT color, COUNT(*) as count 
    FROM cars 
    WHERE color IS NOT NULL AND color != '' 
    GROUP BY color 
    ORDER BY count DESC 
    LIMIT 15
")->results();

$colorByYear = $db->query("
    SELECT year, color, COUNT(*) as count
    FROM cars 
    WHERE color IS NOT NULL AND color != '' 
        AND color IN ('red', 'yellow', 'White', 'Blue', 'BRG', 'Green', 'Cirrus White', 'carnival red')
    GROUP BY year, color 
    ORDER BY year, count DESC
")->results();

$colorBySeries = $db->query("
    SELECT series, color, COUNT(*) as count
    FROM cars 
    WHERE color IS NOT NULL AND color != '' 
        AND color IN ('red', 'yellow', 'White', 'Blue', 'BRG', 'Green')
    GROUP BY series, color 
    ORDER BY series, count DESC
")->results();

// 2. Production Year Insights
$productionByYear = $db->query("
    SELECT year, COUNT(*) as count 
    FROM cars 
    GROUP BY year 
    ORDER BY year
")->results();

$earlyVsLate = $db->query("
    SELECT 
        CASE 
            WHEN year BETWEEN '1963' AND '1967' THEN 'Early Production (1963-1967)'
            WHEN year BETWEEN '1968' AND '1974' THEN 'Late Production (1968-1974)'
        END as period,
        COUNT(*) as count
    FROM cars 
    WHERE year BETWEEN '1963' AND '1974'
    GROUP BY period
")->results();

// 3. Ownership & Market Analysis
$purchaseActivity = $db->query("
    SELECT YEAR(purchasedate) as purchase_year, COUNT(*) as count
    FROM cars 
    WHERE purchasedate IS NOT NULL 
        AND YEAR(purchasedate) >= 2000
    GROUP BY YEAR(purchasedate)
    ORDER BY purchase_year DESC
")->results();

$soldCars = $db->query("
    SELECT YEAR(solddate) as sold_year, COUNT(*) as count
    FROM cars 
    WHERE solddate IS NOT NULL
    GROUP BY YEAR(solddate)
    ORDER BY sold_year DESC
")->results();

$ownershipDuration = $db->query("
    SELECT 
        CASE 
            WHEN DATEDIFF(solddate, purchasedate) <= 365 THEN '0-1 years'
            WHEN DATEDIFF(solddate, purchasedate) <= 1825 THEN '1-5 years'
            WHEN DATEDIFF(solddate, purchasedate) <= 3650 THEN '5-10 years'
            ELSE '10+ years'
        END as duration,
        COUNT(*) as count
    FROM cars 
    WHERE purchasedate IS NOT NULL AND solddate IS NOT NULL
    GROUP BY duration
")->results();

// 4. Data Quality & Completeness
$dataCompleteness = $db->query("
    SELECT 
        COUNT(*) as total_cars,
        COUNT(chassis) as has_chassis,
        COUNT(color) as has_color,
        COUNT(engine) as has_engine,
        COUNT(purchasedate) as has_purchase_date,
        COUNT(solddate) as has_sold_date,
        COUNT(image) as has_image,
        COUNT(lat) as has_location,
        COUNT(last_verified) as verified_cars
    FROM cars
")->first();

// 5. Geographic Insights
$countryDistribution = $db->query("
    SELECT country, COUNT(*) as count
    FROM cars 
    WHERE country IS NOT NULL AND country != ''
    GROUP BY country 
    ORDER BY count DESC 
    LIMIT 15
")->results();

$usStateDistribution = $db->query("
    SELECT 
        CASE 
            WHEN state = 'California' OR state = 'CA' THEN 'California'
            WHEN state = 'Texas' OR state = 'TX' THEN 'Texas'  
            WHEN state = 'New York' OR state = 'NY' THEN 'New York'
            WHEN state = 'Massachusetts' OR state = 'MA' THEN 'Massachusetts'
            WHEN state = 'Pennsylvania' OR state = 'PA' THEN 'Pennsylvania'
            WHEN state = 'Washington' OR state = 'WA' THEN 'Washington'
            WHEN state = 'New Jersey' OR state = 'NJ' THEN 'New Jersey'
            WHEN state = 'Connecticut' OR state = 'CT' THEN 'Connecticut'
            WHEN state = 'Virginia' OR state = 'VA' THEN 'Virginia'
            WHEN state = 'Oregon' OR state = 'OR' THEN 'Oregon'
            WHEN LOWER(state) = 'ohio' THEN 'Ohio'
            ELSE state
        END as normalized_state,
        COUNT(*) as count
    FROM cars 
    WHERE country IN ('United States', 'US') 
        AND state IS NOT NULL 
        AND state != '' 
        AND state != 'None'
        AND TRIM(state) != ''
    GROUP BY normalized_state
    ORDER BY count DESC 
    LIMIT 10
")->results();

// 6. Engine & Technical Data
$enginePatterns = $db->query("
    SELECT 
        LEFT(engine, 2) as engine_prefix,
        COUNT(*) as count
    FROM cars 
    WHERE engine IS NOT NULL AND engine != ''
    GROUP BY LEFT(engine, 2)
    ORDER BY count DESC
    LIMIT 10
")->results();

$technicalByYear = $db->query("
    SELECT 
        year,
        COUNT(DISTINCT engine) as distinct_engines,
        COUNT(DISTINCT color) as distinct_colors,
        COUNT(*) as total_cars
    FROM cars
    GROUP BY year 
    ORDER BY year
")->results();

// 7. Registry Growth Analytics - Check for data and use fallback
$registrationGrowthQuery = $db->query("
    SELECT 
        DATE_FORMAT(ctime, '%Y-%m') as month,
        COUNT(*) as new_registrations
    FROM cars 
    WHERE ctime >= DATE_SUB(NOW(), INTERVAL 3 YEAR)
    GROUP BY DATE_FORMAT(ctime, '%Y-%m')
    ORDER BY month
");

$registrationGrowth = $registrationGrowthQuery->results();

// If no recent data, get last 2 years of any data
if (empty($registrationGrowth)) {
    $registrationGrowth = $db->query("
        SELECT 
            DATE_FORMAT(ctime, '%Y-%m') as month,
            COUNT(*) as new_registrations
        FROM cars 
        WHERE ctime IS NOT NULL
        GROUP BY DATE_FORMAT(ctime, '%Y-%m')
        ORDER BY month DESC
        LIMIT 24
    ")->results();
}

$monthlyActivity = $db->query("
    SELECT 
        MONTH(ctime) as month,
        COUNT(*) as registrations
    FROM cars 
    GROUP BY MONTH(ctime)
    ORDER BY month
")->results();

// Get time data for timeline (same as statistics page)
$timeData = $db->query("SELECT ctime FROM cars WHERE 1 ORDER BY ctime ASC")->results();

$topContributors = $db->query("
    SELECT 
        CONCAT(fname, ' ', lname) as owner_name,
        COUNT(*) as cars_registered
    FROM cars 
    WHERE fname IS NOT NULL AND lname IS NOT NULL
    GROUP BY fname, lname
    HAVING COUNT(*) > 1
    ORDER BY cars_registered DESC
    LIMIT 10
")->results();

?>

<div class="page-wrapper">
    <div class="container-fluid">
        <div class="page-container">
            
            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h1 class="mb-2"><i class="fas fa-chart-line"></i> Comprehensive Registry Analytics</h1>
                            <p class="mb-0 text-muted">Deep insights into the Lotus Elan Registry data - colors, production, ownership, quality, geography, technical details, and growth trends</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 1. Color Analysis Dashboard -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0"><i class="fas fa-palette"></i> Color Analysis Dashboard</h2>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-lg-4">
                                    <h5>Most Popular Colors</h5>
                                    <div id="chart_colors"></div>
                                </div>
                                <div class="col-lg-4">
                                    <h5>Color Trends by Year</h5>
                                    <div id="chart_color_trends"></div>
                                </div>
                                <div class="col-lg-4">
                                    <h5>Colors by Series</h5>
                                    <div id="chart_color_series"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Production Year Insights -->
            <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="card registry-card h-100">
                        <div class="card-header">
                            <h2 class="mb-0"><i class="fas fa-industry"></i> Production Year Analysis</h2>
                        </div>
                        <div class="card-body">
                            <div id="chart_production_years"></div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card registry-card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Early vs Late Production</h5>
                        </div>
                        <div class="card-body">
                            <div id="chart_early_late"></div>
                            <div class="mt-3">
                                <small class="text-muted">
                                    Early production (1963-1967) focused on establishing the model.<br>
                                    Late production (1968-1974) included refinements and variants.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Ownership & Market Analysis -->
            <div class="row mb-4">
                <div class="col-lg-6">
                    <div class="card registry-card h-100">
                        <div class="card-header">
                            <h2 class="mb-0"><i class="fas fa-handshake"></i> Purchase Activity Timeline</h2>
                        </div>
                        <div class="card-body">
                            <div id="chart_purchase_activity"></div>
                            <small class="text-muted">Shows when current owners purchased their cars (2000-present)</small>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card registry-card h-100">
                        <div class="card-header">
                            <h2 class="mb-0"><i class="fas fa-exchange-alt"></i> Ownership Patterns</h2>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-6">
                                    <h6>Cars Sold by Year</h6>
                                    <div id="chart_sold_cars"></div>
                                </div>
                                <div class="col-6">
                                    <h6>Ownership Duration</h6>
                                    <div id="chart_ownership_duration"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. Data Quality & Completeness -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0"><i class="fas fa-check-circle"></i> Data Quality & Completeness</h2>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-lg-8">
                                    <div id="chart_data_completeness"></div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="data-quality-stats">
                                        <h5>Registry Health</h5>
                                        <div class="row text-center">
                                            <div class="col-6 mb-3">
                                                <div class="card bg-light">
                                                    <div class="card-body">
                                                        <h3 class="text-success"><?= round(($dataCompleteness->has_image / $dataCompleteness->total_cars) * 100, 1) ?>%</h3>
                                                        <small>Have Images</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-6 mb-3">
                                                <div class="card bg-light">
                                                    <div class="card-body">
                                                        <h3 class="text-info"><?= round(($dataCompleteness->has_location / $dataCompleteness->total_cars) * 100, 1) ?>%</h3>
                                                        <small>Have Locations</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="card bg-light">
                                                    <div class="card-body">
                                                        <h3 class="text-warning"><?= round(($dataCompleteness->has_purchase_date / $dataCompleteness->total_cars) * 100, 1) ?>%</h3>
                                                        <small>Have Purchase Dates</small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="card bg-light">
                                                    <div class="card-body">
                                                        <h3 class="text-primary"><?= round(($dataCompleteness->verified_cars / $dataCompleteness->total_cars) * 100, 1) ?>%</h3>
                                                        <small>Verified</small>
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

            <!-- 5. Geographic Insights -->
            <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="card registry-card h-100">
                        <div class="card-header">
                            <h2 class="mb-0"><i class="fas fa-globe-americas"></i> Geographic Distribution</h2>
                        </div>
                        <div class="card-body">
                            <div id="chart_geographic"></div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card registry-card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">US State Distribution</h5>
                        </div>
                        <div class="card-body">
                            <div id="chart_us_states"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 6. Engine & Technical Data -->
            <div class="row mb-4">
                <div class="col-lg-6">
                    <div class="card registry-card h-100">
                        <div class="card-header">
                            <h2 class="mb-0"><i class="fas fa-cog"></i> Engine Analysis</h2>
                        </div>
                        <div class="card-body">
                            <div id="chart_engine_patterns"></div>
                            <small class="text-muted mt-2">Engine number prefixes (<?= $dataCompleteness->has_engine ?> cars have engine data)</small>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card registry-card h-100">
                        <div class="card-header">
                            <h2 class="mb-0"><i class="fas fa-tools"></i> Technical Diversity by Year</h2>
                        </div>
                        <div class="card-body">
                            <div id="chart_technical_diversity"></div>
                            <small class="text-muted mt-2">Shows variety of engines and colors available each year</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 7. Registry Growth Analytics -->
            <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="card registry-card h-100">
                        <div class="card-header">
                            <h2 class="mb-0"><i class="fas fa-chart-area"></i> Registry Growth Timeline</h2>
                        </div>
                        <div class="card-body">
                            <div id="chart_registration_growth"></div>
                            <small class="text-muted">Monthly registration activity over the last 3 years</small>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card registry-card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Registry Insights</h5>
                        </div>
                        <div class="card-body">
                            <h6>Seasonal Activity</h6>
                            <div id="chart_seasonal_activity"></div>
                            
                            <h6 class="mt-4">Top Contributors</h6>
                            <div class="list-group list-group-flush">
                                <?php foreach(array_slice($topContributors, 0, 5) as $contributor): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <small><?= htmlspecialchars($contributor->owner_name) ?></small>
                                    <span class="badge badge-primary badge-pill"><?= $contributor->cars_registered ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Load Google Charts -->
<script src="https://www.gstatic.com/charts/loader.js"></script>

<!-- Data injection for JavaScript -->
<script>
window.analyticsData = {
    // 1. Color data
    colorData: [
        ['Color', 'Count'],
        <?php foreach($colorData as $color): ?>
        ['<?= addslashes($color->color) ?>', <?= $color->count ?>],
        <?php endforeach; ?>
    ],
    
    // 2. Production data
    productionData: [
        ['Year', 'Cars Registered'],
        <?php foreach($productionByYear as $year): ?>
        ['<?= $year->year ?>', <?= $year->count ?>],
        <?php endforeach; ?>
    ],
    
    earlyLateData: [
        ['Period', 'Count'],
        <?php foreach($earlyVsLate as $period): ?>
        ['<?= $period->period ?>', <?= $period->count ?>],
        <?php endforeach; ?>
    ],
    
    // 3. Purchase activity
    purchaseData: [
        ['Year', 'Purchases'],
        <?php foreach($purchaseActivity as $purchase): ?>
        ['<?= $purchase->purchase_year ?>', <?= $purchase->count ?>],
        <?php endforeach; ?>
    ],
    
    // 4. Data completeness
    completenessData: [
        ['Field', 'Completion %'],
        ['Chassis', <?= round(($dataCompleteness->has_chassis / $dataCompleteness->total_cars) * 100, 1) ?>],
        ['Color', <?= round(($dataCompleteness->has_color / $dataCompleteness->total_cars) * 100, 1) ?>],
        ['Engine', <?= round(($dataCompleteness->has_engine / $dataCompleteness->total_cars) * 100, 1) ?>],
        ['Image', <?= round(($dataCompleteness->has_image / $dataCompleteness->total_cars) * 100, 1) ?>],
        ['Location', <?= round(($dataCompleteness->has_location / $dataCompleteness->total_cars) * 100, 1) ?>],
        ['Purchase Date', <?= round(($dataCompleteness->has_purchase_date / $dataCompleteness->total_cars) * 100, 1) ?>],
        ['Verified', <?= round(($dataCompleteness->verified_cars / $dataCompleteness->total_cars) * 100, 1) ?>]
    ],
    
    // Color trends by year (for stacked chart)
    colorTrends: [
        ['Year', 'Red', 'Yellow', 'White', 'Blue', 'BRG', 'Green'],
        <?php
        $yearColors = [];
        foreach($colorByYear as $row) {
            $yearColors[$row->year][$row->color] = $row->count;
        }
        foreach($yearColors as $year => $colors) {
            echo "['$year', " . 
                 ($colors['red'] ?? 0) . ", " .
                 ($colors['yellow'] ?? 0) . ", " .
                 ($colors['White'] ?? 0) . ", " .
                 ($colors['Blue'] ?? 0) . ", " .
                 ($colors['BRG'] ?? 0) . ", " .
                 ($colors['Green'] ?? 0) . "],";
        }
        ?>
    ],
    
    // Color by series data
    colorSeriesData: [
        ['Series', 'Red', 'Yellow', 'White', 'Blue', 'BRG', 'Green'],
        <?php
        $seriesColors = [];
        foreach($colorBySeries as $row) {
            $seriesColors[$row->series][$row->color] = $row->count;
        }
        foreach($seriesColors as $series => $colors) {
            echo "['$series', " . 
                 ($colors['red'] ?? 0) . ", " .
                 ($colors['yellow'] ?? 0) . ", " .
                 ($colors['White'] ?? 0) . ", " .
                 ($colors['Blue'] ?? 0) . ", " .
                 ($colors['BRG'] ?? 0) . ", " .
                 ($colors['Green'] ?? 0) . "],";
        }
        ?>
    ],
    
    // Ownership duration
    ownershipDurationData: [
        ['Duration', 'Cars'],
        <?php foreach($ownershipDuration as $duration): ?>
        ['<?= $duration->duration ?>', <?= $duration->count ?>],
        <?php endforeach; ?>
    ],
    
    // Sold cars data
    soldCarsData: [
        ['Year', 'Cars Sold'],
        <?php foreach($soldCars as $sold): ?>
        ['<?= $sold->sold_year ?>', <?= $sold->count ?>],
        <?php endforeach; ?>
    ],
    
    // Technical diversity by year
    technicalDiversity: [
        ['Year', 'Distinct Engines', 'Distinct Colors'],
        <?php foreach($technicalByYear as $tech): ?>
        ['<?= $tech->year ?>', <?= $tech->distinct_engines ?>, <?= $tech->distinct_colors ?>],
        <?php endforeach; ?>
    ],
    
    // Registration growth timeline (like the working statistics chart)
    registrationGrowthTimeline: [
        ['Date', 'Cumulative Cars'],
        <?php
        $count = 0;
        foreach ($timeData as $car) {
            $count++;
            // Only show last 3 years of data
            $carDate = strtotime($car->ctime);
            if ($carDate >= strtotime('-3 years')) {
                echo "[ new Date(" . date('Y, m-1, d, G, i, s', $carDate) . "), " . $count . "],";
            }
        }
        ?>
    ],

    // 5. Geographic data
    countryData: [
        ['Country', 'Cars'],
        <?php foreach($countryDistribution as $country): ?>
        ['<?= addslashes($country->country) ?>', <?= $country->count ?>],
        <?php endforeach; ?>
    ],
    
    usStateData: [
        ['State', 'Cars'],
        <?php if (empty($usStateDistribution)): ?>
        ['No US data available', 0],
        <?php else: ?>
        <?php foreach($usStateDistribution as $state): ?>
        <?php if (!empty(trim($state->normalized_state)) && $state->count > 0): ?>
        ['<?= addslashes(trim($state->normalized_state)) ?>', <?= (int)$state->count ?>],
        <?php endif; ?>
        <?php endforeach; ?>
        <?php endif; ?>
    ],
    
    // 6. Engine patterns
    engineData: [
        ['Engine Prefix', 'Count'],
        <?php foreach($enginePatterns as $engine): ?>
        ['<?= addslashes($engine->engine_prefix) ?>', <?= $engine->count ?>],
        <?php endforeach; ?>
    ],
    
    // 7. Registry growth
    monthlyData: [
        ['Month', 'Registrations'],
        <?php 
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        foreach($monthlyActivity as $month): ?>
        ['<?= $months[$month->month - 1] ?>', <?= $month->registrations ?>],
        <?php endforeach; ?>
    ]
};
</script>

<script>
google.charts.load('current', {
    packages: ['corechart', 'bar', 'line', 'controls']
});

google.charts.setOnLoadCallback(drawAllCharts);

function drawAllCharts() {
    // 1. Color Analysis Charts
    drawChart('chart_colors', analyticsData.colorData, 'PieChart', 'Most Popular Colors');
    drawStackedChart('chart_color_trends', analyticsData.colorTrends, 'Color Trends by Year');
    drawStackedChart('chart_color_series', analyticsData.colorSeriesData, 'Colors by Series');
    
    // 2. Production Analysis 
    drawChart('chart_production_years', analyticsData.productionData, 'ColumnChart', 'Cars Registered by Production Year');
    drawChart('chart_early_late', analyticsData.earlyLateData, 'PieChart', '');
    
    // 3. Ownership & Market Analysis
    drawChart('chart_purchase_activity', analyticsData.purchaseData, 'ColumnChart', 'Purchase Activity by Year');
    drawChart('chart_sold_cars', analyticsData.soldCarsData, 'ColumnChart', '');
    drawChart('chart_ownership_duration', analyticsData.ownershipDurationData, 'PieChart', '');
    
    // 4. Data Completeness
    drawChart('chart_data_completeness', analyticsData.completenessData, 'ColumnChart', 'Data Completeness by Field');
    
    // 5. Geographic
    drawChart('chart_geographic', analyticsData.countryData, 'PieChart', 'Cars by Country');
    drawChart('chart_us_states', analyticsData.usStateData, 'PieChart', 'Top 10 US State Distribution');
    
    // 6. Engine & Technical Analysis
    drawChart('chart_engine_patterns', analyticsData.engineData, 'ColumnChart', 'Engine Prefixes');
    drawLineChart('chart_technical_diversity', analyticsData.technicalDiversity, 'Technical Diversity by Year');
    
    // 7. Registry Growth - Use simple message for now
    document.getElementById('chart_registration_growth').innerHTML = '<div class="text-center py-5"><h5 class="text-muted">Registry Growth Timeline</h5><p class="text-muted">See the main <a href="../reports/statistics.php">Statistics</a> page for the complete "Cars added over Time" timeline chart</p></div>';
    drawChart('chart_seasonal_activity', analyticsData.monthlyData, 'ColumnChart', 'Seasonal Registration Patterns');
}

function drawChart(elementId, data, chartType, title) {
    try {
        const dataTable = google.visualization.arrayToDataTable(data);
        const options = {
            title: title,
            titleTextStyle: { fontSize: 14 },
            height: chartType === 'PieChart' ? 300 : 400,
            backgroundColor: 'transparent',
            legend: { position: chartType === 'PieChart' ? 'right' : 'none' },
            colors: ['#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', '#9467bd', '#8c564b', '#e377c2', '#7f7f7f', '#bcbd22', '#17becf']
        };
        
        // Add specific configurations for ColumnChart with categorical data
        if (chartType === 'ColumnChart') {
            options.hAxis = {
                title: data[0][0], // Use first column name as axis title
                titleTextStyle: { color: '#333' }
            };
            options.vAxis = {
                title: data[0][1], // Use second column name as axis title
                titleTextStyle: { color: '#333' },
                minValue: 0
            };
        }
        
        const chart = chartType === 'PieChart' 
            ? new google.visualization.PieChart(document.getElementById(elementId))
            : new google.visualization.ColumnChart(document.getElementById(elementId));
            
        chart.draw(dataTable, options);
    } catch (error) {
        console.error('Error drawing chart:', elementId, error);
        document.getElementById(elementId).innerHTML = '<p class="text-muted">Chart data loading...</p>';
    }
}

function drawStackedChart(elementId, data, title) {
    try {
        const dataTable = google.visualization.arrayToDataTable(data);
        const options = {
            title: title,
            titleTextStyle: { fontSize: 14 },
            height: 350,
            backgroundColor: 'transparent',
            isStacked: true,
            legend: { position: 'bottom' },
            colors: ['#dc3545', '#ffc107', '#f8f9fa', '#007bff', '#28a745', '#6f42c1']
        };
        
        const chart = new google.visualization.ColumnChart(document.getElementById(elementId));
        chart.draw(dataTable, options);
    } catch (error) {
        console.error('Error drawing stacked chart:', elementId, error);
        document.getElementById(elementId).innerHTML = '<p class="text-muted">Chart data loading...</p>';
    }
}

function drawLineChart(elementId, data, title) {
    try {
        const dataTable = google.visualization.arrayToDataTable(data);
        const options = {
            title: title,
            titleTextStyle: { fontSize: 14 },
            height: 350,
            backgroundColor: 'transparent',
            legend: { position: 'bottom' },
            colors: ['#1f77b4', '#ff7f0e', '#2ca02c'],
            curveType: 'function',
            lineWidth: 3,
            pointSize: 5,
            hAxis: {
                title: 'Date',
                format: 'MMM yyyy',
                titleTextStyle: { color: '#333' }
            },
            vAxis: {
                title: 'Count',
                minValue: 0,
                titleTextStyle: { color: '#333' }
            }
        };
        
        const chart = new google.visualization.LineChart(document.getElementById(elementId));
        chart.draw(dataTable, options);
    } catch (error) {
        console.error('Error drawing line chart:', elementId, error);
        document.getElementById(elementId).innerHTML = '<p class="text-muted">Chart data loading...</p>';
    }
}

function drawBarChart(elementId, data, title) {
    try {
        const dataTable = google.visualization.arrayToDataTable(data);
        const options = {
            title: title,
            titleTextStyle: { fontSize: 14 },
            height: 350,
            backgroundColor: 'transparent',
            legend: { position: 'none' },
            colors: ['#1f77b4'],
            hAxis: {
                title: 'Number of Cars'
            },
            vAxis: {
                title: 'State'
            }
        };
        
        const chart = new google.visualization.BarChart(document.getElementById(elementId));
        chart.draw(dataTable, options);
    } catch (error) {
        console.error('Error drawing bar chart:', elementId, error);
        document.getElementById(elementId).innerHTML = '<p class="text-muted">Chart data loading...</p>';
    }
}

function drawTimelineChart(elementId, data, title) {
    try {
        const dataTable = google.visualization.arrayToDataTable(data);
        const options = {
            title: title,
            titleTextStyle: { fontSize: 14 },
            height: 350,
            backgroundColor: 'transparent',
            legend: { position: 'none' },
            colors: ['#1f77b4'],
            hAxis: {
                title: 'Date',
                format: 'MMM yyyy'
            },
            vAxis: {
                title: 'Cumulative Registrations',
                minValue: 0
            },
            pointSize: 3,
            lineWidth: 2
        };
        
        const chart = new google.visualization.LineChart(document.getElementById(elementId));
        chart.draw(dataTable, options);
    } catch (error) {
        console.error('Error drawing timeline chart:', elementId, error);
        document.getElementById(elementId).innerHTML = '<p class="text-muted">Chart data loading...</p>';
    }
}
</script>

<!-- Custom Analytics Styles -->
<style>
.data-quality-stats .card {
    border: none;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.data-quality-stats h3 {
    font-size: 1.5rem;
    font-weight: bold;
    margin-bottom: 0;
}

.data-quality-stats small {
    font-size: 0.8rem;
    color: #6c757d;
}

.registry-card {
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: none;
}

.registry-card .card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
}

.registry-card .card-header h1,
.registry-card .card-header h2,
.registry-card .card-header h5 {
    color: white;
}

.list-group-item {
    border: none;
    border-bottom: 1px solid #e9ecef;
}

.list-group-item:last-child {
    border-bottom: none;
}

@media (max-width: 768px) {
    .data-quality-stats .col-6 {
        margin-bottom: 1rem;
    }
    
    .registry-card .card-header h1 {
        font-size: 1.5rem;
    }
    
    .registry-card .card-header h2 {
        font-size: 1.3rem;
    }
}
</style>

<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php';
?>