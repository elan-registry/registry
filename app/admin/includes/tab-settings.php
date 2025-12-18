<?php
declare(strict_types=1);
/**
 * tab-settings.php
 * Registry Settings Tab Content
 *
 * Implements Issue #335 with enhanced auto-creation and validation
 */

// Handle settings auto-creation without including the full form
$autoCreationMessages = [];

// Only perform auto-creation logic, not the full form rendering
if (!function_exists('processSettingsAutoCreation')) {
    function processSettingsAutoCreation() {
        global $db, $user, $settings;


        // Image size configuration settings
        $imageSettingsFields = [
            'elan_image_upload_max_size' => ['type' => 'DECIMAL(4,2)', 'default' => '2.00', 'description' => 'Maximum upload file size in MB'],
            'elan_image_display_max_size' => ['type' => 'INT(11)', 'default' => '2048', 'description' => 'Maximum display image width in pixels'],
            'elan_image_thumbnail_sizes' => ['type' => 'TEXT', 'default' => '100,300,768,1024,2048', 'description' => 'Comma-separated thumbnail sizes in pixels']
        ];

        // Chart.js configuration settings
        $chartJsSettingsFields = [
            'elan_chartjs_cdn' => ['type' => 'TEXT', 'default' => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js', 'description' => 'Chart.js CDN URL for statistics charts']
        ];

        // Email & Communication settings
        $emailSettingsFields = [
            'elan_admin_emails' => ['type' => 'TEXT', 'default' => 'registrar@elanregistry.org', 'description' => 'Comma-separated admin email addresses for system notifications and administrative alerts']
        ];

        // System Maintenance settings
        $maintenanceSettingsFields = [
            'elan_backup_age' => ['type' => 'INT(11)', 'default' => '30', 'description' => 'Backup retention period in days']
        ];

        // Google Services settings
        $googleSettingsFields = [
            'elan_google_maps_key' => ['type' => 'TEXT', 'default' => '', 'description' => 'Google Maps API Key for car location maps'],
            'elan_google_geo_key' => ['type' => 'TEXT', 'default' => '', 'description' => 'Google Geocoding API Key for address conversion']
        ];

        // Additional Media settings
        $additionalMediaFields = [
            'elan_image_dir' => ['type' => 'VARCHAR(255)', 'default' => 'userimages/', 'description' => 'Directory path where car images are stored'],
            'elan_image_max' => ['type' => 'INT(11)', 'default' => '10', 'description' => 'Maximum photos per car']
        ];

        // CDN Configuration settings
        $cdnSettingsFields = [
            'elan_jquery_cdn' => ['type' => 'TEXT', 'default' => '', 'description' => 'jQuery CDN URL'],
            'elan_jquery_ui_cdn' => ['type' => 'TEXT', 'default' => '', 'description' => 'jQuery UI CDN URL'],
            'elan_bootstrap_css_cdn' => ['type' => 'TEXT', 'default' => '', 'description' => 'Bootstrap CSS CDN URL'],
            'elan_bootstrap_js_cdn' => ['type' => 'TEXT', 'default' => '', 'description' => 'Bootstrap JS CDN URL'],
            'elan_popper_cdn' => ['type' => 'TEXT', 'default' => '', 'description' => 'Popper.js CDN URL'],
            'elan_bootswatch_cdn' => ['type' => 'TEXT', 'default' => '', 'description' => 'Bootswatch theme CDN URL'],
            'elan_fontawesome_cdn' => ['type' => 'TEXT', 'default' => '', 'description' => 'Font Awesome CDN URL'],
            'elan_datatables_js_cdn' => ['type' => 'TEXT', 'default' => '', 'description' => 'DataTables JS CDN URL'],
            'elan_datatables_css_cdn' => ['type' => 'TEXT', 'default' => '', 'description' => 'DataTables CSS CDN URL'],
            'elan_datepicker_js_cdn' => ['type' => 'TEXT', 'default' => '', 'description' => 'Datepicker JS CDN URL'],
            'elan_datepicker_css_cdn' => ['type' => 'TEXT', 'default' => '', 'description' => 'Datepicker CSS CDN URL'],
            'elan_dropzone_js_cdn' => ['type' => 'TEXT', 'default' => '', 'description' => 'Dropzone JS CDN URL'],
            'elan_dropzone_css_cdn' => ['type' => 'TEXT', 'default' => '', 'description' => 'Dropzone CSS CDN URL']
        ];

        // Combine all settings fields for processing
        $allSettingsFields = array_merge(
            $imageSettingsFields,
            $chartJsSettingsFields,
            $emailSettingsFields,
            $maintenanceSettingsFields,
            $googleSettingsFields,
            $additionalMediaFields,
            $cdnSettingsFields
        );

        $messages = [];
        $fieldsToAdd = [];
        $fieldsToPopulate = [];

        // Validate and process each field safely
        foreach ($allSettingsFields as $fieldName => $fieldConfig) {
            // Validate field name to prevent SQL injection
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fieldName)) {
                logger($user->data()->id ?? 0, 'SecurityError', "Invalid field name attempted: {$fieldName}");
                continue;
            }

            try {
                $checkField = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = ?", [$fieldName]);
                $columnExists = $checkField->count() > 0;

                if (!$columnExists) {
                    $fieldsToAdd[] = $fieldName;
                } else {
                    // Check if existing field has NULL value in settings record
                    $checkValue = $db->query("SELECT `{$fieldName}` FROM settings WHERE id = 1");
                    if ($checkValue->count() > 0) {
                        $currentValue = $checkValue->first()->$fieldName;
                        if ($currentValue === null) {
                            $fieldsToPopulate[] = $fieldName;
                        }
                    }
                }
            } catch (Exception $e) {
                logger($user->data()->id ?? 0, 'SystemError', "Error checking field {$fieldName}: " . $e->getMessage());
                $fieldsToAdd[] = $fieldName;
            }
        }

        // Create missing fields
        if (!empty($fieldsToAdd)) {
            try {
                foreach ($fieldsToAdd as $fieldName) {
                    $fieldConfig = $allSettingsFields[$fieldName];

                    if (!isset($fieldConfig['type']) || !isset($fieldConfig['default']) || !isset($fieldConfig['description'])) {
                        continue;
                    }

                    $allowedTypes = ['TINYINT(1)', 'INT(11)', 'DECIMAL(4,2)', 'TEXT', 'VARCHAR(255)'];
                    if (!in_array($fieldConfig['type'], $allowedTypes)) {
                        continue;
                    }

                    if (strpos($fieldConfig['type'], 'TEXT') !== false) {
                        $sql = "ALTER TABLE settings ADD COLUMN `{$fieldName}` {$fieldConfig['type']} COMMENT ?";
                        $result = $db->query($sql, [$fieldConfig['description']]);
                    } else {
                        $sql = "ALTER TABLE settings ADD COLUMN `{$fieldName}` {$fieldConfig['type']} DEFAULT ? COMMENT ?";
                        $result = $db->query($sql, [$fieldConfig['default'], $fieldConfig['description']]);
                    }

                    if ($result) {
                        // Populate existing settings record with default value
                        $updateSql = "UPDATE settings SET `{$fieldName}` = ? WHERE id = 1";
                        $db->query($updateSql, [$fieldConfig['default']]);
                    }
                }

                if (!empty($fieldsToAdd)) {
                    logger($user->data()->id, 'SettingsUpdate', 'Auto-created and populated settings fields: ' . implode(', ', $fieldsToAdd));
                    $messages[] = ['type' => 'success', 'message' => count($fieldsToAdd) . ' settings fields were automatically added and populated with default values.'];
                }
            } catch (Exception $e) {
                $messages[] = ['type' => 'danger', 'message' => 'Error creating settings fields: ' . $e->getMessage()];
                logger($user->data()->id ?? 0, 'DatabaseError', 'Settings field creation failed: ' . $e->getMessage());
            }
        }

        // Handle existing fields with NULL values
        if (!empty($fieldsToPopulate)) {
            try {
                foreach ($fieldsToPopulate as $fieldName) {
                    $fieldConfig = $allSettingsFields[$fieldName];
                    $updateSql = "UPDATE settings SET `{$fieldName}` = ? WHERE id = 1 AND `{$fieldName}` IS NULL";
                    $db->query($updateSql, [$fieldConfig['default']]);
                }

                logger($user->data()->id, 'SettingsUpdate', 'Populated NULL settings fields with defaults: ' . implode(', ', $fieldsToPopulate));
                $messages[] = ['type' => 'info', 'message' => count($fieldsToPopulate) . ' existing settings fields were populated with default values.'];
            } catch (Exception $e) {
                $messages[] = ['type' => 'danger', 'message' => 'Error populating settings fields: ' . $e->getMessage()];
                logger($user->data()->id ?? 0, 'DatabaseError', 'Settings field population failed: ' . $e->getMessage());
            }
        }

        return $messages;
    }
}

$autoCreationMessages = processSettingsAutoCreation();
?>

<!-- Auto-creation status messages -->
<?php foreach ($autoCreationMessages as $msg): ?>
    <div class="alert alert-<?= $msg['type'] ?> alert-dismissible fade show" role="alert">
        <i class="fas fa-<?= $msg['type'] === 'success' ? 'check' : ($msg['type'] === 'info' ? 'info-circle' : 'exclamation-triangle') ?>"></i>
        <strong>Database Auto-Creation:</strong> <?= $msg['message'] ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
<?php endforeach; ?>

<div class="alert alert-success">
    <h4><i class="fas fa-cog"></i> Registry Settings</h4>
    <p class="mb-0">Comprehensive configuration management for the Elan Registry system with automatic database field creation.</p>
</div>

<!-- AJAX Messages Area -->
<div id="settingsMessages" style="display: none;">
    <span id="settingsMessage"></span>
</div>

<form class="settings-form" method="post" id="registrySettingsForm">
    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">

    <div class="row">
        <div class="col-md-6">
            <!-- Google Services Integration -->
            <div class="card border-info mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fab fa-google"></i> Google Services Integration</h5>
                    <small class="text-light">API keys for Maps, Geocoding, and other Google services</small>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="elan_google_maps_key" class="font-weight-bold">
                            <i class="fas fa-map"></i> Google Maps API Key
                        </label>
                        <input type="text"
                               class="form-control ajxtxt"
                               data-desc="Google Maps API Key"
                               name="elan_google_maps_key"
                               id="elan_google_maps_key"
                               value="<?= htmlspecialchars($settings->elan_google_maps_key ?? '') ?>"
                               placeholder="AIzaSy...">
                        <small class="form-text text-muted">
                            <i class="fas fa-external-link-alt"></i> Required for car location maps and statistics page
                            <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="ml-2">Get API Key</a>
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="elan_google_geo_key" class="font-weight-bold">
                            <i class="fas fa-search-location"></i> Google Geocoding API Key
                        </label>
                        <input type="text"
                               class="form-control ajxtxt"
                               data-desc="Google Geocoding Key"
                               name="elan_google_geo_key"
                               id="elan_google_geo_key"
                               value="<?= htmlspecialchars($settings->elan_google_geo_key ?? '') ?>"
                               placeholder="AIzaSy...">
                        <small class="form-text text-muted">
                            <i class="fas fa-external-link-alt"></i> Converts addresses to coordinates for location sync
                            <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="ml-2">Get API Key</a>
                        </small>
                    </div>

                    <div class="mt-3">
                        <button type="button" class="btn btn-outline-info btn-sm" onclick="testGoogleServices(this)">
                            <i class="fas fa-flask"></i> Test API Keys
                        </button>
                    </div>
                </div>
            </div>

            <!-- System Maintenance -->
            <div class="card border-secondary mb-4">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0"><i class="fas fa-tools"></i> System Maintenance</h5>
                    <small class="text-light">Backup and system maintenance settings</small>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="elan_backup_age" class="font-weight-bold">
                            <i class="fas fa-calendar-times"></i> Backup Retention Period
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   step="1"
                                   min="1"
                                   max="365"
                                   class="form-control ajxnum"
                                   data-desc="Backup Age"
                                   name="elan_backup_age"
                                   id="elan_backup_age"
                                   value="<?= $settings->elan_backup_age ?? '30' ?>">
                            <div class="input-group-append">
                                <span class="input-group-text">days</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">How long to keep automated backups before cleanup</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <!-- Media Management -->
            <div class="card border-warning mb-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="fas fa-images"></i> Media Management</h5>
                    <small class="text-dark">File upload and image handling settings</small>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="elan_image_dir" class="font-weight-bold">
                            <i class="fas fa-folder"></i> Image Upload Directory
                        </label>
                        <input type="text"
                               class="form-control ajxtxt"
                               data-desc="Image Upload Directory"
                               name="elan_image_dir"
                               id="elan_image_dir"
                               value="<?= htmlspecialchars($settings->elan_image_dir ?? 'userimages/') ?>"
                               placeholder="userimages/">
                        <small class="form-text text-muted">Directory path where car images are stored (relative to site root)</small>
                    </div>

                    <div class="form-group">
                        <label for="elan_image_max" class="font-weight-bold">
                            <i class="fas fa-photo-video"></i> Maximum Photos per Car
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   step="1"
                                   min="1"
                                   max="50"
                                   class="form-control ajxnum"
                                   data-desc="Max Photo Upload"
                                   name="elan_image_max"
                                   id="elan_image_max"
                                   value="<?= $settings->elan_image_max ?? '10' ?>">
                            <div class="input-group-append">
                                <span class="input-group-text">photos</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">Limit number of photos users can upload per car</small>
                    </div>

                    <div class="form-group">
                        <label for="elan_image_upload_max_size" class="font-weight-bold">
                            <i class="fas fa-file-upload"></i> Maximum Upload File Size
                        </label>
                        <div class="input-group">
                            <input type="number"
                                   step="0.1"
                                   min="0.5"
                                   max="10.0"
                                   class="form-control ajxnum"
                                   data-desc="Max Upload File Size"
                                   name="elan_image_upload_max_size"
                                   id="elan_image_upload_max_size"
                                   value="<?= $settings->elan_image_upload_max_size ?? '2.00' ?>">
                            <div class="input-group-append">
                                <span class="input-group-text">MB</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">Maximum file size for individual photo uploads (0.5-10 MB)</small>
                    </div>
                </div>
            </div>

            <!-- Email Configuration -->
            <div class="card border-primary mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-envelope"></i> Email & Communication</h5>
                    <small class="text-light">Administrative email addresses and notification settings</small>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="elan_admin_emails" class="font-weight-bold">
                            <i class="fas fa-users-cog"></i> Admin Email Addresses
                        </label>
                        <textarea rows="3"
                                  class="form-control ajxtxt"
                                  data-desc="Admin Email Addresses"
                                  name="elan_admin_emails"
                                  id="elan_admin_emails"
                                  placeholder="registrar@elanregistry.org, manager@elanregistry.org"><?= htmlspecialchars($settings->elan_admin_emails ?? 'registrar@elanregistry.org') ?></textarea>
                        <small class="form-text text-muted">
                            <i class="fas fa-info-circle"></i> Comma-separated email addresses for transfer requests, feedback, and administrative notifications
                        </small>
                    </div>

                    <div class="mt-3">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="testEmailConfiguration()">
                            <i class="fas fa-paper-plane"></i> Test Email Config
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- External Libraries & CDN Configuration -->
<div class="row">
    <div class="col-md-12">
        <div class="card border-dark">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="fas fa-code"></i> External Libraries & CDN Configuration</h5>
            </div>
            <div class="card-body">
                <!-- Core JavaScript Libraries -->
                <div class="border rounded p-3 mb-3" style="background-color: #fff8e1; border-color: #ffb300 !important;">
                    <h5 class="mb-3" style="color: #ff6f00;"><i class="fab fa-js-square"></i> Core JavaScript Libraries</h5>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="elan_jquery_cdn" class="font-weight-bold">
                                    <i class="fab fa-js"></i> jQuery CDN URL
                                </label>
                                <textarea rows="3"
                                          class="form-control ajxtxt"
                                          data-desc="jQuery CDN URL"
                                          name="elan_jquery_cdn"
                                          id="elan_jquery_cdn"
                                          placeholder="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js"><?= $settings->elan_jquery_cdn ?? '' ?></textarea>
                                <small class="form-text text-muted">
                                    <i class="fas fa-external-link-alt"></i> <strong>Note:</strong> Do not use SLIM version
                                    <a href="https://code.jquery.com" target="_blank" class="ml-2">Browse Versions</a>
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="elan_jquery_ui_cdn" class="font-weight-bold">
                                    <i class="fas fa-window-restore"></i> jQuery UI CDN URL
                                </label>
                                <textarea rows="3"
                                          class="form-control ajxtxt"
                                          data-desc="jQuery UI CDN URL"
                                          name="elan_jquery_ui_cdn"
                                          id="elan_jquery_ui_cdn"
                                          placeholder="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"><?= $settings->elan_jquery_ui_cdn ?? '' ?></textarea>
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
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="elan_bootstrap_css_cdn" class="font-weight-bold">
                                    <i class="fab fa-bootstrap"></i> Bootstrap CSS CDN
                                </label>
                                <input type="text"
                                       class="form-control ajxtxt"
                                       data-desc="Bootstrap CSS CDN URL"
                                       name="elan_bootstrap_css_cdn"
                                       id="elan_bootstrap_css_cdn"
                                       value="<?= $settings->elan_bootstrap_css_cdn ?? '' ?>"
                                       placeholder="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/css/bootstrap.min.css">
                                <small class="form-text text-muted">
                                    <i class="fas fa-external-link-alt"></i> Bootstrap CSS framework
                                    <a href="https://getbootstrap.com" target="_blank" class="ml-2">Get Bootstrap</a>
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="elan_bootstrap_js_cdn" class="font-weight-bold">
                                    <i class="fab fa-js"></i> Bootstrap JS CDN
                                </label>
                                <input type="text"
                                       class="form-control ajxtxt"
                                       data-desc="Bootstrap JS CDN URL"
                                       name="elan_bootstrap_js_cdn"
                                       id="elan_bootstrap_js_cdn"
                                       value="<?= $settings->elan_bootstrap_js_cdn ?? '' ?>"
                                       placeholder="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/js/bootstrap.bundle.min.js">
                                <small class="form-text text-muted">
                                    <i class="fas fa-external-link-alt"></i> Bootstrap JavaScript components
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="elan_popper_cdn" class="font-weight-bold">
                                    <i class="fas fa-layer-group"></i> Popper.js CDN
                                </label>
                                <input type="text"
                                       class="form-control ajxtxt"
                                       data-desc="Popper CDN URL"
                                       name="elan_popper_cdn"
                                       id="elan_popper_cdn"
                                       value="<?= $settings->elan_popper_cdn ?? '' ?>"
                                       placeholder="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js">
                                <small class="form-text text-muted">
                                    <i class="fas fa-external-link-alt"></i> Required for Bootstrap tooltips and popovers
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="elan_bootswatch_cdn" class="font-weight-bold">
                                    <i class="fas fa-palette"></i> Bootswatch Theme CDN
                                </label>
                                <input type="text"
                                       class="form-control ajxtxt"
                                       data-desc="Bootswatch Theme CDN URL"
                                       name="elan_bootswatch_cdn"
                                       id="elan_bootswatch_cdn"
                                       value="<?= $settings->elan_bootswatch_cdn ?? '' ?>"
                                       placeholder="https://cdnjs.cloudflare.com/ajax/libs/bootswatch/4.6.0/simplex/bootstrap.min.css">
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
                                <label for="elan_fontawesome_cdn" class="font-weight-bold">
                                    <i class="fab fa-font-awesome"></i> Font Awesome CDN URL
                                </label>
                                <input type="text"
                                       class="form-control ajxtxt"
                                       data-desc="Font Awesome CDN URL"
                                       name="elan_fontawesome_cdn"
                                       id="elan_fontawesome_cdn"
                                       value="<?= $settings->elan_fontawesome_cdn ?? '' ?>"
                                       placeholder="https://kit.fontawesome.com/2d8f489b15.js">
                                <small class="form-text text-muted">
                                    <i class="fas fa-external-link-alt"></i> Font Awesome icons (use Kit URL for latest features)
                                    <a href="https://fontawesome.com" target="_blank" class="ml-2">Get FontAwesome</a>
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="elan_datatables_js_cdn" class="font-weight-bold">
                                    <i class="fas fa-table"></i> DataTables JS CDN
                                </label>
                                <textarea rows="2"
                                          class="form-control ajxtxt"
                                          data-desc="DataTables JS CDN URL"
                                          name="elan_datatables_js_cdn"
                                          id="elan_datatables_js_cdn"
                                          placeholder="https://cdn.datatables.net/v/bs4/dt-1.10.23/..."><?= $settings->elan_datatables_js_cdn ?? '' ?></textarea>
                                <small class="form-text text-muted">
                                    <i class="fas fa-external-link-alt"></i> DataTables with Bootstrap styling
                                    <a href="https://datatables.net/download/" target="_blank" class="ml-2">Get DataTables</a>
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="elan_datatables_css_cdn" class="font-weight-bold">
                                    <i class="fas fa-table"></i> DataTables CSS CDN
                                </label>
                                <textarea rows="2"
                                          class="form-control ajxtxt"
                                          data-desc="DataTables CSS CDN URL"
                                          name="elan_datatables_css_cdn"
                                          id="elan_datatables_css_cdn"
                                          placeholder="https://cdn.datatables.net/v/bs4/dt-1.10.23/..."><?= $settings->elan_datatables_css_cdn ?? '' ?></textarea>
                                <small class="form-text text-muted">
                                    <i class="fas fa-external-link-alt"></i> DataTables CSS styling
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- UI Components -->
                <div class="border rounded p-3" style="background-color: #fce4ec; border-color: #e91e63 !important;">
                    <h5 class="mb-3" style="color: #c2185b;"><i class="fas fa-puzzle-piece"></i> UI Components & Widgets</h5>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="elan_datepicker_js_cdn" class="font-weight-bold">
                                    <i class="fas fa-calendar"></i> Datepicker JS CDN
                                </label>
                                <input type="text"
                                       class="form-control ajxtxt"
                                       data-desc="Datepicker JS CDN URL"
                                       name="elan_datepicker_js_cdn"
                                       id="elan_datepicker_js_cdn"
                                       value="<?= $settings->elan_datepicker_js_cdn ?? '' ?>"
                                       placeholder="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js">
                                <small class="form-text text-muted">
                                    <i class="fas fa-external-link-alt"></i> Bootstrap datepicker component
                                    <a href="https://cdnjs.com/libraries/bootstrap-datepicker" target="_blank" class="ml-2">Get Datepicker</a>
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="elan_datepicker_css_cdn" class="font-weight-bold">
                                    <i class="fas fa-calendar"></i> Datepicker CSS CDN
                                </label>
                                <input type="text"
                                       class="form-control ajxtxt"
                                       data-desc="Datepicker CSS CDN URL"
                                       name="elan_datepicker_css_cdn"
                                       id="elan_datepicker_css_cdn"
                                       value="<?= $settings->elan_datepicker_css_cdn ?? '' ?>"
                                       placeholder="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css">
                                <small class="form-text text-muted">
                                    <i class="fas fa-external-link-alt"></i> Datepicker styling
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="elan_dropzone_js_cdn" class="font-weight-bold">
                                    <i class="fas fa-cloud-upload-alt"></i> Dropzone JS CDN
                                </label>
                                <input type="text"
                                       class="form-control ajxtxt"
                                       data-desc="Dropzone JS CDN URL"
                                       name="elan_dropzone_js_cdn"
                                       id="elan_dropzone_js_cdn"
                                       value="<?= $settings->elan_dropzone_js_cdn ?? '' ?>"
                                       placeholder="https://cdn.jsdelivr.net/npm/dropzone@5.7.6/dist/min/dropzone.min.js">
                                <small class="form-text text-muted">
                                    <i class="fas fa-external-link-alt"></i> Drag & drop file uploads
                                    <a href="https://dropzone.js.org/" target="_blank" class="ml-2">Get Dropzone</a>
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="elan_dropzone_css_cdn" class="font-weight-bold">
                                    <i class="fas fa-cloud-upload-alt"></i> Dropzone CSS CDN
                                </label>
                                <input type="text"
                                       class="form-control ajxtxt"
                                       data-desc="Dropzone CSS CDN URL"
                                       name="elan_dropzone_css_cdn"
                                       id="elan_dropzone_css_cdn"
                                       value="<?= $settings->elan_dropzone_css_cdn ?? '' ?>"
                                       placeholder="https://cdn.jsdelivr.net/npm/dropzone@5.7.6/dist/min/dropzone.min.css">
                                <small class="form-text text-muted">
                                    <i class="fas fa-external-link-alt"></i> Dropzone styling
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group mb-0">
                                <label for="elan_chartjs_cdn" class="font-weight-bold">
                                    <i class="fas fa-chart-pie"></i> Chart.js CDN URL
                                </label>
                                <input type="text"
                                       class="form-control ajxtxt"
                                       data-desc="Chart.js CDN URL"
                                       name="elan_chartjs_cdn"
                                       id="elan_chartjs_cdn"
                                       value="<?= $settings->elan_chartjs_cdn ?? 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js' ?>"
                                       placeholder="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js">
                                <small class="form-text text-muted">
                                    <i class="fas fa-external-link-alt"></i> Chart.js library for statistics page charts
                                    <a href="https://www.chartjs.org/docs/latest/getting-started/installation.html" target="_blank" class="ml-2">Get Chart.js</a>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>


<script>
// Settings management JavaScript
function settingsMessages(data) {
    console.log(data.msg);
    $('#settingsMessages').removeClass();
    $('#settingsMessage').text("");
    $('#settingsMessages').show();
    if (data.success == "true") {
        $('#settingsMessages').addClass("alert alert-success alert-dismissible fade show");
        $('#settingsMessage').text(data.msg || 'Setting updated successfully');
    } else {
        $('#settingsMessages').addClass("alert alert-danger alert-dismissible fade show");
        $('#settingsMessage').text(data.msg || 'Error updating setting');
    }
    $('#settingsMessages').delay(3000).fadeOut('slow');
}

$(document).ready(function() {
    // Handle toggle switches
    $(".toggle").change(function() {
        var value = $(this).prop("checked");
        $(this).prop("checked", value);

        var field = $(this).attr("id");
        var desc = $(this).attr("data-desc");
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
                url: '../../users/parsers/admin_settings.php',
                data: formData,
                dataType: 'json',
            })
            .done(function(data) {
                settingsMessages(data);
            });
    });

    // Handle numeric fields
    $(".ajxnum").change(function() {
        var value = $(this).val();
        var field = $(this).attr("id");
        var desc = $(this).attr("data-desc");
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
                url: '../../users/parsers/admin_settings.php',
                data: formData,
                dataType: 'json',
            })
            .done(function(data) {
                settingsMessages(data);
            });
    });

    // Handle text fields
    $(".ajxtxt").change(function() {
        var value = $(this).val();
        var field = $(this).attr("id");
        var desc = $(this).attr("data-desc");
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
                url: '../../users/parsers/admin_settings.php',
                data: formData,
                dataType: 'json',
            })
            .done(function(data) {
                settingsMessages(data);
            });
    });
});

// Test Google Services functionality
function testGoogleServices(buttonElement) {
    console.log('[API Test] Starting Google Services API test');
    console.log('[API Test] Button element passed:', buttonElement);

    const mapsKey = $('#elan_google_maps_key').val();
    const geoKey = $('#elan_google_geo_key').val();

    console.log('[API Test] Maps Key present:', !!mapsKey);
    console.log('[API Test] Geo Key present:', !!geoKey);

    if (!mapsKey && !geoKey) {
        console.warn('[API Test] No API keys provided');
        alert('Please enter at least one API key to test.');
        return;
    }

    // Wrap button element in jQuery
    const btn = $(buttonElement);
    console.log('[API Test] Button wrapped in jQuery:', btn.length, 'element(s)');

    if (btn.length === 0) {
        console.error('[API Test] ERROR: Button element is invalid!');
        alert('Error: Invalid button element. Please refresh the page.');
        return;
    }

    const originalText = btn.html();
    console.log('[API Test] Original button HTML:', originalText);
    btn.html('<i class="fas fa-spinner fa-spin"></i> Testing...').prop('disabled', true);
    console.log('[API Test] Button disabled, starting test...');

    // Remove any existing result message
    $('#apiTestResult').remove();

    // Helper functions
    function showSuccess(btn, originalText) {
        console.log('[API Test] Showing success state');

        // Reset button to original state
        btn.html(originalText).prop('disabled', false);

        // Add success message next to button
        const successMsg = $('<span id="apiTestResult" class="ml-2 text-success font-weight-bold">' +
            '<i class="fas fa-check-circle"></i> Maps API Valid - Test Successful' +
            '</span>');
        btn.after(successMsg);

        console.log('[API Test] Success message added to DOM');

        // Remove message after 5 seconds
        setTimeout(() => {
            $('#apiTestResult').fadeOut(300, function() {
                $(this).remove();
            });
            console.log('[API Test] Test completed successfully');
        }, 5000);
    }

    function showError(btn, originalText, message) {
        console.error('[API Test] Showing error state:', message);

        // Reset button to original state
        btn.html(originalText).prop('disabled', false);

        // Add error message next to button
        const errorMsg = $('<span id="apiTestResult" class="ml-2 text-danger font-weight-bold">' +
            '<i class="fas fa-times-circle"></i> ' + message +
            '</span>');
        btn.after(errorMsg);

        console.log('[API Test] Error message added to DOM');

        // Remove message after 7 seconds
        setTimeout(() => {
            $('#apiTestResult').fadeOut(300, function() {
                $(this).remove();
            });
        }, 7000);
    }

    // Test Maps API if key provided
    if (mapsKey) {
        console.log('[API Test] Testing Google Maps API');

        // Check if Maps API already loaded
        if (window.google && window.google.maps) {
            console.log('[API Test] Google Maps API already loaded, test successful');
            showSuccess(btn, originalText);
            return;
        }

        // Add loading=async parameter per Google best practices (fixes console warning)
        const testUrl = `https://maps.googleapis.com/maps/api/js?key=${mapsKey}&callback=testCallback&loading=async`;
        console.log('[API Test] Loading Maps API from URL:', testUrl);

        // Set up callback with timeout
        let callbackExecuted = false;
        const timeoutMs = 10000;

        window.testCallback = function() {
            if (callbackExecuted) return;
            callbackExecuted = true;

            console.log('[API Test] Google Maps API callback executed successfully');
            showSuccess(btn, originalText);

            // Clean up
            setTimeout(() => {
                if (window.testCallback) {
                    delete window.testCallback;
                    console.log('[API Test] Cleanup completed');
                }
            }, 2000);
        };

        // Create script tag to test the API
        const script = document.createElement('script');
        script.src = testUrl;
        script.async = true;
        script.defer = true;

        script.onerror = function(error) {
            if (callbackExecuted) return;
            callbackExecuted = true;

            console.error('[API Test] Maps API loading error:', error);
            showError(btn, originalText, 'Maps API Error');

            // Clean up
            setTimeout(() => {
                if (script.parentNode) {
                    document.head.removeChild(script);
                }
                delete window.testCallback;
            }, 2000);
        };

        // Set timeout for slow/failed responses
        const timeout = setTimeout(() => {
            if (!callbackExecuted) {
                console.error('[API Test] Maps API test timeout after', timeoutMs, 'ms');
                showError(btn, originalText, 'Test timeout');

                // Clean up
                if (script.parentNode) {
                    document.head.removeChild(script);
                }
                delete window.testCallback;
            }
        }, timeoutMs);

        console.log('[API Test] Appending script to document head');
        document.head.appendChild(script);

    } else {
        // Just show completion for geocoding key (no direct test available)
        console.log('[API Test] Only Geocoding key provided (no direct test available)');
        setTimeout(() => {
            // Reset button to original state
            btn.html(originalText).prop('disabled', false);

            // Add info message next to button
            const infoMsg = $('<span id="apiTestResult" class="ml-2 text-info font-weight-bold">' +
                '<i class="fas fa-info-circle"></i> Geocoding Key Saved (no direct test available)' +
                '</span>');
            btn.after(infoMsg);

            console.log('[API Test] Info message added to DOM');

            // Remove message after 5 seconds
            setTimeout(() => {
                $('#apiTestResult').fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        }, 1000);
    }
}

// Test Email Configuration
function testEmailConfiguration() {
    const emails = $('#elan_admin_emails').val();

    if (!emails.trim()) {
        alert('Please enter at least one admin email address.');
        return;
    }

    // Validate email format
    const emailList = emails.split(',');
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    let invalidEmails = [];

    emailList.forEach(email => {
        const trimmedEmail = email.trim();
        if (trimmedEmail && !emailRegex.test(trimmedEmail)) {
            invalidEmails.push(trimmedEmail);
        }
    });

    const btn = $('button[onclick="testEmailConfiguration()"]');
    const originalText = btn.html();

    if (invalidEmails.length > 0) {
        btn.html('<i class="fas fa-exclamation-triangle text-warning"></i> Invalid Format').removeClass('btn-outline-primary').addClass('btn-warning');
        alert('Invalid email format detected: ' + invalidEmails.join(', '));
        setTimeout(() => {
            btn.html(originalText).removeClass('btn-warning').addClass('btn-outline-primary');
        }, 3000);
    } else {
        btn.html('<i class="fas fa-check text-success"></i> Format Valid').removeClass('btn-outline-primary').addClass('btn-success');
        setTimeout(() => {
            btn.html(originalText).removeClass('btn-success').addClass('btn-outline-primary');
        }, 3000);
    }
}
</script>
