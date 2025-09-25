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

<div class="alert alert-success">
    <h4><i class="fas fa-users"></i> Manage Owners Interface</h4>
    <p class="mb-0">Search, edit, and manage owner profiles with data quality integration.</p>
</div>

<!-- Owner Statistics and Data Quality Summary -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card border-info">
            <div class="card-header bg-info text-white">
                <h6 class="mb-0"><i class="fas fa-chart-pie"></i> Owner Statistics</h6>
            </div>
            <div class="card-body">
                <div class="text-center">
                    <h3 class="text-primary"><?= number_format($systemStatus['total_users'] ?? 0) ?></h3>
                    <p class="mb-1">Active Owners</p>
                    <small class="text-muted">
                        <i class="fas fa-link"></i>
                        <a href="../../users/admin.php" target="_blank">UserSpice Admin</a>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <!-- Data Quality Summary -->
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <h6 class="mb-0"><i class="fas fa-clipboard-check"></i> Data Quality</h6>
            </div>
            <div class="card-body">
                <div class="text-center">
                    <button class="btn btn-sm btn-outline-warning" onclick="switchToDataQualityTab()">
                        <i class="fas fa-chart-line"></i> Owner Quality Reports
                    </button>
                </div>
            </div>
        </div>
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
                           value="<?= $selectedOwnerId ? 'Loading owner ID ' . $selectedOwnerId . '...' : '' ?>">
                    <div class="input-group-append">
                        <button class="btn btn-primary" type="button" id="ownerSearchBtn">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <button class="btn btn-secondary" type="button" id="ownerClearBtn">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>
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

// Get owner quality reports for display at bottom of page
$dataQualityReports = getOwnerQualityReports($db);
?>

<!-- Owner Quality Reports Section -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-users"></i> Owner Quality Reports</h5>
            </div>
            <div class="card-body">
                <!-- Owner Quality Summary Cards -->
                <div class="row mb-4">
                    <?php foreach ($dataQualityReports as $key => $report) { ?>
                        <?php if (!in_array($key, ['owners_missing_info', 'users_without_cars', 'duplicate_emails'])) continue; ?>
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
                                <div class="card-header bg-dark" data-toggle="collapse" data-target="#owner-collapse-<?= $key ?>" aria-expanded="false" style="cursor: pointer;">
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
                                <div class="collapse" id="owner-collapse-<?= $key ?>">
                                    <div class="card-body">
                                        <p class="card-text mb-3"><?= $report['description'] ?></p>

                                        <?php if ($key === 'inactive_owners') { ?>
                                            <!-- Summary for inactive owners - no table shown -->
                                            <div class="alert alert-info">
                                                <h6 class="alert-heading"><i class="fas fa-info-circle"></i> Inactive Car Owners Summary</h6>
                                                <p class="mb-2">Found <strong><?= $report['count'] ?></strong> car owners who have not logged in for over 2 years or never logged in.</p>
                                                <p class="mb-0">These accounts may be abandoned or owners may have lost access. Consider reaching out via email to re-engage these users or verify if their cars should remain in the registry.</p>
                                            </div>
                                        <?php } elseif ($key === 'duplicate_emails') { ?>
                                            <!-- Duplicate emails table -->
                                            <div class="table-responsive">
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
                                                                    <button class="btn btn-sm btn-outline-primary" onclick="loadOwnerFromEmailList('<?= htmlspecialchars($duplicate->email) ?>')" title="Edit Owners">
                                                                        <i class="fas fa-edit"></i> Edit
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        <?php } ?>
                                                    </tbody>
                                                </table>
                                            </div>
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
                                                                    <span class="badge badge-primary"><?= $owner->id ?></span>
                                                                </td>
                                                                <td>
                                                                    <?php if ($owner->fname || $owner->lname) { ?>
                                                                        <?= htmlspecialchars(trim($owner->fname . ' ' . $owner->lname)) ?>
                                                                    <?php } else { ?>
                                                                        <span class="badge badge-warning">Missing Name</span>
                                                                    <?php } ?>
                                                                </td>
                                                                <td><?= htmlspecialchars($owner->email) ?></td>
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
                                                                    <button type="button" class="btn btn-sm btn-outline-primary mr-1"
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
        loadOwnerById(<?= $selectedOwnerId ?>);
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

    $.ajax({
        url: 'includes/process-owner-search.php',
        method: 'POST',
        data: {
            query: query,
            csrf: '<?= Token::generate() ?>'
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                displaySearchResults(response.owners);
            } else {
                $('#ownerSearchResults').html('<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> ' + response.message + '</div>');
            }
        },
        error: function() {
            $('#ownerSearchResults').html('<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Search failed. Please try again.</div>');
        }
    });
}

// Display search results
function displaySearchResults(owners) {
    if (owners.length === 0) {
        $('#ownerSearchResults').html('<div class="alert alert-info"><i class="fas fa-info-circle"></i> No owners found matching your search.</div>');
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
        // Fallback - just show an alert
        alert('Please use the Manage Cars tab to view quality reports');
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