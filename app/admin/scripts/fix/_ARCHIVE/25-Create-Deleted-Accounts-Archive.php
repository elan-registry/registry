<?php

declare(strict_types=1);

/**
 * Create Deleted Accounts Archive Table
 *
 * Administrative script to create the deleted_accounts_archive table used by
 * the Account Cleanup tab to preserve a restorable snapshot of every account
 * deleted via the cleanup tool.
 * Issue #1127: Account cleanup admin tab with archive and restore
 *
 * Run once. Safe to re-run — skips table creation if already exists.
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Access via the Maintenance tab or direct URL
 * 2. Review the description, then click "Run Script"
 */

define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');

require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if ($errno !== E_DEPRECATED) {
        logger(isset($user) ? $user->data()->id : 0, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
            "Error [{$errno}]: {$errstr} in {$errfile}:{$errline}");
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
                                <i class="fas fa-archive"></i> Create Deleted Accounts Archive Table
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">
                                Creates the <code>deleted_accounts_archive</code> table. The Account Cleanup tab
                                writes a snapshot of each deleted account here before permanent removal, enabling
                                one-click restore from the admin dashboard.
                            </p>

                            <div class="alert alert-info">
                                <h5><i class="fas fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Creates <code>deleted_accounts_archive</code> if it does not already exist</li>
                                    <li>Stores: original user ID, email, name, join date, last login, logins count, location, deletion metadata (password hash is intentionally excluded)</li>
                                    <li>Includes <code>restored_at</code> / <code>restored_by</code> columns for the restore audit trail</li>
                                    <li>Safe to re-run — skips silently if the table already exists</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fas fa-exclamation-triangle"></i> Notes:</h5>
                                <ul class="mb-0">
                                    <li>One-time migration — run once after deployment</li>
                                    <li>No data is modified; this only creates a table</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <a href="?start=1" class="btn btn-success btn-lg">
                                    <i class="fas fa-play"></i> Run Script
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
                        <i class="fas fa-cogs"></i> Creating Archive Table
                    </h2>
                    <small class="text-muted">
                        <i class="fas fa-clock"></i> Started: <?= date('Y-m-d H:i:s') ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background:#f5f5f5;padding:15px;border-radius:4px;max-height:600px;overflow-y:auto;font-family:'Courier New',monospace;font-size:13px;"><?php

                    function logProgress(string $message, string $type = 'info'): void {
                        $icons = ['info' => 'ℹ️', 'success' => '✅', 'error' => '❌', 'warning' => '⚠️', 'step' => '▶️'];
                        echo date('[H:i:s] ') . ($icons[$type] ?? '•') . ' ' . $message . "\n";
                        flush();
                    }

                    try {
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('Creating deleted_accounts_archive table', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $db->query("
                            CREATE TABLE IF NOT EXISTS deleted_accounts_archive (
                                id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                                original_user_id INT UNSIGNED NOT NULL,
                                email            VARCHAR(155)  DEFAULT NULL,
                                username         VARCHAR(255)  DEFAULT NULL,
                                fname            VARCHAR(255)  DEFAULT NULL,
                                lname            VARCHAR(255)  DEFAULT NULL,
                                join_date        DATETIME      DEFAULT NULL,
                                last_login       DATETIME      DEFAULT NULL,
                                logins           INT           DEFAULT 0,
                                email_verified   TINYINT(1)    DEFAULT 0,
                                city             VARCHAR(100)  DEFAULT NULL,
                                state            VARCHAR(100)  DEFAULT NULL,
                                country          VARCHAR(100)  DEFAULT NULL,
                                bio              TEXT          DEFAULT NULL,
                                website          VARCHAR(100)  DEFAULT NULL,
                                deleted_by       INT UNSIGNED  NOT NULL,
                                deleted_at       DATETIME      NOT NULL,
                                deletion_type    ENUM('unverified','verified') NOT NULL,
                                restored_at      DATETIME      DEFAULT NULL,
                                restored_by      INT UNSIGNED  DEFAULT NULL,
                                INDEX idx_original_user_id (original_user_id),
                                INDEX idx_deleted_at (deleted_at)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ", []);

                        logProgress('Table deleted_accounts_archive created (or already existed)', 'success');

                        $db->insert('fix_script_runs', [
                            'script_name'  => '25-Create-Deleted-Accounts-Archive.php',
                            'completed_at' => date('Y-m-d H:i:s'),
                        ]);

                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            'Script 25-Create-Deleted-Accounts-Archive.php completed successfully');

                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('DONE — archive table is ready', 'success');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('Next: Account Cleanup tab will now archive accounts before deletion', 'info');

                    } catch (Exception $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            'Fatal error in 25-Create-Deleted-Accounts-Archive: ' . $e->getMessage());
                    }

                    ?></pre>

                    <div class="text-center mt-3">
                        <a href="../../maintenance.php?tab=maintenance" class="btn btn-primary btn-lg">
                            <i class="fas fa-arrow-left"></i> Return to Maintenance
                        </a>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
