<?php
declare(strict_types=1);
/**
 * load-owner-profile.php
 * AJAX endpoint for loading owner profile editing form
 *
 * Returns HTML form for editing owner profiles with ElanRegistryOwner integration
 */

// Include required files
require_once '../../../users/init.php';

// Security check - admin permission required
if (!$user->isLoggedIn() || !hasPerm([1, 2], $user->data()->id)) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Unauthorized access</div>';
    exit;
}

// CSRF protection
if (!isset($_POST['csrf']) || !Token::check($_POST['csrf'])) {
    http_response_code(400);
    echo '<div class="alert alert-danger">Invalid CSRF token</div>';
    exit;
}

// Validate owner ID
$ownerId = (int)($_POST['owner_id'] ?? 0);
if ($ownerId <= 0) {
    echo '<div class="alert alert-danger">Invalid owner ID</div>';
    exit;
}

try {
    // Load owner data using ElanRegistryOwner
    $owner = new ElanRegistryOwner($ownerId);
    $ownerData = $owner->data();

    if (!$ownerData) {
        echo '<div class="alert alert-warning">Owner not found</div>';
        exit;
    }

    // Get quality information
    $qualityScore = $owner->getProfileQualityScore();
    $missingFields = $owner->validateProfileCompleteness();

    // Get country list for dropdown
    $countryQuery = $db->query("SELECT DISTINCT name FROM country ORDER BY name");
    $countries = $countryQuery->count() > 0 ? $countryQuery->results() : [];

    ?>
    <form id="ownerProfileUpdateForm" method="post">
        <input type="hidden" name="owner_id" value="<?= $ownerId ?>">
        <input type="hidden" name="csrf" value="<?= Token::generate() ?>">

        <!-- Profile Quality Indicator -->
        <div class="alert alert-<?= $qualityScore >= 80 ? 'success' : ($qualityScore >= 60 ? 'warning' : 'danger') ?> mb-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h6 class="mb-1">
                        <i class="fas fa-chart-pie"></i> Profile Quality Score: <strong><?= $qualityScore ?>%</strong>
                    </h6>
                    <?php if (!empty($missingFields)): ?>
                        <small>Missing: <?= implode(', ', $missingFields) ?></small>
                    <?php else: ?>
                        <small>Profile is complete!</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 text-right">
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-<?= $qualityScore >= 80 ? 'success' : ($qualityScore >= 60 ? 'warning' : 'danger') ?>"
                             style="width: <?= $qualityScore ?>%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Basic Information -->
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-user"></i> Basic Information</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="fname">First Name <span class="text-danger">*</span></label>
                            <input type="text"
                                   class="form-control <?= empty($ownerData->fname) ? 'border-warning' : '' ?>"
                                   id="fname"
                                   name="fname"
                                   value="<?= htmlspecialchars($ownerData->fname ?? '') ?>"
                                   required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="lname">Last Name <span class="text-danger">*</span></label>
                            <input type="text"
                                   class="form-control <?= empty($ownerData->lname) ? 'border-warning' : '' ?>"
                                   id="lname"
                                   name="lname"
                                   value="<?= htmlspecialchars($ownerData->lname ?? '') ?>"
                                   required>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="email">Email Address <span class="text-danger">*</span></label>
                    <input type="email"
                           class="form-control <?= empty($ownerData->email) ? 'border-warning' : '' ?>"
                           id="email"
                           name="email"
                           value="<?= htmlspecialchars($ownerData->email ?? '') ?>"
                           required>
                </div>
                <div class="form-group">
                    <label for="website">Website</label>
                    <input type="url"
                           class="form-control"
                           id="website"
                           name="website"
                           value="<?= htmlspecialchars($ownerData->website ?? '') ?>"
                           placeholder="https://example.com">
                </div>
            </div>
        </div>

        <!-- Location Information -->
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="fas fa-map-marker-alt"></i> Location Information
                    <small class="text-muted">(with automatic geocoding)</small>
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="city">City</label>
                            <input type="text"
                                   class="form-control <?= empty($ownerData->city) ? 'border-warning' : '' ?>"
                                   id="city"
                                   name="city"
                                   value="<?= htmlspecialchars($ownerData->city ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="state">State/Province</label>
                            <input type="text"
                                   class="form-control <?= empty($ownerData->state) ? 'border-warning' : '' ?>"
                                   id="state"
                                   name="state"
                                   value="<?= htmlspecialchars($ownerData->state ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label for="country">Country</label>
                            <select class="form-control <?= empty($ownerData->country) ? 'border-warning' : '' ?>"
                                    id="country"
                                    name="country">
                                <option value="">Select Country</option>
                                <?php foreach ($countries as $country): ?>
                                    <option value="<?= htmlspecialchars($country->name) ?>"
                                            <?= ($ownerData->country ?? '') === $country->name ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($country->name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Coordinates Display -->
                <?php if (!empty($ownerData->lat) && !empty($ownerData->lon)): ?>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Latitude</label>
                                <input type="text" class="form-control" value="<?= $ownerData->lat ?>" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Longitude</label>
                                <input type="text" class="form-control" value="<?= $ownerData->lon ?>" readonly>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        Coordinates will be automatically generated when location is saved.
                    </div>
                <?php endif; ?>

                <!-- Location Actions -->
                <div class="mt-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="syncLocationToCars(<?= $ownerId ?>)">
                        <i class="fas fa-sync"></i> Sync Location to Owned Cars
                    </button>
                </div>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="card">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeOwnerProfile()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                    <div class="col-md-4 text-right">
                        <small class="text-muted">
                            User ID: <?= $ownerData->id ?> | Joined: <?= date('M j, Y', strtotime($ownerData->join_date)) ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <script>
    // Store original values for change detection
    $(document).ready(function() {
        $('#ownerProfileUpdateForm input, #ownerProfileUpdateForm select').each(function() {
            $(this).data('original-value', $(this).val());
        });
    });

    // Handle form submission
    $('#ownerProfileUpdateForm').on('submit', function(e) {
        e.preventDefault();

        const formData = $(this).serialize();
        const submitBtn = $(this).find('button[type="submit"]');
        const originalText = submitBtn.html();

        // Show loading state
        submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Saving...').prop('disabled', true);

        // Add visual feedback for location fields if they're being updated
        const locationFields = ['city', 'state', 'country'];
        const hasLocationChanges = locationFields.some(field =>
            $(this).find(`[name="${field}"]`).val() !== $(this).find(`[name="${field}"]`).data('original-value')
        );

        if (hasLocationChanges) {
            locationFields.forEach(field => {
                const fieldElement = $(this).find(`[name="${field}"]`);
                if (fieldElement.val()) {
                    fieldElement.addClass('border-info').attr('title', 'Location will be geocoded...');
                }
            });
        }

        $.ajax({
            url: 'includes/process-owner-update.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Build success message
                    let successMessage = '<i class="fas fa-check"></i> ' + response.message;

                    // Add geocoding feedback if available
                    let alertClass = 'alert-success';
                    if (response.geocoding_success) {
                        successMessage += '<br><div class="mt-2"><i class="fas fa-map-marker-alt text-success"></i> ' +
                            '<strong class="text-success">' + response.geocoding_message + '</strong></div>';
                    } else if (response.geocoding_failed) {
                        successMessage += '<br><div class="mt-2"><i class="fas fa-exclamation-circle text-danger"></i> ' +
                            '<strong class="text-danger">' + response.geocoding_message + '</strong></div>';
                        alertClass = 'alert-warning'; // Mixed success (data saved) but geocoding failed
                    } else if (response.geocoding_message) {
                        successMessage += '<br><div class="mt-2"><i class="fas fa-exclamation-triangle text-warning"></i> ' +
                            '<span class="text-warning">' + response.geocoding_message + '</span></div>';
                        alertClass = 'alert-info';
                    }

                    // Show success message with geocoding feedback
                    $('#ownerProfileForm').prepend(
                        '<div class="alert ' + alertClass + ' alert-dismissible fade show">' +
                        successMessage +
                        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                        '</div>'
                    );

                    // Update coordinates display if geocoding was successful
                    if (response.geocoding_success && response.new_coordinates) {
                        updateCoordinatesDisplay(response.new_coordinates.lat, response.new_coordinates.lon);
                    }

                    // Reload the profile form to show updated data
                    setTimeout(() => {
                        loadOwnerById(<?= $ownerId ?>);
                    }, 2000); // Extended timeout to let user see the geocoding message

                    // Refresh search results if visible
                    const searchQuery = $('#ownerSearchInput').val();
                    if (searchQuery.length >= 2) {
                        searchOwners(searchQuery);
                    }
                } else {
                    $('#ownerProfileForm').prepend(
                        '<div class="alert alert-danger alert-dismissible fade show">' +
                        '<i class="fas fa-exclamation-triangle"></i> ' + response.message +
                        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                        '</div>'
                    );
                }
            },
            error: function() {
                $('#ownerProfileForm').prepend(
                    '<div class="alert alert-danger alert-dismissible fade show">' +
                    '<i class="fas fa-exclamation-circle"></i> Update failed. Please try again.' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '</div>'
                );
            },
            complete: function() {
                submitBtn.html(originalText).prop('disabled', false);

                // Clean up location field styling
                const locationFields = ['city', 'state', 'country'];
                locationFields.forEach(field => {
                    $(`[name="${field}"]`).removeClass('border-info').removeAttr('title');
                });
            }
        });
    });

    // Sync location to cars function
    function syncLocationToCars(ownerId) {
        const btn = $('button[onclick="syncLocationToCars(' + ownerId + ')"]');
        const originalText = btn.html();

        btn.html('<i class="fas fa-spinner fa-spin"></i> Syncing...').prop('disabled', true);

        $.ajax({
            url: 'includes/process-owner-sync-location.php',
            method: 'POST',
            data: {
                owner_id: ownerId,
                csrf: '<?= Token::generate() ?>'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#ownerProfileForm').prepend(
                        '<div class="alert alert-success alert-dismissible fade show">' +
                        '<i class="fas fa-check"></i> ' + response.message +
                        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                        '</div>'
                    );
                } else {
                    $('#ownerProfileForm').prepend(
                        '<div class="alert alert-warning alert-dismissible fade show">' +
                        '<i class="fas fa-exclamation-triangle"></i> ' + response.message +
                        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                        '</div>'
                    );
                }
            },
            error: function() {
                $('#ownerProfileForm').prepend(
                    '<div class="alert alert-danger alert-dismissible fade show">' +
                    '<i class="fas fa-exclamation-circle"></i> Sync failed. Please try again.' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '</div>'
                );
            },
            complete: function() {
                btn.html(originalText).prop('disabled', false);
            }
        });
    }

    // Function to update coordinates display immediately after successful geocoding
    function updateCoordinatesDisplay(lat, lon) {
        // Update existing coordinate fields if they exist
        const latField = $('input[value="' + <?= json_encode($ownerData->lat ?? '') ?> + '"][readonly]');
        const lonField = $('input[value="' + <?= json_encode($ownerData->lon ?? '') ?> + '"][readonly]');

        if (latField.length) {
            latField.val(lat).addClass('border-success').effect('highlight', {color: '#28a745'}, 1500);
        }
        if (lonField.length) {
            lonField.val(lon).addClass('border-success').effect('highlight', {color: '#28a745'}, 1500);
        }

        // If coordinates section doesn't exist, replace the info message
        const infoAlert = $('.alert-info:contains("Coordinates will be automatically generated")');
        if (infoAlert.length) {
            infoAlert.removeClass('alert-info').addClass('alert-success')
                .html('<i class="fas fa-check-circle"></i> <strong>Coordinates generated successfully!</strong><br>' +
                     'Latitude: <code>' + lat + '</code> | Longitude: <code>' + lon + '</code>')
                .effect('highlight', {color: '#28a745'}, 2000);
        }
    }
    </script>

    <?php

} catch (Exception $e) {
    logger($user->data()->id, 'SystemError', 'Owner profile load failed: ' . $e->getMessage());
    echo '<div class="alert alert-danger">Failed to load owner profile. Please try again.</div>';
}
?>