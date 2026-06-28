<?php
declare(strict_types=1);
/**
 * tab-manage_cars.php
 * Manage Cars Tab Content
 *
 * Phase 2A: Car management and data quality reporting functionality
 * Provides comprehensive car quality analysis with actionable workflows
 * Includes duplicate detection and correction capabilities
 */

// Import ChassisValidator for validation functionality
require_once '../../usersc/classes/ChassisValidator.php';

// Data Quality Reports Functions
function getDataQualityReports(object $db): array {
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
               u.id as user_id, u.fname, u.lname, u.email
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
               u.id as user_id, u.fname, u.lname, u.email
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
               u.id as user_id, u.fname, u.lname, u.email
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
               u.id as user_id, u.fname, u.lname, u.email
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
               u.id as user_id, u.fname, u.lname, u.email,
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

    // Report 6: Duplicate Cars Detection
    $duplicatesQ = $db->query("
        SELECT a.* FROM cars a
        JOIN (
            SELECT type, chassis, COUNT(*)
            FROM cars
            WHERE chassis <> '' AND chassis IS NOT NULL
            GROUP BY type, chassis
            HAVING COUNT(*) > 1
        ) b ON a.chassis = b.chassis AND a.type = b.type
        ORDER BY a.chassis, a.type
    ");
    $reports['duplicate_cars'] = [
        'title' => 'Duplicate Cars',
        'description' => 'Cars with identical type and chassis number combinations',
        'icon' => 'fas fa-copy',
        'severity' => 'warning',
        'count' => count($duplicatesQ->results()),
        'data' => $duplicatesQ->results(),
        'impact' => 'Medium - May indicate duplicate registrations or data entry errors'
    ];

    return $reports;
}

$dataQualityReports = getDataQualityReports($db);

// Map 'info' severity to brand primary token for CSS classes
$severityClass = static function (string $severity): string {
    return $severity === 'info' ? 'primary' : $severity;
};

// Calculate severity-specific counts for car issues only
$carCriticalIssues = 0;
$carWarningIssues = 0;
foreach ($dataQualityReports as $key => $report) {
    // Only count car-related issues, exclude owner/user issues
    if (!in_array($key, ['owners_missing_info', 'inactive_owners', 'duplicate_emails', 'users_without_cars'])) {
        // Severity counts for car issues only
        if ($report['severity'] === 'danger') {
            $carCriticalIssues += $report['count'];
        } elseif ($report['severity'] === 'warning') {
            $carWarningIssues += $report['count'];
        }
    }
}

// Car issues count is the sum of critical and warning issues
$carIssues = $carCriticalIssues + $carWarningIssues;

// Calculate car quality health score (higher is better)
$totalCars = $systemStatus['total_cars'];
$qualityScore = $totalCars > 0 ? max(0, 100 - (($carIssues / $totalCars) * 100)) : 100;

?>

<!-- Manage Cars Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="text-primary mb-1">
            <i class="fas fa-car"></i> Manage Cars
        </h2>
        <p class="text-muted mb-0">Monitor car data quality and detect duplicate registrations</p>
    </div>
</div>



<!-- Car Quality Summary Cards -->
<div class="row mb-4">
    <!-- Data Health Card -->
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-<?= ElanRegistryOwner::getQualityBadgeClass($qualityScore) ?> h-100">
            <div class="card-body text-center">
                <div class="text-<?= ElanRegistryOwner::getQualityBadgeClass($qualityScore) ?> mb-3">
                    <i class="fas fa-<?= $qualityScore >= 80 ? 'check-circle' : ($qualityScore >= 60 ? 'exclamation-triangle' : 'times-circle') ?>" style="font-size: 2.5rem;"></i>
                </div>
                <h5 class="card-title">Data Health</h5>
                <h3 class="text-<?= ElanRegistryOwner::getQualityBadgeClass($qualityScore) ?> mb-2"><?= number_format($qualityScore, 1) ?>%</h3>
                <p class="card-text small text-muted">Overall car data quality score</p>
            </div>
        </div>
    </div>
    <?php foreach ($dataQualityReports as $key => $report) { ?>
        <?php if (in_array($key, ['owners_missing_info', 'inactive_owners', 'users_without_cars', 'duplicate_emails'])) continue; ?>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card border-<?= $severityClass($report['severity']) ?> h-100">
                <div class="card-body text-center">
                    <div class="text-<?= $severityClass($report['severity']) ?> mb-3">
                        <i class="<?= $report['icon'] ?>" style="font-size: 2.5rem;"></i>
                    </div>
                    <h5 class="card-title"><?= $report['title'] ?></h5>
                    <h3 class="text-<?= $severityClass($report['severity']) ?> mb-2"><?= $report['count'] ?></h3>
                    <p class="card-text small text-muted"><?= $report['description'] ?></p>
                    <?php if ($report['count'] > 0) { ?>
                        <?php if ($key === 'duplicate_cars') { ?>
                            <a href="#duplicateDetectionSection" class="btn btn-outline-<?= $severityClass($report['severity']) ?> btn-sm">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                        <?php } else { ?>
                            <a href="#report-<?= $key ?>" class="btn btn-outline-<?= $severityClass($report['severity']) ?> btn-sm">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                        <?php } ?>
                    <?php } ?>
                </div>
            </div>
        </div>
    <?php } ?>
</div>


<!-- Detailed Car Reports -->
<?php foreach ($dataQualityReports as $key => $report) { ?>
    <?php if ($report['count'] > 0 && !in_array($key, ['owners_missing_info', 'inactive_owners', 'users_without_cars', 'duplicate_emails', 'duplicate_cars'])) { ?>
        <div class="row mb-4" id="report-<?= $key ?>">
            <div class="col-12">
                <div class="card border-primary">
                    <div class="card-header card-header-er-primary" data-bs-toggle="collapse" data-bs-target="#collapse-<?= $key ?>" aria-expanded="false" style="cursor: pointer;">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="mb-0 card-header-er-primary-text">
                                <i class="<?= $report['icon'] ?>"></i> <?= $report['title'] ?>
                                <span class="badge text-bg-warning ms-2"><?= $report['count'] ?></span>
                            </h4>
                            <div class="d-flex align-items-center">
                                <small class="card-header-er-primary-text me-3">Impact: <?= $report['impact'] ?></small>
                                <i class="fas fa-chevron-down card-header-er-primary-text collapse-icon"></i>
                            </div>
                        </div>
                    </div>
                    <div class="collapse" id="collapse-<?= $key ?>">
                        <div class="card-body">
                        <p class="card-text mb-3"><?= $report['description'] ?></p>

                        <?php if ($report['count'] > 0) { ?>
                            <?php if ($key === 'inactive_owners') { ?>
                                <!-- Summary for inactive owners - no table shown -->
                                <div class="alert alert-primary">
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
                                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="openCarDetails(<?= $car->id ?>)" title="Edit Car Details">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-warning ms-1"
                                                            onclick="openAdminContactModal(
                                                                {id: '<?= $car->id ?>', year: '<?= htmlspecialchars($car->year) ?>', model: '<?= htmlspecialchars($car->model) ?>', chassis: '<?= htmlspecialchars($car->chassis) ?>', series: '<?= htmlspecialchars($car->series ?? '') ?>'},
                                                                {id: '<?= $car->user_id ?? '' ?>', name: '<?= htmlspecialchars($car->fname && $car->lname ? $car->fname . ' ' . $car->lname : 'Unknown') ?>', email: '<?= htmlspecialchars($car->email ?? '') ?>'},
                                                                'Invalid Chassis'
                                                            )" title="Contact Owner via Registry">
                                                        <i class="fas fa-envelope"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-primary ms-1" data-bs-toggle="modal" data-bs-target="#chassisValidationModal">
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
                                                        <span class="badge text-bg-primary"><?= $owner->id ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if ($owner->fname || $owner->lname) { ?>
                                                            <?= htmlspecialchars(trim($owner->fname . ' ' . $owner->lname)) ?>
                                                        <?php } else { ?>
                                                            <span class="badge text-bg-warning">Missing Name</span>
                                                        <?php } ?>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-outline-primary me-1"
                                                                onclick="switchToOwnerManagementTab(<?= $owner->id ?>)"
                                                                title="Edit Owner Profile">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </button>
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
                                                            <span class="badge text-bg-warning">Missing Location</span>
                                                        <?php } ?>
                                                    </td>
                                                    <td>
                                                        <?php if (isset($owner->car_count)) { ?>
                                                            <span class="badge text-bg-primary"><?= $owner->car_count ?></span>
                                                        <?php } else { ?>
                                                            <span class="badge text-bg-secondary">0</span>
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
                                                            <span class="badge text-bg-danger">Never</span>
                                                        <?php } ?>
                                                    </td>
                                                    <?php if ($key === 'owners_missing_info') { ?>
                                                        <td>
                                                            <small class="text-danger"><?= htmlspecialchars($owner->missing_fields_list) ?></small>
                                                        </td>
                                                    <?php } ?>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-primary" onclick="switchToOwnerManagementTab(<?= $owner->id ?>)" title="Edit Owner">
                                                            <i class="fas fa-edit"></i> Edit
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
                                                        <span class="badge text-bg-warning"><?= $duplicate->user_count ?></span>
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
                                                        <span class="badge text-bg-danger">Invalid (||)</span>
                                                    <?php } else { ?>
                                                        <?= htmlspecialchars($car->model ?: 'N/A') ?>
                                                    <?php } ?>
                                                </td>
                                                <td><?= htmlspecialchars($car->series ?: 'Missing') ?></td>
                                                <td><?= htmlspecialchars($car->year ?: 'N/A') ?></td>
                                                <td>
                                                    <?php if (empty($car->chassis)) { ?>
                                                        <span class="badge text-bg-warning">Missing</span>
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
                                                        <span class="badge text-bg-danger"><?= $car->missing_count ?></span>
                                                    </td>
                                                <?php } ?>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="openCarDetails(<?= $car->id ?>)" title="Edit Car Details">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-warning ms-1"
                                                            onclick="openAdminContactModal(
                                                                {id: '<?= $car->id ?>', year: '<?= htmlspecialchars($car->year) ?>', model: '<?= htmlspecialchars($car->model) ?>', chassis: '<?= htmlspecialchars($car->chassis) ?>', series: '<?= htmlspecialchars($car->series ?? '') ?>'},
                                                                {id: '<?= $car->user_id ?? '' ?>', name: '<?= htmlspecialchars($car->fname && $car->lname ? $car->fname . ' ' . $car->lname : 'Unknown') ?>', email: '<?= htmlspecialchars($car->email ?? '') ?>'},
                                                                'Missing Information'
                                                            )" title="Contact Owner via Registry">
                                                        <i class="fas fa-envelope"></i>
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
                            <div class="text-center py-4 text-primary">
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
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
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
                        <div class="card border-primary">
                            <div class="card-header card-header-er-primary">
                                <h6 class="mb-0 card-header-er-primary-text"><i class="fas fa-plus"></i> +2 Models</h6>
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
                        <h6 class="text-primary"><i class="fas fa-check-circle"></i> Valid Examples</h6>
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

                <div class="alert alert-primary mb-0">
                    <h6 class="alert-heading"><i class="fas fa-info-circle"></i> Override Option</h6>
                    <p class="mb-0">If your chassis number doesn't validate but you have historical documentation supporting it, you can use the validation override checkbox with caution.</p>
                </div>
            </div>
            <div class="modal-footer">
                <a href="../help/chassis-validation.php" target="_blank" class="btn btn-outline-primary">
                    <i class="fas fa-external-link-alt"></i> View Full Documentation
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Admin Contact Owner Modal -->
<div class="modal fade" id="adminContactModal" tabindex="-1" role="dialog" aria-labelledby="adminContactModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header card-header-er-primary">
                <h5 class="modal-title card-header-er-primary-text" id="adminContactModalLabel">
                    <i class="fas fa-shield-alt"></i> Administrator Contact Owner
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="adminContactForm" method="POST" action="<?= $us_url_root ?>app/admin/includes/process-admin-contact.php">
                <div class="modal-body">
                    <div class="alert alert-primary">
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
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-envelope"></i> Send Administrator Message
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Duplicate Detection Section -->
<?php if (isset($dataQualityReports['duplicate_cars']) && $dataQualityReports['duplicate_cars']['count'] > 0) { ?>
<div class="row mt-4" id="duplicateDetectionSection">
    <div class="col-12">
        <div class="card border-primary">
            <div class="card-header card-header-er-primary" data-bs-toggle="collapse" data-bs-target="#collapse-duplicate-detection" aria-expanded="false" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-center">
                    <h4 class="mb-0 card-header-er-primary-text">
                        <i class="fas fa-search"></i> Duplicate Detection & Management
                        <span class="badge text-bg-warning ms-2"><?= $dataQualityReports['duplicate_cars']['count'] ?></span>
                    </h4>
                    <div class="d-flex align-items-center">
                        <small class="card-header-er-primary-text me-3">Impact: Medium - May indicate duplicate registrations or data entry errors</small>
                        <i class="fas fa-chevron-down card-header-er-primary-text collapse-icon"></i>
                    </div>
                </div>
            </div>
            <div class="collapse" id="collapse-duplicate-detection">
                <div class="card-body">
                <!-- Merge Reason Explanations -->
                <div class="alert alert-primary mb-4">
                    <h5 class="alert-heading"><i class="fas fa-info-circle"></i> Merge Reason Guide</h5>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <h6 class="text-danger"><i class="fas fa-clone"></i> Duplicate Car</h6>
                            <p class="mb-0 small">Two identical records of the same physical car. One record will be removed and all history preserved in the remaining record.</p>
                        </div>
                        <div class="col-md-4 mb-3">
                            <h6 class="text-primary"><i class="fas fa-arrow-right"></i> Keep Newer Record</h6>
                            <p class="mb-0 small">Car sold to new owner who registered it again. <strong>Keep the newer record</strong> with current owner's information, merge history from older record.</p>
                        </div>
                        <div class="col-md-4 mb-3">
                            <h6 class="text-primary"><i class="fas fa-arrow-left"></i> Keep Older Record</h6>
                            <p class="mb-0 small">Car was sold but <strong>keep the original record</strong> as the primary entry. Transfer ownership information and merge newer record's history.</p>
                        </div>
                    </div>
                    <hr class="mt-3 mb-2">
                    <div class="row">
                        <div class="col-md-8">
                            <p class="mb-0 small text-muted"><strong>Note:</strong> All merge operations preserve complete history and create audit trail entries. The operation cannot be undone once completed.</p>
                        </div>
                        <div class="col-md-4 text-end">
                            <small class="text-muted">
                                <span class="badge text-bg-primary badge-sm me-1"><i class="fas fa-check"></i></span>Matching Fields
                                <span class="badge text-bg-danger badge-sm ms-2"><i class="fas fa-exclamation-triangle"></i></span>Different Fields
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Duplicate Groups Container -->
                <div id="duplicateGroups">
                    <?php
                    // Group duplicates by type and chassis number (exact matches)
                    $duplicateCars = $dataQualityReports['duplicate_cars']['data'];
                    $groupedDuplicates = [];
                    foreach ($duplicateCars as $car) {
                        $key = $car->chassis . '_' . $car->type;
                        if (!isset($groupedDuplicates[$key])) {
                            $groupedDuplicates[$key] = [];
                        }
                        $groupedDuplicates[$key][] = $car;
                    }

                    $groupIndex = 0;
                    foreach ($groupedDuplicates as $chassis => $cars) {
                        $groupIndex++;
                    ?>
                        <div class="duplicate-group card mb-3 border">
                            <div class="card-header card-header-er-l2 d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 card-header-er-l2-text">
                                    <button class="btn btn-link text-decoration-none p-0 card-header-er-l2-text" type="button" data-bs-toggle="collapse" data-bs-target="#group<?= $groupIndex ?>" aria-expanded="true">
                                        <i class="fas fa-chevron-down"></i>
                                        Group <?= $groupIndex ?>: <?= explode('_', $chassis)[1] ?>-<?= explode('_', $chassis)[0] ?>
                                    </button>
                                </h5>
                                <div>
                                    <span class="badge text-bg-warning"><?= count($cars) ?> matches</span>
                                    <span class="badge text-bg-secondary">Confidence: High</span>
                                </div>
                            </div>
                                <div class="collapse show" id="group<?= $groupIndex ?>">
                                    <div class="card-body">
                                        <form action="manage-consolidated.php" method="POST" class="merge-form">
                                            <input type="hidden" name="command" value="merge" />
                                            <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />

                                            <!-- Car Comparison Cards -->
                                            <div class="row">
                                                <?php
                                                // Sort cars by creation date - newest on the right
                                                usort($cars, function($a, $b) {
                                                    return strtotime($a->ctime) - strtotime($b->ctime);
                                                });

                                                // Create comparison data for highlighting differences
                                                $vehicleFields = ['year', 'type', 'chassis', 'series', 'color'];
                                                $ownerFields = ['fname', 'lname', 'email', 'city', 'state', 'country'];

                                                $comparison = [];
                                                foreach ($cars as $c) {
                                                    foreach ($vehicleFields as $field) {
                                                        $comparison['vehicle'][$field][] = $c->$field ?? '';
                                                    }
                                                    foreach ($ownerFields as $field) {
                                                        $comparison['owner'][$field][] = $c->$field ?? '';
                                                    }
                                                }

                                                // Determine if fields match or differ
                                                $vehicleMatches = [];
                                                $ownerMatches = [];
                                                foreach ($vehicleFields as $field) {
                                                    $values = array_unique($comparison['vehicle'][$field]);
                                                    $vehicleMatches[$field] = count($values) === 1;
                                                }
                                                foreach ($ownerFields as $field) {
                                                    $values = array_unique($comparison['owner'][$field]);
                                                    $ownerMatches[$field] = count($values) === 1;
                                                }

                                                // Check if this is a perfect match (all fields identical)
                                                $allVehicleMatch = array_reduce($vehicleMatches, function($carry, $match) { return $carry && $match; }, true);
                                                $allOwnerMatch = array_reduce($ownerMatches, function($carry, $match) { return $carry && $match; }, true);
                                                $isPerfectMatch = $allVehicleMatch && $allOwnerMatch;
                                                ?>

                                            <!-- Perfect Match Recommendation -->
                                            <?php if ($isPerfectMatch) { ?>
                                                <div class="alert alert-primary mb-3">
                                                    <h6 class="alert-heading mb-2">
                                                        <i class="fas fa-bullseye text-primary"></i> Perfect Match Detected
                                                    </h6>
                                                    <p class="mb-2">
                                                        <strong>Recommendation:</strong> These cars have identical vehicle and owner information.
                                                        This appears to be a duplicate entry of the same car.
                                                    </p>
                                                    <div class="d-flex align-items-center">
                                                        <i class="fas fa-arrow-right text-primary me-2"></i>
                                                        <strong>Suggested Action:</strong>
                                                        <span class="badge text-bg-primary ms-2">Merge as Duplicate</span>
                                                    </div>
                                                </div>
                                            <?php } ?>

                                            <!-- Merge Controls -->
                                            <div class="mb-3">
                                                <div class="row">
                                                    <div class="col-md-8">
                                                        <strong>Merge Reason:</strong>
                                                        <div class="form-check form-check-inline">
                                                            <input class="form-check-input" type="radio" name="reason[]" id="duplicate<?= $groupIndex ?>" value="duplicate" <?= $isPerfectMatch ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="duplicate<?= $groupIndex ?>">
                                                                <i class="fas fa-clone text-danger"></i> Duplicate Car
                                                                <?php if ($isPerfectMatch) { ?>
                                                                    <span class="badge text-bg-primary badge-sm ms-1">Recommended</span>
                                                                <?php } ?>
                                                            </label>
                                                        </div>
                                                        <div class="form-check form-check-inline">
                                                            <input class="form-check-input" type="radio" name="reason[]" id="newowner1_<?= $groupIndex ?>" value="newownerNewToOld">
                                                            <label class="form-check-label" for="newowner1_<?= $groupIndex ?>">
                                                                <i class="fas fa-arrow-right text-primary"></i> Keep Newer Record
                                                            </label>
                                                        </div>
                                                        <div class="form-check form-check-inline">
                                                            <input class="form-check-input" type="radio" name="reason[]" id="newowner2_<?= $groupIndex ?>" value="newownerOldToNew">
                                                            <label class="form-check-label" for="newowner2_<?= $groupIndex ?>">
                                                                <i class="fas fa-arrow-left text-primary"></i> Keep Older Record
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4 text-end">
                                                        <button type="submit" class="btn btn-danger btn-sm merge-btn" <?= $isPerfectMatch ? '' : 'disabled' ?>>
                                                            <i class="fas fa-compress-arrows-alt"></i> Merge Selected
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Car Comparison Cards -->
                                            <div class="car-comparison-cards-row">
                                                <?php

                                                foreach ($cars as $index => $car) {
                                                    $isNewer = $index === count($cars) - 1 && count($cars) > 1;
                                                    $cardClass = $isNewer ? 'car-comparison-card newer-car' : 'car-comparison-card';
                                                ?>
                                                    <div class="col-lg-6 col-md-6 mb-3 d-flex">
                                                        <div class="card <?= $cardClass ?> w-100">
                                                            <div class="card-header card-header-er-l3 d-flex justify-content-between align-items-center">
                                                                <div class="form-check">
                                                                    <input class="form-check-input car-select" type="checkbox" name="cars[]" value="<?= $car->id ?>" id="car<?= $car->id ?>">
                                                                    <label class="form-check-label" for="car<?= $car->id ?>">
                                                                        <strong class="card-header-er-l3-text">Car #<?= $car->id ?></strong>
                                                                        <?php if ($isNewer) { ?>
                                                                            <span class="badge text-bg-primary badge-sm ms-1">NEWER</span>
                                                                        <?php } ?>
                                                                    </label>
                                                                </div>
                                                                <a class="btn btn-outline-primary btn-sm" target="_blank" href='<?= $us_url_root ?>app/cars/details.php?car_id=<?= $car->id ?>'>
                                                                    <i class="fas fa-external-link-alt"></i> View Details
                                                                </a>
                                                            </div>

                                                            <!-- Prominent Date Section -->
                                                            <div class="card-header card-header-er-l4">
                                                                <div class="row text-center">
                                                                    <div class="col-6">
                                                                        <div class="timestamp-info">
                                                                            <i class="fas fa-calendar-plus text-primary"></i>
                                                                            <div class="timestamp-label">Created</div>
                                                                            <div class="timestamp-value"><?= date('M j, Y', strtotime($car->ctime)) ?></div>
                                                                            <div class="timestamp-time"><?= date('g:i A', strtotime($car->ctime)) ?></div>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-6 border-left">
                                                                        <div class="timestamp-info">
                                                                            <i class="fas fa-edit text-warning"></i>
                                                                            <div class="timestamp-label">Modified</div>
                                                                            <div class="timestamp-value"><?= date('M j, Y', strtotime($car->mtime)) ?></div>
                                                                            <div class="timestamp-time"><?= date('g:i A', strtotime($car->mtime)) ?></div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <div class="card-body">
                                                                <div class="row">
                                                                    <div class="col-sm-6">
                                                                        <h6 class="card-header-er-l4-text">Vehicle Info</h6>
                                                                        <p class="mb-1 <?= $vehicleMatches['year'] ? 'field-match' : 'field-differ' ?>">
                                                                            <strong>Year:</strong>
                                                                            <span class="field-value"><?= $car->year ?></span>
                                                                            <?= !$vehicleMatches['year'] ? '<i class="fas fa-exclamation-triangle text-warning ms-1" title="Different values"></i>' : '<i class="fas fa-check text-primary ms-1" title="Values match"></i>' ?>
                                                                        </p>
                                                                        <p class="mb-1">
                                                                            <strong>Type:</strong> <?= $car->type ?>
                                                                        </p>
                                                                        <p class="mb-1">
                                                                            <strong>Chassis:</strong> <?= $car->chassis ?>
                                                                        </p>
                                                                        <p class="mb-1 <?= $vehicleMatches['series'] ? 'field-match' : 'field-differ' ?>">
                                                                            <strong>Series:</strong>
                                                                            <span class="field-value"><?= $car->series ?></span>
                                                                            <?= !$vehicleMatches['series'] ? '<i class="fas fa-exclamation-triangle text-warning ms-1" title="Different values"></i>' : '<i class="fas fa-check text-primary ms-1" title="Values match"></i>' ?>
                                                                        </p>
                                                                        <p class="mb-1 <?= $vehicleMatches['color'] ? 'field-match' : 'field-differ' ?>">
                                                                            <strong>Color:</strong>
                                                                            <span class="field-value"><?= $car->color ?></span>
                                                                            <?= !$vehicleMatches['color'] ? '<i class="fas fa-exclamation-triangle text-warning ms-1" title="Different values"></i>' : '<i class="fas fa-check text-primary ms-1" title="Values match"></i>' ?>
                                                                        </p>
                                                                    </div>
                                                                    <div class="col-sm-6">
                                                                        <h6 class="card-header-er-l4-text">Owner Info</h6>
                                                                        <p class="mb-1 <?= $ownerMatches['fname'] && $ownerMatches['lname'] ? 'field-match' : 'field-differ' ?>">
                                                                            <strong>Owner:</strong>
                                                                            <span class="field-value"><?= $car->fname ?> <?= $car->lname ?></span>
                                                                            <?= !($ownerMatches['fname'] && $ownerMatches['lname']) ? '<i class="fas fa-exclamation-triangle text-warning ms-1" title="Different values"></i>' : '<i class="fas fa-check text-primary ms-1" title="Values match"></i>' ?>
                                                                        </p>
                                                                        <p class="mb-1 <?= $ownerMatches['email'] ? 'field-match' : 'field-differ' ?>">
                                                                            <strong>Email:</strong>
                                                                            <span class="field-value"><?= $car->email ?></span>
                                                                            <?= !$ownerMatches['email'] ? '<i class="fas fa-exclamation-triangle text-warning ms-1" title="Different values"></i>' : '<i class="fas fa-check text-primary ms-1" title="Values match"></i>' ?>
                                                                        </p>
                                                                        <p class="mb-1 <?= $ownerMatches['city'] && $ownerMatches['state'] && $ownerMatches['country'] ? 'field-match' : 'field-differ' ?>">
                                                                            <strong>Location:</strong>
                                                                            <span class="field-value"><?= $car->city ?>, <?= $car->state ?> <?= $car->country ?></span>
                                                                            <?= !($ownerMatches['city'] && $ownerMatches['state'] && $ownerMatches['country']) ? '<i class="fas fa-exclamation-triangle text-warning ms-1" title="Different values"></i>' : '<i class="fas fa-check text-primary ms-1" title="Values match"></i>' ?>
                                                                        </p>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php } ?>
                                            </div> <!-- Close car-comparison-cards-row -->
                                        </form>
                                    </div> <!-- Close card-body -->
                                </div> <!-- Close collapse -->
                            </div>
                        </div> <!-- Close duplicate-group card -->
                    <?php } ?>
                </div>
                </div> <!-- Close card-body -->
            </div> <!-- Close collapse -->
        </div> <!-- Close card -->
    </div> <!-- Close col-12 -->
</div> <!-- Close row -->
<?php } ?>

<style>
.car-comparison-card {
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
}

.car-comparison-card .card-body {
    flex: 1;
}

/* Fix cascading height issue - ensure all car cards have same minimum height */
.car-comparison-cards-row {
    display: flex;
    flex-wrap: wrap;
    margin: 0 -15px;
}

.car-comparison-cards-row > .col-lg-6 {
    display: flex;
    padding: 0 15px;
    margin-bottom: 1rem;
}

.car-comparison-cards-row .car-comparison-card {
    width: 100%;
    min-height: 420px; /* Set consistent minimum height */
}

.car-comparison-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.car-comparison-card.selected {
    border: 2px solid var(--er-primary);
    background-color: rgba(var(--er-primary-rgb), 0.03);
}

.car-comparison-card.newer-car {
    border-left: 4px solid var(--er-primary);
}

.car-comparison-card.newer-car .card-header {
    background-color: rgba(var(--er-primary-rgb), 0.04);
}

.duplicate-group {
    transition: all 0.3s ease;
}

/* Prominent timestamp styles */
.timestamp-info {
    padding: 8px 4px;
}

.timestamp-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--er-neutral);
    text-transform: uppercase;
    margin-bottom: 2px;
}

.timestamp-value {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--er-neutral-dark);
    margin-bottom: 1px;
}

.timestamp-time {
    font-size: 0.7rem;
    color: var(--er-neutral);
}

/* Field comparison highlighting */
.field-match {
    background-color: rgba(var(--er-primary-rgb), 0.08);
    border-left: 3px solid var(--er-primary);
    padding: 4px 8px;
    margin: 2px 0;
    border-radius: 4px;
}

.field-differ {
    background-color: rgba(var(--er-danger-rgb), 0.08);
    border-left: 3px solid var(--er-danger);
    padding: 4px 8px;
    margin: 2px 0;
    border-radius: 4px;
}

.field-match .field-value {
    font-weight: 500;
    color: var(--er-primary);
}

.field-differ .field-value {
    font-weight: 500;
    color: var(--er-danger);
}

.field-match i.fa-check {
    font-size: 0.8rem;
}

.field-differ i.fa-exclamation-triangle {
    font-size: 0.8rem;
}

.duplicate-group:hover {
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
}

.form-validation input.is-invalid {
    border-color: var(--er-danger);
}

.form-validation input.is-valid {
    border-color: var(--er-primary);
}

.badge-lg {
    font-size: 0.9rem;
    padding: 0.5rem 0.75rem;
}

.bg-opacity-10 {
    background-color: rgba(255, 193, 7, 0.1) !important;
}

.merge-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

@media (max-width: 768px) {
    .car-comparison-card .col-sm-6 {
        margin-bottom: 1rem;
    }

    .form-check-inline {
        display: block !important;
        margin-bottom: 0.5rem;
    }

    .timestamp-info {
        padding: 6px 2px;
    }

    .timestamp-label {
        font-size: 0.7rem;
    }

    .timestamp-value {
        font-size: 0.8rem;
    }

    .timestamp-time {
        font-size: 0.65rem;
    }

    .badge-sm {
        font-size: 0.7rem;
        padding: 0.2rem 0.4rem;
    }

    .field-match, .field-differ {
        padding: 3px 6px;
        margin: 1px 0;
    }

    .field-match i.fa-check,
    .field-differ i.fa-exclamation-triangle {
        font-size: 0.7rem;
    }
}
</style>

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

// Function to switch to owner management tab with specific user pre-loaded
function switchToOwnerManagementTab(userId) {
    // Switch to owner management tab and pass user ID as parameter
    window.location.href = '?tab=owner-mgmt&owner_id=' + userId;
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
    bootstrap.Modal.getOrCreateInstance(document.getElementById('adminContactModal')).show();
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
                new bootstrap.Collapse(collapseElement, {toggle: false}).show();
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
    $('.card-header[data-bs-toggle="collapse"]').hover(
        function() {
            $(this).css('background-color', '#495057');
        },
        function() {
            $(this).css('background-color', '');
        }
    );
});

// Duplicate detection JavaScript functionality
$(document).ready(function() {
    // Enhanced merge functionality
    $('.merge-form').each(function() {
        const $form = $(this);
        const $carCheckboxes = $form.find('.car-select');
        const $reasonRadios = $form.find('input[name="reason[]"]');
        const $mergeBtn = $form.find('.merge-btn');

        // Enable/disable merge button based on selections
        function updateMergeButton() {
            const selectedCars = $carCheckboxes.filter(':checked').length;
            const selectedReason = $reasonRadios.filter(':checked').length;

            const canMerge = selectedCars === 2 && selectedReason === 1;
            $mergeBtn.prop('disabled', !canMerge);

            if (selectedCars > 2) {
                $mergeBtn.text('Select exactly 2 cars to merge');
                $mergeBtn.removeClass('btn-danger').addClass('btn-warning');
            } else if (selectedCars === 2 && selectedReason === 1) {
                $mergeBtn.html('<i class="fas fa-compress-arrows-alt"></i> Merge Selected');
                $mergeBtn.removeClass('btn-warning').addClass('btn-danger');
            } else {
                $mergeBtn.html('<i class="fas fa-compress-arrows-alt"></i> Merge Selected');
                $mergeBtn.removeClass('btn-warning').addClass('btn-danger');
            }
        }

        // Visual feedback for selected cars
        $carCheckboxes.on('change', function() {
            const $card = $(this).closest('.car-comparison-card');
            if ($(this).is(':checked')) {
                $card.addClass('selected');
            } else {
                $card.removeClass('selected');
            }
            updateMergeButton();
        });

        $reasonRadios.on('change', updateMergeButton);

        // Confirmation dialog for merge operations
        $form.on('submit', function(e) {
            const selectedCars = $carCheckboxes.filter(':checked');
            const selectedReason = $reasonRadios.filter(':checked');

            if (selectedCars.length !== 2 || selectedReason.length !== 1) {
                e.preventDefault();
                showNotification('Please select exactly 2 cars and 1 merge reason.', 'danger');
                return false;
            }

            const car1Id = $(selectedCars[0]).val();
            const car2Id = $(selectedCars[1]).val();
            const reason = selectedReason.val();

            let reasonText = '';
            switch(reason) {
                case 'duplicate':
                    reasonText = 'These are duplicate entries of the same car';
                    break;
                case 'newownerNewToOld':
                    reasonText = 'Keep the newer record (current owner information)';
                    break;
                case 'newownerOldToNew':
                    reasonText = 'Keep the older record (original registration)';
                    break;
            }

            e.preventDefault();

            showConfirmDialog(
                'Confirm Car Merge',
                `Are you sure you want to merge cars #${car1Id} and #${car2Id}?\n\nReason: ${reasonText}\n\nThis action cannot be undone. The history will be preserved, but one car record will be permanently deleted.`,
                function() { $form[0].submit(); }
            );
        });

        // Initialize button state
        updateMergeButton();
    });

    // Collapsible group management
    $('.duplicate-group [data-bs-toggle="collapse"]').on('click', function() {
        const $icon = $(this).find('i');
        const isExpanded = $(this).attr('aria-expanded') === 'true';

        setTimeout(function() {
            if (isExpanded) {
                $icon.removeClass('fa-chevron-down').addClass('fa-chevron-right');
            } else {
                $icon.removeClass('fa-chevron-right').addClass('fa-chevron-down');
            }
        }, 100);
    });

    // Enhanced tooltips and popovers (if Bootstrap supports them)
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) { bootstrap.Tooltip.getOrCreateInstance(el); });
});
</script>