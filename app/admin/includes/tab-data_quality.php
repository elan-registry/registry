<?php
declare(strict_types=1);
/**
 * tab-data_quality.php
 * Data Quality Dashboard Tab Content
 *
 * Phase 1C: Complete data quality reporting functionality integrated from data-quality.php
 * Provides comprehensive data quality analysis with actionable workflows
 */

// Import ChassisValidator for validation functionality
require_once '../../usersc/classes/ChassisValidator.php';

// Data Quality Reports Functions
function getDataQualityReports($db) {
    $reports = [];

    // Owner Quality Reports

    // Owner Report 1: Car Owners Missing Critical Information
    $ownersWithMissingInfoQ = $db->query("
        SELECT u.id, u.fname, u.lname, u.email, u.join_date, u.last_login,
               p.city, p.state, p.country, p.lat, p.lon,
               COUNT(cu.car_id) as car_count,
               CASE WHEN u.fname IS NULL OR u.fname = '' THEN 1 ELSE 0 END +
               CASE WHEN u.lname IS NULL OR u.lname = '' THEN 1 ELSE 0 END +
               CASE WHEN p.city IS NULL OR p.city = '' THEN 1 ELSE 0 END +
               CASE WHEN p.lat IS NULL OR p.lon IS NULL THEN 1 ELSE 0 END as missing_count,
               CONCAT_WS(', ',
                   CASE WHEN u.fname IS NULL OR u.fname = '' THEN 'First Name' ELSE NULL END,
                   CASE WHEN u.lname IS NULL OR u.lname = '' THEN 'Last Name' ELSE NULL END,
                   CASE WHEN p.city IS NULL OR p.city = '' THEN 'City' ELSE NULL END,
                   CASE WHEN p.lat IS NULL OR p.lon IS NULL THEN 'Coordinates' ELSE NULL END
               ) as missing_fields_list
        FROM users u
        JOIN car_user cu ON u.id = cu.userid
        LEFT JOIN profiles p ON u.id = p.user_id
        WHERE u.active = 1 AND (
            (u.fname IS NULL OR u.fname = '') OR
            (u.lname IS NULL OR u.lname = '') OR
            (p.city IS NULL OR p.city = '') OR
            (p.lat IS NULL OR p.lon IS NULL)
        )
        GROUP BY u.id, u.fname, u.lname, u.email, u.join_date, u.last_login, p.city, p.state, p.country, p.lat, p.lon
        ORDER BY missing_count DESC, car_count DESC, u.last_login DESC
    ");
    $reports['owners_missing_info'] = [
        'title' => 'Car Owners Missing Information',
        'description' => 'Active car owners with incomplete profile information affecting contact and location features',
        'icon' => 'fas fa-user-times',
        'severity' => 'warning',
        'count' => count($ownersWithMissingInfoQ->results()),
        'data' => $ownersWithMissingInfoQ->results(),
        'impact' => 'High - Affects owner contact, mapping, and user experience'
    ];

    // Owner Report 2: Inactive Users with Cars
    $inactiveOwnersQ = $db->query("
        SELECT u.id, u.fname, u.lname, u.email, u.join_date, u.last_login,
               p.city, p.state, p.country,
               COUNT(cu.car_id) as car_count,
               DATEDIFF(NOW(), u.last_login) as days_since_login
        FROM users u
        JOIN car_user cu ON u.id = cu.userid
        LEFT JOIN profiles p ON u.id = p.user_id
        WHERE u.active = 1 AND (
            u.last_login IS NULL OR
            u.last_login = '0000-00-00 00:00:00' OR
            u.last_login < DATE_SUB(NOW(), INTERVAL 2 YEAR)
        )
        GROUP BY u.id, u.fname, u.lname, u.email, u.join_date, u.last_login, p.city, p.state, p.country
        ORDER BY car_count DESC, u.join_date DESC
    ");
    $reports['inactive_owners'] = [
        'title' => 'Inactive Car Owners',
        'description' => 'Car owners who have not logged in for over 2 years or never logged in',
        'icon' => 'fas fa-user-clock',
        'severity' => 'info',
        'count' => count($inactiveOwnersQ->results()),
        'data' => $inactiveOwnersQ->results(),
        'impact' => 'Medium - May indicate abandoned accounts or need for contact'
    ];

    // Owner Report 3: Users Without Cars
    $usersWithoutCarsQ = $db->query("
        SELECT u.id, u.fname, u.lname, u.email, u.join_date, u.last_login,
               p.city, p.state, p.country,
               DATEDIFF(NOW(), u.join_date) as days_since_join,
               CASE WHEN u.last_login IS NULL OR u.last_login = '0000-00-00 00:00:00' THEN 'Never'
                    ELSE DATE_FORMAT(u.last_login, '%M %d, %Y') END as last_login_formatted
        FROM users u
        LEFT JOIN car_user cu ON u.id = cu.userid
        LEFT JOIN profiles p ON u.id = p.user_id
        WHERE u.active = 1 AND cu.userid IS NULL
        ORDER BY u.join_date DESC
        LIMIT 50
    ");
    $reports['users_without_cars'] = [
        'title' => 'Active Users Without Cars',
        'description' => 'Active users who have registered but never added a car to the registry',
        'icon' => 'fas fa-user-plus',
        'severity' => 'info',
        'count' => count($usersWithoutCarsQ->results()),
        'data' => $usersWithoutCarsQ->results(),
        'impact' => 'Low - Potential registry growth opportunity'
    ];

    // Owner Report 4: Duplicate Email Analysis
    $duplicateEmailsQ = $db->query("
        SELECT email, COUNT(*) as user_count,
               GROUP_CONCAT(CONCAT(fname, ' ', lname, ' (ID:', id, ')') SEPARATOR ', ') as users_list
        FROM users
        WHERE active = 1
        GROUP BY email
        HAVING COUNT(*) > 1
        ORDER BY user_count DESC
    ");
    $reports['duplicate_emails'] = [
        'title' => 'Duplicate Email Addresses',
        'description' => 'Email addresses associated with multiple active user accounts',
        'icon' => 'fas fa-envelope-duplicate',
        'severity' => 'warning',
        'count' => count($duplicateEmailsQ->results()),
        'data' => $duplicateEmailsQ->results(),
        'impact' => 'Medium - May indicate duplicate accounts or shared email usage'
    ];

    // Car Quality Reports

    // Report 1: Missing Chassis Numbers
    $missingChassisQ = $db->query("
        SELECT c.id, c.model, c.series, c.year, c.chassis, c.mtime,
               u.fname, u.lname, u.email
        FROM cars c
        LEFT JOIN car_user cu ON c.id = cu.car_id
        LEFT JOIN users u ON cu.userid = u.id
        WHERE c.chassis IS NULL OR c.chassis = ''
        ORDER BY c.mtime DESC, c.id
    ");
    $reports['missing_chassis'] = [
        'title' => 'Missing Chassis Numbers',
        'description' => 'Cars without chassis numbers cannot be properly identified, sorted, or verified for duplicates',
        'icon' => 'fas fa-exclamation-triangle',
        'severity' => 'warning',
        'count' => count($missingChassisQ->results()),
        'data' => $missingChassisQ->results(),
        'impact' => 'High - Affects identification, sorting, and duplicate detection'
    ];

    // Report 2: Invalid Chassis Numbers (using centralized validator)
    $invalidChassisData = [];
    $chassisCheckQ = $db->query("
        SELECT c.id, c.model, c.series, c.year, c.chassis, c.mtime,
               u.fname, u.lname, u.email
        FROM cars c
        LEFT JOIN car_user cu ON c.id = cu.car_id
        LEFT JOIN users u ON cu.userid = u.id
        WHERE c.chassis IS NOT NULL AND c.chassis != ''
          AND c.year IS NOT NULL AND c.year != 0
          AND c.model IS NOT NULL AND c.model != ''
        ORDER BY c.mtime DESC, c.id
    ");

    $validator = new ChassisValidator();
    foreach ($chassisCheckQ->results() as $car) {
        $result = $validator->validate($car->chassis, (int)$car->year, $car->model, false);
        if (!$result['valid']) {
            $car->validation_error = $result['error_reason'];
            $invalidChassisData[] = $car;
        }
    }

    $reports['invalid_chassis'] = [
        'title' => 'Invalid Chassis Numbers',
        'description' => 'Cars with chassis numbers that fail validation against Lotus numbering standards',
        'icon' => 'fas fa-times-circle',
        'severity' => 'danger',
        'count' => count($invalidChassisData),
        'data' => $invalidChassisData,
        'impact' => 'High - May indicate incorrect data entry or non-standard numbering'
    ];

    // Report 3: Invalid Model Data
    $invalidModelQ = $db->query("
        SELECT c.id, c.model, c.series, c.year, c.chassis, c.mtime,
               u.fname, u.lname, u.email
        FROM cars c
        LEFT JOIN car_user cu ON c.id = cu.car_id
        LEFT JOIN users u ON cu.userid = u.id
        WHERE c.model = '||' OR c.model LIKE '%test%' OR c.model LIKE '%placeholder%'
        ORDER BY c.mtime DESC, c.id
    ");
    $reports['invalid_model'] = [
        'title' => 'Invalid Model Data',
        'description' => 'Cars with placeholder, test, or malformed model information',
        'icon' => 'fas fa-times-circle',
        'severity' => 'danger',
        'count' => count($invalidModelQ->results()),
        'data' => $invalidModelQ->results(),
        'impact' => 'Critical - Prevents proper display and categorization'
    ];

    // Report 4: Missing Series Data
    $missingSeriesQ = $db->query("
        SELECT c.id, c.model, c.series, c.year, c.chassis, c.mtime,
               u.fname, u.lname, u.email
        FROM cars c
        LEFT JOIN car_user cu ON c.id = cu.car_id
        LEFT JOIN users u ON cu.userid = u.id
        WHERE c.series IS NULL OR c.series = ''
        ORDER BY c.mtime DESC, c.id
    ");
    $reports['missing_series'] = [
        'title' => 'Missing Series Information',
        'description' => 'Cars without series data cannot be properly categorized or filtered',
        'icon' => 'fas fa-question-circle',
        'severity' => 'info',
        'count' => count($missingSeriesQ->results()),
        'data' => $missingSeriesQ->results(),
        'impact' => 'Medium - Affects categorization and filtering'
    ];

    // Report 5: Multiple Critical Missing Fields
    $multipleMissingQ = $db->query("
        SELECT c.id, c.model, c.series, c.year, c.chassis, c.mtime,
               u.fname, u.lname, u.email,
               CASE WHEN c.series IS NULL OR c.series = '' THEN 1 ELSE 0 END +
               CASE WHEN c.chassis IS NULL OR c.chassis = '' THEN 1 ELSE 0 END +
               CASE WHEN c.model = '||' THEN 1 ELSE 0 END as missing_count
        FROM cars c
        LEFT JOIN car_user cu ON c.id = cu.car_id
        LEFT JOIN users u ON cu.userid = u.id
        HAVING missing_count >= 2
        ORDER BY missing_count DESC, c.mtime DESC, c.id
    ");
    $reports['multiple_missing'] = [
        'title' => 'Multiple Missing Fields',
        'description' => 'Cars with 2 or more critical missing fields require immediate attention',
        'icon' => 'fas fa-exclamation-circle',
        'severity' => 'danger',
        'count' => count($multipleMissingQ->results()),
        'data' => $multipleMissingQ->results(),
        'impact' => 'Critical - Severely impacts display and functionality'
    ];

    return $reports;
}

$dataQualityReports = getDataQualityReports($db);

// Calculate overall statistics
$totalIssues = 0;
$ownerIssues = 0;
$carIssues = 0;
$criticalIssues = 0;
$warningIssues = 0;
foreach ($dataQualityReports as $key => $report) {
    if ($key !== 'users_without_cars') { // Don't count these as critical "issues"
        $totalIssues += $report['count'];
        // Categorize issues
        if (in_array($key, ['owners_missing_info', 'inactive_owners', 'duplicate_emails'])) {
            $ownerIssues += $report['count'];
        } else {
            $carIssues += $report['count'];
        }
        // Severity counts
        if ($report['severity'] === 'danger') {
            $criticalIssues += $report['count'];
        } elseif ($report['severity'] === 'warning') {
            $warningIssues += $report['count'];
        }
    }
}

// Calculate quality health score (higher is better)
$totalRecords = $systemStatus['total_cars'] + $systemStatus['total_users'];
$qualityScore = $totalRecords > 0 ? max(0, 100 - (($totalIssues / $totalRecords) * 100)) : 100;

?>

<!-- Data Quality Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="text-primary mb-1">
            <i class="fas fa-chart-line"></i> Data Quality Reports
        </h2>
        <p class="text-muted mb-0">Monitor and improve registry data quality</p>
    </div>
    <div>
        <a href="<?= $us_url_root ?>docs/admin/data-quality-guide.php" class="btn btn-outline-info" target="_blank" title="Data Quality Administrator Guide">
            <i class="fas fa-question-circle"></i> Help Guide
        </a>
    </div>
</div>

<!-- Quality Metrics Dashboard -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-<?= $qualityScore >= 80 ? 'success' : ($qualityScore >= 60 ? 'warning' : 'danger') ?>">
            <div class="card-body text-center">
                <div class="text-<?= $qualityScore >= 80 ? 'success' : ($qualityScore >= 60 ? 'warning' : 'danger') ?> mb-3">
                    <i class="fas fa-<?= $qualityScore >= 80 ? 'check-circle' : ($qualityScore >= 60 ? 'exclamation-triangle' : 'times-circle') ?>" style="font-size: 2.5rem;"></i>
                </div>
                <h5>Data Health</h5>
                <h3 class="text-<?= $qualityScore >= 80 ? 'success' : ($qualityScore >= 60 ? 'warning' : 'danger') ?> mb-2"><?= number_format($qualityScore, 1) ?>%</h3>
                <p class="small text-muted">Overall quality score</p>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-warning">
            <div class="card-body text-center">
                <div class="text-warning mb-3">
                    <i class="fas fa-exclamation-triangle" style="font-size: 2.5rem;"></i>
                </div>
                <h5>Warning Issues</h5>
                <h3 class="text-warning mb-2"><?= number_format($warningIssues) ?></h3>
                <p class="small text-muted">Records needing attention</p>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-danger">
            <div class="card-body text-center">
                <div class="text-danger mb-3">
                    <i class="fas fa-times-circle" style="font-size: 2.5rem;"></i>
                </div>
                <h5>Critical Issues</h5>
                <h3 class="text-danger mb-2"><?= number_format($criticalIssues) ?></h3>
                <p class="small text-muted">Require immediate action</p>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-info">
            <div class="card-body text-center">
                <div class="text-info mb-3">
                    <i class="fas fa-chart-line" style="font-size: 2.5rem;"></i>
                </div>
                <h5>Total Issues</h5>
                <h3 class="text-info mb-2"><?= number_format($totalIssues) ?></h3>
                <p class="small text-muted"><?= number_format($ownerIssues) ?> owner, <?= number_format($carIssues) ?> car</p>
            </div>
        </div>
    </div>
</div>

<!-- Action Recommendations -->
<?php if ($totalIssues > 0) { ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h4 class="mb-0">
                        <i class="fas fa-lightbulb"></i> Recommended Actions
                    </h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <h6><i class="fas fa-exclamation-triangle text-danger"></i> High Priority - Cars</h6>
                            <ul class="list-unstyled ml-3">
                                <?php if ($dataQualityReports['invalid_model']['count'] > 0) { ?>
                                    <li><i class="fas fa-arrow-right text-danger"></i> Fix invalid model data (critical for display)</li>
                                <?php } ?>
                                <?php if ($dataQualityReports['multiple_missing']['count'] > 0) { ?>
                                    <li><i class="fas fa-arrow-right text-danger"></i> Address cars with multiple missing fields</li>
                                <?php } ?>
                                <?php if ($dataQualityReports['invalid_chassis']['count'] > 0) { ?>
                                    <li><i class="fas fa-arrow-right text-danger"></i> Fix invalid chassis numbers using validation override if needed</li>
                                <?php } ?>
                                <?php if ($dataQualityReports['missing_chassis']['count'] > 10) { ?>
                                    <li><i class="fas fa-arrow-right text-warning"></i> Add chassis numbers for identification</li>
                                <?php } ?>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <h6><i class="fas fa-users text-warning"></i> Owner Issues</h6>
                            <ul class="list-unstyled ml-3">
                                <?php if ($dataQualityReports['owners_missing_info']['count'] > 0) { ?>
                                    <li><i class="fas fa-arrow-right text-warning"></i> Contact owners with missing profile information</li>
                                <?php } ?>
                                <?php if ($dataQualityReports['duplicate_emails']['count'] > 0) { ?>
                                    <li><i class="fas fa-arrow-right text-warning"></i> Resolve duplicate email accounts</li>
                                <?php } ?>
                                <?php if ($dataQualityReports['inactive_owners']['count'] > 10) { ?>
                                    <li><i class="fas fa-arrow-right text-info"></i> Re-engage inactive car owners</li>
                                <?php } ?>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <h6><i class="fas fa-info-circle text-info"></i> General Improvements</h6>
                            <ul class="list-unstyled ml-3">
                                <?php if ($dataQualityReports['missing_series']['count'] > 0) { ?>
                                    <li><i class="fas fa-arrow-right text-info"></i> Fill in missing series information</li>
                                <?php } ?>
                                <?php if ($dataQualityReports['users_without_cars']['count'] > 20) { ?>
                                    <li><i class="fas fa-arrow-right text-info"></i> Encourage car registration among new users</li>
                                <?php } ?>
                                <li><i class="fas fa-arrow-right text-info"></i>
                                    <button class="btn btn-sm btn-outline-info" onclick="location.reload()">
                                        <i class="fas fa-sync"></i> Refresh Data
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php } ?>

<!-- Car Quality Summary Cards -->
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h4 mb-3 text-success"><i class="fas fa-car"></i> Car Quality Reports</h2>
    </div>
    <?php foreach ($dataQualityReports as $key => $report) { ?>
        <?php if (in_array($key, ['owners_missing_info', 'inactive_owners', 'users_without_cars', 'duplicate_emails'])) continue; ?>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card border-<?= $report['severity'] ?> h-100">
                <div class="card-body text-center">
                    <div class="text-<?= $report['severity'] ?> mb-3">
                        <i class="<?= $report['icon'] ?>" style="font-size: 2.5rem;"></i>
                    </div>
                    <h5 class="card-title"><?= $report['title'] ?></h5>
                    <h3 class="text-<?= $report['severity'] ?> mb-2"><?= $report['count'] ?></h3>
                    <p class="card-text small text-muted"><?= $report['description'] ?></p>
                    <?php if ($report['count'] > 0) { ?>
                        <a href="#report-<?= $key ?>" class="btn btn-outline-<?= $report['severity'] ?> btn-sm">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                    <?php } ?>
                </div>
            </div>
        </div>
    <?php } ?>
</div>

<!-- Owner Quality Summary Cards -->
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h4 mb-3 text-primary"><i class="fas fa-users"></i> Owner Quality Reports</h2>
    </div>
    <?php foreach ($dataQualityReports as $key => $report) { ?>
        <?php if (!in_array($key, ['owners_missing_info', 'inactive_owners', 'users_without_cars', 'duplicate_emails'])) continue; ?>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card border-<?= $report['severity'] ?> h-100">
                <div class="card-body text-center">
                    <div class="text-<?= $report['severity'] ?> mb-3">
                        <i class="<?= $report['icon'] ?>" style="font-size: 2.5rem;"></i>
                    </div>
                    <h5 class="card-title"><?= $report['title'] ?></h5>
                    <h3 class="text-<?= $report['severity'] ?> mb-2"><?= $report['count'] ?></h3>
                    <p class="card-text small text-muted"><?= $report['description'] ?></p>
                    <?php if ($report['count'] > 0) { ?>
                        <a href="#report-<?= $key ?>" class="btn btn-outline-<?= $report['severity'] ?> btn-sm">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                    <?php } ?>
                </div>
            </div>
        </div>
    <?php } ?>
</div>

<!-- Detailed Reports -->
<?php foreach ($dataQualityReports as $key => $report) { ?>
    <?php if ($report['count'] > 0) { ?>
        <div class="row mb-4" id="report-<?= $key ?>">
            <div class="col-12">
                <div class="card border-<?= $report['severity'] ?>">
                    <div class="card-header bg-dark" data-toggle="collapse" data-target="#collapse-<?= $key ?>" aria-expanded="false" style="cursor: pointer;">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0 text-white">
                                <i class="<?= $report['icon'] ?> text-<?= $report['severity'] ?>"></i> <?= $report['title'] ?>
                                <span class="badge badge-<?= $report['severity'] ?> ml-2"><?= $report['count'] ?></span>
                            </h4>
                            <div class="d-flex align-items-center">
                                <small class="text-light mr-3">Impact: <?= $report['impact'] ?></small>
                                <i class="fas fa-chevron-down text-light collapse-icon"></i>
                            </div>
                        </div>
                    </div>
                    <div class="collapse" id="collapse-<?= $key ?>">
                        <div class="card-body">
                        <p class="card-text mb-3"><?= $report['description'] ?></p>

                        <?php if ($report['count'] > 0) { ?>
                            <?php if ($key === 'inactive_owners') { ?>
                                <!-- Summary for inactive owners - no table shown -->
                                <div class="alert alert-info">
                                    <h6 class="alert-heading"><i class="fas fa-info-circle"></i> Inactive Car Owners Summary</h6>
                                    <p class="mb-2">Found <strong><?= $report['count'] ?></strong> car owners who have not logged in for over 2 years or never logged in.</p>
                                    <p class="mb-0">These accounts may be abandoned or owners may have lost access. Consider reaching out via email to re-engage these users or verify if their cars should remain in the registry.</p>
                                </div>
                            <?php } elseif ($key === 'invalid_chassis') { ?>
                                <!-- Special handling for invalid chassis with validation error display -->
                                <div class="alert alert-warning mb-3">
                                    <h6 class="alert-heading"><i class="fas fa-info-circle"></i> Chassis Validation Information</h6>
                                    <p class="mb-2">These chassis numbers fail validation against Lotus numbering standards. Review each case to determine if:</p>
                                    <ul class="mb-0">
                                        <li>The chassis number contains data entry errors</li>
                                        <li>The car has non-standard factory numbering requiring validation override</li>
                                        <li>Historical documentation supports the unusual chassis format</li>
                                    </ul>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Car ID</th>
                                                <th>Model</th>
                                                <th>Year</th>
                                                <th>Chassis</th>
                                                <th>Validation Error</th>
                                                <th>Owner</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report['data'] as $car) { ?>
                                            <tr>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary" onclick="openCarDetails(<?= $car->id ?>)">
                                                        <i class="fas fa-eye"></i> <?= $car->id ?>
                                                    </button>
                                                </td>
                                                <td><?= htmlspecialchars($car->model) ?></td>
                                                <td><?= $car->year ?></td>
                                                <td><code class="text-danger"><?= htmlspecialchars($car->chassis) ?></code></td>
                                                <td>
                                                    <small class="text-danger">
                                                        <i class="fas fa-exclamation-triangle"></i>
                                                        <?= htmlspecialchars($car->validation_error) ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <?php if ($car->fname && $car->lname) { ?>
                                                        <?= htmlspecialchars($car->fname . ' ' . $car->lname) ?>
                                                    <?php } else { ?>
                                                        <span class="text-muted">Owner Unknown</span>
                                                    <?php } ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-success" onclick="openCarDetails(<?= $car->id ?>)" title="Edit Car Details">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-info ml-1" data-toggle="modal" data-target="#chassisValidationModal">
                                                        <i class="fas fa-info-circle"></i> Rules
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php } else { ?>
                                <!-- Data table for other reports -->
                                <div class="table-responsive">
                                    <?php if (in_array($key, ['owners_missing_info', 'users_without_cars'])) { ?>
                                    <!-- Owner report table -->
                                    <table class="table table-hover">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>User ID</th>
                                                <th>Name</th>
                                                <th>Email</th>
                                                <th>Location</th>
                                                <th>Cars</th>
                                                <th>Join Date</th>
                                                <th>Last Login</th>
                                                <?php if ($key === 'owners_missing_info') { ?>
                                                    <th>Missing Fields</th>
                                                <?php } ?>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report['data'] as $owner) { ?>
                                                <tr>
                                                    <td>
                                                        <span class="badge badge-primary"><?= $owner->id ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if ($owner->fname || $owner->lname) { ?>
                                                            <?= htmlspecialchars(trim($owner->fname . ' ' . $owner->lname)) ?>
                                                        <?php } else { ?>
                                                            <span class="badge badge-warning">Missing Name</span>
                                                        <?php } ?>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-outline-warning"
                                                                onclick="openAdminContactModal(
                                                                    {id: '<?= $owner->car_count ?? 'Multiple' ?>', year: '', model: '', chassis: '', series: ''},
                                                                    {id: '<?= $owner->id ?>', name: '<?= htmlspecialchars(trim($owner->fname . ' ' . $owner->lname)) ?>', email: '<?= htmlspecialchars($owner->email) ?>'},
                                                                    'Missing Information'
                                                                )" title="Contact Owner via Registry">
                                                            <i class="fas fa-envelope"></i>
                                                        </button>
                                                    </td>
                                                    <td>
                                                        <?php if ($owner->city || $owner->state || $owner->country) { ?>
                                                            <?= htmlspecialchars($owner->city ? $owner->city . ', ' : '') ?>
                                                            <?= htmlspecialchars($owner->state ? $owner->state . ', ' : '') ?>
                                                            <?= htmlspecialchars($owner->country ?: '') ?>
                                                        <?php } else { ?>
                                                            <span class="badge badge-warning">Missing Location</span>
                                                        <?php } ?>
                                                    </td>
                                                    <td>
                                                        <?php if (isset($owner->car_count)) { ?>
                                                            <span class="badge badge-success"><?= $owner->car_count ?></span>
                                                        <?php } else { ?>
                                                            <span class="badge badge-secondary">0</span>
                                                        <?php } ?>
                                                    </td>
                                                    <td>
                                                        <small class="text-muted">
                                                            <?= date('M j, Y', strtotime($owner->join_date)) ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <?php if ($owner->last_login && $owner->last_login !== '0000-00-00 00:00:00') { ?>
                                                            <small class="text-muted">
                                                                <?= date('M j, Y', strtotime($owner->last_login)) ?>
                                                            </small>
                                                        <?php } else { ?>
                                                            <span class="badge badge-danger">Never</span>
                                                        <?php } ?>
                                                    </td>
                                                    <?php if ($key === 'owners_missing_info') { ?>
                                                        <td>
                                                            <small class="text-danger"><?= htmlspecialchars($owner->missing_fields_list) ?></small>
                                                        </td>
                                                    <?php } ?>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-primary" onclick="switchToUserManagementTab(<?= $owner->id ?>)" title="Manage User">
                                                            <i class="fas fa-user-edit"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                <?php } elseif ($key === 'duplicate_emails') { ?>
                                    <!-- Duplicate emails table -->
                                    <table class="table table-hover">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Email Address</th>
                                                <th>User Count</th>
                                                <th>Associated Users</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report['data'] as $duplicate) { ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($duplicate->email) ?></td>
                                                    <td>
                                                        <span class="badge badge-warning"><?= $duplicate->user_count ?></span>
                                                    </td>
                                                    <td>
                                                        <small><?= htmlspecialchars($duplicate->users_list) ?></small>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-outline-warning"
                                                                onclick="openAdminContactModal(
                                                                    {id: 'Multiple', year: '', model: '', chassis: '', series: ''},
                                                                    {id: 'Multiple', name: 'Users with <?= htmlspecialchars($duplicate->email) ?>', email: '<?= htmlspecialchars($duplicate->email) ?>'},
                                                                    'Duplicate Email Addresses',
                                                                    '<?= htmlspecialchars($duplicate->email) ?>'
                                                                )" title="Contact Users via Registry">
                                                            <i class="fas fa-envelope"></i> Contact
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                <?php } else { ?>
                                    <!-- Car report table -->
                                    <table class="table table-hover">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Car ID</th>
                                                <th>Model</th>
                                                <th>Series</th>
                                                <th>Year</th>
                                                <th>Chassis</th>
                                                <th>Owner</th>
                                                <th>Last Modified</th>
                                                <?php if ($key === 'multiple_missing') { ?>
                                                    <th>Missing Count</th>
                                                <?php } ?>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($report['data'] as $car) { ?>
                                            <tr>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary" onclick="openCarDetails(<?= $car->id ?>)">
                                                        <?= $car->id ?>
                                                    </button>
                                                </td>
                                                <td>
                                                    <?php if ($car->model === '||') { ?>
                                                        <span class="badge badge-danger">Invalid (||)</span>
                                                    <?php } else { ?>
                                                        <?= htmlspecialchars($car->model ?: 'N/A') ?>
                                                    <?php } ?>
                                                </td>
                                                <td><?= htmlspecialchars($car->series ?: 'Missing') ?></td>
                                                <td><?= htmlspecialchars($car->year ?: 'N/A') ?></td>
                                                <td>
                                                    <?php if (empty($car->chassis)) { ?>
                                                        <span class="badge badge-warning">Missing</span>
                                                    <?php } else { ?>
                                                        <?= htmlspecialchars($car->chassis) ?>
                                                    <?php } ?>
                                                </td>
                                                <td>
                                                    <?php if ($car->fname && $car->lname) { ?>
                                                        <?= htmlspecialchars($car->fname . ' ' . $car->lname) ?>
                                                    <?php } else { ?>
                                                        <span class="text-muted">No owner</span>
                                                    <?php } ?>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?= date('M j, Y', strtotime($car->mtime)) ?>
                                                    </small>
                                                </td>
                                                <?php if ($key === 'multiple_missing') { ?>
                                                    <td>
                                                        <span class="badge badge-danger"><?= $car->missing_count ?></span>
                                                    </td>
                                                <?php } ?>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-success" onclick="openCarDetails(<?= $car->id ?>)" title="Edit Car Details">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                <?php } ?>
                            </div>
                            <?php } ?>
                        <?php } else { ?>
                            <div class="text-center py-4 text-success">
                                <i class="fas fa-check-circle" style="font-size: 3rem;"></i>
                                <h5 class="mt-3">No Issues Found</h5>
                                <p class="text-muted">All cars have proper data for this category.</p>
                            </div>
                        <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php } ?>
<?php } ?>

<!-- Chassis Validation Rules Modal -->
<div class="modal fade" id="chassisValidationModal" tabindex="-1" role="dialog" aria-labelledby="chassisValidationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="chassisValidationModalLabel">
                    <i class="fas fa-barcode"></i> Chassis Validation Rules - Quick Reference
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- Format Overview -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="text-center p-3 border rounded bg-light">
                            <h6 class="text-primary mb-2">Pre-1970 (1963-1969)</h6>
                            <code class="d-block mb-2">1234</code>
                            <small class="text-muted">4 digits only</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center p-3 border rounded bg-light">
                            <h6 class="text-warning mb-2">1970 Transition</h6>
                            <code class="d-block mb-1">1234A</code>
                            <code class="d-block mb-2">7001019999B</code>
                            <small class="text-muted">5 or 11 characters</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center p-3 border rounded bg-light">
                            <h6 class="text-success mb-2">Post-1970 (1971-1974)</h6>
                            <code class="d-block mb-2">7301019999B</code>
                            <small class="text-muted">11 characters YYMMBBXXXXC</small>
                        </div>
                    </div>
                </div>

                <!-- Letter Codes -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card border-primary">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="fas fa-car-side"></i> Elan Models</h6>
                            </div>
                            <div class="card-body">
                                <p><strong>Valid codes:</strong> A, B, C, D, E, F, G, H, J, K</p>
                                <p class="text-danger mb-0"><strong>Invalid:</strong> I (never used)</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-success">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="fas fa-plus"></i> +2 Models</h6>
                            </div>
                            <div class="card-body">
                                <p><strong>Valid codes:</strong> L, M, N only</p>
                                <p class="text-danger mb-0"><strong>Invalid:</strong> A-K (Elan codes)</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Examples -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <h6 class="text-success"><i class="fas fa-check-circle"></i> Valid Examples</h6>
                        <ul class="list-unstyled">
                            <li><code class="text-success">1234</code> - Pre-1970</li>
                            <li><code class="text-success">5678A</code> - 1970 Elan</li>
                            <li><code class="text-success">7012345678M</code> - 1970 +2</li>
                            <li><code class="text-success">7301019999B</code> - 1973 Elan</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-danger"><i class="fas fa-times-circle"></i> Invalid Examples</h6>
                        <ul class="list-unstyled">
                            <li><code class="text-danger">123</code> - Too short</li>
                            <li><code class="text-danger">7301019999I</code> - Invalid letter I</li>
                            <li><code class="text-danger">7301019999L</code> - Wrong letter for Elan</li>
                            <li><code class="text-danger">36/1234</code> - Includes type prefix</li>
                        </ul>
                    </div>
                </div>

                <div class="alert alert-info mb-0">
                    <h6 class="alert-heading"><i class="fas fa-info-circle"></i> Override Option</h6>
                    <p class="mb-0">If your chassis number doesn't validate but you have historical documentation supporting it, you can use the validation override checkbox with caution.</p>
                </div>
            </div>
            <div class="modal-footer">
                <a href="../help/chassis-validation.php" target="_blank" class="btn btn-outline-primary">
                    <i class="fas fa-external-link-alt"></i> View Full Documentation
                </a>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Admin Contact Owner Modal -->
<div class="modal fade" id="adminContactModal" tabindex="-1" role="dialog" aria-labelledby="adminContactModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="adminContactModalLabel">
                    <i class="fas fa-shield-alt"></i> Administrator Contact Owner
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="adminContactForm" method="POST" action="<?= $us_url_root ?>app/admin/includes/process-admin-contact.php">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <strong>Administrator Contact:</strong>
                        This will send an email to the car owner.
                    </div>

                    <!-- Owner Information Display -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6 class="text-primary">Car Information</h6>
                            <div class="bg-light p-3 rounded">
                                <div id="contactCarInfo">
                                    <!-- Populated by JavaScript -->
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary">Owner Information</h6>
                            <div class="bg-light p-3 rounded">
                                <div id="contactOwnerInfo">
                                    <!-- Populated by JavaScript -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quality Issue Context -->
                    <div class="mb-3">
                        <label for="qualityIssue" class="form-label">
                            <i class="fas fa-exclamation-triangle text-warning"></i> Data Quality Issue
                        </label>
                        <select class="form-control" id="qualityIssue" name="quality_issue">
                            <option value="">Select the data quality issue (optional)</option>
                            <option value="Missing Information">Missing Critical Information</option>
                            <option value="Invalid Data">Invalid Data Entry</option>
                            <option value="Duplicate Records">Duplicate Records</option>
                            <option value="Duplicate Email Addresses">Duplicate Email Addresses</option>
                            <option value="Missing Chassis">Missing Chassis Number</option>
                            <option value="Invalid Chassis">Invalid Chassis Number</option>
                            <option value="Missing Series">Missing Series Information</option>
                            <option value="Other">Other Data Quality Issue</option>
                        </select>
                    </div>

                    <!-- Message -->
                    <div class="mb-3">
                        <label for="adminMessage" class="form-label">
                            <i class="fas fa-comment text-primary"></i> Your Message to Owner
                        </label>
                        <textarea class="form-control" id="adminMessage" name="message" rows="6"
                                  placeholder="Enter your message to the car owner..." required></textarea>
                        <div class="form-text">
                            <small class="text-muted">
                                <i class="fas fa-lightbulb"></i> <strong>Tip:</strong> Be specific about what information needs to be updated and why.
                            </small>
                        </div>
                    </div>

                    <!-- Hidden fields -->
                    <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                    <input type="hidden" name="action" value="admin_contact_owner" />
                    <input type="hidden" name="car_id" id="contactCarId" value="" />
                    <input type="hidden" name="owner_id" id="contactOwnerId" value="" />
                    <input type="hidden" name="target_email" id="contactTargetEmail" value="" />
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-envelope"></i> Send Administrator Message
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Auto-refresh data every 5 minutes for live monitoring
setTimeout(function() {
    location.reload();
}, 300000);

// Function to open car details page for editing
function openCarDetails(carId) {
    // Open car details page in new tab for editing
    window.open('../cars/details.php?car_id=' + carId, '_blank');
}

// Function to switch to car management tab with specific car pre-loaded
function switchToCarManagementTab(carId) {
    // Switch to car management tab and pass car ID as parameter
    window.location.href = '?tab=car-mgmt&car_id=' + carId;
}

// Function to switch to user management tab with specific user pre-loaded
function switchToUserManagementTab(userId) {
    // Switch to user management tab and pass user ID as parameter
    window.location.href = '?tab=user-mgmt&user_id=' + userId;
}

// Function to open admin contact modal for owner communication
function openAdminContactModal(carData, ownerData, qualityIssue = '', targetEmail = '') {
    // Populate car information
    document.getElementById('contactCarInfo').innerHTML = `
        <div><strong>Car ID:</strong> ${carData.id}</div>
        <div><strong>Year/Model:</strong> ${carData.year || 'N/A'} ${carData.model || 'N/A'}</div>
        <div><strong>Chassis:</strong> ${carData.chassis || 'Missing'}</div>
        <div><strong>Series:</strong> ${carData.series || 'Missing'}</div>
    `;

    // Populate owner information
    document.getElementById('contactOwnerInfo').innerHTML = `
        <div><strong>Name:</strong> ${ownerData.name || 'Unknown'}</div>
        <div><strong>Email:</strong> ${ownerData.email || 'Unknown'}</div>
        <div><strong>User ID:</strong> ${ownerData.id || 'Unknown'}</div>
    `;

    // Set hidden field values
    document.getElementById('contactCarId').value = carData.id;
    document.getElementById('contactOwnerId').value = ownerData.id;
    document.getElementById('contactTargetEmail').value = targetEmail || ownerData.email || '';

    // Pre-populate quality issue if provided
    if (qualityIssue) {
        document.getElementById('qualityIssue').value = qualityIssue;
    } else {
        document.getElementById('qualityIssue').value = '';
    }

    // Show the modal
    $('#adminContactModal').modal('show');
}

// Smooth scrolling to report sections
document.querySelectorAll('a[href^="#report-"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
            // Auto-expand the clicked report
            const collapseElement = target.querySelector('.collapse');
            if (collapseElement && !collapseElement.classList.contains('show')) {
                $(collapseElement).collapse('show');
            }
        }
    });
});

// Handle collapse icon rotation
$(document).on('show.bs.collapse', '.collapse', function() {
    const icon = $(this).prev('.card-header').find('.collapse-icon');
    icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
});

$(document).on('hide.bs.collapse', '.collapse', function() {
    const icon = $(this).prev('.card-header').find('.collapse-icon');
    icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
});

// Add hover effect for collapsible headers
$(document).ready(function() {
    $('.card-header[data-toggle="collapse"]').hover(
        function() {
            $(this).css('background-color', '#495057');
        },
        function() {
            $(this).css('background-color', '');
        }
    );
});
</script>