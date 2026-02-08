<?php

declare(strict_types=1);

/**
 * Convert Elan Registry Tables to InnoDB Script
 *
 * Administrative script to convert Elan Registry database tables from MyISAM to InnoDB
 * storage engine for improved data integrity, transaction support, and crash recovery.
 * Issue #383: Storage Engine Standardization
 *
 * TABLES CONVERTED:
 * - cars (critical data, 1,230 rows)
 * - cars_hist (audit trail, 5,520 rows)
 * - car_user (car ownership, 1,230 rows)
 * - car_user_hist (ownership audit trail, 264 rows)
 * - country (reference data, 238 rows)
 *
 * DATA FIXES APPLIED BEFORE CONVERSION:
 * - Corrects invalid date values in cars.purchasedate and cars.solddate
 * - Corrects invalid date values in cars_hist.purchasedate and cars_hist.solddate
 * - Converts 1900-01-01 dates to 0000-00-00 (null equivalent)
 * - Fixes YYYY-00-00 dates to YYYY-01-01 (zero month/day)
 * - Fixes YYYY-MM-00 dates to YYYY-MM-01 (zero day only)
 *
 * BENEFITS OF INNODB:
 * - ACID-compliant transactions
 * - Foreign key support
 * - Row-level locking (better concurrency)
 * - Robust crash recovery
 * - Automatic backup before conversion
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Access via FIX/index.php menu or direct URL
 * 2. Script uses two-step confirmation for safety
 * 3. Automatic backup created before any changes using BackupManager
 * 4. Invalid dates are corrected before conversion to prevent InnoDB strictness issues
 * 5. All changes logged to UserSpice audit system
 * 6. No data loss - only storage engine conversion and date normalization
 */

// UI Constants for progress output
define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
// BackupManager auto-loaded via custom autoloader (now in usersc/classes/admin/)

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Set up custom error handler to log through UserSpice logger
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    global $user;
    if ($errno !== E_DEPRECATED) {
        logger(isset($user) ? (int)$user->data()->id : 0, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, "Error [$errno]: $errstr in $errfile:$errline");
    }
    return true;
});

$db = DB::getInstance();
$backupManager = new BackupManager($db, $abs_us_root . $us_url_root . 'backups/', (int)$user->data()->id);

$tablesToConvert = [
    'cars',
    'cars_hist',
    'car_user',
    'car_user_hist',
    'country',
];

?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">

            <?php if (!isset($_GET['start'])): ?>
            <!-- Initial Description -->
            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-exchange"></i> Convert Tables to InnoDB
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script converts Elan Registry database tables from MyISAM to InnoDB storage engine. This improves data integrity, enables transaction support, and provides better crash recovery.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Creates automatic backup before any changes (for safety and rollback)</li>
                                    <li>Fixes invalid date values in cars and cars_hist tables (blocking data issues)</li>
                                    <li>Checks current storage engine status for all 5 Elan tables</li>
                                    <li>Converts MyISAM tables to InnoDB using ALTER TABLE</li>
                                    <li>Logs all changes to UserSpice audit system</li>
                                    <li>Displays conversion results and post-conversion steps</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>No data will be lost - only storage engine changes</li>
                                    <li>Tables will be temporarily locked during conversion</li>
                                    <li>Backup created before conversion (~2.7 MB of data)</li>
                                    <li>Typical conversion time: less than 1 minute</li>
                                    <li>UserSpice core tables (logs, menus, etc.) will NOT be changed</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <a href="?start=1" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Continue - Start Table Conversion
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else:
                // Processing mode - simple text output
                ob_end_clean(); // Clear template buffering
                header('Content-Type: text/html; charset=utf-8');
                echo str_repeat(' ', 1024); // Pad to force initial flush
                flush();
            ?>

            <div class="card registry-card">
                <div class="card-header">
                    <h2 class="mb-0">
                        <i class="fa fa-cogs"></i> Converting Tables to InnoDB
                    </h2>
                    <small class="text-muted">
                        <i class="fa fa-clock-o"></i> Started: <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px;"><?php

                    // Helper function for progress output
                    function logProgress($message, $type = 'info') {
                        $icons = [
                            'info' => 'ℹ️',
                            'success' => '✅',
                            'error' => '❌',
                            'warning' => '⚠️',
                            'step' => '▶️'
                        ];
                        $icon = $icons[$type] ?? '•';
                        echo date('[H:i:s] ') . $icon . ' ' . $message . "\n";
                        flush();
                    }

                    $results = [
                        'successful' => [],
                        'failed' => [],
                        'already_innodb' => [],
                        'backup_path' => null,
                        'dates_fixed' => 0
                    ];

                    try {
                        // STEP 1: Create Backup
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Creating Pre-Conversion Backup', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $backupPath = $backupManager->createSchemaBackup('Convert Tables to InnoDB', $tablesToConvert);
                        $results['backup_path'] = $backupPath;
                        logProgress('Backup created successfully', 'success');
                        logProgress('Backup location: ' . basename($backupPath), 'info');
                        logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, "Pre-conversion backup created: {$backupPath}");

                        // STEP 2: Fix Invalid Dates
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: Fixing Invalid Date Values', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        // Set SQL mode to allow date manipulations
                        $db->query("SET sql_mode = ''");
                        logProgress('SQL mode configured for date corrections', 'info');

                        // Define date fixing queries
                        $dateFixQueries = [
                            [
                                'table' => 'cars_hist',
                                'field' => 'purchasedate',
                                'query' => "UPDATE cars_hist
                                    SET `purchasedate` = CASE
                                        WHEN CAST(`purchasedate` AS CHAR) = '1900-01-01' THEN '0000-00-00'
                                        WHEN CAST(`purchasedate` AS CHAR) LIKE '%-00-00' THEN CONCAT(LEFT(`purchasedate`, 4), '-01-01')
                                        WHEN CAST(`purchasedate` AS CHAR) LIKE '%-00' THEN CONCAT(LEFT(`purchasedate`, 7), '-01')
                                        ELSE `purchasedate`
                                    END
                                    WHERE CAST(`purchasedate` AS CHAR) IN ('1900-01-01')
                                       OR CAST(`purchasedate` AS CHAR) LIKE '%-00-00'
                                       OR CAST(`purchasedate` AS CHAR) LIKE '%-00'"
                            ],
                            [
                                'table' => 'cars_hist',
                                'field' => 'solddate',
                                'query' => "UPDATE cars_hist
                                    SET `solddate` = CASE
                                        WHEN CAST(`solddate` AS CHAR) = '1900-01-01' THEN '0000-00-00'
                                        WHEN CAST(`solddate` AS CHAR) LIKE '%-00-00' THEN CONCAT(LEFT(`solddate`, 4), '-01-01')
                                        WHEN CAST(`solddate` AS CHAR) LIKE '%-00' THEN CONCAT(LEFT(`solddate`, 7), '-01')
                                        ELSE `solddate`
                                    END
                                    WHERE CAST(`solddate` AS CHAR) IN ('1900-01-01')
                                       OR CAST(`solddate` AS CHAR) LIKE '%-00-00'
                                       OR CAST(`solddate` AS CHAR) LIKE '%-00'"
                            ],
                            [
                                'table' => 'cars',
                                'field' => 'purchasedate',
                                'query' => "UPDATE cars
                                    SET `purchasedate` = CASE
                                        WHEN CAST(`purchasedate` AS CHAR) = '1900-01-01' THEN '0000-00-00'
                                        WHEN CAST(`purchasedate` AS CHAR) LIKE '%-00-00' THEN CONCAT(LEFT(`purchasedate`, 4), '-01-01')
                                        WHEN CAST(`purchasedate` AS CHAR) LIKE '%-00' THEN CONCAT(LEFT(`purchasedate`, 7), '-01')
                                        ELSE `purchasedate`
                                    END
                                    WHERE CAST(`purchasedate` AS CHAR) IN ('1900-01-01')
                                       OR CAST(`purchasedate` AS CHAR) LIKE '%-00-00'
                                       OR CAST(`purchasedate` AS CHAR) LIKE '%-00'"
                            ],
                            [
                                'table' => 'cars',
                                'field' => 'solddate',
                                'query' => "UPDATE cars
                                    SET `solddate` = CASE
                                        WHEN CAST(`solddate` AS CHAR) = '1900-01-01' THEN '0000-00-00'
                                        WHEN CAST(`solddate` AS CHAR) LIKE '%-00-00' THEN CONCAT(LEFT(`solddate`, 4), '-01-01')
                                        WHEN CAST(`solddate` AS CHAR) LIKE '%-00' THEN CONCAT(LEFT(`solddate`, 7), '-01')
                                        ELSE `solddate`
                                    END
                                    WHERE CAST(`solddate` AS CHAR) IN ('1900-01-01')
                                       OR CAST(`solddate` AS CHAR) LIKE '%-00-00'
                                       OR CAST(`solddate` AS CHAR) LIKE '%-00'"
                            ]
                        ];

                        $totalFixed = 0;
                        foreach ($dateFixQueries as $fix) {
                            $db->query($fix['query']);
                            $rowsAffected = $db->count();
                            $totalFixed += $rowsAffected;

                            if ($rowsAffected > 0) {
                                logProgress("Fixed {$rowsAffected} invalid dates in {$fix['table']}.{$fix['field']}", 'success');
                                logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, "Fixed {$rowsAffected} invalid dates in {$fix['table']}.{$fix['field']}");
                            } else {
                                logProgress("No invalid dates in {$fix['table']}.{$fix['field']}", 'info');
                            }
                        }

                        $results['dates_fixed'] = $totalFixed;
                        logProgress("Date fixing complete: {$totalFixed} total dates corrected", 'success');
                        logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, "Date fixing complete: {$totalFixed} total dates corrected");

                        // STEP 3: Check Current Status
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 3: Checking Current Table Status', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        foreach ($tablesToConvert as $table) {
                            $current = $db->query(
                                "SELECT ENGINE, TABLE_ROWS, ROUND(DATA_LENGTH/1024/1024, 2) as size_mb
                                 FROM information_schema.TABLES
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                                [$table]
                            )->first();

                            if ($current && strtoupper($current->ENGINE) === 'MYISAM') {
                                logProgress("{$table}: MyISAM ({$current->TABLE_ROWS} rows, {$current->size_mb} MB) - needs conversion", 'info');
                            } elseif ($current && strtoupper($current->ENGINE) === 'INNODB') {
                                $results['already_innodb'][] = $table;
                                logProgress("{$table}: Already InnoDB - skipping", 'info');
                            }
                        }

                        // STEP 4: Convert Tables
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 4: Converting Tables to InnoDB', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        foreach ($tablesToConvert as $table) {
                            // Validate table name to prevent SQL injection (whitelist approach)
                            if (!in_array($table, $tablesToConvert, true)) {
                                logProgress("{$table}: Invalid table name - skipped", 'error');
                                continue;
                            }

                            // Check current engine
                            $current = $db->query(
                                "SELECT ENGINE FROM information_schema.TABLES
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                                [$table]
                            )->first();

                            if ($current && strtoupper($current->ENGINE) === 'INNODB') {
                                logProgress("{$table}: Already InnoDB - skipped", 'info');
                                continue;
                            }

                            // Perform conversion (table names cannot use prepared statements, validated above)
                            logProgress("{$table}: Converting to InnoDB...", 'info');
                            // phpcs:ignore Squiz.Strings.ConcatenationSpacing.PaddingFound,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name validated against whitelist (L293-296)
                            $db->query("ALTER TABLE `{$table}` ENGINE=InnoDB");
                            logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, "Executed: ALTER TABLE `{$table}` ENGINE=InnoDB");

                            // Verify conversion
                            $verify = $db->query(
                                "SELECT ENGINE FROM information_schema.TABLES
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                                [$table]
                            )->first();

                            if ($verify && strtoupper($verify->ENGINE) === 'INNODB') {
                                $results['successful'][] = $table;
                                logProgress("{$table}: Successfully converted to InnoDB", 'success');
                                logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, "Successfully converted {$table} to InnoDB");
                            } else {
                                $currentEngine = $verify->ENGINE ?? 'unknown';
                                $results['failed'][] = ['table' => $table, 'error' => "Still {$currentEngine}"];
                                logProgress("{$table}: Conversion failed - still {$currentEngine}", 'error');
                                logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, "Conversion failed for {$table}: Still {$currentEngine}");
                            }
                        }

                        // Log completion
                        $successCount = count($results['successful']);
                        $failCount = count($results['failed']);
                        $alreadyCount = count($results['already_innodb']);

                        $db->insert('fix_script_runs', [
                            'script_name' => '16-Convert-Tables-to-InnoDB.php',
                            'completed_at' => date('Y-m-d H:i:s')
                        ]);

                        logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE,
                            "Conversion complete - Dates: {$results['dates_fixed']}, Converted: {$successCount}, Already InnoDB: {$alreadyCount}, Failed: {$failCount}");

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('CONVERSION COMPLETE', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Dates Fixed: {$results['dates_fixed']}", 'success');
                        logProgress("Tables Converted: {$successCount}", 'success');
                        logProgress("Already InnoDB: {$alreadyCount}", 'info');
                        if ($failCount > 0) {
                            logProgress("Failed: {$failCount}", 'error');
                        }
                        logProgress('', 'info');
                        logProgress('Backup: ' . basename($results['backup_path']), 'info');
                        logProgress('', 'info');
                        logProgress('Post-Conversion Steps:', 'info');
                        logProgress('  • Verify all tables are using InnoDB', 'info');
                        logProgress('  • Run smoke tests on car registry features', 'info');
                        logProgress('  • Monitor performance for any changes', 'info');
                        logProgress('  • Archive backup for future reference', 'info');

                    } catch (Exception $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, 'Fatal error: ' . $e->getMessage());
                    }

                    ?></pre>

                    <div class="text-center mt-3">
                        <a href="index.php" class="btn btn-primary btn-lg">
                            <i class="fa fa-arrow-left"></i> Return to FIX Menu
                        </a>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
