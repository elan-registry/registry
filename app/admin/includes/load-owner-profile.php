<?php
declare(strict_types=1);

use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Exceptions\OwnerNotFoundException;
use ElanRegistry\OwnerView;

require_once '../../../users/init.php';

header('Content-Type: application/json');

requireAdminAjax('owner profile', false);

$ownerId = (int)($_POST['owner_id'] ?? 0);
if ($ownerId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid owner ID']);
    exit;
}

$obLevelBefore = ob_get_level();
try {
    ob_start();
    $owner = new Owner($ownerId);
    $ownerData = $owner->data();

    if (!$ownerData) {
        if (ob_get_level() > $obLevelBefore) ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Owner not found']);
        exit;
    }

    $qualityScore = $owner->getProfileQualityScore();
    $missingFields = $owner->validateProfileCompleteness();

    ?>
    <form id="ownerProfileUpdateForm" method="post">
        <input type="hidden" name="owner_id" value="<?= $ownerId // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>">
        <input type="hidden" name="csrf" value="<?= Token::generate() ?>">

        <!-- Profile Quality Indicator -->
        <div class="alert alert-<?= OwnerView::qualityBadgeClass($qualityScore) ?> mb-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h6 class="mb-1">
                        <i class="fas fa-chart-pie"></i> Profile Quality Score: <strong><?= (int)$qualityScore // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>%</strong>
                    </h6>
                    <?php if (!empty($missingFields)): ?>
                        <small>Missing: <?= htmlspecialchars(implode(', ', $missingFields), ENT_QUOTES, 'UTF-8') ?></small>
                    <?php else: ?>
                        <small>Profile is complete!</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 text-end">
                    <?= OwnerView::displayQualityProgressBar($qualityScore) // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>
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
                        <div class="mb-3">
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
                        <div class="mb-3">
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
                <div class="mb-3">
                    <label for="email">Email Address <span class="text-danger">*</span></label>
                    <input type="email"
                           class="form-control <?= empty($ownerData->email) ? 'border-warning' : '' ?>"
                           id="email"
                           name="email"
                           value="<?= htmlspecialchars($ownerData->email ?? '') ?>"
                           required>
                </div>
                <div class="mb-3">
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
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="syncLocationToCars(<?= $ownerId // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>)">
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
                    <div class="col-md-4 text-end">
                        <small class="text-muted">
                            <?php
                            $joinTimestamp = strtotime($ownerData->join_date ?? '');
                            $joinFormatted = $joinTimestamp !== false
                                ? htmlspecialchars(date('M j, Y', $joinTimestamp), ENT_QUOTES, 'UTF-8')
                                : 'Unknown date';
                            ?>
                            User ID: <?= (int)$ownerData->id // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?> | Joined: <?= $joinFormatted // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>
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

        if (document.getElementById('location-picker-admin')) {
            const urlRoot = '<?php echo $us_url_root ?? '/'; ?>';

            const currentLocation = {
                city: '<?= htmlspecialchars($ownerData->city ?? '', ENT_QUOTES) ?>',
                state: '<?= htmlspecialchars($ownerData->state ?? '', ENT_QUOTES) ?>',
                country: '<?= htmlspecialchars($ownerData->country ?? '', ENT_QUOTES) ?>',
                lat: <?= (float)($ownerData->lat ?? 0) // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>,
                lon: <?= (float)($ownerData->lon ?? 0) // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>
            };

            const locationPicker = new LocationPicker({
                containerId: 'location-picker-admin',
                csrfToken: '<?= Token::generate() ?>',
                urlRoot: urlRoot,
                showGPS: true,
                required: false
            });

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

        const esc = NotificationHelper.escapeHtml;
        const formDataObj = {};
        $(this).serializeArray().forEach(function(item) {
            formDataObj[item.name] = item.value;
        });
        const submitBtn = $(this).find('button[type="submit"]');
        const originalText = submitBtn.html();

        submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Saving...').prop('disabled', true);

        new ElanRegistryAPI()
            .post('<?= $us_url_root ?>app/admin/includes/process-owner-update.php', formDataObj)
            .then(function(response) {
                $('#ownerProfileForm').prepend(
                    '<div class="alert alert-success alert-dismissible fade show">' +
                    '<i class="fas fa-check"></i> ' + esc(response.message) +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                    '</div>'
                );

                setTimeout(() => {
                    loadOwnerById(<?= $ownerId // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>);
                }, 1500);

                const searchQuery = $('#ownerSearchInput').val();
                if (searchQuery.length >= 2) {
                    searchOwners(searchQuery);
                }
            })
            .catch(function(error) {
                console.error('Owner profile update failed:', error);
                $('#ownerProfileForm').prepend(
                    '<div class="alert alert-danger alert-dismissible fade show">' +
                    '<i class="fas fa-exclamation-circle"></i> ' + esc(error.message || 'Update failed. Please try again.') +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                    '</div>'
                );
            })
            .finally(function() {
                submitBtn.html(originalText).prop('disabled', false);
            });
    });

    function syncLocationToCars(ownerId) {
        const esc = NotificationHelper.escapeHtml;
        const btn = $('button[onclick="syncLocationToCars(' + ownerId + ')"]');
        const originalText = btn.html();

        btn.html('<i class="fas fa-spinner fa-spin"></i> Syncing...').prop('disabled', true);

        new ElanRegistryAPI()
            .post('<?= $us_url_root ?>app/admin/includes/process-owner-sync-location.php', { owner_id: ownerId })
            .then(function(response) {
                $('#ownerProfileForm').prepend(
                    '<div class="alert alert-success alert-dismissible fade show">' +
                    '<i class="fas fa-check"></i> ' + esc(response.message) +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                    '</div>'
                );
            })
            .catch(function(error) {
                console.error('Location sync failed:', error);
                $('#ownerProfileForm').prepend(
                    '<div class="alert alert-danger alert-dismissible fade show">' +
                    '<i class="fas fa-exclamation-circle"></i> ' + esc(error.message || 'Sync failed. Please try again.') +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                    '</div>'
                );
            })
            .finally(function() {
                btn.html(originalText).prop('disabled', false);
            });
    }
    </script>

    <?php

    $html = ob_get_clean();
    $encoded = json_encode(['success' => true, 'html' => $html]);
    if ($encoded === false) {
        logger($user->data()->id, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Owner profile json_encode failed for owner ' . $ownerId . ': ' . json_last_error_msg());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to encode owner profile response.']);
        exit;
    }
    echo $encoded;

} catch (OwnerNotFoundException $e) {
    if (ob_get_level() > $obLevelBefore) ob_end_clean();
    logger($user->data()->id, $e->getLogCategory(), 'Owner not found: ' . $e->getMessage());
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Owner not found. Please check the ID and try again.']);
} catch (ElanRegistryException $e) {
    if (ob_get_level() > $obLevelBefore) ob_end_clean();
    logger($user->data()->id, $e->getLogCategory(), 'Owner profile load failed: ' . $e->getMessage());
    http_response_code($e->getHttpStatusCode());
    echo json_encode(['success' => false, 'message' => $e->getUserMessage()]);
} catch (Exception $e) {
    if (ob_get_level() > $obLevelBefore) ob_end_clean();
    logger($user->data()->id, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Owner profile load unexpected error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load owner profile. Please try again.']);
}
?>