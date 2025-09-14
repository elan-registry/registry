<?php

declare(strict_types=1);

/**
 * Menu System Export/Import Tool
 *
 * Administrative script to export menu configurations from development
 * and apply them to production, keeping menu systems synchronized.
 * Issue #297: Develop a method to export menu system from development and apply to production
 *
 * FEATURES:
 * - Environment detection and validation
 * - Export complete menu ecosystem (pages, permissions, menus, relationships)
 * - Import with automatic backup and rollback capability
 * - Support for both Classic Menu and future UltraMenu systems
 * - JSON format for easy parsing and version control
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. This script can be run via command line or web interface
 * 2. Environment detection prevents accidental cross-environment operations
 * 3. Automatic backup before any import operations
 * 4. Follow FIX script patterns for consistency
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Get the database instance
$db = DB::getInstance();

/**
 * Environment Detection
 * Based on URL patterns documented in docs/development/ENVIRONMENT.md
 *
 * @return string Environment name (development, test, production)
 */
function detectEnvironment(): string {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '';

    // Check for test environment first (more specific)
    if (strpos($host, 'test.elanregistry.org') !== false) {
        return 'test';
    }
    // Check for development environment
    elseif (strpos($host, 'localhost') !== false && strpos($uri, '/elan_registry') !== false) {
        return 'development';
    }
    // Check for production environment (main domain only, excluding subdomains)
    elseif ($host === 'elanregistry.org' || $host === 'www.elanregistry.org') {
        return 'production';
    }

    return 'unknown';
}

/**
 * Menu System Detection
 * Determines whether Classic Menu or UltraMenu system is in use
 * based on the active template's menu system support
 *
 * IMPORTANT: Template-to-Menu-System Mapping
 * This logic maps templates to their supported menu systems and will need
 * updates when converting to UltraMenu or adding new templates.
 */
function detectMenuSystem($db) {
    try {
        // Check what template is currently active
        $templateQuery = $db->query("SELECT template FROM settings WHERE id = 1");

        if ($templateQuery && $templateQuery->first()) {
            $template = $templateQuery->first()->template;

            // TEMPLATE MENU SYSTEM MAPPING
            // =============================
            // ElanRegistry template uses Classic Menu system
            // UltraMenu tables exist for future compatibility but are not active
            if ($template === 'ElanRegistry') {
                return 'classic';
            }

            // TODO: Future template conversions
            // When converting ElanRegistry to UltraMenu or adding new templates:
            // 1. Test menu system detection with new template
            // 2. Update template mapping logic below
            // 3. Ensure export/import handles both systems correctly
            // 4. Test menu sync between environments
            //
            // Example future mappings:
            // if ($template === 'ElanRegistryUltra') {
            //     return 'ultramenu';
            // }
            // if ($template === 'SomeOtherTemplate') {
            //     return 'ultramenu'; // or 'classic' depending on template
            // }

            // For unknown templates, fall through to data-based detection
        }

        // Fallback: Check which system has active data
        $tables = $db->query("SHOW TABLES LIKE 'us_menus'")->results();

        if (empty($tables)) {
            // No us_menus table = definitely classic
            return 'classic';
        }

        // Check if classic menu system has active configuration
        $classicMenuCount = $db->query("SELECT COUNT(*) as count FROM menus WHERE id > 20")->first();

        if ($classicMenuCount && $classicMenuCount->count > 5) {
            // Significant classic menu data = active classic system
            return 'classic';
        }

        // Check if UltraMenu system has active configuration
        $ultraMenuCount = $db->query("SELECT COUNT(*) as count FROM us_menus")->first();

        if ($ultraMenuCount && $ultraMenuCount->count > 0) {
            return 'ultramenu';
        }

        // Default to classic
        return 'classic';

    } catch (Exception $e) {
        // If there's any error, default to classic menu system
        return 'classic';
    }
}

/**
 * Export Menu System
 * Exports complete menu configuration to JSON format
 */
function exportMenuSystem($db, $environment) {
    try {
        $menuSystem = detectMenuSystem($db);
        $timestamp = date('c');

        $export = [
            'export_info' => [
                'timestamp' => $timestamp,
                'source_environment' => $environment,
                'menu_system' => $menuSystem,
                'version' => '1.0'
            ]
        ];

        // Both menu systems need pages and permissions
        $pagesQuery = $db->query("SELECT id, page, title, private, re_auth, core FROM pages");
        if (!$pagesQuery) {
            throw new Exception('Failed to query pages table');
        }
        $export['pages'] = $pagesQuery->results();

        $permissionsQuery = $db->query("SELECT permission_id, page_id FROM permission_page_matches");
        if (!$permissionsQuery) {
            throw new Exception('Failed to query permission_page_matches table');
        }
        $export['permissions'] = $permissionsQuery->results();

        if ($menuSystem === 'classic') {
            // Export Classic Menu system
            $menusQuery = $db->query("SELECT id, menu_title, parent, dropdown, logged_in, display_order, label, link, icon_class FROM menus");
            if (!$menusQuery) {
                throw new Exception('Failed to query menus table');
            }
            $export['menus'] = $menusQuery->results();

            $menuPermissionsQuery = $db->query("SELECT group_id, menu_id FROM groups_menus");
            if (!$menuPermissionsQuery) {
                throw new Exception('Failed to query groups_menus table');
            }
            $export['menu_permissions'] = $menuPermissionsQuery->results();
        } else {
            // Export UltraMenu system
            $ultraMenusQuery = $db->query("SELECT id, menu_name, type, z_index, show_active, theme, disabled FROM us_menus");
            if (!$ultraMenusQuery) {
                throw new Exception('Failed to query us_menus table');
            }
            $export['us_menus'] = $ultraMenusQuery->results();
            // Add other UltraMenu related tables as needed
            // Note: UltraMenu may have different permission structures - to be implemented
        }

        // Clean data for JSON encoding (remove any invalid UTF-8)
        array_walk_recursive($export, function(&$value) {
            if (is_string($value)) {
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
        });

        return $export;

    } catch (Exception $e) {
        throw new Exception('Export failed: ' . $e->getMessage());
    }
}

/**
 * Import Menu System
 * Imports menu configuration from JSON with safety checks
 */
function importMenuSystem($db, $importData, $targetEnvironment) {
    // Validate import data
    if (!isset($importData->export_info) || !isset($importData->export_info->menu_system)) {
        throw new Exception("Invalid import data: Missing export information");
    }

    $menuSystem = $importData->export_info->menu_system;
    $sourceEnv = $importData->export_info->source_environment ?? 'unknown';

    // Environment validation
    if ($sourceEnv === $targetEnvironment) {
        throw new Exception("Cannot import from same environment ($sourceEnv)");
    }

    // Create backup before import
    $backupFile = createBackup($targetEnvironment);

    try {
        // Begin transaction
        $db->query("START TRANSACTION");

        // Import pages and permissions (common to both menu systems)
        importPagesAndPermissions($db, $importData);

        if ($menuSystem === 'classic') {
            // Import Classic Menu system
            importClassicMenus($db, $importData);
        } else {
            // Import UltraMenu system
            importUltraMenus($db, $importData);
        }

        // Commit transaction
        $db->query("COMMIT");

        return [
            'success' => true,
            'backup_file' => $backupFile,
            'menu_system' => $menuSystem,
            'source_environment' => $sourceEnv
        ];

    } catch (Exception $e) {
        // Rollback on error
        $db->query("ROLLBACK");
        throw $e;
    }
}

/**
 * Import Pages and Permissions (Common to both menu systems)
 */
function importPagesAndPermissions($db, $importData) {
    // Import pages (with conflict handling)
    if (isset($importData->pages)) {
        foreach ($importData->pages as $page) {
            $db->query("INSERT INTO pages (id, page, title, private, re_auth, core)
                       VALUES (?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE
                       title = VALUES(title),
                       private = VALUES(private),
                       re_auth = VALUES(re_auth),
                       core = VALUES(core)", [
                $page->id, $page->page, $page->title,
                $page->private, $page->re_auth, $page->core
            ]);
        }
    }

    // Import permission_page_matches
    if (isset($importData->permissions)) {
        // Clear existing permission-page relationships that might be replaced
        $db->query("DELETE FROM permission_page_matches");

        foreach ($importData->permissions as $perm) {
            $db->query("INSERT INTO permission_page_matches (permission_id, page_id)
                       VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE
                       permission_id = VALUES(permission_id),
                       page_id = VALUES(page_id)", [
                $perm->permission_id, $perm->page_id
            ]);
        }
    }
}

/**
 * Import Classic Menu System
 */
function importClassicMenus($db, $importData) {
    // Clear existing menu data
    $db->query("DELETE FROM groups_menus WHERE group_id IN (0, 2, 3)");
    $db->query("DELETE FROM menus WHERE id > 20"); // Keep base UserSpice menus

    // Import menus
    if (isset($importData->menus)) {
        foreach ($importData->menus as $menu) {
            $db->query("INSERT INTO menus (id, menu_title, parent, dropdown, logged_in, display_order, label, link, icon_class)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE
                       menu_title = VALUES(menu_title),
                       parent = VALUES(parent),
                       dropdown = VALUES(dropdown),
                       logged_in = VALUES(logged_in),
                       display_order = VALUES(display_order),
                       label = VALUES(label),
                       link = VALUES(link),
                       icon_class = VALUES(icon_class)", [
                $menu->id, $menu->menu_title, $menu->parent, $menu->dropdown,
                $menu->logged_in, $menu->display_order, $menu->label,
                $menu->link, $menu->icon_class
            ]);
        }
    }

    // Import groups_menus (menu permissions)
    if (isset($importData->menu_permissions)) {
        foreach ($importData->menu_permissions as $menuPerm) {
            $db->query("INSERT INTO groups_menus (group_id, menu_id)
                       VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE
                       group_id = VALUES(group_id),
                       menu_id = VALUES(menu_id)", [
                $menuPerm->group_id, $menuPerm->menu_id
            ]);
        }
    }
}

/**
 * Import UltraMenu System (for future template conversion)
 *
 * CONVERSION CHECKLIST:
 * When implementing UltraMenu conversion, ensure:
 * 1. Identify all UltraMenu tables beyond us_menus
 * 2. Map Classic Menu fields to UltraMenu equivalents
 * 3. Handle permission structures (may differ from Classic)
 * 4. Test with ElanRegistry template converted to UltraMenu
 * 5. Verify backup/restore includes all UltraMenu tables
 */
function importUltraMenus($db, $importData) {
    // Clear existing UltraMenu data
    $db->query("DELETE FROM us_menus");
    // TODO: Add clearing of other UltraMenu tables as needed

    // Import UltraMenu data
    if (isset($importData->us_menus)) {
        foreach ($importData->us_menus as $menu) {
            $db->query("INSERT INTO us_menus (id, menu_name, type, z_index, show_active, theme, disabled)
                       VALUES (?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE
                       menu_name = VALUES(menu_name),
                       type = VALUES(type),
                       z_index = VALUES(z_index),
                       show_active = VALUES(show_active),
                       theme = VALUES(theme),
                       disabled = VALUES(disabled)", [
                $menu->id, $menu->menu_name, $menu->type, $menu->z_index,
                $menu->show_active, $menu->theme, $menu->disabled
            ]);
        }
    }

    // TODO: Import other UltraMenu related tables
    // Research needed: UltraMenu permission structure vs Classic Menu
    // May require different approach than groups_menus table
}

/**
 * Create Backup
 * Creates timestamped backup following FIX script patterns
 */
function createBackup($environment) {
    $backupDir = dirname(__FILE__) . '/../FIX/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    $timestamp = date('Ymd_His');
    $backupFile = "{$backupDir}/menu_sync_backup_{$environment}_{$timestamp}.sql";

    // Get database config from globals
    $host = $GLOBALS['config']['mysql']['host'] ?? 'localhost';
    $username = $GLOBALS['config']['mysql']['username'] ?? '';
    $password = $GLOBALS['config']['mysql']['password'] ?? '';
    $dbname = $GLOBALS['config']['mysql']['db'] ?? '';
    $port = $GLOBALS['config']['mysql']['port'] ?? 3306;

    // Tables to backup (always include pages and permissions for both systems)
    $tables = ['pages', 'permission_page_matches'];

    // Add menu system specific tables
    if (detectMenuSystem(DB::getInstance()) === 'ultramenu') {
        $tables = array_merge($tables, ['us_menus']);
        // Add other UltraMenu tables as needed
    } else {
        $tables = array_merge($tables, ['menus', 'groups_menus']);
    }

    $tablesStr = implode(' ', $tables);

    // For hosting environments, use PHP-based backup for reliability
    $currentEnv = detectEnvironment();
    $backupSuccess = false;

    if ($currentEnv === 'development') {
        // Local MAMP installation - use mysqldump
        $mysqldumpPath = '/Applications/MAMP/Library/bin/mysql57/bin/mysqldump';
        $command = sprintf(
            '%s -h %s -P %d -u %s -p%s %s %s > %s',
            $mysqldumpPath,
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($dbname),
            $tablesStr,
            escapeshellarg($backupFile)
        );

        exec($command, $output, $returnCode);
        $backupSuccess = ($returnCode === 0 && file_exists($backupFile) && filesize($backupFile) > 100);
    } else {
        // For test/production environments, use PHP backup directly for reliability
        error_log("Using PHP-based backup for environment: {$currentEnv}");
        $backupSuccess = createPhpBackup($backupFile, $tables, DB::getInstance());
    }

    if (!$backupSuccess) {
        throw new Exception("Backup creation failed for environment: {$currentEnv}");
    }

    return $backupFile;
}

/**
 * Create PHP-based backup (fallback when mysqldump not available)
 * Creates SQL dump using PHP database queries
 */
function createPhpBackup($backupFile, $tables, $db) {
    try {
        $sql = "-- Menu System Backup\n";
        $sql .= "-- Created: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Environment: " . detectEnvironment() . "\n\n";

        foreach ($tables as $table) {
            // Get table structure
            $createQuery = $db->query("SHOW CREATE TABLE `{$table}`");
            if ($createQuery && $createQuery->first()) {
                $sql .= "-- Table structure for {$table}\n";
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sql .= $createQuery->first()->{'Create Table'} . ";\n\n";
            }

            // Get table data
            $dataQuery = $db->query("SELECT * FROM `{$table}`");
            if ($dataQuery && $dataQuery->results()) {
                $sql .= "-- Data for table {$table}\n";

                foreach ($dataQuery->results() as $row) {
                    $columns = array_keys((array)$row);
                    $values = array_values((array)$row);

                    // Escape values
                    $escapedValues = array_map(function($value) {
                        if ($value === null) {
                            return 'NULL';
                        }
                        return "'" . addslashes($value) . "'";
                    }, $values);

                    $sql .= "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (";
                    $sql .= implode(', ', $escapedValues) . ");\n";
                }
                $sql .= "\n";
            }
        }

        // Write to file
        $result = file_put_contents($backupFile, $sql);
        return ($result !== false && file_exists($backupFile) && filesize($backupFile) > 100);

    } catch (Exception $e) {
        error_log("PHP backup failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Rollback from Backup
 */
function rollbackFromBackup($backupFile) {
    if (!file_exists($backupFile)) {
        throw new Exception("Backup file not found: {$backupFile}");
    }

    // Get database config
    $host = $GLOBALS['config']['mysql']['host'] ?? 'localhost';
    $username = $GLOBALS['config']['mysql']['username'] ?? '';
    $password = $GLOBALS['config']['mysql']['password'] ?? '';
    $dbname = $GLOBALS['config']['mysql']['db'] ?? '';
    $port = $GLOBALS['config']['mysql']['port'] ?? 3306;

    // Determine mysql path based on environment
    $currentEnv = detectEnvironment();
    if ($currentEnv === 'development') {
        // Local MAMP installation
        $mysqlPath = '/Applications/MAMP/Library/bin/mysql57/bin/mysql';
    } else {
        // Production/Test environments (A2 Hosting)
        $mysqlPath = '/usr/bin/mysql';
    }

    // Build mysql restore command
    $command = sprintf(
        '%s -h %s -P %d -u %s -p%s %s < %s',
        $mysqlPath,
        escapeshellarg($host),
        $port,
        escapeshellarg($username),
        escapeshellarg($password),
        escapeshellarg($dbname),
        escapeshellarg($backupFile)
    );

    exec($command, $output, $returnCode);

    if ($returnCode !== 0) {
        throw new Exception("Rollback failed - return code: {$returnCode}");
    }

    return true;
}

// Main execution logic
$currentEnvironment = detectEnvironment();
$menuSystem = detectMenuSystem($db);

// Handle AJAX requests BEFORE any HTML output
if (isset($_GET['action'])) {
    // Prevent any HTML output
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');

    try {
        if ($_GET['action'] === 'export') {
            if ($currentEnvironment !== 'development') {
                throw new Exception('Export only allowed from development environment');
            }

            $exportData = exportMenuSystem($db, $currentEnvironment);

            // Check if export data is valid
            if (empty($exportData)) {
                throw new Exception('Export data is empty');
            }

            // Validate JSON encoding
            $jsonResult = json_encode([
                'success' => true,
                'export' => $exportData,
                'timestamp' => date('Ymd_His')
            ]);

            if ($jsonResult === false) {
                throw new Exception('JSON encoding failed: ' . json_last_error_msg());
            }

            echo $jsonResult;

        } elseif ($_GET['action'] === 'import') {
            $input = json_decode(file_get_contents('php://input'), false);
            if (!$input) {
                throw new Exception('Invalid JSON input');
            }

            $result = importMenuSystem($db, $input, $currentEnvironment);
            echo json_encode(['success' => true] + $result);

        } else {
            throw new Exception('Invalid action');
        }

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }

    exit; // Critical: exit before any HTML is rendered
}

// Stop here if being included for functions only
if (defined('INCLUDE_FUNCTIONS_ONLY') && INCLUDE_FUNCTIONS_ONLY) {
    return;
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Menu System Sync Tool</title>
    <style>
        .menu-sync-container { max-width: 800px; margin: 20px auto; padding: 20px; }
        .environment-info { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .export-section, .import-section { background: white; padding: 20px; border: 1px solid #dee2e6; border-radius: 5px; margin-bottom: 20px; }
        .success { color: #28a745; }
        .warning { color: #ffc107; }
        .error { color: #dc3545; }
        .code-block { background: #f8f9fa; padding: 10px; border-radius: 3px; font-family: monospace; white-space: pre-wrap; }
    </style>
</head>
<body>
    <div class="menu-sync-container">
        <h1>Menu System Sync Tool</h1>

        <div class="environment-info">
            <h3>Current Environment</h3>
            <p><strong>Environment:</strong> <span class="<?= $currentEnvironment === 'development' ? 'success' : 'warning' ?>"><?= ucfirst($currentEnvironment) ?></span></p>
            <p><strong>Menu System:</strong> <?= ucfirst($menuSystem) ?></p>
            <p><strong>URL:</strong> <?= $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ?></p>
        </div>

        <?php if ($currentEnvironment === 'development'): ?>
        <div class="export-section">
            <h3>Export Menu Configuration</h3>
            <p>Export the current menu system configuration to JSON format for deployment to other environments.</p>
            <button onclick="exportMenus()" class="btn btn-primary">Export Menus</button>
            <div id="exportResults"></div>
        </div>
        <?php endif; ?>

        <div class="import-section">
            <h3>Import Menu Configuration</h3>
            <p>Import menu configuration from a JSON file. <strong>Warning:</strong> This will overwrite existing menu configuration after creating a backup.</p>
            <input type="file" id="importFile" accept=".json">
            <button onclick="importMenus()" class="btn btn-warning">Import Menus</button>
            <div id="importResults"></div>
        </div>
    </div>

    <script>
    function exportMenus() {
        document.getElementById('exportResults').innerHTML = '<p>Exporting...</p>';

        fetch('?action=export', { method: 'POST' })
            .then(response => {
                // Check if response is ok
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                // Parse response as JSON
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (jsonError) {
                        throw new Error('Invalid JSON response: ' + jsonError.message);
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    const blob = new Blob([JSON.stringify(data.export, null, 2)], {type: 'application/json'});
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `menu_export_${data.timestamp}.json`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);

                    document.getElementById('exportResults').innerHTML =
                        '<div class="success">Export successful! Download started.</div>';
                } else {
                    document.getElementById('exportResults').innerHTML =
                        '<div class="error">Export failed: ' + (data.error || 'Unknown error') + '</div>';
                }
            })
            .catch(error => {
                document.getElementById('exportResults').innerHTML =
                    '<div class="error">Export failed: ' + error.message + '</div>';
            });
    }

    function importMenus() {
        const fileInput = document.getElementById('importFile');
        if (!fileInput.files[0]) {
            alert('Please select a JSON file to import');
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const importData = JSON.parse(e.target.result);

                document.getElementById('importResults').innerHTML = '<p>Importing...</p>';

                fetch('?action=import', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(importData)
                })
                .then(response => {
                    // Check if response is ok
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }

                    // Parse response as JSON
                    return response.text().then(text => {
                        try {
                            return JSON.parse(text);
                        } catch (jsonError) {
                            throw new Error('Invalid JSON response: ' + jsonError.message);
                        }
                    });
                })
                .then(data => {
                    if (data.success) {
                        document.getElementById('importResults').innerHTML =
                            '<div class="success">Import successful!</div>' +
                            '<p>Backup created: ' + (data.backup_file || 'unknown') + '</p>';
                    } else {
                        document.getElementById('importResults').innerHTML =
                            '<div class="error">Import failed: ' + (data.error || 'Unknown error') + '</div>';
                    }
                })
                .catch(error => {
                    document.getElementById('importResults').innerHTML =
                        '<div class="error">Import failed: ' + error.message + '</div>';
                });

            } catch (error) {
                document.getElementById('importResults').innerHTML =
                    '<div class="error">Invalid JSON file: ' + error.message + '</div>';
            }
        };
        reader.readAsText(fileInput.files[0]);
    }
    </script>
</body>
</html>

