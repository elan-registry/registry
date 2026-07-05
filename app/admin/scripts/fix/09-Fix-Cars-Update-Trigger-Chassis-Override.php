<?php

declare(strict_types=1);

/**
 * 09-Fix-Cars-Update-Trigger-Chassis-Override.php
 * Recreate the cars_update trigger so it captures NEW.chassis_override
 * instead of OLD.chassis_override in the audit history row.
 * Issue #959: requireAdminAjax() helper / trigger audit correctness
 *
 * The cars_update trigger was shipped (via script 07) using OLD.chassis_override,
 * which records the pre-update value. When an admin sets chassis_override = 1,
 * the history row should record 1 (the new value), not 0 (the old value). This
 * script drops and recreates cars_update with NEW.chassis_override. All other
 * columns continue to use OLD.* (before-image audit pattern).
 *
 * Safe to re-run: trigger is unconditionally dropped and recreated.
 *
 * DEPLOYMENT:
 * 1. Run on staging before production.
 * 2. Run via the Maintenance tab in the admin panel.
 * 3. Completion is auto-logged to fix_script_runs.
 */

// UI Constants for progress output
define('SECTION_SEPARATOR_09', '═══════════════════════════════════════════════════════');

require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
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
            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-wrench"></i> Fix cars_update Trigger — chassis_override Audit
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Recreates the <code>cars_update</code> trigger to capture <code>NEW.chassis_override</code> instead of <code>OLD.chassis_override</code> in the audit history row (Issue #959).</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Drops the existing <code>cars_update</code> trigger</li>
                                    <li>Recreates it with <code>NEW.chassis_override</code> so the history row records when the flag <em>was set</em>, not its prior value</li>
                                    <li>All other columns continue to use <code>OLD.*</code> (before-image audit pattern)</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>Safe to re-run — trigger is unconditionally dropped and recreated</li>
                                    <li>Existing <code>cars_hist</code> rows are not modified; only future UPDATE operations are affected</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <form method="post" action="">
                                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fa fa-play"></i> Continue — Recreate Trigger
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else:
                ob_end_clean();
                header('Content-Type: text/html; charset=utf-8');
                echo str_repeat(' ', 1024);
                flush();
            ?>

            <div class="card registry-card">
                <div class="card-header">
                    <h2 class="mb-0">
                        <i class="fa fa-cogs"></i> Recreating cars_update Trigger
                    </h2>
                    <small class="text-muted">
                        <i class="fa fa-clock-o"></i> Started: <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px;"><?php

                    /**
                     * @param string $message
                     * @param string $type
                     */
                    function logProgress09(string $message, string $type = 'info'): void {
                        $icons = [
                            'info'    => 'ℹ️',
                            'success' => '✅',
                            'error'   => '❌',
                            'warning' => '⚠️',
                            'step'    => '▶️',
                        ];
                        echo date('[H:i:s] ') . ($icons[$type] ?? '•') . ' ' . $message . "\n";
                        flush();
                    }

                    $errors = 0;

                    try {
                        logProgress09(SECTION_SEPARATOR_09, 'step');
                        logProgress09('STEP 1: DROP cars_update TRIGGER', 'step');
                        logProgress09(SECTION_SEPARATOR_09, 'step');

                        $db->query("DROP TRIGGER IF EXISTS cars_update");
                        logProgress09('Dropped cars_update', 'success');

                        logProgress09('', 'info');
                        logProgress09(SECTION_SEPARATOR_09, 'step');
                        logProgress09('STEP 2: RECREATE cars_update TRIGGER', 'step');
                        logProgress09(SECTION_SEPARATOR_09, 'step');

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
            OLD.series, OLD.variant, OLD.year, OLD.type, OLD.chassis, NEW.chassis_override,
            OLD.color, OLD.engine, OLD.purchasedate, OLD.solddate, OLD.comments, OLD.image,
            OLD.user_id, OLD.email, OLD.fname, OLD.lname, OLD.join_date, OLD.city,
            OLD.state, OLD.country, OLD.lat, OLD.lon, OLD.website
        );
    END IF;
END");
                        logProgress09('Recreated cars_update trigger (NEW.chassis_override)', 'success');

                        logProgress09('', 'info');
                        logProgress09(SECTION_SEPARATOR_09, 'step');
                        logProgress09('STEP 3: RECORD COMPLETION', 'step');
                        logProgress09(SECTION_SEPARATOR_09, 'step');

                        $inserted = $db->insert('fix_script_runs', [
                            'script_name'  => '09-Fix-Cars-Update-Trigger-Chassis-Override.php',
                            'completed_at' => date('Y-m-d H:i:s'),
                        ]);
                        if (!$inserted) {
                            logProgress09('Warning: could not record completion in fix_script_runs — ' . $db->errorString(), 'warning');
                        }

                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            '09-Fix-Cars-Update-Trigger-Chassis-Override completed — cars_update trigger recreated with NEW.chassis_override');

                        logProgress09('', 'info');
                        logProgress09(SECTION_SEPARATOR_09, 'step');
                        logProgress09('Done. cars_update trigger now captures NEW.chassis_override.', 'success');
                        logProgress09(SECTION_SEPARATOR_09, 'step');

                    } catch (\Throwable $e) {
                        $errors++;
                        $detail = get_class($e) . ': ' . $e->getMessage()
                            . ' in ' . $e->getFile() . ':' . $e->getLine();
                        logProgress09('FATAL ERROR: ' . $detail, 'error');
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,
                            '09-Fix-Cars-Update-Trigger-Chassis-Override fatal error: ' . $detail);
                    }

                    ?></pre>

                    <div class="text-center mt-3">
                        <a href="../../index.php?tab=maintenance" class="btn btn-primary btn-lg">
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
