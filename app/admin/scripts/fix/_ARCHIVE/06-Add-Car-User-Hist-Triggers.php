<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;

/**
 * 06-Add-Car-User-Hist-Triggers.php
 * Add database triggers and performance indexes for the car_user_hist table.
 * Issue #592: Add database triggers and indexes for car_user_hist table
 *
 * The car_user_hist audit table has existed since ADR-003 but was never
 * populated. This script installs AFTER INSERT/UPDATE/DELETE triggers on
 * car_user and adds indexes on car_id and userid. Idempotent: triggers are
 * dropped before recreation and indexes are skipped if already present.
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Copy this file to app/admin/scripts/fix/ with proper naming: ##-Descriptive-Name.php
 * 2. Scripts are accessed via manage-maintenance.php (Maintenance tab) or direct URL
 * 3. Use sequential numbering (01, 02, 03...) for proper execution order
 * 4. All scripts auto-log completion to fix_script_runs table
 * 5. See app/admin/scripts/fix/README.md for detailed instructions and best practices
 */

// UI Constants for progress output
define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');

require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

// Set up custom error handler to log through UserSpice logger
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if ($errno !== E_DEPRECATED) {
        logger(isset($user) ? $user->data()->id : 0, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Error [$errno]: $errstr in $errfile:$errline");
    }
    return true;
});

$db = DB::getInstance();

?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">

            <?php if (!Input::exists('post') || !Token::check(Input::get('csrf'))): ?>
            <!-- Initial Description -->
            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-history"></i> Add car_user_hist Triggers and Indexes
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Installs audit triggers and performance indexes for the <code>car_user_hist</code> table so that changes to car-user relationships are recorded at the database level.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Creates the <code>car_user_insert</code>, <code>car_user_update</code>, and <code>car_user_delete</code> triggers on <code>car_user</code></li>
                                    <li>Each trigger writes an audit row to <code>car_user_hist</code> (operation, car_id, userid)</li>
                                    <li>Adds index <code>idx_car_user_hist_car_id</code> on <code>car_id</code></li>
                                    <li>Adds index <code>idx_car_user_hist_userid</code> on <code>userid</code></li>
                                    <li>Is idempotent: existing triggers are recreated and existing indexes are skipped</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>This changes schema objects on live data — run on dev first</li>
                                    <li>Safe to re-run; it will not create duplicate triggers or indexes</li>
                                    <li>Back up the database before running on production</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <form method="post" action="">
                                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fa fa-play"></i> Continue - Install Triggers and Indexes
                                    </button>
                                </form>
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
                        <i class="fa fa-cogs"></i> Installing car_user_hist Triggers and Indexes
                    </h2>
                    <small class="text-muted">
                        <i class="fa fa-clock-o"></i> Started: <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px;"><?php

                    /**
                     * Helper function for progress output
                     *
                     * @param string $message Message to display
                     * @param string $type Type of message: 'info', 'success', 'error', 'warning', 'step'
                     */
                    function logProgress(string $message, string $type = 'info'): void {
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

                    // Initialize results tracking
                    $results = [
                        'processed' => 0,
                        'errors' => 0,
                        'warnings' => 0
                    ];

                    /**
                     * Drop and recreate a trigger. Both SQL arguments ($dropSql, $createSql)
                     * are complete literal strings at every call site — no user input is ever
                     * interpolated into either query. $name is used only for log messages.
                     *
                     * @param string $name      Trigger name (log messages only)
                     * @param string $dropSql   Complete DROP TRIGGER IF EXISTS statement
                     * @param string $createSql Complete CREATE TRIGGER statement
                     */
                    $createTrigger = function(string $name, string $dropSql, string $createSql) use ($db, $user, &$results): void {
                        $db->query($dropSql);
                        if ($db->error()) {
                            $dbError = $db->errorString();
                            logProgress("Trigger {$name}: DB error on DROP — {$dbError}", 'error');
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "06-Add-Car-User-Hist-Triggers: DB error dropping trigger {$name}: {$dbError}");
                            $results['errors']++;
                            return;
                        }

                        $db->query($createSql);
                        if ($db->error()) {
                            $dbError = $db->errorString();
                            logProgress("Trigger {$name}: DB error on CREATE — {$dbError}", 'error');
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "06-Add-Car-User-Hist-Triggers: DB error creating trigger {$name}: {$dbError}");
                            $results['errors']++;
                        } else {
                            logProgress("Trigger {$name}: created", 'success');
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "06-Add-Car-User-Hist-Triggers: trigger {$name} created");
                            $results['processed']++;
                        }
                    };

                    /**
                     * Add an index on car_user_hist if it does not already exist.
                     * $indexName is passed as a bound parameter to the SHOW INDEX query and
                     * used in log messages — never interpolated into SQL. $addKeySql is a
                     * complete literal string at every call site.
                     *
                     * @param string $indexName  Index name (bound parameter in SHOW INDEX query)
                     * @param string $addKeySql  Complete ALTER TABLE … ADD KEY statement
                     */
                    $addIndex = function(string $indexName, string $addKeySql) use ($db, $user, &$results): void {
                        $existing = $db->query("SHOW INDEX FROM car_user_hist WHERE Key_name = ?", [$indexName]);
                        if ($db->error()) {
                            $dbError = $db->errorString();
                            logProgress("Index {$indexName}: DB error on existence check — {$dbError}", 'error');
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "06-Add-Car-User-Hist-Triggers: DB error checking index {$indexName}: {$dbError}");
                            $results['errors']++;
                            return;
                        }

                        if ($existing->count() > 0) {
                            logProgress("Index {$indexName}: already exists, skipping", 'warning');
                            $results['warnings']++;
                            return;
                        }

                        $db->query($addKeySql);
                        if ($db->error()) {
                            $dbError = $db->errorString();
                            logProgress("Index {$indexName}: DB error on ADD — {$dbError}", 'error');
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "06-Add-Car-User-Hist-Triggers: DB error adding index {$indexName}: {$dbError}");
                            $results['errors']++;
                        } else {
                            logProgress("Index {$indexName}: created", 'success');
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "06-Add-Car-User-Hist-Triggers: index {$indexName} created");
                            $results['processed']++;
                        }
                    };

                    try {
                        // STEP 1: CREATE TRIGGERS
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: CREATE TRIGGERS', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $createTrigger(
                            'car_user_delete',
                            "DROP TRIGGER IF EXISTS `car_user_delete`",
                            "CREATE TRIGGER `car_user_delete` AFTER DELETE ON `car_user` FOR EACH ROW BEGIN
    INSERT INTO car_user_hist (operation, car_id, userid)
    VALUES ('DELETE', OLD.car_id, OLD.userid);
END"
                        );

                        $createTrigger(
                            'car_user_insert',
                            "DROP TRIGGER IF EXISTS `car_user_insert`",
                            "CREATE TRIGGER `car_user_insert` AFTER INSERT ON `car_user` FOR EACH ROW BEGIN
    INSERT INTO car_user_hist (operation, car_id, userid)
    VALUES ('INSERT', NEW.car_id, NEW.userid);
END"
                        );

                        $createTrigger(
                            'car_user_update',
                            "DROP TRIGGER IF EXISTS `car_user_update`",
                            "CREATE TRIGGER `car_user_update` AFTER UPDATE ON `car_user` FOR EACH ROW BEGIN
    IF @disable_triggers IS NULL THEN
        INSERT INTO car_user_hist (operation, car_id, userid)
        VALUES ('UPDATE', OLD.car_id, OLD.userid);
    END IF;
END"
                        );

                        // STEP 2: ADD INDEXES
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: ADD INDEXES', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $addIndex(
                            'idx_car_user_hist_car_id',
                            "ALTER TABLE car_user_hist ADD KEY idx_car_user_hist_car_id (car_id)"
                        );

                        $addIndex(
                            'idx_car_user_hist_userid',
                            "ALTER TABLE car_user_hist ADD KEY idx_car_user_hist_userid (userid)"
                        );

                        // STEP 3: VERIFY AND COMPLETE
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 3: VERIFY TRIGGER STATE', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $found = $db->query("SHOW TRIGGERS WHERE `Table` = 'car_user'")->results();
                        $presentNames = array_column((array) $found, 'Trigger');
                        foreach (['car_user_delete', 'car_user_insert', 'car_user_update'] as $expected) {
                            if (in_array($expected, $presentNames, true)) {
                                logProgress("  {$expected}: present", 'success');
                            } else {
                                logProgress("  {$expected}: MISSING", 'error');
                                $results['errors']++;
                            }
                        }

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        if ($results['errors'] > 0) {
                            logProgress('COMPLETED WITH ERRORS — some operations failed, review above.', 'error');
                        } else {
                            logProgress('car_user_hist triggers and indexes installation complete.', 'step');
                        }
                        logProgress(SECTION_SEPARATOR, 'step');
                        if ($results['errors'] > 0) {
                            logProgress("Errors: {$results['errors']}", 'error');
                        }
                        if ($results['warnings'] > 0) {
                            logProgress("Warnings: {$results['warnings']}", 'warning');
                        }
                        logProgress("Items Processed: {$results['processed']}", 'success');

                        // Only record completion when all schema operations succeeded
                        if ($results['errors'] === 0) {
                            $inserted = $db->insert('fix_script_runs', [
                                'script_name' => '06-Add-Car-User-Hist-Triggers.php',
                                'completed_at' => date('Y-m-d H:i:s')
                            ]);
                            if (!$inserted) {
                                logProgress('Warning: could not record completion in fix_script_runs — ' . $db->errorString(), 'warning');
                            }
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                "Script completed successfully — Processed: {$results['processed']}, Warnings: {$results['warnings']}");
                        } else {
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                "Script completed WITH ERRORS — Processed: {$results['processed']}, Errors: {$results['errors']}, Warnings: {$results['warnings']}");
                        }

                    } catch (\Throwable $e) {
                        $detail = get_class($e) . ': ' . $e->getMessage()
                            . ' in ' . $e->getFile() . ':' . $e->getLine();
                        logProgress('FATAL ERROR: ' . $detail, 'error');
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,
                            '06-Add-Car-User-Hist-Triggers fatal error: ' . $detail);
                    }

                    ?></pre>

                    <div class="text-center mt-3">
                        <a href="../../manage-maintenance.php?tab=maintenance" class="btn btn-primary btn-lg">
                            <i class="fa fa-arrow-left"></i> Return to Maintenance
                        </a>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
