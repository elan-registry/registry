/* exported loadOwnerById, searchOwners, switchToDataQualityTab, closeOwnerProfile */

(function() {
'use strict';

let searchTimeout = null;

$(document).ready(function() {
    const initialOwnerId = parseInt(new URLSearchParams(window.location.search).get('owner_id') || '0', 10);
    if (initialOwnerId > 0) {
        loadOwnerById(initialOwnerId);
    }

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

    $('#ownerSearchInput').keypress(function(e) {
        if (e.which === 13) {
            $('#ownerSearchBtn').click();
        }
    });

    $('#ownerSearchResults').on('click', 'tr[data-owner-id]', function() {
        const ownerId = parseInt($(this).data('owner-id'), 10);
        if (!isNaN(ownerId) && ownerId > 0) {
            loadOwnerById(ownerId);
        }
    });
});

function searchOwners(query) {
    $('#ownerSearchResults').html('<div class="text-center py-2"><i class="fas fa-spinner fa-spin"></i> Searching...</div>');

    new ElanRegistryAPI()
        .post(window.elanUrlRoot + 'app/admin/includes/process-owner-search.php', { query: query })
        .then(function(response) {
            displaySearchResults(response.owners);
        })
        .catch(function(error) {
            console.error('Owner search failed for query:', query, error);
            $('#ownerSearchResults').html('<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Search failed. Please try again.</div>');
        });
}

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
        const location = escapeHtml([owner.city, owner.state, owner.country].filter(Boolean).join(', ') || 'Not specified');
        const ownerId = parseInt(owner.id, 10);

        html += `<tr data-owner-id="${ownerId}" style="cursor: pointer;">`;
        html += `<td><strong>${escapeHtml(owner.fname)} ${escapeHtml(owner.lname)}</strong></td>`;
        html += `<td>${escapeHtml(owner.email)}</td>`;
        html += `<td>${location}</td>`;
        html += `<td><span class="badge badge-${qualityClass}">${parseInt(owner.quality_score, 10)}%</span></td>`;
        html += `<td><button class="btn btn-sm btn-primary"><i class="fas fa-edit"></i> Edit</button></td>`;
        html += '</tr>';
    });

    html += '</tbody></table></div>';
    $('#ownerSearchResults').html(html);
}

function loadOwnerById(ownerId) {
    $('#ownerProfilePanel').show();

    $('html, body').animate({
        scrollTop: $('#ownerProfilePanel').offset().top - 100
    }, 500);

    const currentUrl = new URL(window.location);
    currentUrl.searchParams.set('owner_id', ownerId);
    window.history.replaceState({}, '', currentUrl);

    new ElanRegistryAPI()
        .post(window.elanUrlRoot + 'app/admin/includes/load-owner-profile.php', { owner_id: ownerId })
        .then(function(response) {
            $('#ownerProfileForm').html(response.html);
        })
        .catch(function(error) {
            console.error('Failed to load owner profile for ID', ownerId, error);
            $('#ownerProfileForm').html('<div class="alert alert-danger">Failed to load owner profile.</div>');
        });

    new ElanRegistryAPI()
        .post(window.elanUrlRoot + 'app/admin/includes/load-owner-info.php', { owner_id: ownerId })
        .then(function(response) {
            $('#ownerInfoSidebar').html(response.html);
        })
        .catch(function(error) {
            console.error('Failed to load owner info for ID', ownerId, error);
            $('#ownerInfoSidebar').html('<div class="alert alert-danger">Failed to load owner information.</div>');
        });
}

function clearSearchResults() {
    $('#ownerSearchInput').val('');
    $('#ownerSearchResults').html('<div class="text-center py-4 text-muted"><i class="fas fa-users fa-2x mb-2"></i><p>Search for owners to view and edit their profiles</p></div>');
}

function closeOwnerProfile() {
    $('#ownerProfilePanel').hide();

    const currentUrl = new URL(window.location);
    currentUrl.searchParams.delete('owner_id');
    window.history.replaceState({}, '', currentUrl);
}

// Redirects to manage-cars tab; called from car quality report links in PHP template
function switchToDataQualityTab() {
    window.location.href = '?tab=manage-cars';
}

// Expose functions called from data-action handlers in admin-core.js and from
// the load-owner-profile.php AJAX response body.
window.loadOwnerById          = loadOwnerById;
window.searchOwners           = searchOwners;
window.switchToDataQualityTab = switchToDataQualityTab;
window.closeOwnerProfile      = closeOwnerProfile;

}());
