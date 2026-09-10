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
 * {@see BrevoEventReconciliationJob}). Calls `runNowWithSummary()` rather than
 * `run()`, so the 20-hour CronJobGuard interval and the job's `enabled` flag
 * are both bypassed — an operator triggering this has already made the
 * scheduling decision the guard exists to make.
 *
 * Repeatable maintenance, not a one-time fix, hence `scripts/maintenance/`
 * (see CLAUDE.md). Safe to run as often as wanted: every write the job performs
 * is idempotent (`CarRepository::insertEmailEvent()` is ON DUPLICATE KEY UPDATE,
 * the flag writes are plain column updates, and the retention prune is bounded
 * by a fixed cutoff).
 *
 * Unlike the original `runNow()`, which is crash-isolated and returns void,
 * `runNowWithSummary()` logs and then **rethrows** on a genuinely failed run
 * so a crash cannot be rendered as a plausible all-zero summary. The
 * try/catch below therefore covers the call itself, not just the
 * construction above it. This job fetches exactly one bounded page per run
 * (see the job class's own docblock) — the same scope `AbstractCronJob`'s
 * shared `runNow()` already budgets for — so unlike #1923's multi-page
 * backfill, no additional `set_time_limit()` is needed here.
 *
 * Issues #1889, #2061.
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
                                    <li>Shows matched, unmatched, and ignored counts plus a breakdown by event type below, so the result of this run is visible immediately rather than requiring a trip to Admin &rarr; Logs</li>
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

                                    // runNowWithSummary() returns the run's counts and can throw:
                                    // it logs and rethrows on a genuinely failed run rather than
                                    // fabricating an all-zero summary indistinguishable from a
                                    // successful run over an empty page. The catch below renders
                                    // that failure — and still covers the construction above,
                                    // which can throw on its own.
                                    $summary = (new BrevoEventReconciliationJob(
                                        dbi(),
                                        $reconciliationRepo,
                                        new EmailEventApplier($reconciliationRepo, new CarVerificationManager($reconciliationRepo)),
                                        new BrevoEventReconciliationClient(dbi())
                                    ))->runNowWithSummary();

                                    logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE,
                                        sprintf(
                                            'Brevo event reconciliation manually triggered (cron guard bypassed) —'
                                            . ' %d matched, %d unmatched, %d skipped, %d ignored (non-verification tag),'
                                            . ' %d page(s) fetched%s',
                                            $summary->matchedCount,
                                            $summary->unmatchedCount,
                                            $summary->skippedCount,
                                            $summary->ignoredByTagCount,
                                            $summary->pagesFetched,
                                            $summary->pollFailed ? ', a Brevo poll failed' : ''
                                        ));
                                    $recordingWarning = null;
                                    admin_script_record_completion(__FILE__, (int) $user->data()->id, function (string $msg) use (&$recordingWarning) {
                                        $recordingWarning = $msg;
                                    });
                                    ?>
                                    <?php if ($summary->pollFailed): ?>
                                    <div class="alert alert-warning">
                                        <h5 class="mb-2"><i class="fa fa-exclamation-triangle"></i> Reconciliation incomplete — Brevo poll failed</h5>
                                        <p class="mb-0">The request to Brevo failed, so no events were fetched this run. The failure is logged under <code>CronJobFailure</code>. Re-run this script once Brevo is reachable — events already reconciled are re-applied harmlessly.</p>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($summary->skippedCount > 0): ?>
                                    <div class="alert alert-warning">
                                        <h5 class="mb-2"><i class="fa fa-exclamation-triangle"></i> <?= (int) $summary->skippedCount ?> item(s) could not be processed</h5>
                                        <p class="mb-0">These were skipped due to a malformed Brevo payload, an oversized value, a failed car lookup, or a failed database write. Counted per car for a failed write and per event otherwise, so an event matching several cars can appear here <em>and</em> in the matched total below. Each is logged individually under <code>EmailWebhook</code> or <code>CronJobFailure</code>. Re-running this script is safe and will retry them.</p>
                                    </div>
                                    <?php endif; ?>

                                    <div class="alert alert-success">
                                        <i class="fa fa-check-circle"></i> Reconciliation completed:
                                        <strong><?= (int) $summary->eventsExamined ?></strong> event(s) examined —
                                        <strong><?= (int) $summary->matchedCount ?></strong> matched,
                                        <strong><?= (int) $summary->unmatchedCount ?></strong> unmatched,
                                        <strong><?= (int) $summary->ignoredByTagCount ?></strong> ignored (non-verification tag),
                                        across <strong><?= (int) $summary->pagesFetched ?></strong> page(s) fetched.
                                    </div>

                                    <h5 class="mt-4"><i class="fa fa-table"></i> Events by type</h5>
                                    <?php if ($summary->eventTypeCounts === []): ?>
                                    <p class="text-muted mb-0">No events were recorded in this run.</p>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped mb-0">
                                            <thead>
                                                <tr>
                                                    <th scope="col">Event type</th>
                                                    <th scope="col" class="text-end">Count</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($summary->eventTypeCounts as $eventType => $eventCount): ?>
                                                <tr>
                                                    <td><code><?= htmlspecialchars((string) $eventType, ENT_QUOTES, 'UTF-8') ?></code></td>
                                                    <td class="text-end"><?= (int) $eventCount ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($recordingWarning !== null): ?>
                                    <div class="alert alert-warning mt-3 mb-0">
                                        <?= htmlspecialchars($recordingWarning, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php
                                } catch (\Throwable $e) {
                                    logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR, sprintf(
                                        'Brevo event reconciliation failed (manual run): %s: %s',
                                        get_class($e),
                                        $e->getMessage()
                                    ));
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
