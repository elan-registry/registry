<?php

/**
 * Auto-create SPAM cleanup settings fields if they don't exist
 */
$spamCleanupFields = [
    'elan_spam_cleanup_enabled' => ['type' => 'TINYINT(1)', 'default' => '0', 'description' => 'Enable automated SPAM cleanup'],
    'elan_spam_cleanup_dry_run' => ['type' => 'TINYINT(1)', 'default' => '1', 'description' => 'Run SPAM cleanup in dry-run mode'],
    'elan_spam_inactive_days' => ['type' => 'INT(11)', 'default' => '30', 'description' => 'Days before considering user inactive'],
    'elan_spam_grace_period_days' => ['type' => 'INT(11)', 'default' => '7', 'description' => 'Grace period days before deletion'],
    'elan_spam_max_deletions' => ['type' => 'INT(11)', 'default' => '50', 'description' => 'Maximum deletions per cleanup run'],
    'elan_spam_max_percentage' => ['type' => 'DECIMAL(4,2)', 'default' => '5.00', 'description' => 'Maximum percentage of users to cleanup'],
    'elan_spam_email_notifications' => ['type' => 'TINYINT(1)', 'default' => '0', 'description' => 'Enable grace period email notifications']
];

$fieldsToAdd = [];
foreach ($spamCleanupFields as $fieldName => $fieldConfig) {
    $checkField = $db->query("SHOW COLUMNS FROM settings LIKE ?", [$fieldName]);
    if ($checkField->count() == 0) {
        $fieldsToAdd[] = $fieldName;
    }
}

if (!empty($fieldsToAdd)) {
    try {
        foreach ($fieldsToAdd as $fieldName) {
            $fieldConfig = $spamCleanupFields[$fieldName];
            $sql = "ALTER TABLE settings ADD COLUMN {$fieldName} {$fieldConfig['type']} DEFAULT {$fieldConfig['default']} COMMENT '{$fieldConfig['description']}'";
            $db->query($sql);
        }

        // Log the addition
        logger($user->data()->id, 'SettingsUpdate', 'Auto-created SPAM cleanup settings fields: ' . implode(', ', $fieldsToAdd));

        // Show success message
        $fieldsAddedMsg = count($fieldsToAdd) . ' SPAM cleanup settings fields were automatically added to the database.';
    } catch (Exception $e) {
        $fieldsErrorMsg = 'Error adding SPAM cleanup settings fields: ' . $e->getMessage();
        logger($user->data()->id, 'SettingsError', $fieldsErrorMsg);
    }
}
?>

<div class="content mt-3">
    <?php if (isset($fieldsAddedMsg)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <strong>Database Updated:</strong> <?= $fieldsAddedMsg ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php endif; ?>

    <?php if (isset($fieldsErrorMsg)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Database Error:</strong> <?= $fieldsErrorMsg ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php endif; ?>

    <!-- Site Settings -->
    <form class="" action="admin.php?tab=7" method="post" name="custom_settings">
        <input type="hidden" name="csrf" value="<?=Token::generate()?>">
        <h2 class="mb-3">Elan Registry Settings</h2>
        
        <!-- AJAX Messages Area -->
        <div id="messages" style="display: none;">
            <span id="message"></span>
        </div>
        <div class="row">
            <div class="col-md-6">
                <!-- Google Services Integration -->
                <div class="card no-padding border-info">
                    <div class="card-header bg-info text-white">
                        <h3 class="mb-1"><i class="fab fa-google"></i> Google Services Integration</h3>
                        <small class="text-light">API keys for Maps, Geocoding, and other Google services</small>
                    </div>
                    <div class="card-body">
                        <div class="border rounded p-3 mb-3 bg-light">
                            <h5 class="text-info mb-3"><i class="fas fa-map-marked-alt"></i> Maps & Location Services</h5>

                            <div class="form-group">
                                <label for='elan_google_maps_key' class="font-weight-bold">
                                    <i class="fas fa-map"></i> Google Maps API Key
                                </label>
                                <input class="form-control ajxtxt" data-desc="Google Maps API Key" name="elan_google_maps_key" id="elan_google_maps_key" value="<?= $settings->elan_google_maps_key; ?>" placeholder="AIzaSy...">
                                <small class="form-text text-muted">
                                    <i class="fas fa-external-link-alt"></i> Required for car location maps and statistics page
                                    <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="ml-2">Get API Key</a>
                                </small>
                            </div>

                            <div class="form-group mb-0">
                                <label for="elan_google_geo_key" class="font-weight-bold">
                                    <i class="fas fa-search-location"></i> Google Geocoding API Key
                                </label>
                                <input class="form-control ajxtxt" data-desc="Google Geocoding Key" name="elan_google_geo_key" id="elan_google_geo_key" value="<?= $settings->elan_google_geo_key; ?>" placeholder="AIzaSy...">
                                <small class="form-text text-muted">
                                    <i class="fas fa-external-link-alt"></i> Converts addresses to coordinates for location sync
                                    <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="ml-2">Get API Key</a>
                                </small>
                            </div>
                        </div>

                        <div class="alert alert-sm alert-info mb-0">
                            <small><i class="fas fa-info-circle"></i> <strong>Security:</strong> Enable domain restrictions in Google Cloud Console for production use</small>
                        </div>
                    </div>
                </div>

                <!-- System Maintenance -->
                <div class='card no-padding border-secondary'>
                    <div class="card-header bg-dark text-white">
                        <h3 class="mb-1" style="color: white;"><i class="fas fa-tools"></i> System Maintenance</h3>
                        <small style="color: #f8f9fa;">Backup and system maintenance settings</small>
                    </div>
                    <div class='card-body'>
                        <div class="border rounded p-3 mb-0 bg-light">
                            <h5 class="text-secondary mb-3"><i class="fas fa-database"></i> Backup Management</h5>

                            <div class='form-group mb-0'>
                                <label for='elan_backup_age' class="font-weight-bold">
                                    <i class="fas fa-calendar-times"></i> Backup Retention Period
                                </label>
                                <div class="input-group">
                                    <input type="number" step="1" min="1" max="365" class="form-control ajxnum" data-desc="Backup Age" name="elan_backup_age" id="elan_backup_age" value="<?= $settings->elan_backup_age; ?>">
                                    <div class="input-group-append">
                                        <span class="input-group-text">days</span>
                                    </div>
                                </div>
                                <small class="form-text text-muted">How long to keep automated backups before cleanup</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Media Management -->
                <div class="card no-padding border-warning">
                    <div class="card-header bg-warning text-dark">
                        <h3 class="mb-1"><i class="fas fa-images"></i> Media Management</h3>
                        <small class="text-dark">File upload and image handling settings</small>
                    </div>
                    <div class="card-body">
                        <div class="border rounded p-3 mb-0 bg-light">
                            <h5 class="text-warning mb-3"><i class="fas fa-camera"></i> Car Photo Settings</h5>
                            
                            <div class="form-group">
                                <label for="elan_image_dir" class="font-weight-bold">
                                    <i class="fas fa-folder"></i> Image Upload Directory
                                </label>
                                <input class="form-control ajxtxt" data-desc="Image Upload Directory" name="elan_image_dir" id="elan_image_dir" value="<?= $settings->elan_image_dir; ?>" placeholder="userimages/">
                                <small class="form-text text-muted">Directory path where car images are stored (relative to site root)</small>
                            </div>
                            
                            <div class="form-group mb-0">
                                <label for='elan_image_max' class="font-weight-bold">
                                    <i class="fas fa-photo-video"></i> Maximum Photos per Car
                                </label>
                                <div class="input-group">
                                    <input type="number" step="1" min="1" max="50" class="form-control ajxnum" data-desc="Max Photo Upload" name="elan_image_max" id="elan_image_max" value="<?= $settings->elan_image_max; ?>">
                                    <div class="input-group-append">
                                        <span class="input-group-text">photos</span>
                                    </div>
                                </div>
                                <small class="form-text text-muted">Limit number of photos users can upload per car</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <!-- User Account Cleanup System -->
                <div class="card no-padding border-danger">
                    <div class="card-header bg-danger text-white">
                        <h3 class="mb-1"><i class="fas fa-user-times"></i> User Account Cleanup System</h3>
                        <small class="text-light">Automated removal of SPAM accounts and inactive users</small>
                    </div>
                    <div class="card-body">
                        <div class="border rounded p-3 mb-3 bg-light">
                            <h5 class="text-danger mb-3"><i class="fas fa-shield-alt"></i> SPAM Detection</h5>

                            <div class="form-group">
                                <label class="font-weight-bold">
                                    <i class="fas fa-power-off"></i> Enable Automated Cleanup
                                </label>
                                <span class="float-end offset-switch">
                                    <label class="switch switch-text switch-success">
                                        <div class="form-check form-switch">
                                            <input id="elan_spam_cleanup_enabled" type="checkbox" role="switch" class="form-check-input switch-input toggle" data-desc="Enable Automated Cleanup" <?= ($settings->elan_spam_cleanup_enabled == 1) ? 'checked="true"' : ''; ?>>
                                        </div>
                                        <span data-on="Yes" data-off="No" class="switch-label"></span>
                                        <span class="switch-handle"></span>
                                    </label>
                                </span>
                                <small class="form-text text-muted">Master switch to enable/disable the entire cleanup system</small>
                            </div>

                            <div class="form-group mb-0">
                                <label class="font-weight-bold">
                                    <i class="fas fa-bug"></i> Dry Run Mode
                                </label>
                                <span class="float-end offset-switch">
                                    <label class="switch switch-text switch-success">
                                        <div class="form-check form-switch">
                                            <input id="elan_spam_cleanup_dry_run" type="checkbox" role="switch" class="form-check-input switch-input toggle" data-desc="Dry Run Mode" <?= ($settings->elan_spam_cleanup_dry_run == 1) ? 'checked="true"' : ''; ?>>
                                        </div>
                                        <span data-on="Yes" data-off="No" class="switch-label"></span>
                                        <span class="switch-handle"></span>
                                    </label>
                                    <br>
                                    <a style="color:blue;" href="admin.php?view=logs&search=SPAM+Cleanup" class="small">View Dry Run Logs</a>
                                </span>
                                <small class="form-text text-muted">Test mode - logs actions without actually deleting users</small>
                            </div>
                        </div>

                        <div class="border rounded p-3 mb-3 bg-light">
                            <h5 class="text-danger mb-3"><i class="fas fa-user-clock"></i> Inactive User Management</h5>

                            <div class="form-group">
                                <label for="elan_spam_inactive_days" class="font-weight-bold">
                                    <i class="fas fa-calendar-alt"></i> Inactive User Threshold
                                </label>
                                <div class="input-group">
                                    <input type="number" step="1" min="7" max="365" class="form-control ajxnum" data-field="elan_spam_inactive_days" data-id="1" data-desc="Inactive User Threshold Days" name="elan_spam_inactive_days" id="elan_spam_inactive_days" value="<?= $settings->elan_spam_inactive_days; ?>">
                                    <div class="input-group-append">
                                        <span class="input-group-text">days</span>
                                    </div>
                                </div>
                                <small class="form-text text-muted">Days before considering users without cars as inactive</small>
                            </div>

                            <div class="form-group">
                                <label for="elan_spam_grace_period_days" class="font-weight-bold">
                                    <i class="fas fa-hourglass-half"></i> Grace Period
                                </label>
                                <div class="input-group">
                                    <input type="number" step="1" min="1" max="30" class="form-control ajxnum" data-desc="Grace Period Days" name="elan_spam_grace_period_days" id="elan_spam_grace_period_days" value="<?= $settings->elan_spam_grace_period_days; ?>">
                                    <div class="input-group-append">
                                        <span class="input-group-text">days</span>
                                    </div>
                                </div>
                                <small class="form-text text-muted">Days to wait after notification before deletion</small>
                            </div>

                            <div class="form-group mb-0">
                                <label class="font-weight-bold">
                                    <i class="fas fa-envelope"></i> Send Grace Period Emails
                                </label>
                                <span class="float-end offset-switch">
                                    <label class="switch switch-text switch-success">
                                        <div class="form-check form-switch">
                                            <input id="elan_spam_email_notifications" type="checkbox" role="switch" class="form-check-input switch-input toggle" data-desc="Send Grace Period Emails" <?= ($settings->elan_spam_email_notifications == 1) ? 'checked="true"' : ''; ?>>
                                        </div>
                                        <span data-on="Yes" data-off="No" class="switch-label"></span>
                                        <span class="switch-handle"></span>
                                    </label>
                                </span>
                                <small class="form-text text-muted">Email users before deleting inactive accounts</small>
                            </div>
                        </div>

                        <div class="border rounded p-3 mb-0 bg-light">
                            <h5 class="text-danger mb-3"><i class="fas fa-shield-alt"></i> Safety Limits</h5>

                            <div class="form-group">
                                <label for="elan_spam_max_deletions" class="font-weight-bold">
                                    <i class="fas fa-sort-numeric-up"></i> Max Deletions Per Run
                                </label>
                                <div class="input-group">
                                    <input type="number" step="1" min="1" max="1000" class="form-control ajxnum" data-desc="Max Deletions Per Run" name="elan_spam_max_deletions" id="elan_spam_max_deletions" value="<?= $settings->elan_spam_max_deletions; ?>">
                                    <div class="input-group-append">
                                        <span class="input-group-text">users</span>
                                    </div>
                                </div>
                                <small class="form-text text-muted">Maximum users to delete in single execution</small>
                            </div>

                            <div class="form-group mb-0">
                                <label for="elan_spam_max_percentage" class="font-weight-bold">
                                    <i class="fas fa-percentage"></i> Max Cleanup Percentage
                                </label>
                                <div class="input-group">
                                    <input type="number" step="0.1" min="0.1" max="25.0" class="form-control ajxnum" data-desc="Max Cleanup Percentage" name="elan_spam_max_percentage" id="elan_spam_max_percentage" value="<?= $settings->elan_spam_max_percentage; ?>">
                                    <div class="input-group-append">
                                        <span class="input-group-text">% of users</span>
                                    </div>
                                </div>
                                <small class="form-text text-muted">Maximum percentage of total users to cleanup per run</small>
                            </div>
                        </div>

                        <div class="alert alert-warning mb-0">
                            <small><i class="fas fa-exclamation-triangle"></i> <strong>Safety:</strong> Multiple safety checks prevent accidental deletions. Always test with dry-run mode first.</small>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <!-- External Libraries & CDN Configuration -->
                <div class="card no-padding border-dark">
                    <div class="card-header bg-dark text-white">
                        <h3 class="mb-1"><i class="fas fa-code"></i> External Libraries & CDN Configuration</h3>
                        <small class="text-light">Content Delivery Network URLs for JavaScript libraries and CSS frameworks</small>
                    </div>
                    <div class="card-body">
                        <!-- Core JavaScript Libraries -->
                        <div class="border rounded p-3 mb-3" style="background-color: #fff8e1; border-color: #ffb300 !important;">
                            <h5 class="mb-3" style="color: #ff6f00;"><i class="fab fa-js-square"></i> Core JavaScript Libraries</h5>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for='elan_jquery_cdn' class="font-weight-bold">
                                            <i class="fab fa-js"></i> jQuery CDN URL
                                        </label>
                                        <textarea rows="3" class="form-control ajxtxt" data-desc="JQuery CDN URL" name="elan_jquery_cdn" id="elan_jquery_cdn" placeholder="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js"><?= $settings->elan_jquery_cdn; ?></textarea>
                                        <small class="form-text text-muted">
                                            <i class="fas fa-external-link-alt"></i> <strong>Note:</strong> Do not use SLIM version
                                            <a href="https://code.jquery.com" target="_blank" class="ml-2">Browse Versions</a>
                                        </small>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for='elan_jquery_ui_cdn' class="font-weight-bold">
                                            <i class="fas fa-window-restore"></i> jQuery UI CDN URL
                                        </label>
                                        <textarea rows="3" class="form-control ajxtxt" data-desc="JQuery UI CDN URL" name="elan_jquery_ui_cdn" id="elan_jquery_ui_cdn" placeholder="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"><?= $settings->elan_jquery_ui_cdn; ?></textarea>
                                        <small class="form-text text-muted">
                                            <i class="fas fa-external-link-alt"></i> jQuery UI components and widgets
                                            <a href="https://jqueryui.com/download/" target="_blank" class="ml-2">Get UI CDN</a>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- CSS Frameworks -->
                        <div class="border rounded p-3 mb-3" style="background-color: #f3e5f5; border-color: #9c27b0 !important;">
                            <h5 class="mb-3" style="color: #7b1fa2;"><i class="fab fa-bootstrap"></i> CSS Frameworks & UI</h5>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for='elan_bootstrap_css_cdn' class="font-weight-bold">
                                            <i class="fab fa-bootstrap"></i> Bootstrap CSS CDN
                                        </label>
                                        <input type="text" class="form-control ajxtxt" data-desc="Bootstrap CSS CDN URL" name="elan_bootstrap_css_cdn" id="elan_bootstrap_css_cdn" value="<?= $settings->elan_bootstrap_css_cdn; ?>" placeholder="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/css/bootstrap.min.css">
                                        <small class="form-text text-muted">
                                            <i class="fas fa-external-link-alt"></i> Bootstrap CSS framework
                                            <a href="https://getbootstrap.com" target="_blank" class="ml-2">Get Bootstrap</a>
                                        </small>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for='elan_bootstrap_js_cdn' class="font-weight-bold">
                                            <i class="fab fa-js"></i> Bootstrap JS CDN
                                        </label>
                                        <input type="text" class="form-control ajxtxt" data-desc="Bootstrap JS CDN URL" name="elan_bootstrap_js_cdn" id="elan_bootstrap_js_cdn" value="<?= $settings->elan_bootstrap_js_cdn; ?>" placeholder="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/js/bootstrap.bundle.min.js">
                                        <small class="form-text text-muted">
                                            <i class="fas fa-external-link-alt"></i> Bootstrap JavaScript components
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-0">
                                        <label for='elan_popper_cdn' class="font-weight-bold">
                                            <i class="fas fa-layer-group"></i> Popper.js CDN
                                        </label>
                                        <input type="text" class="form-control ajxtxt" data-desc="Popper CDN URL" name="elan_popper_cdn" id="elan_popper_cdn" value="<?= $settings->elan_popper_cdn; ?>" placeholder="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js">
                                        <small class="form-text text-muted">
                                            <i class="fas fa-external-link-alt"></i> Required for Bootstrap tooltips and popovers
                                        </small>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group mb-0">
                                        <label for='elan_bootswatch_cdn' class="font-weight-bold">
                                            <i class="fas fa-palette"></i> Bootswatch Theme CDN
                                        </label>
                                        <input type="text" class="form-control ajxtxt" data-desc="Bootswatch Template CDN URL" name="elan_bootswatch_cdn" id="elan_bootswatch_cdn" value="<?= $settings->elan_bootswatch_cdn; ?>" placeholder="https://cdnjs.cloudflare.com/ajax/libs/bootswatch/4.6.0/simplex/bootstrap.min.css">
                                        <small class="form-text text-muted">
                                            <i class="fas fa-external-link-alt"></i> Bootstrap theme (tested with Simplex)
                                            <a href="https://cdnjs.com/libraries/bootswatch" target="_blank" class="ml-2">Browse Themes</a>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Icons & Data Components -->
                        <div class="border rounded p-3 mb-3" style="background-color: #e8f5e8; border-color: #4caf50 !important;">
                            <h5 class="mb-3" style="color: #2e7d32;"><i class="fas fa-icons"></i> Icons & Data Components</h5>

                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label for='elan_fontawesome_cdn' class="font-weight-bold">
                                            <i class="fab fa-font-awesome"></i> Font Awesome CDN URL
                                        </label>
                                        <input type="text" class="form-control ajxtxt" data-desc="Font Awesome CDN URL" name="elan_fontawesome_cdn" id="elan_fontawesome_cdn" value="<?= $settings->elan_fontawesome_cdn; ?>" placeholder="https://kit.fontawesome.com/2d8f489b15.js">
                                        <small class="form-text text-muted">
                                            <i class="fas fa-external-link-alt"></i> Font Awesome icons (use Kit URL for latest features)
                                            <a href="https://fontawesome.com" target="_blank" class="ml-2">Get FontAwesome</a>
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for='elan_datatables_js_cdn' class="font-weight-bold">
                                            <i class="fas fa-table"></i> DataTables JS CDN
                                        </label>
                                        <textarea rows="2" class="form-control ajxtxt" data-desc="Datatables JS CDN URL" name="elan_datatables_js_cdn" id="elan_datatables_js_cdn" placeholder="https://cdn.datatables.net/v/bs4/dt-1.10.23/..."><?= $settings->elan_datatables_js_cdn; ?></textarea>
                                        <small class="form-text text-muted">
                                            <i class="fas fa-external-link-alt"></i> DataTables with Bootstrap styling
                                            <a href="https://datatables.net/download/" target="_blank" class="ml-2">Get DataTables</a>
                                        </small>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for='elan_datatables_css_cdn' class="font-weight-bold">
                                            <i class="fas fa-table"></i> DataTables CSS CDN
                                        </label>
                                        <textarea rows="2" class="form-control ajxtxt" data-desc="Datatables CSS CDN URL" name="elan_datatables_css_cdn" id="elan_datatables_css_cdn" placeholder="https://cdn.datatables.net/v/bs4/dt-1.10.23/..."><?= $settings->elan_datatables_css_cdn; ?></textarea>
                                        <small class="form-text text-muted">
                                            <i class="fas fa-external-link-alt"></i> DataTables CSS styling
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- UI Components -->
                        <div class="border rounded p-3 mb-0" style="background-color: #fce4ec; border-color: #e91e63 !important;">
                            <h5 class="mb-3" style="color: #c2185b;"><i class="fas fa-puzzle-piece"></i> UI Components & Widgets</h5>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for='elan_datepicker_js_cdn' class="font-weight-bold">
                                            <i class="fas fa-calendar"></i> Datepicker JS CDN
                                        </label>
                                        <input type="text" class="form-control ajxtxt" data-desc="Datepicker JS CDN URL" name="elan_datepicker_js_cdn" id="elan_datepicker_js_cdn" value="<?= $settings->elan_datepicker_js_cdn; ?>" placeholder="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js">
                                        <small class="form-text text-muted">
                                            <i class="fas fa-external-link-alt"></i> Bootstrap datepicker component
                                            <a href="https://cdnjs.com/libraries/bootstrap-datepicker" target="_blank" class="ml-2">Get Datepicker</a>
                                        </small>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for='elan_datepicker_css_cdn' class="font-weight-bold">
                                            <i class="fas fa-calendar"></i> Datepicker CSS CDN
                                        </label>
                                        <input type="text" class="form-control ajxtxt" data-desc="Datepicker CSS CDN URL" name="elan_datepicker_css_cdn" id="elan_datepicker_css_cdn" value="<?= $settings->elan_datepicker_css_cdn; ?>" placeholder="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css">
                                        <small class="form-text text-muted">
                                            <i class="fas fa-external-link-alt"></i> Datepicker styling
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-0">
                                        <label for='elan_dropzone_js_cdn' class="font-weight-bold">
                                            <i class="fas fa-cloud-upload-alt"></i> Dropzone JS CDN
                                        </label>
                                        <input type="text" class="form-control ajxtxt" data-desc="Dropzone JS CDN URL" name="elan_dropzone_js_cdn" id="elan_dropzone_js_cdn" value="<?= $settings->elan_dropzone_js_cdn; ?>" placeholder="https://cdn.jsdelivr.net/npm/dropzone@5.7.6/dist/min/dropzone.min.js">
                                        <small class="form-text text-muted">
                                            <i class="fas fa-external-link-alt"></i> Drag & drop file uploads
                                            <a href="https://dropzone.js.org/" target="_blank" class="ml-2">Get Dropzone</a>
                                        </small>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group mb-0">
                                        <label for='elan_dropzone_css_cdn' class="font-weight-bold">
                                            <i class="fas fa-cloud-upload-alt"></i> Dropzone CSS CDN
                                        </label>
                                        <input type="text" class="form-control ajxtxt" data-desc="Dropzone CSS CDN URL" name="elan_dropzone_css_cdn" id="elan_dropzone_css_cdn" value="<?= $settings->elan_dropzone_css_cdn; ?>" placeholder="https://cdn.jsdelivr.net/npm/dropzone@5.7.6/dist/min/dropzone.min.css">
                                        <small class="form-text text-muted">
                                            <i class="fas fa-external-link-alt"></i> Dropzone styling
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script type="text/javascript" src="<?= $us_url_root ?>users/js/oce.js?v2"></script>
<script type="text/javascript">
function messages(data) {
    console.log(data.msg);
    console.log("messages found");
    $('#messages').removeClass();
    $('#message').text("");
    $('#messages').show();
    if (data.success == "true") {
        $('#messages').addClass("alert alert-success alert-dismissible fade show");
        $('#message').text(data.msg || 'Setting updated successfully');
    } else {
        $('#messages').addClass("alert alert-danger alert-dismissible fade show");
        $('#message').text(data.msg || 'Error updating setting');
    }
    $('#messages').delay(3000).fadeOut('slow');
}

$(document).ready(function() {
    // Handle toggle switches (same as UserSpice dashboard_js.php)
    $(".toggle").change(function() {
        var value = $(this).prop("checked");
        $(this).prop("checked", value);

        var field = $(this).attr("id"); // the id in the input tells which field to update
        var desc = $(this).attr("data-desc"); // For messages
        var table = $(this).attr("data-table") || 'settings';
        var formData = {
            'value': value,
            'field': field,
            'desc': desc,
            'table': table,
            'type': 'toggle',
            'token': "<?= Token::generate() ?>",
        };

        $.ajax({
                type: 'POST',
                url: 'parsers/admin_settings.php',
                data: formData,
                dataType: 'json',
            })
            .done(function(data) {
                messages(data);
            });
    });
    
    // Handle ajxnum fields (same as UserSpice dashboard_js.php)
    $(".ajxnum").change(function() {
        var value = $(this).val();
        var field = $(this).attr("id"); // the id in the input tells which field to update
        var desc = $(this).attr("data-desc"); // For messages
        var table = $(this).attr("data-table") || 'settings';
        var formData = {
            'value': value,
            'field': field,
            'desc': desc,
            'table': table,
            'type': 'num',
            'token': "<?= Token::generate() ?>",
        };

        $.ajax({
                type: 'POST',
                url: 'parsers/admin_settings.php',
                data: formData,
                dataType: 'json',
            })
            .done(function(data) {
                messages(data);
            });
    });
    
    // Handle ajxtxt fields (same as UserSpice dashboard_js.php)
    $(".ajxtxt").change(function() {
        var value = $(this).val();
        var field = $(this).attr("id"); // the id in the input tells which field to update
        var desc = $(this).attr("data-desc"); // For messages
        var table = $(this).attr("data-table") || 'settings';
        var formData = {
            'value': value,
            'field': field,
            'desc': desc,
            'table': table,
            'type': 'txt',
            'token': "<?= Token::generate() ?>",
        };

        $.ajax({
                type: 'POST',
                url: 'parsers/admin_settings.php',
                data: formData,
                dataType: 'json',
            })
            .done(function(data) {
                messages(data);
            });
    });
});
</script>