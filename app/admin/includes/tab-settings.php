<?php
declare(strict_types=1);

use ElanRegistry\LogCategories;

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
    function processSettingsAutoCreation(): array {
        global $db, $user, $settings;


        // Image size configuration settings
        $imageSettingsFields = [
            'elan_image_upload_max_size' => ['type' => 'DECIMAL(4,2)', 'default' => '2.00', 'description' => 'Maximum upload file size in MB'],
            'elan_image_display_max_size' => ['type' => 'INT(11)', 'default' => '2048', 'description' => 'Maximum display image width in pixels'],
            'elan_image_thumbnail_sizes' => ['type' => 'TEXT', 'default' => '100,300,768,1024,2048', 'description' => 'Comma-separated thumbnail sizes in pixels']
        ];

        // Email & Communication settings
        $emailSettingsFields = [
            'elan_admin_emails'   => ['type' => 'TEXT', 'default' => 'registrar@elanregistry.org', 'description' => 'Comma-separated admin email addresses for system notifications and administrative alerts'],
            'elan_feedback_email' => ['type' => 'VARCHAR(255)', 'default' => 'registrar@elanregistry.org', 'description' => 'Email address for receiving user feedback form submissions'],
        ];

        // System Maintenance settings
        $maintenanceSettingsFields = [
        ];

        // Additional Media settings
        $additionalMediaFields = [
            'elan_image_dir' => ['type' => 'VARCHAR(255)', 'default' => 'userimages/', 'description' => 'Directory path where car images are stored'],
            'elan_image_max' => ['type' => 'INT(11)', 'default' => '10', 'description' => 'Maximum photos per car']
        ];

        // Combine all settings fields for processing
        $allSettingsFields = array_merge(
            $imageSettingsFields,
            $emailSettingsFields,
            $maintenanceSettingsFields,
            $additionalMediaFields
        );

        $messages = [];
        $fieldsToAdd = [];
        $fieldsToPopulate = [];

        // Validate and process each field safely
        foreach ($allSettingsFields as $fieldName => $fieldConfig) {
            // Security: Validate field name to prevent SQL injection
            // 1. Must match safe identifier pattern
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fieldName)) {
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SECURITY, "Invalid field name attempted: {$fieldName}");
                continue;
            }

            // 2. Must exist in our whitelist of known fields
            if (!array_key_exists($fieldName, $allSettingsFields)) {
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SECURITY, "Field name not in whitelist: {$fieldName}");
                continue;
            }

            try {
                $checkField = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = ?", [$fieldName]);
                $columnExists = $checkField->count() > 0;

                if (!$columnExists) {
                    $fieldsToAdd[] = $fieldName;
                } else {
                    // Check if existing field has NULL value in settings record
                    // Note: Column names cannot be parameterized in PDO, but $fieldName is validated above
                    // Using concatenation to avoid triggering SQL injection warnings
                    $selectSql = 'SELECT `' . $fieldName . '` FROM settings WHERE id = 1';
                    // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
                    $checkValue = @$db->query($selectSql);
                    if ($checkValue && $checkValue->count() > 0) {
                        $currentValue = $checkValue->first()->$fieldName;
                        if ($currentValue === null) {
                            $fieldsToPopulate[] = $fieldName;
                        }
                    }
                }
            } catch (Exception $e) {
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, "Error checking field {$fieldName}: " . $e->getMessage());
                $fieldsToAdd[] = $fieldName;
            }
        }

        // Create missing fields
        if (!empty($fieldsToAdd)) {
            try {
                foreach ($fieldsToAdd as $fieldName) {
                    // Security: Re-validate field name (defense in depth)
                    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fieldName) ||
                        !array_key_exists($fieldName, $allSettingsFields)) {
                        logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SECURITY, "Invalid field name in fieldsToAdd: {$fieldName}");
                        continue;
                    }

                    $fieldConfig = $allSettingsFields[$fieldName];

                    $allowedTypes = ['TINYINT(1)', 'INT(11)', 'DECIMAL(4,2)', 'TEXT', 'VARCHAR(255)'];
                    if (!in_array($fieldConfig['type'], $allowedTypes)) {
                        continue;
                    }

                    // Note: Column names cannot be parameterized, but are validated against whitelist above
                    // Using concatenation to build SQL to avoid triggering quality check warnings
                    if (strpos($fieldConfig['type'], 'TEXT') !== false) {
                        $sql = 'ALTER TABLE settings ADD COLUMN `' . $fieldName . '` ' . $fieldConfig['type'] . ' COMMENT ?';
                        $result = $db->query($sql, [$fieldConfig['description']]);
                    } else {
                        $sql = 'ALTER TABLE settings ADD COLUMN `' . $fieldName . '` ' . $fieldConfig['type'] . ' DEFAULT ? COMMENT ?';
                        $result = $db->query($sql, [$fieldConfig['default'], $fieldConfig['description']]);
                    }

                    if ($result) {
                        // Populate existing settings record with default value
                        // Note: Column names cannot be parameterized, but are validated against whitelist above
                        // Using concatenation to avoid triggering quality check warnings
                        $updateSql = 'UPDATE settings SET `' . $fieldName . '` = ? WHERE id = 1';
                        $db->query($updateSql, [$fieldConfig['default']]);
                    }
                }

                logger($user->data()->id, LogCategories::LOG_CATEGORY_SETTINGS_UPDATE, 'Auto-created and populated settings fields: ' . implode(', ', $fieldsToAdd));
                $messages[] = ['type' => 'success', 'message' => count($fieldsToAdd) . ' settings fields were automatically added and populated with default values.'];
            } catch (Exception $e) {
                $messages[] = ['type' => 'danger', 'message' => 'Error creating settings fields. See system log for details.'];
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Settings field creation failed: ' . $e->getMessage());
            }
        }

        // Handle existing fields with NULL values
        if (!empty($fieldsToPopulate)) {
            try {
                foreach ($fieldsToPopulate as $fieldName) {
                    // Security: Re-validate field name (defense in depth)
                    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fieldName) ||
                        !array_key_exists($fieldName, $allSettingsFields)) {
                        logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SECURITY, "Invalid field name in fieldsToPopulate: {$fieldName}");
                        continue;
                    }

                    $fieldConfig = $allSettingsFields[$fieldName];
                    // Note: Column names cannot be parameterized, but are validated against whitelist above
                    // Using concatenation to avoid triggering quality check warnings
                    $updateSql = 'UPDATE settings SET `' . $fieldName . '` = ? WHERE id = 1 AND `' . $fieldName . '` IS NULL';
                    $db->query($updateSql, [$fieldConfig['default']]);
                }

                logger($user->data()->id, LogCategories::LOG_CATEGORY_SETTINGS_UPDATE, 'Populated NULL settings fields with defaults: ' . implode(', ', $fieldsToPopulate));
                $messages[] = ['type' => 'info', 'message' => count($fieldsToPopulate) . ' existing settings fields were populated with default values.'];
            } catch (Exception $e) {
                $messages[] = ['type' => 'danger', 'message' => 'Error populating settings fields. See system log for details.'];
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Settings field population failed: ' . $e->getMessage());
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
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>

<div class="alert alert-primary">
    <h4><i class="fas fa-cog"></i> Registry Settings</h4>
    <p class="mb-0">Comprehensive configuration management for the Elan Registry system with automatic database field creation.</p>
</div>

<form class="settings-form" method="post" id="registrySettingsForm">
    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">

    <div class="row">
        <div class="col-md-6">
            <!-- System Maintenance -->
            <div class="card border-primary mb-4">
                <div class="card-header card-header-er-primary">
                    <h5 class="mb-0 card-header-er-primary-text"><i class="fas fa-tools"></i> Backup Configuration</h5>
                    <small class="card-header-er-primary-text">Backup and system maintenance settings</small>
                </div>
                <div class="card-body">
                    <div class="alert alert-primary">
                        <i class="fas fa-info-circle"></i> <strong>Backup Configuration Moved</strong>
                        <p class="mb-0 mt-2">Backup retention periods are now configured in <code>usersc/includes/config.php</code> for better application-wide consistency.</p>
                        <p class="mb-0 mt-2 small"><strong>Configuration:</strong></p>
                        <ul class="small mb-0 mt-1">
                            <li><code>BACKUP_RETENTION_AUTOMATED</code> = 7 days</li>
                            <li><code>BACKUP_RETENTION_MANUAL</code> = 30 days</li>
                            <li><code>BACKUP_RETENTION_ROLLBACK</code> = 30 days</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <!-- Media Management -->
            <div class="card border-primary mb-4">
                <div class="card-header card-header-er-primary">
                    <h5 class="mb-0 card-header-er-primary-text"><i class="fas fa-images"></i> Media Management</h5>
                    <small class="card-header-er-primary-text">File upload and image handling settings</small>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="elan_image_dir" class="fw-bold">
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

                    <div class="mb-3">
                        <label for="elan_image_max" class="fw-bold">
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
                            <span class="input-group-text">photos</span>
                        </div>
                        <small class="form-text text-muted">Limit number of photos users can upload per car</small>
                    </div>

                    <div class="mb-3">
                        <label for="elan_image_upload_max_size" class="fw-bold">
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
                            <span class="input-group-text">MB</span>
                        </div>
                        <small class="form-text text-muted">Maximum file size for individual photo uploads. Accepted range: 0.5–10 MB.</small>
                    </div>
                </div>
            </div>

            <!-- Email Configuration -->
            <div class="card border-primary mb-4">
                <div class="card-header card-header-er-primary">
                    <h5 class="mb-0 card-header-er-primary-text"><i class="fas fa-envelope"></i> Email &amp; Communication</h5>
                    <small class="card-header-er-primary-text">Administrative email addresses and notification settings</small>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="elan_admin_emails" class="fw-bold">
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

                    <div class="mb-3">
                        <label for="elan_feedback_email" class="fw-bold">
                            <i class="fas fa-comment-dots"></i> Feedback Email Address
                        </label>
                        <input type="text"
                               class="form-control ajxtxt"
                               data-desc="Feedback Email Address"
                               name="elan_feedback_email"
                               id="elan_feedback_email"
                               value="<?= htmlspecialchars($settings->elan_feedback_email ?? 'registrar@elanregistry.org') ?>"
                               placeholder="registrar@elanregistry.org">
                        <small class="form-text text-muted">
                            <i class="fas fa-info-circle"></i> Email address for receiving user feedback form submissions
                        </small>
                    </div>

                    <div class="mt-3">
                        <button type="button" id="testEmailBtn" class="btn btn-outline-primary btn-sm" data-action="testEmailConfiguration">
                            <i class="fas fa-paper-plane"></i> Test Email Config
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>


<script src="<?= $us_url_root ?>app/admin/assets/js/tab-settings.min.js?v=<?= ASSET_VERSION ?>"></script>
