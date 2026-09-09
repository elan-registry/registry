<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Cron\BrevoEventReconciliationClient;
use ElanRegistry\Cron\BrevoEventReconciliationJob;
use ElanRegistry\LogCategories;

/**
 * Brevo Event Reconciliation — Manual Run (v2.30.2)
 *
 * Admin-triggered "run now" wrapper around the nightly reconciliation cron job
 * (`users/cron/brevo_event_reconciliation.php`, backed by
 * {@see BrevoEventReconciliationJob}). Calls `runNow()` rather than `run()`, so
 * the 20-hour CronJobGuard interval and the job's `enabled` flag are both
 * bypassed — an operator triggering this has already made the scheduling
 * decision the guard exists to make.
 *
 * Repeatable maintenance, not a one-time fix, hence `scripts/maintenance/`
 * (see CLAUDE.md). Safe to run as often as wanted: every write the job performs
 * is idempotent (`CarRepository::insertEmailEvent()` is ON DUPLICATE KEY UPDATE,
 * the flag writes are plain column updates, and the retention prune is bounded
 * by a fixed cutoff).
 *
 * Issue #1889.
 */
require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'app/admin/includes/fix-script-core.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

if (!isAdmin()) {
    logger($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY,
        'Non-admin attempted Brevo event reconciliation script');
    echo '<div class="alert alert-danger mt-3">Administrator access required.</div>';
    exit;
}

?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">

            <?php $is_exec = admin_script_exec_requested(); ?>

            <!-- Initial Description Card -->
            <div class="row" id="descriptionSection"<?= $is_exec ? ' style="display:none;"' : '' ?>>
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-rotate"></i> Brevo Event Reconciliation
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Runs the nightly Brevo delivery-event reconciliation immediately, instead of waiting for the next scheduled cron run.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Polls Brevo for the last 48 hours of transactional delivery events (bounces, blocks, spam complaints, deliveries)</li>
                                    <li>Backfills any event the webhook never received, applying it through the same escalation rules a live webhook event uses</li>
                                    <li>Purges <code>er_email_events</code> rows older than 24 months</li>
                                    <li>Bypasses the normal 20-hour cron guard, so it runs even if the scheduled job has already run today</li>
                                    <li>Safe to run repeatedly — every write is idempotent, so re-applying an event already recorded is a no-op</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <?= admin_script_start_form('Run Reconciliation Now') ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($is_exec): ?>
                <div class="row mb-4">
                    <div class="col-lg-12">
                        <div class="card registry-card">
                            <div class="card-header">
                                <h2 class="mb-0">
                                    <i class="fa fa-check-circle"></i> Reconciliation Result
                                </h2>
                            </div>
                            <div class="card-body">
                                <?php
                                try {
                                    $reconciliationRepo = new CarRepository(dbi());

                                    // runNow() is crash-isolated and returns void — it reports
                                    // nothing back, so there is no count to render here. Per-event
                                    // outcomes and failures are in Admin → Logs under the
                                    // CronJobFailure category. The try/catch still matters: the
                                    // construction above (dbi(), CarRepository) can throw, and a
                                    // failure there must not fatal this admin page.
                                    (new BrevoEventReconciliationJob(
                                        dbi(),
                                        $reconciliationRepo,
                                        new EmailEventApplier($reconciliationRepo, new CarVerificationManager($reconciliationRepo)),
                                        new BrevoEventReconciliationClient(dbi())
                                    ))->runNow();

                                    logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE,
                                        'Brevo event reconciliation manually triggered (cron guard bypassed)');
                                    $recordingWarning = null;
                                    admin_script_record_completion(__FILE__, (int) $user->data()->id, function (string $msg) use (&$recordingWarning) {
                                        $recordingWarning = $msg;
                                    });
                                    ?>
                                    <div class="alert alert-success mb-0">
                                        <i class="fa fa-check-circle"></i> Reconciliation run completed. See <strong>Admin &rarr; Logs</strong> (category <code>CronJobFailure</code>) for any events that could not be applied.
                                    </div>
                                    <?php if ($recordingWarning !== null): ?>
                                    <div class="alert alert-warning mt-2 mb-0">
                                        <?= htmlspecialchars($recordingWarning, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php
                                } catch (\Throwable $e) {
                                    logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,
                                        'Brevo event reconciliation failed: ' . $e->getMessage());
                                    ?>
                                    <div class="alert alert-danger mb-0">
                                        <i class="fa fa-exclamation-triangle"></i> Reconciliation failed: <?= htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <?php
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div> <!-- well -->
    </div><!-- Container -->
</div> <!-- page-wrapper -->

<!-- Return to Admin Console button -->
<div style="margin-top: 20px; text-align: center;">
    <?= admin_script_close_button() ?>
</div>

<!-- footers -->
<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; //custom template footer ?>
