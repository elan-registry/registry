<?php

declare(strict_types=1);

/**
 * process-admin-settings.php
 * Pattern A endpoint for updating registry settings from the admin settings tab.
 *
 * Accepts a single field update (field, value, table) and writes it to the settings
 * table. Field type is derived server-side from FIELD_TYPES — never from POST — to
 * prevent type-mismatch bypasses on per-field numeric or boolean validation.
 *
 * Called by: app/admin/includes/tab-settings.php via ElanRegistryAPI
 * Issue #528 — Migrate admin settings $.ajax() calls to ElanRegistryAPI
 */

require_once '../../../users/init.php';

// Admin-only (level 2) — matches maintenance.php access requirement
if (!$user->isLoggedIn() || !hasPerm([2], $user->data()->id)) {
    ApiResponse::forbidden('Access denied')
        ->withLogging(0, LogCategories::LOG_CATEGORY_SECURITY, 'Unauthorized admin settings update attempt')
        ->send();
}

// CSRF protection
if (!isset($_POST['csrf']) || !Token::check($_POST['csrf'])) {
    ApiResponse::forbidden('Invalid CSRF token')
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY, 'Invalid CSRF token in admin settings update')
        ->send();
}

// Field-to-type map — type is always server-derived, never from POST.
// Must match $allSettingsFields in processSettingsAutoCreation() (tab-settings.php).
const FIELD_TYPES = [
    'elan_image_dir'              => 'txt',
    'elan_image_max'              => 'num',
    'elan_image_upload_max_size'  => 'num',
    'elan_image_display_max_size' => 'num',
    'elan_image_thumbnail_sizes'  => 'txt',
    'elan_admin_emails'           => 'txt',
    'elan_feedback_email'         => 'txt',
];

$field = (string)($_POST['field'] ?? '');
$table = (string)($_POST['table'] ?? 'settings');
$value = $_POST['value'] ?? '';
$desc  = trim((string)($_POST['desc'] ?? $field));

if (!array_key_exists($field, FIELD_TYPES)) {
    ApiResponse::error('Invalid setting field', 400)
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY, "Rejected unknown settings field: {$field}")
        ->send();
}

if ($table !== 'settings') {
    ApiResponse::error('Invalid table', 400)
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY, "Rejected unexpected settings table: {$table}")
        ->send();
}

$type = FIELD_TYPES[$field];

if ($type === 'num' && !is_numeric($value)) {
    ApiResponse::error('Value must be numeric', 400)
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY,
            "Rejected non-numeric value for numeric settings field: {$field}")
        ->send();
}

$processedValue = match ($type) {
    'toggle' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
    'num'    => $value + 0,
    default  => (string) $value,
};

try {
    $db = DB::getInstance();
    $updated = $db->update($table, 1, [$field => $processedValue]);

    if (!$updated || $db->error()) {
        ApiResponse::serverError('Database error updating setting')
            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
                "DB error updating settings.{$field}: " . $db->errorString())
            ->send();
    }

    $label = $desc !== '' ? htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') : $field;

    ApiResponse::success($label . ' updated successfully')
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SETTINGS_UPDATE,
            "Admin settings: {$field} updated")
        ->send();
} catch (\Throwable $e) {
    ApiResponse::serverError('Failed to update setting')
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            'Admin settings update exception: ' . $e->getMessage())
        ->send();
}
