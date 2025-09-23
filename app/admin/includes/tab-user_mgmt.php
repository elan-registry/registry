<?php
declare(strict_types=1);
/**
 * tab-user_mgmt.php
 * Owner Management Tab Content
 *
 * Phase 2A: Functional ElanRegistry owner management interface
 * Implements Issue #269 with ElanRegistryOwner class integration
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
    <h4><i class="fas fa-users"></i> Owner Management Interface</h4>
    <p class="mb-0">Search, edit, and manage owner profiles with data quality integration. <strong>✅ Issue #269 Implemented</strong></p>
</div>

<!-- Owner Search and Management Interface -->
<div class="row mb-4">
    <div class="col-md-8">
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

    <div class="col-md-4">
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

        <!-- Data Quality Summary -->
        <div class="card border-warning mt-3">
            <div class="card-header bg-warning text-dark">
                <h6 class="mb-0"><i class="fas fa-clipboard-check"></i> Data Quality</h6>
            </div>
            <div class="card-body">
                <div class="text-center">
                    <button class="btn btn-sm btn-outline-warning" onclick="switchToDataQualityTab()">
                        <i class="fas fa-chart-line"></i> Quality Reports
                    </button>
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

<!-- Scripts for Owner Management -->
<script>
// Owner Management JavaScript
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

// Integration function for data quality tab
function switchToDataQualityTab() {
    // This function should be available from the main admin interface
    if (typeof switchToTab === 'function') {
        switchToTab('data_quality');
    } else {
        // Fallback - just show an alert
        alert('Please use the Data Quality tab to view quality reports');
    }
}

// Function to be called from data quality tab for direct owner editing
function switchToUserManagementTab(ownerId) {
    if (typeof switchToTab === 'function') {
        switchToTab('user_mgmt');
        // Wait for tab switch then load owner
        setTimeout(() => loadOwnerById(ownerId), 100);
    }
}
</script>