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
if (!$user->isLoggedIn() || !isRegistryAdmin($user->data()->id)) {
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
                </h6>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">
                    <i class="fas fa-info-circle"></i>
                    Use the GPS button or search for the owner's location below.
                </p>

                <!-- Location Picker Component -->
                <div id="location-picker-admin" class="location-picker-container mb-3"></div>

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

        // Initialize Location Picker for admin
        if (document.getElementById('location-picker-admin')) {
            const urlRoot = '<?php echo $us_url_root ?? '/'; ?>';

            const currentLocation = {
                city: '<?= htmlspecialchars($ownerData->city ?? '', ENT_QUOTES) ?>',
                state: '<?= htmlspecialchars($ownerData->state ?? '', ENT_QUOTES) ?>',
                country: '<?= htmlspecialchars($ownerData->country ?? '', ENT_QUOTES) ?>',
                lat: '<?= $ownerData->lat ?? '' ?>',
                lon: '<?= $ownerData->lon ?? '' ?>'
            };

            const locationPicker = new LocationPicker({
                containerId: 'location-picker-admin',
                csrfToken: '<?= Token::generate() ?>',
                urlRoot: urlRoot,
                showGPS: true,
                required: false
            });

            // Pre-populate with current location if available
            if (currentLocation.city && currentLocation.country) {
                const displayText = [currentLocation.city, currentLocation.state, currentLocation.country]
                    .filter(Boolean).join(', ');
                locationPicker.setLocation(currentLocation, displayText);
            }
        }
    });

    // Handle form submission
    $('#ownerProfileUpdateForm').on('submit', function(e) {
        e.preventDefault();

        const formDataObj = {};
        $(this).serializeArray().forEach(function(item) {
            formDataObj[item.name] = item.value;
        });
        const submitBtn = $(this).find('button[type="submit"]');
        const originalText = submitBtn.html();

        // Show loading state
        submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Saving...').prop('disabled', true);

        new ElanRegistryAPI()
            .post('<?= $us_url_root ?>app/admin/includes/process-owner-update.php', formDataObj)
            .then(function(response) {
                // Show success message
                $('#ownerProfileForm').prepend(
                    '<div class="alert alert-success alert-dismissible fade show">' +
                    '<i class="fas fa-check"></i> ' + response.message +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '</div>'
                );

                // Reload the profile form to show updated data
                setTimeout(() => {
                    loadOwnerById(<?= $ownerId ?>);
                }, 1500);

                // Refresh search results if visible
                const searchQuery = $('#ownerSearchInput').val();
                if (searchQuery.length >= 2) {
                    searchOwners(searchQuery);
                }
            })
            .catch(function(error) {
                $('#ownerProfileForm').prepend(
                    '<div class="alert alert-danger alert-dismissible fade show">' +
                    '<i class="fas fa-exclamation-circle"></i> ' + (error.message || 'Update failed. Please try again.') +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '</div>'
                );
            })
            .finally(function() {
                submitBtn.html(originalText).prop('disabled', false);
            });
    });

    // Sync location to cars function
    function syncLocationToCars(ownerId) {
        const btn = $('button[onclick="syncLocationToCars(' + ownerId + ')"]');
        const originalText = btn.html();

        btn.html('<i class="fas fa-spinner fa-spin"></i> Syncing...').prop('disabled', true);

        new ElanRegistryAPI()
            .post('<?= $us_url_root ?>app/admin/includes/process-owner-sync-location.php', { owner_id: ownerId })
            .then(function(response) {
                $('#ownerProfileForm').prepend(
                    '<div class="alert alert-success alert-dismissible fade show">' +
                    '<i class="fas fa-check"></i> ' + response.message +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '</div>'
                );
            })
            .catch(function(error) {
                $('#ownerProfileForm').prepend(
                    '<div class="alert alert-danger alert-dismissible fade show">' +
                    '<i class="fas fa-exclamation-circle"></i> ' + (error.message || 'Sync failed. Please try again.') +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '</div>'
                );
            })
            .finally(function() {
                btn.html(originalText).prop('disabled', false);
            });
    }
    </script>

    <?php

} catch (OwnerNotFoundException $e) {
    logger($user->data()->id, $e->getLogCategory(), 'Owner not found: ' . $e->getMessage());
    echo '<div class="alert alert-danger">Owner not found. Please check the ID and try again.</div>';
} catch (ElanRegistryException $e) {
    logger($user->data()->id, $e->getLogCategory(), 'Owner profile load failed: ' . $e->getMessage());
    echo '<div class="alert alert-danger">' . htmlspecialchars($e->getUserMessage()) . '</div>';
} catch (Exception $e) {
    logger($user->data()->id, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Owner profile load unexpected error: ' . $e->getMessage());
    echo '<div class="alert alert-danger">Failed to load owner profile. Please try again.</div>';
}
?>