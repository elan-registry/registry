<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Cron\BrevoSuppressionSyncClient;
use ElanRegistry\Cron\BrevoSuppressionSyncJob;
use ElanRegistry\LogCategories;

/**
 * Brevo Suppression List Sync — Manual Full Backfill (v2.30.2)
 *
 * Admin-triggered "run now" wrapper around the nightly suppression sync cron
 * job (backed by {@see BrevoSuppressionSyncJob}). Calls `runNowWithSummary()`
 * rather than `run()`, so both the job's `enabled` flag and the 20-hour
 * CronJobGuard interval are bypassed — an operator triggering this has already
 * made the scheduling decision the guard exists to make — and, unlike the
 * nightly path's single windowed page, a full unwindowed walk of Brevo's
 * suppression list is performed, bounded by the job's page-count AND
 * wall-clock safety caps.
 *
 * Repeatable maintenance, not a one-time fix, hence `scripts/maintenance/`
 * (see CLAUDE.md). Safe to run as often as wanted: every write is idempotent —
 * the synthetic message-id scheme makes each contact collide with its existing
 * `er_email_events` row under the UNIQUE constraint, and the flag writes are
 * plain column updates.
 *
 * Unlike 27-Reconcile-Brevo-Events.php's `runNow()`, which is crash-isolated
 * and returns void, `runNowWithSummary()` logs and then **rethrows** on a
 * genuinely failed run so a crash cannot be rendered as a plausible all-zero
 * summary. The try/catch below therefore covers the call itself, not just the
 * construction above it.
 *
 * Issue #1923.
 */
require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'app/admin/includes/fix-script-core.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

if (!isAdmin()) {
    logger($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY,
        'Non-admin attempted Brevo suppression sync script');
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
                                <i class="fa fa-rotate"></i> Brevo Suppression List Sync
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Imports Brevo's transactional suppression list — the addresses Brevo will no longer deliver to — into the registry's own email flags, immediately rather than waiting for the next scheduled cron run.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Polls Brevo's blocked-contacts list for every suppressed address: hard bounces, spam complaints, and unsubscribes</li>
                                    <li>Runs a <strong>full backfill</strong> with no date window — unlike the nightly job's single 48-hour page — walking up to 500 pages (about 50,000 contacts), stopping early if that page count or a 20-second time limit is reached first</li>
                                    <li>Maps Brevo's reason codes onto the registry's bounce and suppression flags through the same <code>EmailEventApplier</code> a live webhook event uses, so an imported suppression flags a car identically to a live one</li>
                                    <li>Skips any reason code it does not recognize rather than guessing — unmapped codes are tallied in the breakdown below and logged</li>
                                    <li>Bypasses the normal 20-hour cron guard, so it runs even if the scheduled job has already run today</li>
                                    <li>Safe to run repeatedly — every write is idempotent under a synthetic message-id scheme, so re-importing a suppression already recorded is a no-op</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <?= admin_script_start_form('Run Suppression Sync Now') ?>
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
                                    <i class="fa fa-check-circle"></i> Suppression Sync Result
                                </h2>
                            </div>
                            <div class="card-body">
                                <?php
                                try {
                                    // A full backfill can walk up to MAX_BACKFILL_PAGES sequential
                                    // Brevo requests — comfortably past a typical 30s
                                    // max_execution_time. runFullBackfill() has its own
                                    // MAX_BACKFILL_SECONDS (20s) wall-clock stop that degrades into
                                    // a normal "capped" summary between pages, but that only helps
                                    // if the PHP-level limit doesn't fire first — an execution
                                    // timeout is not catchable (it skips every catch block below,
                                    // including this one), so the operator would otherwise see a
                                    // blank or truncated page instead of either a real summary or a
                                    // real error. Raised here, not left to AbstractCronJob::runNow()
                                    // (which deliberately skips set_time_limit() for the one-page
                                    // execute() case — that reasoning does not cover this method).
                                    //
                                    // A bounded value, not 0 (unbounded): every other call site in
                                    // this codebase (AbstractCronJob::run(), transfer-request.php)
                                    // sizes this backstop rather than disabling it, and runNow()'s
                                    // own docblock argues against silently extending an admin
                                    // request's budget past what it was granted — set_time_limit(0)
                                    // is the maximal form of exactly that. 120s is comfortably over
                                    // the worst legitimate case (the loop's own 20s deadline plus one
                                    // in-flight page's 30s client timeout, since the deadline check
                                    // runs between pages, not mid-page — see MAX_BACKFILL_SECONDS's
                                    // docblock), so this can only fire when something is genuinely
                                    // wrong, which is what a backstop is for.
                                    set_time_limit(120);

                                    $suppressionRepo = new CarRepository(dbi());

                                    // runNowWithSummary() returns the run's counts and can throw:
                                    // it logs and rethrows on a genuinely failed run rather than
                                    // fabricating an all-zero summary indistinguishable from a
                                    // successful run over an empty list. The catch below renders
                                    // that failure — and still covers the construction above,
                                    // which can throw on its own.
                                    $summary = (new BrevoSuppressionSyncJob(
                                        dbi(),
                                        $suppressionRepo,
                                        new EmailEventApplier($suppressionRepo, new CarVerificationManager($suppressionRepo)),
                                        new BrevoSuppressionSyncClient(dbi())
                                    ))->runNowWithSummary();

                                    logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE,
                                        sprintf(
                                            'Brevo suppression sync manually triggered (cron guard bypassed) —'
                                            . ' %d matched, %d unmatched, %d already flagged, %d skipped,'
                                            . ' %d page(s) fetched%s%s',
                                            $summary->matchedCount,
                                            $summary->unmatchedCount,
                                            $summary->alreadyFlaggedCount,
                                            $summary->skippedCount,
                                            $summary->pagesFetched,
                                            $summary->backfillCapped ? ', stopped at a safety cap (page count or elapsed time)' : '',
                                            $summary->pollFailed ? ', a Brevo poll failed mid-run' : ''
                                        ));
                                    $recordingWarning = null;
                                    admin_script_record_completion(__FILE__, (int) $user->data()->id, function (string $msg) use (&$recordingWarning) {
                                        $recordingWarning = $msg;
                                    });
                                    ?>
                                    <?php if ($summary->backfillCapped): ?>
                                    <div class="alert alert-warning">
                                        <h5 class="mb-2"><i class="fa fa-exclamation-triangle"></i> Backfill incomplete — re-run this script</h5>
                                        <p class="mb-0">The walk stopped at a safety cap (page count or elapsed time) before reaching the end of Brevo's suppression list, so more suppressions likely remain unimported. Run this script again to continue — suppressions already imported are re-applied harmlessly.</p>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($summary->pollFailed): ?>
                                    <div class="alert alert-warning">
                                        <h5 class="mb-2"><i class="fa fa-exclamation-triangle"></i> Backfill incomplete — Brevo poll failed</h5>
                                        <p class="mb-0">A page request to Brevo failed partway through the walk, so the import stopped early and more suppressions likely remain. The failure is logged under <code>CronJobFailure</code>. Re-run this script once Brevo is reachable — suppressions already imported are re-applied harmlessly.</p>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($summary->skippedCount > 0): ?>
                                    <div class="alert alert-warning">
                                        <h5 class="mb-2"><i class="fa fa-exclamation-triangle"></i> <?= (int) $summary->skippedCount ?> contact(s) could not be processed</h5>
                                        <p class="mb-0">These were skipped due to a malformed Brevo payload, an unmapped reason code, an oversized value, or a failed database write, and are counted in none of the totals below. Each is logged individually under <code>EmailWebhook</code> or <code>CronJobFailure</code>. Re-running this script is safe and will retry them.</p>
                                    </div>
                                    <?php endif; ?>

                                    <div class="alert alert-success">
                                        <i class="fa fa-check-circle"></i> Suppression sync completed:
                                        <strong><?= (int) $summary->matchedCount ?></strong> matched,
                                        <strong><?= (int) $summary->unmatchedCount ?></strong> unmatched,
                                        <strong><?= (int) $summary->alreadyFlaggedCount ?></strong> already flagged,
                                        across <strong><?= (int) $summary->pagesFetched ?></strong> page(s) fetched.
                                    </div>

                                    <h5 class="mt-4"><i class="fa fa-table"></i> Suppressions by Brevo reason code</h5>
                                    <?php if ($summary->reasonCodeCounts === []): ?>
                                    <p class="text-muted mb-0">No suppression reason codes were recorded in this run.</p>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped mb-0">
                                            <thead>
                                                <tr>
                                                    <th scope="col">Reason code</th>
                                                    <th scope="col" class="text-end">Contacts</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($summary->reasonCodeCounts as $reasonCode => $reasonCount): ?>
                                                <tr>
                                                    <td><code><?= htmlspecialchars((string) $reasonCode, ENT_QUOTES, 'UTF-8') ?></code></td>
                                                    <td class="text-end"><?= (int) $reasonCount ?></td>
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
                                        'Brevo suppression sync failed (manual run): %s: %s',
                                        get_class($e),
                                        $e->getMessage()
                                    ));
                                    ?>
                                    <div class="alert alert-danger mb-0">
                                        <i class="fa fa-exclamation-triangle"></i> Suppression sync failed: <?= htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') ?>
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
