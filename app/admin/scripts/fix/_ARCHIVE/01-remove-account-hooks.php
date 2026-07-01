<?php

declare(strict_types=1);

/**
 * Remove Account Hook Rows Script
 *
 * One-time DB migration: removes the two hooker plugin rows for account.php
 * from us_plugin_hooks now that usersc/account.php provides a full-page override.
 * Issue #923: refactor: replace account hook pair with usersc/account.php full-page override
 *
 * Idempotent — re-running when rows are already absent is a no-op.
 */

define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');

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

            <?php if (!isset($_GET['start'])): ?>
            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-trash"></i> 01 — Remove Account Hook Rows
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Removes the two hooker plugin rows for <code>account.php</code> from <code>us_plugin_hooks</code>.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Deletes the <code>account_body_hook.php</code> row from <code>us_plugin_hooks</code></li>
                                    <li>Deletes the <code>account_bottom_hook.php</code> row from <code>us_plugin_hooks</code></li>
                                    <li>Idempotent — safe to re-run if rows are already absent</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Prerequisites:</h5>
                                <ul class="mb-0">
                                    <li><code>usersc/account.php</code> must be deployed (replaces the hook pair)</li>
                                    <li>Both hook files should already be removed from disk</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <a href="?start=1" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Continue — Remove Hook Rows
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
                        <i class="fa fa-cogs"></i> Removing Account Hook Rows
                    </h2>
                    <small class="text-muted">
                        <i class="fa fa-clock-o"></i> Started: <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px;"><?php

                    function logProgress(string $message, string $type = 'info'): void
                    {
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

                    $deleted = 0;
                    $errors  = 0;

                    try {
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('Deleting account hook rows from us_plugin_hooks', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $db->query(
                            "DELETE FROM us_plugin_hooks WHERE folder = ? AND file IN (?, ?)",
                            ['hooker', 'account_body_hook.php', 'account_bottom_hook.php']
                        );
                        $deleted = $db->count();
                        logProgress("Deleted {$deleted} row(s) (0 = already absent, idempotent)", 'success');

                        $db->insert('fix_script_runs', [
                            'script_name'  => '01-remove-account-hooks.php',
                            'completed_at' => date('Y-m-d H:i:s'),
                        ]);

                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            "01-remove-account-hooks.php completed — {$deleted} row(s) deleted from us_plugin_hooks");

                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('COMPLETE', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('Account hook rows removed. The usersc/account.php full-page override is now active.', 'success');

                    } catch (Exception $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            '01-remove-account-hooks.php fatal error: ' . $e->getMessage());
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
