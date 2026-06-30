<?php

declare(strict_types=1);

/**
 * process-admin-settings.php
 * Pattern A endpoint for updating registry settings from the admin settings tab.
 *
 * Accepts a single field update (field, value, type, table) and writes it to the
 * settings table. Field names are validated against an explicit allowlist to prevent
 * unauthorised column access.
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

// Field allowlist — must match $allSettingsFields in processSettingsAutoCreation() (tab-settings.php)
const ALLOWED_SETTINGS_FIELDS = [
    'elan_image_dir',
    'elan_image_max',
    'elan_image_upload_max_size',
    'elan_image_display_max_size',
    'elan_image_thumbnail_sizes',
    'elan_admin_emails',
    'elan_feedback_email',
];

$field = (string)($_POST['field'] ?? '');
$table = (string)($_POST['table'] ?? 'settings');
$type  = (string)($_POST['type'] ?? '');
$value = $_POST['value'] ?? '';
$desc  = trim((string)($_POST['desc'] ?? $field));

if (!in_array($field, ALLOWED_SETTINGS_FIELDS, true)) {
    ApiResponse::error('Invalid setting field', 400)
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY, "Rejected unknown settings field: {$field}")
        ->send();
}

if ($table !== 'settings') {
    ApiResponse::error('Invalid table', 400)
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY, "Rejected unexpected settings table: {$table}")
        ->send();
}

if (!in_array($type, ['toggle', 'num', 'txt'], true)) {
    ApiResponse::error('Invalid type', 400)
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY,
            "Rejected invalid type '{$type}' for settings field: {$field}")
        ->send();
}

if ($type === 'num' && !is_numeric($value)) {
    ApiResponse::error('Value must be numeric', 400)
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY,
            "Rejected non-numeric value for numeric settings field: {$field}")
        ->send();
}

$processedValue = match ($type) {
    'toggle' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
    'num'    => $value,
    default  => (string) $value,
};

try {
    $db = DB::getInstance();
    $updated = $db->update('settings', 1, [$field => $processedValue]);

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
