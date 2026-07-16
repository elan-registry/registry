/* exported initOwnerProfileForm */

(function() {
'use strict';

function syncLocationToCars(ownerId) {
    var btn = $('[data-sync-owner-id="' + ownerId + '"]');
    var originalText = btn.html();

    btn.html('<i class="fas fa-spinner fa-spin"></i> Syncing...').prop('disabled', true);

    new ElanRegistryAPI()
        .post(window.elanUrlRoot + 'app/admin/includes/process-owner-sync-location.php', { owner_id: ownerId })
        .then(function(response) {
            $('#ownerProfileForm').prepend(
                '<div class="alert alert-success alert-dismissible fade show">' +
                '<i class="fas fa-check"></i> ' + escapeHtml(response.message) +
                '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                '</div>'
            );
        })
        .catch(function(error) {
            console.error('Location sync failed:', error);
            $('#ownerProfileForm').prepend(
                '<div class="alert alert-danger alert-dismissible fade show">' +
                '<i class="fas fa-exclamation-circle"></i> ' + escapeHtml(error.message || 'Sync failed. Please try again.') +
                '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                '</div>'
            );
        })
        .finally(function() {
            btn.html(originalText).prop('disabled', false);
        });
}

function initOwnerProfileForm(profileData) {
    // Store original values for change detection
    $('#ownerProfileUpdateForm input, #ownerProfileUpdateForm select').each(function() {
        $(this).data('original-value', $(this).val());
    });

    // Initialize LocationPicker
    if (document.getElementById('location-picker-admin')) {
        var locationPicker = new LocationPicker({
            containerId: 'location-picker-admin',
            csrfToken: profileData.csrfToken,
            urlRoot: window.elanUrlRoot,
            showGPS: true,
            required: false
        });
        if (profileData.location.city && profileData.location.country) {
            var displayText = [profileData.location.city, profileData.location.state, profileData.location.country]
                .filter(Boolean).join(', ');
            locationPicker.setLocation(profileData.location, displayText);
        }
    }

    // Use namespaced events to prevent accumulation across multiple profile loads
    $('#ownerProfileUpdateForm').off('submit.ownerProfile').on('submit.ownerProfile', function(e) {
        e.preventDefault();
        var formDataObj = {};
        $(this).serializeArray().forEach(function(item) {
            formDataObj[item.name] = item.value;
        });
        var submitBtn = $(this).find('button[type="submit"]');
        var originalText = submitBtn.html();

        submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Saving...').prop('disabled', true);

        new ElanRegistryAPI()
            .post(window.elanUrlRoot + 'app/admin/includes/process-owner-update.php', formDataObj)
            .then(function(response) {
                $('#ownerProfileForm').prepend(
                    '<div class="alert alert-success alert-dismissible fade show">' +
                    '<i class="fas fa-check"></i> ' + escapeHtml(response.message) +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                    '</div>'
                );
                setTimeout(function() {
                    if (typeof window.loadOwnerById === 'function') {
                        window.loadOwnerById(profileData.ownerId);
                    }
                }, 1500);
                var searchQuery = $('#ownerSearchInput').val();
                if (typeof window.searchOwners === 'function' && searchQuery.length >= 2) {
                    window.searchOwners(searchQuery);
                }
            })
            .catch(function(error) {
                console.error('Owner profile update failed:', error);
                $('#ownerProfileForm').prepend(
                    '<div class="alert alert-danger alert-dismissible fade show">' +
                    '<i class="fas fa-exclamation-circle"></i> ' + escapeHtml(error.message || 'Update failed. Please try again.') +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                    '</div>'
                );
            })
            .finally(function() {
                submitBtn.html(originalText).prop('disabled', false);
            });
    });

    // Sync location button — use a scoped selector to the current form container
    $('[data-sync-owner-id]').off('click.syncLocation').on('click.syncLocation', function() {
        syncLocationToCars(parseInt(this.dataset.syncOwnerId, 10));
    });
}

window.initOwnerProfileForm = initOwnerProfileForm;

}());
