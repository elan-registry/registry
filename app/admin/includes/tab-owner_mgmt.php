<?php
declare(strict_types=1);
/**
 * tab-owner_mgmt.php
 * Manage OwnersTab Content
 */

// Get system status for statistics
if (!isset($systemStatus) || !is_array($systemStatus) || !isset($systemStatus['total_users'])) {
    // Calculate user statistics if not already available
    $userCountQuery = $db->query("SELECT COUNT(*) as count FROM users");
    $systemStatus = $systemStatus ?? [];
    $systemStatus['total_users'] = $userCountQuery->count() > 0 ? $userCountQuery->first()->count : 0;
}

// Handle direct owner ID parameter from data quality links
$selectedOwnerId = null;
if (isset($_GET['owner_id']) && is_numeric($_GET['owner_id'])) {
    $selectedOwnerId = (int)$_GET['owner_id'];
}
?>

<!-- Manage Owners Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="text-primary mb-1">
            <i class="fas fa-users"></i> Manage Owners
        </h2>
        <p class="text-muted mb-0">Monitor owner data quality and manage user profiles</p>
    </div>
</div>


<!-- Owner Search and Management Interface -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-search"></i> Owner Search & Management</h5>
            </div>
            <div class="card-body">
                <div class="input-group mb-3">
                    <input type="text"
                           id="ownerSearchInput"
                           class="form-control"
                           placeholder="Search owners by name, email, or location..."
                           value="<?= $selectedOwnerId ? 'Loading owner ID ' . $selectedOwnerId . '...' : '' // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>">
                    <button class="btn btn-primary" type="button" id="ownerSearchBtn">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <button class="btn btn-secondary" type="button" id="ownerClearBtn">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>

                <!-- Search Results Area -->
                <div id="ownerSearchResults" class="mt-3">
                    <?php if ($selectedOwnerId): ?>
                        <div class="text-center py-2">
                            <i class="fas fa-spinner fa-spin"></i> Loading owner information...
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-users fa-2x mb-2"></i>
                            <p>Search for owners to view and edit their profiles</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Owner Profile Management Panel -->
<div class="card" id="ownerProfilePanel" style="display: none;">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-user-edit"></i> Owner Profile Management
            <span id="ownerProfileName" class="text-muted"></span>
        </h5>
        <div class="card-tools">
            <button class="btn btn-sm btn-outline-secondary" onclick="closeOwnerProfile()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
                <!-- Owner Profile Form will be loaded here -->
                <div id="ownerProfileForm">
                    <div class="text-center py-3">
                        <i class="fas fa-spinner fa-spin"></i> Loading profile...
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <!-- Owner Information Sidebar -->
                <div id="ownerInfoSidebar">
                    <div class="text-center py-3">
                        <i class="fas fa-spinner fa-spin"></i> Loading information...
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
/**
 * Get owner quality reports for display in owner management interface
 *
 * @param DB $db Database connection instance
 * @return array Array of quality report data with counts and details
 * @throws InvalidArgumentException If database connection is invalid
 */
function getOwnerQualityReports($db): array {
    if (!$db || !is_object($db)) {
        throw new InvalidArgumentException('Valid database connection required');
    }

    $reports = [];

    // Owner Report: Car Owners Missing Critical Information
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



    // Owner Report: Duplicate Email Analysis
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

    return $reports;
}

/**
 * Get detailed information for all owners with a duplicate email
 *
 * @param DB $db Database connection
 * @param string $email The duplicate email address
 * @return array Array of owner objects with profile and car count data
 */
function getDuplicateEmailDetails($db, $email): array {
    // Get all users with this email, with car counts via LEFT JOIN
    $ownersQ = $db->query("
        SELECT
            u.id, u.fname, u.lname, u.email, u.join_date, u.last_login,
            p.city, p.state, p.country, p.lat, p.lon,
            COUNT(DISTINCT cu.car_id) as car_count,
            GROUP_CONCAT(DISTINCT c.id ORDER BY c.id SEPARATOR ',') as car_ids
        FROM users u
        LEFT JOIN profiles p ON u.id = p.user_id
        LEFT JOIN car_user cu ON u.id = cu.userid
        LEFT JOIN cars c ON cu.car_id = c.id
        WHERE u.email = ? AND u.active = 1
        GROUP BY u.id, u.fname, u.lname, u.email, u.join_date, u.last_login,
                 p.city, p.state, p.country, p.lat, p.lon
        ORDER BY u.join_date ASC
    ", [$email]);

    $owners = $ownersQ->results();

    // Collect all car IDs from all owners for batch fetching
    $allCarIds = [];
    foreach ($owners as $owner) {
        if ($owner->car_ids) {
            $carIdsArray = explode(',', $owner->car_ids);
            $allCarIds = array_merge($allCarIds, array_map('intval', $carIdsArray));
        }
    }

    // Fetch all cars in one query if there are any
    $carsById = [];
    if (!empty($allCarIds)) {
        $allCarIds = array_unique($allCarIds);
        $allCarIds = array_filter($allCarIds, function($id) { return $id > 0; });

        if (!empty($allCarIds)) {
            // Build WHERE clause with prepared statement placeholders
            $placeholderCount = count($allCarIds);
            $placeholders = array_fill(0, $placeholderCount, '?');
            $whereclause = implode(',', $placeholders);

            // phpcs:disable - False positive: Using prepared statements correctly
            $carsQ = $db->query(
                "SELECT id, model, series, year, chassis, type
                 FROM cars
                 WHERE id IN (" . $whereclause . ")
                 ORDER BY id ASC",
                $allCarIds
            );
            // phpcs:enable

            foreach ($carsQ->results() as $car) {
                $carsById[$car->id] = $car;
            }
        }
    }

    // Assign cars to owners and calculate quality scores
    foreach ($owners as $owner) {
        $owner->cars = [];
        if ($owner->car_ids) {
            $carIdsArray = array_map('intval', explode(',', $owner->car_ids));
            foreach ($carIdsArray as $carId) {
                if (isset($carsById[$carId])) {
                    $owner->cars[] = $carsById[$carId];
                }
            }
        }

        // Calculate profile quality score
        $qualityScore = 100;
        if (!$owner->fname || $owner->fname === '') $qualityScore -= 20;
        if (!$owner->lname || $owner->lname === '') $qualityScore -= 20;
        if (!$owner->city || $owner->city === '') $qualityScore -= 15;
        if (!$owner->state || $owner->state === '') $qualityScore -= 10;
        if (!$owner->lat || !$owner->lon) $qualityScore -= 20;
        $owner->quality_score = max(0, $qualityScore);
    }

    return $owners;
}

// Get owner quality reports for display at bottom of page
$dataQualityReports = getOwnerQualityReports($db);

// Calculate owner quality statistics
$totalOwners = $systemStatus['total_users'] ?? 0;
$qualityIssues = 0;
foreach ($dataQualityReports as $report) {
    $qualityIssues += $report['count'];
}
$ownerQualityScore = $totalOwners > 0 ? max(0, 100 - (($qualityIssues / $totalOwners) * 100)) : 100;
?>

<!-- Owner Quality Summary Cards -->
<div class="row mb-4">
    <!-- Data Health Card -->
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-<?= $ownerQualityScore >= 80 ? 'success' : ($ownerQualityScore >= 60 ? 'warning' : 'danger') ?> h-100">
            <div class="card-body text-center">
                <div class="text-<?= $ownerQualityScore >= 80 ? 'success' : ($ownerQualityScore >= 60 ? 'warning' : 'danger') ?> mb-3">
                    <i class="fas fa-<?= $ownerQualityScore >= 80 ? 'check-circle' : ($ownerQualityScore >= 60 ? 'exclamation-triangle' : 'times-circle') ?>" style="font-size: 2.5rem;"></i>
                </div>
                <h5 class="card-title">Data Health</h5>
                <h3 class="text-<?= $ownerQualityScore >= 80 ? 'success' : ($ownerQualityScore >= 60 ? 'warning' : 'danger') ?> mb-2"><?= number_format($ownerQualityScore, 1) ?>%</h3>
                <p class="card-text small text-muted">Overall owner data quality score</p>
            </div>
        </div>
    </div>
    <?php foreach ($dataQualityReports as $key => $report) { ?>
        <?php if (!in_array($key, ['owners_missing_info', 'duplicate_emails'])) continue; ?>
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
                        <a href="#owner-report-<?= $key ?>" class="btn btn-outline-<?= $report['severity'] ?> btn-sm">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                    <?php } ?>
                </div>
            </div>
        </div>
    <?php } ?>
</div>

<!-- Detailed Owner Reports -->
                <?php foreach ($dataQualityReports as $key => $report) { ?>
                    <?php if (!in_array($key, ['owners_missing_info', 'users_without_cars', 'duplicate_emails']) || $report['count'] == 0) continue; ?>
                    <div class="row mb-4" id="owner-report-<?= $key ?>">
                        <div class="col-12">
                            <div class="card border-<?= $report['severity'] ?>">
                                <div class="card-header bg-dark" data-bs-toggle="collapse" data-bs-target="#owner-collapse-<?= $key ?>" aria-expanded="false" style="cursor: pointer;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h4 class="mb-0 text-white">
                                            <i class="<?= $report['icon'] ?> text-<?= $report['severity'] ?>"></i> <?= $report['title'] ?>
                                            <span class="badge text-bg-<?= $report['severity'] ?> ms-2"><?= $report['count'] ?></span>
                                        </h4>
                                        <div class="d-flex align-items-center">
                                            <small class="text-light me-3">Impact: <?= $report['impact'] ?></small>
                                            <i class="fas fa-chevron-down text-light collapse-icon"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="collapse" id="owner-collapse-<?= $key ?>">
                                    <div class="card-body">
                                        <p class="card-text mb-3"><?= $report['description'] ?></p>

                                        <?php if ($key === 'inactive_owners') { ?>
                                            <!-- Summary for inactive owners - no table shown -->
                                            <div class="alert alert-primary">
                                                <h6 class="alert-heading"><i class="fas fa-info-circle"></i> Inactive Car Owners Summary</h6>
                                                <p class="mb-2">Found <strong><?= $report['count'] ?></strong> car owners who have not logged in for over 2 years or never logged in.</p>
                                                <p class="mb-0">These accounts may be abandoned or owners may have lost access. Consider reaching out via email to re-engage these users or verify if their cars should remain in the registry.</p>
                                            </div>
                                        <?php } elseif ($key === 'duplicate_emails') { ?>
                                            <!-- Enhanced Duplicate Emails Interface -->
                                            <div class="alert alert-primary mb-4">
                                                <h5 class="alert-heading"><i class="fas fa-info-circle"></i> Understanding Duplicate Emails</h5>
                                                <p class="mb-2">Multiple user accounts sharing the same email address. This may indicate:</p>
                                                <ul class="mb-0">
                                                    <li><strong>Duplicate Accounts:</strong> User accidentally created multiple accounts</li>
                                                    <li><strong>Shared Email:</strong> Multiple family members using one email</li>
                                                    <li><strong>Account Transfer:</strong> Car was transferred but new account created</li>
                                                </ul>
                                                <hr class="mt-3 mb-2">
                                                <div class="row">
                                                    <div class="col-md-8">
                                                        <p class="mb-0 small text-muted">
                                                            <strong>Recommended Actions:</strong> Review owner details, check car ownership, contact owners to clarify situation, merge accounts if appropriate.
                                                        </p>
                                                    </div>
                                                    <div class="col-md-4 text-end">
                                                        <small class="text-muted">
                                                            <span class="badge text-bg-primary badge-sm me-1"><i class="fas fa-check"></i></span>Matching Fields
                                                            <span class="badge text-bg-danger badge-sm ms-2"><i class="fas fa-exclamation-triangle"></i></span>Different Fields
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Duplicate Email Groups Container -->
                                            <div id="duplicateEmailGroups">
                                                <?php
                                                $groupIndex = 0;
                                                foreach ($report['data'] as $duplicate) {
                                                    $groupIndex++;

                                                    // Get detailed owner information for this email
                                                    $owners = getDuplicateEmailDetails($db, $duplicate->email);

                                                    // Create comparison data for highlighting differences
                                                    $ownerFields = ['fname', 'lname', 'city', 'state', 'country'];
                                                    $comparison = [];
                                                    foreach ($owners as $o) {
                                                        foreach ($ownerFields as $field) {
                                                            $comparison[$field][] = $o->$field ?? '';
                                                        }
                                                    }

                                                    // Determine if fields match or differ
                                                    $fieldMatches = [];
                                                    foreach ($ownerFields as $field) {
                                                        $values = array_unique(array_filter($comparison[$field]));
                                                        $fieldMatches[$field] = count($values) <= 1;
                                                    }
                                                ?>
                                                    <div class="duplicate-email-group card mb-4 border-warning">
                                                        <div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center">
                                                            <h5 class="mb-0">
                                                                <button class="btn btn-link text-decoration-none p-0" type="button"
                                                                        data-bs-toggle="collapse" data-bs-target="#emailGroup<?= $groupIndex ?>" aria-expanded="true">
                                                                    <i class="fas fa-chevron-down"></i>
                                                                    Group <?= $groupIndex ?>: <?= htmlspecialchars($duplicate->email) ?>
                                                                </button>
                                                            </h5>
                                                            <div>
                                                                <span class="badge text-bg-warning"><?= count($owners) ?> owners</span>
                                                                <span class="badge text-bg-secondary">Total Cars: <?= array_sum(array_column($owners, 'car_count')) ?></span>
                                                            </div>
                                                        </div>

                                                        <div class="collapse show" id="emailGroup<?= $groupIndex ?>">
                                                            <div class="card-body">
                                                                <!-- Owner Comparison Cards -->
                                                                <div class="row">
                                                                    <?php foreach ($owners as $index => $owner) {
                                                                        $isNewer = $index === count($owners) - 1 && count($owners) > 1;
                                                                        $cardClass = $isNewer ? 'owner-comparison-card newer-owner' : 'owner-comparison-card';
                                                                        $qualityClass = $owner->quality_score >= 80 ? 'success' :
                                                                                       ($owner->quality_score >= 60 ? 'warning' : 'danger');
                                                                    ?>
                                                                        <div class="col-lg-6 col-md-6 mb-3 d-flex">
                                                                            <div class="card <?= $cardClass ?> w-100">
                                                                                <!-- Card Header with Owner ID and Quality Score -->
                                                                                <div class="card-header d-flex justify-content-between align-items-center">
                                                                                    <div>
                                                                                        <strong>Owner #<?= $owner->id ?></strong>
                                                                                        <?php if ($isNewer) { ?>
                                                                                            <span class="badge text-bg-primary badge-sm ms-1">NEWER</span>
                                                                                        <?php } ?>
                                                                                    </div>
                                                                                    <div>
                                                                                        <span class="badge text-bg-<?= $qualityClass ?> badge-sm">
                                                                                            Quality: <?= $owner->quality_score ?>%
                                                                                        </span>
                                                                                    </div>
                                                                                </div>

                                                                                <!-- Prominent Date Section -->
                                                                                <div class="card-header bg-light border-top-0 pt-2 pb-2">
                                                                                    <div class="row text-center">
                                                                                        <div class="col-6">
                                                                                            <div class="timestamp-info">
                                                                                                <i class="fas fa-user-plus text-primary"></i>
                                                                                                <div class="timestamp-label">Joined</div>
                                                                                                <div class="timestamp-value"><?= date('M j, Y', strtotime($owner->join_date)) ?></div>
                                                                                                <div class="timestamp-time"><?= date('g:i A', strtotime($owner->join_date)) ?></div>
                                                                                            </div>
                                                                                        </div>
                                                                                        <div class="col-6 border-left">
                                                                                            <div class="timestamp-info">
                                                                                                <i class="fas fa-clock text-primary"></i>
                                                                                                <div class="timestamp-label">Last Login</div>
                                                                                                <?php if ($owner->last_login && $owner->last_login !== '0000-00-00 00:00:00') { ?>
                                                                                                    <div class="timestamp-value"><?= date('M j, Y', strtotime($owner->last_login)) ?></div>
                                                                                                    <div class="timestamp-time"><?= date('g:i A', strtotime($owner->last_login)) ?></div>
                                                                                                <?php } else { ?>
                                                                                                    <div class="timestamp-value text-danger">Never</div>
                                                                                                <?php } ?>
                                                                                            </div>
                                                                                        </div>
                                                                                    </div>
                                                                                </div>

                                                                                <!-- Owner Details -->
                                                                                <div class="card-body">
                                                                                    <div class="row">
                                                                                        <div class="col-sm-6">
                                                                                            <h6 class="text-primary">Personal Info</h6>
                                                                                            <p class="mb-1 <?= $fieldMatches['fname'] && $fieldMatches['lname'] ? 'field-match' : 'field-differ' ?>">
                                                                                                <strong>Name:</strong>
                                                                                                <span class="field-value">
                                                                                                    <?= htmlspecialchars($owner->fname ?: 'Missing') ?>
                                                                                                    <?= htmlspecialchars($owner->lname ?: 'Missing') ?>
                                                                                                </span>
                                                                                                <?= !($fieldMatches['fname'] && $fieldMatches['lname']) ?
                                                                                                    '<i class="fas fa-exclamation-triangle text-warning ms-1" title="Different values"></i>' :
                                                                                                    '<i class="fas fa-check text-primary ms-1" title="Values match"></i>' ?>
                                                                                            </p>
                                                                                            <p class="mb-1">
                                                                                                <strong>Email:</strong> <?= htmlspecialchars($owner->email) ?>
                                                                                                <i class="fas fa-check text-primary ms-1" title="Same email (expected)"></i>
                                                                                            </p>
                                                                                            <p class="mb-1 <?= $fieldMatches['city'] && $fieldMatches['state'] && $fieldMatches['country'] ? 'field-match' : 'field-differ' ?>">
                                                                                                <strong>Location:</strong>
                                                                                                <span class="field-value">
                                                                                                    <?php
                                                                                                    $locationParts = array_filter([$owner->city, $owner->state, $owner->country]);
                                                                                                    echo htmlspecialchars(implode(', ', $locationParts) ?: 'Missing');
                                                                                                    ?>
                                                                                                </span>
                                                                                                <?= !($fieldMatches['city'] && $fieldMatches['state'] && $fieldMatches['country']) ?
                                                                                                    '<i class="fas fa-exclamation-triangle text-warning ms-1" title="Different values"></i>' :
                                                                                                    '<i class="fas fa-check text-primary ms-1" title="Values match"></i>' ?>
                                                                                            </p>
                                                                                            <p class="mb-1">
                                                                                                <strong>Coordinates:</strong>
                                                                                                <?php if ($owner->lat && $owner->lon) { ?>
                                                                                                    <?= number_format((float)$owner->lat, 4) ?>, <?= number_format((float)$owner->lon, 4) ?>
                                                                                                    <i class="fas fa-check text-primary ms-1"></i>
                                                                                                <?php } else { ?>
                                                                                                    <span class="text-danger">Missing</span>
                                                                                                    <i class="fas fa-times text-danger ms-1"></i>
                                                                                                <?php } ?>
                                                                                            </p>
                                                                                        </div>

                                                                                        <div class="col-sm-6">
                                                                                            <h6 class="text-primary">Car Ownership</h6>
                                                                                            <?php if ($owner->car_count > 0) { ?>
                                                                                                <p class="mb-2">
                                                                                                    <span class="badge text-bg-primary"><?= $owner->car_count ?> <?= $owner->car_count === 1 ? 'car' : 'cars' ?></span>
                                                                                                </p>
                                                                                                <div class="car-list-container" style="max-height: 200px; overflow-y: auto;">
                                                                                                    <table class="table table-sm table-bordered mb-0">
                                                                                                        <thead class="thead-light">
                                                                                                            <tr>
                                                                                                                <th>ID</th>
                                                                                                                <th>Model</th>
                                                                                                                <th>Chassis</th>
                                                                                                            </tr>
                                                                                                        </thead>
                                                                                                        <tbody>
                                                                                                            <?php foreach ($owner->cars as $car) { ?>
                                                                                                                <tr>
                                                                                                                    <td>
                                                                                                                        <a href="<?= $us_url_root ?>app/cars/details.php?car_id=<?= $car->id ?>"
                                                                                                                           target="_blank" class="badge text-bg-primary">
                                                                                                                            <?= $car->id ?>
                                                                                                                        </a>
                                                                                                                    </td>
                                                                                                                    <td>
                                                                                                                        <small>
                                                                                                                            <?= htmlspecialchars($car->year ?: '?') ?>
                                                                                                                            <?= htmlspecialchars($car->type ?: '?') ?>
                                                                                                                            <?php if ($car->series) { ?>
                                                                                                                                <span class="badge text-bg-secondary badge-sm"><?= htmlspecialchars($car->series) ?></span>
                                                                                                                            <?php } ?>
                                                                                                                        </small>
                                                                                                                    </td>
                                                                                                                    <td><small><?= htmlspecialchars($car->chassis ?: 'Missing') ?></small></td>
                                                                                                                </tr>
                                                                                                            <?php } ?>
                                                                                                        </tbody>
                                                                                                    </table>
                                                                                                </div>
                                                                                            <?php } else { ?>
                                                                                                <p class="mb-0">
                                                                                                    <span class="badge text-bg-secondary">0 cars</span>
                                                                                                </p>
                                                                                            <?php } ?>
                                                                                        </div>
                                                                                    </div>

                                                                                    <!-- Action Buttons -->
                                                                                    <div class="mt-3 pt-3 border-top">
                                                                                        <div class="d-flex justify-content-between">
                                                                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                                                                    onclick="loadOwnerById(<?= $owner->id ?>)"
                                                                                                    title="Edit Owner Profile">
                                                                                                <i class="fas fa-edit"></i> Edit Profile
                                                                                            </button>
                                                                                            <button type="button" class="btn btn-sm btn-outline-warning"
                                                                                                    onclick="openAdminContactModal(
                                                                                                        {id: '<?= $owner->car_count ?>', year: '', model: '', chassis: '', series: ''},
                                                                                                        {id: '<?= $owner->id ?>', name: '<?= htmlspecialchars(trim(($owner->fname ?: '') . ' ' . ($owner->lname ?: ''))) ?>', email: '<?= htmlspecialchars($owner->email) ?>'},
                                                                                                        'Duplicate Email Addresses'
                                                                                                    )"
                                                                                                    title="Contact Owner via Registry">
                                                                                                <i class="fas fa-envelope"></i> Contact
                                                                                            </button>
                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    <?php } ?>
                                                                </div> <!-- Close row -->
                                                            </div> <!-- Close card-body -->
                                                        </div> <!-- Close collapse -->
                                                    </div> <!-- Close duplicate-email-group card -->
                                                <?php } ?>
                                            </div> <!-- Close duplicateEmailGroups -->
                                        <?php } else { ?>
                                            <!-- Owner report table -->
                                            <div class="table-responsive">
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
                                                                <td><?= htmlspecialchars($owner->email) ?></td>
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
                                                                    <button type="button" class="btn btn-sm btn-outline-primary me-1"
                                                                            onclick="loadOwnerById(<?= $owner->id ?>)"
                                                                            title="Edit Owner Profile">
                                                                        <i class="fas fa-edit"></i> Edit
                                                                    </button>
                                                                    <?php if ($key === 'owners_missing_info') { ?>
                                                                    <button type="button" class="btn btn-sm btn-outline-warning"
                                                                            onclick="openAdminContactModal(
                                                                                {id: '<?= $owner->car_count ?? 'Multiple' ?>', year: '', model: '', chassis: '', series: ''},
                                                                                {id: '<?= $owner->id ?>', name: '<?= htmlspecialchars(trim($owner->fname . ' ' . $owner->lname)) ?>', email: '<?= htmlspecialchars($owner->email) ?>'},
                                                                                'Missing Information'
                                                                            )" title="Contact Owner via Registry">
                                                                        <i class="fas fa-envelope"></i>
                                                                    </button>
                                                                    <?php } ?>
                                                                </td>
                                                            </tr>
                                                        <?php } ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<!-- Scripts for Manage Owners-->
<script>
// Manage OwnersJavaScript
let currentOwnerId = null;
let searchTimeout = null;

// Initialize owner management
$(document).ready(function() {
    // Auto-load owner if ID provided in URL
    <?php if ($selectedOwnerId): ?>
        loadOwnerById(<?= $selectedOwnerId // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>);
    <?php endif; ?>

    // Setup search functionality
    $('#ownerSearchInput').on('input', function() {
        clearTimeout(searchTimeout);
        const query = $(this).val().trim();

        if (query.length >= 2) {
            searchTimeout = setTimeout(() => searchOwners(query), 300);
        } else if (query.length === 0) {
            clearSearchResults();
        }
    });

    $('#ownerSearchBtn').click(function() {
        const query = $('#ownerSearchInput').val().trim();
        if (query.length >= 2) {
            searchOwners(query);
        }
    });

    $('#ownerClearBtn').click(function() {
        clearSearchResults();
        closeOwnerProfile();
    });

    // Handle Enter key in search
    $('#ownerSearchInput').keypress(function(e) {
        if (e.which === 13) {
            $('#ownerSearchBtn').click();
        }
    });
});

// Search owners functionality
function searchOwners(query) {
    $('#ownerSearchResults').html('<div class="text-center py-2"><i class="fas fa-spinner fa-spin"></i> Searching...</div>');

    new ElanRegistryAPI()
        .post('<?= $us_url_root ?>app/admin/includes/process-owner-search.php', { query: query })
        .then(function(response) {
            displaySearchResults(response.owners);
        })
        .catch(function() {
            $('#ownerSearchResults').html('<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Search failed. Please try again.</div>');
        });
}

// Display search results
function displaySearchResults(owners) {
    if (owners.length === 0) {
        $('#ownerSearchResults').html('<div class="alert alert-primary"><i class="fas fa-info-circle"></i> No owners found matching your search.</div>');
        return;
    }

    let html = '<div class="table-responsive"><table class="table table-hover"><thead><tr>';
    html += '<th>Name</th><th>Email</th><th>Location</th><th>Data Quality</th><th>Actions</th>';
    html += '</tr></thead><tbody>';

    owners.forEach(function(owner) {
        const qualityClass = owner.quality_score >= 80 ? 'success' : (owner.quality_score >= 60 ? 'warning' : 'danger');
        const location = [owner.city, owner.state, owner.country].filter(Boolean).join(', ') || 'Not specified';

        html += `<tr onclick="loadOwnerById(${owner.id})" style="cursor: pointer;">`;
        html += `<td><strong>${owner.fname} ${owner.lname}</strong></td>`;
        html += `<td>${owner.email}</td>`;
        html += `<td>${location}</td>`;
        html += `<td><span class="badge badge-${qualityClass}">${owner.quality_score}%</span></td>`;
        html += `<td><button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); loadOwnerById(${owner.id})"><i class="fas fa-edit"></i> Edit</button></td>`;
        html += '</tr>';
    });

    html += '</tbody></table></div>';
    $('#ownerSearchResults').html(html);
}

// Load owner by ID
function loadOwnerById(ownerId) {
    currentOwnerId = ownerId;
    $('#ownerProfilePanel').show();

    // Scroll to the panel smoothly
    $('html, body').animate({
        scrollTop: $('#ownerProfilePanel').offset().top - 100
    }, 500);

    // Update URL without reload
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.set('owner_id', ownerId);
    window.history.replaceState({}, '', currentUrl);

    // Load owner profile form
    $.ajax({
        url: 'includes/load-owner-profile.php',
        method: 'POST',
        data: {
            owner_id: ownerId,
            csrf: '<?= Token::generate() ?>'
        },
        success: function(response) {
            $('#ownerProfileForm').html(response);
        },
        error: function() {
            $('#ownerProfileForm').html('<div class="alert alert-danger">Failed to load owner profile.</div>');
        }
    });

    // Load owner information sidebar
    $.ajax({
        url: 'includes/load-owner-info.php',
        method: 'POST',
        data: {
            owner_id: ownerId,
            csrf: '<?= Token::generate() ?>'
        },
        success: function(response) {
            $('#ownerInfoSidebar').html(response);
        },
        error: function() {
            $('#ownerInfoSidebar').html('<div class="alert alert-danger">Failed to load owner information.</div>');
        }
    });
}

// Clear search results
function clearSearchResults() {
    $('#ownerSearchInput').val('');
    $('#ownerSearchResults').html('<div class="text-center py-4 text-muted"><i class="fas fa-users fa-2x mb-2"></i><p>Search for owners to view and edit their profiles</p></div>');
}

// Close owner profile panel
function closeOwnerProfile() {
    $('#ownerProfilePanel').hide();
    currentOwnerId = null;

    // Remove owner_id from URL
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.delete('owner_id');
    window.history.replaceState({}, '', currentUrl);
}

// Integration function for data quality tab (updated to point to manage-cars)
function switchToDataQualityTab() {
    // This function should be available from the main admin interface
    if (typeof switchToTab === 'function') {
        switchToTab('manage-cars');
    } else {
        showNotification('Please use the Manage Cars tab to view quality reports.', 'info');
    }
}

// Function to be called from manage cars tab for direct owner editing
function switchToOwnerManagementTab(ownerId) {
    if (typeof switchToTab === 'function') {
        switchToTab('owner-mgmt');
        // Wait for tab switch then load owner
        setTimeout(() => loadOwnerById(ownerId), 100);
    }
}
</script>