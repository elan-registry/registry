<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;

/**
 * Register Verify-Email-Sync Hook Script
 *
 * Administrative script to register the verifySuccess hooker event that runs
 * usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php.
 * Issue #1958: an owner's confirmed email change wasn't syncing to their cars.
 *
 * The hook file itself does nothing until it is registered in the
 * us_plugin_hooks table for this environment. This script performs that
 * registration via registerHooks(). registerHooks() is idempotent — it looks
 * for an existing matching row (page/folder/position/hook) and flips
 * disabled = 0 if found, or inserts a new row if not — so running this
 * script more than once on the same environment is safe and has no
 * additional effect.
 *
 * Registration is per-environment: this must be run once on test and once
 * on prod. Running it on one does not register the hook on the other.
 *
 * Idempotent, but this is a one-time fix script per CLAUDE.md conventions,
 * not a repeatable maintenance task. See app/admin/scripts/fix/README.md.
 */

require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'app/admin/includes/fix-script-core.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

// Set up custom error handler to log through UserSpice logger
set_error_handler(function ($errno, $errstr, $errfile, $errline) use ($user) {
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

            <?php if (!admin_script_exec_requested()): ?>
            <!-- Initial Description -->
            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-link"></i> Register Verify-Email-Sync Hook
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Registers the <code>verifySuccess</code> hooker event so a confirmed
                            email change is synced to an owner's cars (Issue #1958).</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Registers the <code>verifySuccess</code> hooker plugin event in the
                                        <code>us_plugin_hooks</code> table</li>
                                    <li>Points that event at
                                        <code>hooker/hooks/sync_owner_email_on_verify.php</code></li>
                                    <li>Once registered, that hook fires after an owner confirms an email
                                        change and pushes the owner-contact fields to every car they own</li>
                                    <li>Without this registration, the hook file exists but never runs, and
                                        confirmed email changes will not propagate to cars</li>
                                    <li>This script only inserts/updates one row in <code>us_plugin_hooks</code> —
                                        it does not touch car or owner data itself</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>Registration is per-environment: this must be run once on test and
                                        once on prod. Running it on test does not register the hook on
                                        prod, and vice versa.</li>
                                    <li>This script is safe to run more than once on the same environment —
                                        <code>registerHooks()</code> checks for an existing matching row and
                                        simply re-enables it rather than duplicating it.</li>
                                    <li>Requires that <code>usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php</code>
                                        already exists in this environment's codebase before the hook can take
                                        effect.</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <?= admin_script_start_form('Continue - Start Hook Registration') ?>
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
                        <i class="fa fa-cogs"></i> Registering Verify-Email-Sync Hook
                    </h2>
                    <small class="text-muted">
                        <i class="fa fa-clock-o"></i> Started: <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px;"><?php

                    try {
                        // STEP 1: Register the verifySuccess hooker event
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Register verifySuccess hook', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        logProgress('Registering hooker/hooks/sync_owner_email_on_verify.php for the verifySuccess event...', 'info');

                        // registerHooks() has no useful return value to check, and it can
                        // swallow a failed $db->insert() without throwing — so returning
                        // normally is NOT sufficient evidence the row landed. Verify the
                        // enabled row actually exists before reporting success.
                        registerHooks(['verifySuccess' => ['body' => 'hooks/sync_owner_email_on_verify.php']], 'hooker');

                        $verifyQ = $db->query(
                            'SELECT id FROM us_plugin_hooks WHERE `page` = ? AND folder = ? AND position = ? AND hook = ? AND disabled = 0',
                            ['verifySuccess', 'hooker', 'body', 'hooks/sync_owner_email_on_verify.php']
                        );
                        if ($verifyQ->count() === 0) {
                            throw new \RuntimeException('registerHooks() returned but no enabled us_plugin_hooks row exists');
                        }

                        logProgress('Hook registered', 'success');

                        // Log script completion
                        admin_script_record_completion(__FILE__, (int) $user->data()->id, 'logProgress');

                        logger((int) $user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            'Script completed - registered verifySuccess hook: hooker/hooks/sync_owner_email_on_verify.php');

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('Hook Registration Complete', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('Hook registered', 'success');
                        logProgress('', 'info');
                        logProgress('Post-Processing Steps:', 'info');
                        logProgress('  • Confirm the email-change verification flow syncs owner-contact fields to owned cars', 'info');
                        logProgress('  • Run this same script on the other environment (test/prod) if not already done there', 'info');
                        logProgress('  • This script is safe to re-run on this environment if needed', 'info');

                    } catch (\Throwable $e) {
                        // \Throwable, not Exception: registerHooks() is an untyped upstream
                        // UserSpice helper, so an Error (TypeError et al.) from it would
                        // otherwise escape this handler entirely, skipping both the
                        // FATAL ERROR output below and the logger() audit line.
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger((int) $user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, 'Fatal error: ' . $e->getMessage());
                    }

                    ?></pre>

                    <div class="text-center mt-3">
                        <?= admin_script_close_button() ?>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
