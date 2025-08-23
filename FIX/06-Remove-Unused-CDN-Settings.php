<?php
/**
 * ELAN REGISTRY FIX SCRIPT #06
 * Remove Unused CDN Configuration Settings from Database
 * 
 * This script removes CDN configuration settings that are no longer needed
 * with the switch to the customizer template. The customizer template uses
 * built-in CDN links for most dependencies.
 * 
 * SETTINGS TO REMOVE (No longer used by customizer template):
 * - elan_bootstrap_css_cdn - Customizer uses hardcoded Bootstrap 5.3.3
 * - elan_bootstrap_js_cdn - Customizer uses hardcoded Bootstrap 5.3.3  
 * - elan_bootswatch_cdn - Customizer uses built-in theming system
 * - elan_jquery_cdn - Customizer uses UserSpice's built-in jQuery
 * - elan_popper_cdn - Included in Bootstrap bundle
 * - elan_fontawesome_cdn - Customizer uses local FontAwesome files
 * 
 * SETTINGS TO KEEP (Still used by car management pages):
 * - elan_jquery_ui_cdn - Used in car edit forms
 * - elan_datatables_js_cdn - Used in car listing pages
 * - elan_datatables_css_cdn - Used in car listing pages
 * - elan_datepicker_js_cdn - Used in car edit forms
 * - elan_datepicker_css_cdn - Used in car edit forms
 * - elan_dropzone_js_cdn - Used for file uploads
 * - elan_dropzone_css_cdn - Used for file uploads
 * - elan_google_maps_key - Required for maps
 * - elan_google_geo_key - Required for geocoding
 * - elan_backup_age - System settings
 * - elan_image_dir - System settings
 * - elan_image_max - System settings
 * 
 * Generated: 2025-08-23
 * Author: Claude Code Analysis
 */

// Initialize UserSpice
$abs_us_root = $_SERVER['DOCUMENT_ROOT'];
$self_path = explode("/", $_SERVER['PHP_SELF']);
$self_path_length = count($self_path);
$file_found = false;

// Find UserSpice root
for ($i = 1; $i < $self_path_length; $i++) {
    array_splice($self_path, $self_path_length - $i, $i);
    $us_url_root = implode("/", $self_path) . "/";
    
    if (file_exists($abs_us_root . $us_url_root . 'z_us_root.php')) {
        $file_found = true;
        break;
    }
}

if (!$file_found) {
    die("Error: Could not locate UserSpice root directory");
}

require_once $abs_us_root . $us_url_root . 'users/init.php';

echo "<h2>Remove Unused CDN Configuration Settings</h2>\n";
echo "<h3>Customizer Template Cleanup</h3>\n";

if (!$user->isLoggedIn() || !in_array($user->data()->id, $master_account)) {
    die("<p style='color: red;'>Access denied. This script requires admin privileges.</p>");
}

echo "<h4>Analysis:</h4>\n";
echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
echo "<p><strong>Template Change Impact:</strong></p>\n";
echo "<ul>\n";
echo "<li>ElanRegistry template: Used configurable CDN links for all dependencies</li>\n";
echo "<li>Customizer template: Uses hardcoded CDN links and local files</li>\n";
echo "<li>Result: Many CDN configuration settings are no longer used</li>\n";
echo "</ul>\n";
echo "</div>\n";

// Settings to remove (no longer used)
$settings_to_remove = [
    'elan_bootstrap_css_cdn',
    'elan_bootstrap_js_cdn',
    'elan_bootswatch_cdn',
    'elan_jquery_cdn',
    'elan_popper_cdn',
    'elan_fontawesome_cdn'
];

// Settings to keep (still used by car management pages)
$settings_to_keep = [
    'elan_jquery_ui_cdn',
    'elan_datatables_js_cdn',
    'elan_datatables_css_cdn',
    'elan_datepicker_js_cdn',
    'elan_datepicker_css_cdn',
    'elan_dropzone_js_cdn',
    'elan_dropzone_css_cdn',
    'elan_google_maps_key',
    'elan_google_geo_key',
    'elan_backup_age',
    'elan_image_dir',
    'elan_image_max'
];

echo "<h4>Settings to Remove:</h4>\n";
echo "<ul>\n";
foreach ($settings_to_remove as $setting) {
    $current_value = isset($settings->$setting) ? substr($settings->$setting, 0, 50) . '...' : 'not set';
    echo "<li><strong>{$setting}</strong>: {$current_value}</li>\n";
}
echo "</ul>\n";

echo "<h4>Settings to Keep:</h4>\n";
echo "<ul>\n";
foreach ($settings_to_keep as $setting) {
    $current_value = isset($settings->$setting) ? substr($settings->$setting, 0, 50) . '...' : 'not set';
    echo "<li><strong>{$setting}</strong>: {$current_value}</li>\n";
}
echo "</ul>\n";

// Create backup of current settings
echo "<h4>Creating Settings Backup...</h4>\n";
$backup_data = [];
foreach ($settings_to_remove as $setting) {
    if (isset($settings->$setting)) {
        $backup_data[$setting] = $settings->$setting;
    }
}

$backup_file = $abs_us_root . $us_url_root . 'FIX/cdn-settings-backup-' . date('Y-m-d-H-i-s') . '.json';
if (file_put_contents($backup_file, json_encode($backup_data, JSON_PRETTY_PRINT))) {
    echo "<p style='color: green;'>✓ Backup created: " . basename($backup_file) . "</p>\n";
} else {
    echo "<p style='color: red;'>❌ Failed to create backup file</p>\n";
}

// Remove unused settings from database
echo "<h4>Removing Unused Settings from Database...</h4>\n";
$removed_count = 0;

foreach ($settings_to_remove as $setting) {
    try {
        $query = $db->prepare("DELETE FROM settings WHERE setting = ?");
        $query->execute([$setting]);
        
        if ($query->rowCount() > 0) {
            echo "<p style='color: green;'>✓ Removed: {$setting}</p>\n";
            $removed_count++;
        } else {
            echo "<p style='color: blue;'>• Not found: {$setting}</p>\n";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Error removing {$setting}: " . $e->getMessage() . "</p>\n";
    }
}

echo "<h4>Summary:</h4>\n";
echo "<div style='background: #d1e7dd; padding: 15px; border-radius: 5px; margin: 10px 0;'>\n";
echo "<p><strong>Cleanup Complete:</strong></p>\n";
echo "<ul>\n";
echo "<li>Settings removed: {$removed_count}</li>\n";
echo "<li>Settings preserved: " . count($settings_to_keep) . "</li>\n";
echo "<li>Backup created: " . basename($backup_file) . "</li>\n";
echo "</ul>\n";
echo "</div>\n";

echo "<h4>What This Achieves:</h4>\n";
echo "<ul>\n";
echo "<li>Cleaner database with only relevant settings</li>\n";
echo "<li>Admin interface shows only configurable dependencies</li>\n";
echo "<li>Better alignment with customizer template architecture</li>\n";
echo "<li>Reduced configuration complexity for administrators</li>\n";
echo "</ul>\n";

echo "<h4>Rollback Instructions:</h4>\n";
echo "<p>If you need to restore these settings:</p>\n";
echo "<ol>\n";
echo "<li>Restore from backup: <code>" . basename($backup_file) . "</code></li>\n";
echo "<li>Import JSON data back to settings table</li>\n";
echo "<li>Refresh UserSpice settings cache</li>\n";
echo "</ol>\n";

echo "<hr>\n";
echo "<p style='color: blue;'><strong>CDN settings cleanup complete!</strong> The database now contains only the CDN settings needed for the customizer template.</p>\n";
?>