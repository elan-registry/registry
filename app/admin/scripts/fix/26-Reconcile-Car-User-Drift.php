<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;

/**
 * Reconcile Car-User Drift Script
 *
 * Administrative script to sync car_user.userid with cars.user_id before
 * the drop_car_user_tables migration (20260711000000) can run.
 *
 * Issue #1162: Eliminate car_user junction table
 *
 * The car_user table was a redundant mirror of cars.user_id with no FK
 * constraint to enforce consistency. Over time, ownership transfers updated
 * cars.user_id but left car_user.userid stale. This script brings car_user
 * into alignment with cars.user_id (the authoritative source) so the
 * migration's pre-flight guard will pass.
 *
 * MUST run this script on prod BEFORE running composer migrate.
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Run on prod via the Maintenance tab or direct URL before deploying v2.26.2
 * 2. Verify the audit output shows expected drifted rows
 * 3. After the script completes, run: composer migrate
 * 4. Archive this script after the migration is confirmed on prod
 */

define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');

require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
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

            <?php if (!isset($_GET['start'])): ?>
            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-refresh"></i> Reconcile car_user Drift
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">
                                Syncs <code>car_user.userid</code> to match <code>cars.user_id</code>
                                for any cars where the two representations have drifted.
                                This is a prerequisite for the <code>20260711000000_drop_car_user_tables</code>
                                migration, which verifies data parity before dropping the junction table.
                            </p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Audits all cars where <code>car_user.userid ≠ cars.user_id</code></li>
                                    <li>Reports each drifted car with both the stale and correct owner</li>
                                    <li>Updates <code>car_user.userid</code> to match <code>cars.user_id</code> for each drifted row</li>
                                    <li>Verifies the guard queries pass after the fix</li>
                                    <li>Logs all changes for audit trail</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li><strong>Run this before deploying v2.26.2</strong> — the drop migration will block if drift exists</li>
                                    <li><code>cars.user_id</code> is the authoritative ownership source — <code>car_user</code> is the stale copy</li>
                                    <li>No car ownership visible to users changes — this only corrects the redundant junction table</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <a href="?start=1" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Continue — Reconcile Drift
                                </a>
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
                        <i class="fa fa-cogs"></i> Reconciling car_user Drift
                    </h2>
                    <small class="text-muted">
                        <i class="fa fa-clock-o"></i> Started: <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px;"><?php

                    function logProgress(string $message, string $type = 'info'): void {
                        $icons = ['info' => 'ℹ️', 'success' => '✅', 'error' => '❌', 'warning' => '⚠️', 'step' => '▶️'];
                        echo date('[H:i:s] ') . ($icons[$type] ?? '•') . ' ' . $message . "\n";
                        flush();
                    }

                    $fixed   = 0;
                    $errors  = 0;

                    try {
                        // STEP 1: Audit — find all drifted rows
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Audit — find cars where car_user.userid ≠ cars.user_id', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $drifted = $db->query(
                            "SELECT cu.car_id,
                                    cu.userid      AS stale_userid,
                                    u1.username    AS stale_username,
                                    c.user_id      AS correct_userid,
                                    u2.username    AS correct_username,
                                    c.chassis
                             FROM car_user cu
                             JOIN cars c  ON cu.car_id = c.id
                             LEFT JOIN users u1 ON u1.id = cu.userid
                             LEFT JOIN users u2 ON u2.id = c.user_id
                             WHERE c.user_id IS NULL OR c.user_id != cu.userid
                             ORDER BY cu.car_id"
                        )->results();

                        if (empty($drifted)) {
                            logProgress('No drifted rows found — car_user is already in sync with cars.user_id.', 'success');
                            logProgress('The migration guard will pass. No changes needed.', 'success');
                        } else {
                            logProgress(count($drifted) . ' drifted row(s) found:', 'warning');
                            foreach ($drifted as $row) {
                                logProgress(
                                    "  car_id={$row->car_id} chassis={$row->chassis}: " .
                                    "car_user says user {$row->stale_userid} ({$row->stale_username}) → " .
                                    "cars.user_id says user " . ($row->correct_userid ?? 'NULL') .
                                    " ({$row->correct_username})",
                                    'warning'
                                );
                            }

                            // STEP 2: Fix — update car_user.userid to match cars.user_id
                            logProgress('', 'info');
                            logProgress(SECTION_SEPARATOR, 'step');
                            logProgress('STEP 2: Fix — update car_user.userid to match cars.user_id', 'step');
                            logProgress(SECTION_SEPARATOR, 'step');

                            foreach ($drifted as $row) {
                                if ($row->correct_userid === null) {
                                    // cars.user_id is NULL — delete the stale car_user row
                                    $db->query(
                                        'DELETE FROM car_user WHERE car_id = ?',
                                        [$row->car_id]
                                    );
                                    if ($db->error()) {
                                        logProgress("  ❌ car_id={$row->car_id}: DELETE failed — " . $db->errorString(), 'error');
                                        $errors++;
                                    } else {
                                        logProgress(
                                            "  car_id={$row->car_id} ({$row->chassis}): deleted stale car_user row (was userid={$row->stale_userid})",
                                            'success'
                                        );
                                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                            "26-Reconcile-Car-User-Drift: deleted car_user row for car_id={$row->car_id} chassis={$row->chassis} (stale userid={$row->stale_userid})");
                                        $fixed++;
                                    }
                                } else {
                                    // Update stale userid to the correct value
                                    $db->query(
                                        'UPDATE car_user SET userid = ? WHERE car_id = ?',
                                        [$row->correct_userid, $row->car_id]
                                    );
                                    if ($db->error()) {
                                        logProgress("  ❌ car_id={$row->car_id}: UPDATE failed — " . $db->errorString(), 'error');
                                        $errors++;
                                    } else {
                                        logProgress(
                                            "  car_id={$row->car_id} ({$row->chassis}): userid {$row->stale_userid} ({$row->stale_username}) → {$row->correct_userid} ({$row->correct_username})",
                                            'success'
                                        );
                                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                            "26-Reconcile-Car-User-Drift: car_id={$row->car_id} chassis={$row->chassis} " .
                                            "userid {$row->stale_userid} → {$row->correct_userid}");
                                        $fixed++;
                                    }
                                }
                            }

                            // STEP 3: Verify the migration guard will now pass
                            logProgress('', 'info');
                            logProgress(SECTION_SEPARATOR, 'step');
                            logProgress('STEP 3: Verify migration guard queries pass', 'step');
                            logProgress(SECTION_SEPARATOR, 'step');

                            $remainingDrift = $db->query(
                                "SELECT COUNT(*) AS n FROM car_user cu JOIN cars c ON cu.car_id = c.id WHERE c.user_id IS NULL OR c.user_id != cu.userid"
                            )->first();

                            if ((int) $remainingDrift->n === 0) {
                                logProgress('Guard 2 (drift check): PASS — 0 drifted rows remain', 'success');
                            } else {
                                logProgress("Guard 2 (drift check): FAIL — {$remainingDrift->n} drifted row(s) remain after fix", 'error');
                                $errors++;
                            }

                            $orphaned = $db->query(
                                "SELECT COUNT(*) AS n FROM cars WHERE user_id IS NOT NULL AND user_id NOT IN (SELECT id FROM users)"
                            )->first();

                            if ((int) $orphaned->n === 0) {
                                logProgress('Guard 1 (orphaned owners): PASS — all cars.user_id values reference valid users', 'success');
                            } else {
                                logProgress("Guard 1 (orphaned owners): FAIL — {$orphaned->n} car(s) reference a non-existent user", 'error');
                                $errors++;
                            }
                        }

                        $db->insert('fix_script_runs', [
                            'script_name' => '26-Reconcile-Car-User-Drift.php',
                            'completed_at' => date('Y-m-d H:i:s'),
                        ]);

                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            "26-Reconcile-Car-User-Drift completed — fixed: {$fixed}, errors: {$errors}");

                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('COMPLETE', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Rows fixed: {$fixed}", 'success');
                        if ($errors > 0) {
                            logProgress("Errors: {$errors} — resolve before running the migration", 'error');
                        } else {
                            logProgress('Next step: deploy v2.26.2 and run: composer migrate', 'info');
                        }

                    } catch (Exception $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            '26-Reconcile-Car-User-Drift fatal error: ' . $e->getMessage());
                    }

                    ?></pre>

                    <div class="text-center mt-3">
                        <a href="../../maintenance.php?tab=maintenance" class="btn btn-primary btn-lg">
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
