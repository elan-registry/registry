<?php

declare(strict_types=1);

use ElanRegistry\ChassisValidator;
use ElanRegistry\LogCategories;

/**
 * 07-Chassis-Override-Schema-Backfill.php
 * Add the chassis_override column to cars and cars_hist, refresh the cars
 * insert/update triggers to carry it, and backfill the flag on existing cars.
 * Issue #915: Chassis override flag persistence
 *
 * The chassis_override flag was previously never persisted. This script adds
 * the column to both cars and cars_hist, recreates all three cars_* triggers
 * (insert, update, delete) so audit rows include the column, and backfills
 * chassis_override = 1 on cars whose comments contain the legacy audit phrase
 * "CHASSIS VALIDATION OVERRIDDEN", or whose chassis is currently invalid AND
 * were modified on or after the override feature ship date (2025-08-31).
 *
 * Safe to re-run: column additions are skipped if columns already exist,
 * triggers are unconditionally recreated (non-destructive), and the backfill
 * skips cars already marked chassis_override = 1.
 *
 * DEPLOYMENT:
 * 1. Run on dev/staging before production.
 * 2. Back up the database before running on production.
 * 3. Run via the Maintenance tab in the admin panel.
 * 4. Completion is auto-logged to fix_script_runs.
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
                                <i class="fa fa-wrench"></i> Chassis Override Schema and Backfill
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Adds the <code>chassis_override</code> column to the car tables, refreshes the audit triggers to carry it, and backfills the flag on qualifying existing cars (Issue #915).</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Adds <code>chassis_override TINYINT(1) NOT NULL DEFAULT 0</code> to <code>cars_hist</code> (if not exists)</li>
                                    <li>Adds <code>chassis_override TINYINT(1) NOT NULL DEFAULT 0</code> to <code>cars</code> (if not exists)</li>
                                    <li>Drops and recreates the <code>cars_insert</code> and <code>cars_update</code> triggers with the new column</li>
                                    <li>Backfills <code>chassis_override = 1</code> on qualifying existing cars: those whose comments contain "CHASSIS VALIDATION OVERRIDDEN", or whose chassis is currently invalid AND were modified on/after 2025-08-31</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>This changes schema objects on live data — run on dev first</li>
                                    <li>Safe to re-run — column additions skipped if already present, triggers unconditionally recreated (non-destructive), backfill skips already-set rows</li>
                                    <li>Back up the database before running on production</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <form method="post" action="">
                                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fa fa-play"></i> Continue - Start Schema and Backfill
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
                        <i class="fa fa-cogs"></i> Applying Chassis Override Schema and Backfill
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

                    try {
                        // STEP 1: ADD chassis_override TO cars_hist
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: ADD chassis_override TO cars_hist', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $col = $db->query("SHOW COLUMNS FROM cars_hist LIKE 'chassis_override'")->first();
                        if (!$col) {
                            $db->query("ALTER TABLE cars_hist ADD COLUMN chassis_override TINYINT(1) NOT NULL DEFAULT 0 AFTER chassis");
                            logProgress('Added chassis_override column to cars_hist', 'success');
                        } else {
                            logProgress('cars_hist.chassis_override already exists — skipped', 'warning');
                            $results['warnings']++;
                        }

                        // STEP 2: ADD chassis_override TO cars
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: ADD chassis_override TO cars', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $col = $db->query("SHOW COLUMNS FROM cars LIKE 'chassis_override'")->first();
                        if (!$col) {
                            $db->query("ALTER TABLE cars ADD COLUMN chassis_override TINYINT(1) NOT NULL DEFAULT 0 AFTER chassis");
                            logProgress('Added chassis_override column to cars', 'success');
                        } else {
                            logProgress('cars.chassis_override already exists — skipped', 'warning');
                            $results['warnings']++;
                        }

                        // STEP 3: RECREATE cars_insert TRIGGER
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 3: RECREATE cars_insert TRIGGER', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $db->query("DROP TRIGGER IF EXISTS cars_insert");
                        $db->query("CREATE TRIGGER cars_insert AFTER INSERT ON cars FOR EACH ROW BEGIN
    INSERT INTO cars_hist(
        operation, car_id, ctime, mtime, ModifiedBy, model, series, variant,
        year, type, chassis, chassis_override, color, engine, purchasedate, solddate, comments,
        image, user_id, email, fname, lname, join_date, city, state, country,
        lat, lon, website
    )
    VALUES (
        'INSERT', NEW.id, NEW.ctime, NEW.mtime, NEW.ModifiedBy, NEW.model,
        NEW.series, NEW.variant, NEW.year, NEW.type, NEW.chassis, NEW.chassis_override,
        NEW.color, NEW.engine, NEW.purchasedate, NEW.solddate, NEW.comments, NEW.image,
        NEW.user_id, NEW.email, NEW.fname, NEW.lname, NEW.join_date, NEW.city,
        NEW.state, NEW.country, NEW.lat, NEW.lon, NEW.website
    );
END");
                        logProgress('Recreated cars_insert trigger', 'success');

                        // STEP 4: RECREATE cars_update TRIGGER
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 4: RECREATE cars_update TRIGGER', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $db->query("DROP TRIGGER IF EXISTS cars_update");
                        $db->query("CREATE TRIGGER cars_update AFTER UPDATE ON cars FOR EACH ROW BEGIN
    IF @disable_triggers IS NULL THEN
        INSERT INTO cars_hist(
            operation, car_id, ctime, mtime, ModifiedBy, model, series, variant,
            year, type, chassis, chassis_override, color, engine, purchasedate, solddate, comments,
            image, user_id, email, fname, lname, join_date, city, state, country,
            lat, lon, website
        )
        VALUES (
            'UPDATE', OLD.id, OLD.ctime, OLD.mtime, OLD.ModifiedBy, OLD.model,
            OLD.series, OLD.variant, OLD.year, OLD.type, OLD.chassis, OLD.chassis_override,
            OLD.color, OLD.engine, OLD.purchasedate, OLD.solddate, OLD.comments, OLD.image,
            OLD.user_id, OLD.email, OLD.fname, OLD.lname, OLD.join_date, OLD.city,
            OLD.state, OLD.country, OLD.lat, OLD.lon, OLD.website
        );
    END IF;
END");
                        logProgress('Recreated cars_update trigger', 'success');

                        // STEP 5: RECREATE cars_delete TRIGGER
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 5: RECREATE cars_delete TRIGGER', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $db->query("DROP TRIGGER IF EXISTS cars_delete");
                        $db->query("CREATE TRIGGER cars_delete AFTER DELETE ON cars FOR EACH ROW BEGIN
    INSERT INTO cars_hist(
        operation, car_id, ctime, mtime, ModifiedBy, model, series, variant,
        year, type, chassis, chassis_override, color, engine, purchasedate, solddate, comments,
        image, user_id, email, fname, lname, join_date, city, state, country,
        lat, lon, website
    )
    VALUES (
        'DELETE', OLD.id, OLD.ctime, OLD.mtime, OLD.ModifiedBy, OLD.model,
        OLD.series, OLD.variant, OLD.year, OLD.type, OLD.chassis, OLD.chassis_override,
        OLD.color, OLD.engine, OLD.purchasedate, OLD.solddate, OLD.comments, OLD.image,
        OLD.user_id, OLD.email, OLD.fname, OLD.lname, OLD.join_date, OLD.city,
        OLD.state, OLD.country, OLD.lat, OLD.lon, OLD.website
    );
END");
                        logProgress('Recreated cars_delete trigger', 'success');

                        // STEP 6: BACKFILL chassis_override ON QUALIFYING CARS
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 6: BACKFILL chassis_override ON QUALIFYING CARS', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $chassisValidator = new ChassisValidator();

                        // Suppress trigger during backfill (avoid duplicate history rows)
                        $db->query("SET @disable_triggers = 1");
                        $qualifying = $db->query(
                            "SELECT id, chassis, year, series, variant, type, ctime, mtime,
                                    GREATEST(ctime, mtime) AS last_modified,
                                    comments
                             FROM cars
                             WHERE chassis_override = 0
                               AND (GREATEST(ctime, mtime) >= '2025-08-31'
                                    OR comments LIKE '%CHASSIS VALIDATION OVERRIDDEN%')"
                        )->results();
                        foreach ($qualifying as $car) {
                            $matchesDate    = strtotime((string) $car->last_modified) >= strtotime('2025-08-31');
                            $matchesComment = str_contains((string) $car->comments, 'CHASSIS VALIDATION OVERRIDDEN');

                            $reasons = [];

                            if ($matchesComment) {
                                $reasons[] = 'COMMENT';
                            }

                            if ($matchesDate) {
                                $model = $car->series . '|' . $car->variant . '|' . $car->type;
                                try {
                                    $validation = $chassisValidator->validate((string) $car->chassis, (int) $car->year, $model);
                                } catch (\Throwable $e) {
                                    $errMsg = 'ChassisValidator threw for car ID ' . $car->id . ' (chassis "' . $car->chassis . '"): ' . $e->getMessage();
                                    logProgress($errMsg, 'error');
                                    logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR, $errMsg);
                                    $results['errors']++;
                                    continue;
                                }
                                if (!$validation['valid']) {
                                    $reasons[] = 'After Date AND invalid chassis (' . $car->last_modified . ')';
                                }
                            }

                            if (empty($reasons)) {
                                continue;
                            }

                            if (!$matchesComment) {
                                $auditPhrase = 'CHASSIS VALIDATION OVERRIDDEN: ' . date('Y-m-d H:i:s') . ' - Admin override used for chassis validation.';
                                $updatedComments = trim((string) $car->comments . "\n" . $auditPhrase);
                                $db->query("UPDATE cars SET chassis_override = 1, comments = ? WHERE id = ?", [$updatedComments, $car->id]);
                            } else {
                                $db->query("UPDATE cars SET chassis_override = 1 WHERE id = ?", [$car->id]);
                            }

                            if ($db->error()) {
                                $errMsg = 'UPDATE failed for car ID ' . $car->id . ': ' . $db->errorString();
                                logProgress($errMsg, 'error');
                                logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR, $errMsg);
                                $results['errors']++;
                                continue;
                            }

                            logProgress(
                                'Set chassis_override=1 for car ID ' . $car->id
                                    . ' (chassis: ' . htmlspecialchars((string) $car->chassis, ENT_QUOTES, 'UTF-8') . ')'
                                    . ' — reason: ' . htmlspecialchars(implode('; ', $reasons), ENT_QUOTES, 'UTF-8'),
                                'info'
                            );
                            $results['processed']++;
                        }
                        $db->query("SET @disable_triggers = NULL"); // Re-enable triggers before any subsequent writes
                        if ($results['errors'] > 0) {
                            logProgress('Backfill finished with errors: ' . $results['processed'] . ' updated, ' . $results['errors'] . ' failed — check logs', 'warning');
                        } else {
                            logProgress('Backfill complete: ' . $results['processed'] . ' car(s) updated', 'success');
                        }

                        // STEP 7: LOG AND COMPLETE
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 7: RECORD COMPLETION', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $inserted = $db->insert('fix_script_runs', [
                            'script_name' => '07-Chassis-Override-Schema-Backfill.php',
                            'completed_at' => date('Y-m-d H:i:s')
                        ]);
                        if (!$inserted) {
                            logProgress('Warning: could not record completion in fix_script_runs — ' . $db->errorString(), 'warning');
                            $results['warnings']++;
                        }

                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            "07-Chassis-Override-Schema-Backfill completed — Processed: {$results['processed']}, Errors: {$results['errors']}, Warnings: {$results['warnings']}");

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('Chassis override schema and backfill complete.', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Cars Backfilled: {$results['processed']}", 'success');
                        if ($results['warnings'] > 0) {
                            logProgress("Warnings: {$results['warnings']}", 'warning');
                        }
                        if ($results['errors'] > 0) {
                            logProgress("Errors: {$results['errors']}", 'error');
                        }

                    } catch (\Throwable $e) {
                        // Ensure trigger suppression is cleared if backfill aborted mid-run
                        $db->query("SET @disable_triggers = NULL");
                        $detail = get_class($e) . ': ' . $e->getMessage()
                            . ' in ' . $e->getFile() . ':' . $e->getLine();
                        logProgress('FATAL ERROR: ' . $detail, 'error');
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,
                            '07-Chassis-Override-Schema-Backfill fatal error: ' . $detail);
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
