<?php
declare(strict_types=1);

use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\Cron\BrevoEventReconciliationJob;
use ElanRegistry\Cron\CronJobEnabledState;
use ElanRegistry\Cron\CronJobRunsReader;
use ElanRegistry\Cron\SendVerificationBatchJob;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;

/**
 * tab-verification.php
 * Verification System tab for the consolidated admin interface.
 *
 * Read-only for editors; the feature switch itself is admin-only. The `disabled`
 * attribute on the switch is a UI affordance, not a security control — the
 * toggle endpoint re-checks permissions server-side.
 *
 * Included by app/admin/index.php, which has already run init.php, securePage(),
 * and established $currentUserId / $csrfToken.
 */

// ---------------------------------------------------------------------------
// Context variables supplied by the parent admin/index.php page. Guard here so
// static analysis (and any direct include) always sees them initialized.
// ---------------------------------------------------------------------------
$currentUserId = $currentUserId ?? currentUserId();
$csrfToken     = $csrfToken ?? Token::generate();

// Batch-send report, populated by index.php's `verification_send_batch` case.
$sendBatchJustRan      = $sendBatchJustRan ?? false;
$sendReportSent        = $sendReportSent ?? [];
$sendReportUnrecorded  = $sendReportUnrecorded ?? [];
$sendReportSkipped     = $sendReportSkipped ?? [];
$sendReportFailed      = $sendReportFailed ?? [];

// Send services constructed by index.php, shared via the include scope.
$verificationSendSvc = $verificationSendSvc ?? null;

// ---------------------------------------------------------------------------
// Readiness probes. VerificationSettings never throws from its probes, but a
// construction or connection failure must not take the whole admin page down.
// ---------------------------------------------------------------------------
$vsEnabled         = false;
$vsBrevoReady      = false;
$vsCronReady       = false;
$vsLastCronAt      = null;
$vsUnmatchedCount  = 0;
// True whenever unmatchedRecipientCount() could not produce a real value.
// Defaults to true so a throw anywhere in the probe block below — which never
// reaches that method's own never-throws handling — is reported as the fault
// it is, rather than rendering the initial 0 as a reassuring "nothing
// unmatched". Same convention as $autoSendCountsUnreadable further down.
$vsUnmatchedUnreadable = true;
$vsProbeFailed     = false;

try {
    $vsSettings       = new VerificationSettings(dbi());
    $vsEnabled        = $vsSettings->isEnabled();
    $vsBrevoReady     = $vsSettings->brevoReady();
    $vsCronReady      = $vsSettings->cronReady();
    $vsLastCronAt     = $vsSettings->lastCronRequestAt();

    // null means the counter could not be read (query error, missing row, or a
    // malformed/negative stored value) — the method has already logged the
    // specific reason under VerificationConfigWarning. Leave the flag true so
    // the render below shows "Unavailable" instead of a green zero.
    $vsUnmatchedRead = $vsSettings->unmatchedRecipientCount();
    if ($vsUnmatchedRead !== null) {
        $vsUnmatchedCount = $vsUnmatchedRead;
        $vsUnmatchedUnreadable = false;
    }
} catch (\Throwable $e) {
    $vsProbeFailed = true;
    logger($currentUserId, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
        'Verification tab status probe failed: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Eligible-car preview (read-only). Its own fault domain: a failure here must
// not blank out the status section above, so it neither sets nor reads
// $vsProbeFailed.
//
// DELIBERATELY DOES NOT CONSULT VerificationSettings::isEnabled(). That switch
// governs the AUTOMATIC, owner-facing verification mailing (the cron job and
// the webhook paths). This section is a manual admin tool gated by
// securePage()/permissions alone, and its whole purpose is to let an
// administrator send a batch by hand — including while the site-wide switch
// is off, which is exactly when a manual send is most likely to be needed. An
// isEnabled() gate here would silently disable the recovery tool at the
// moment it matters.
//
// THIS IS A DIFFERENT GATE FROM er_cron_job_runs.enabled (#1885's Pause on
// the "Automatic Sending" panel below), and the two are DELIBERATELY NOT
// symmetric. isEnabled() is a site-wide kill switch — never checked here, per
// the paragraph above. er_cron_job_runs.enabled is this specific job's
// schedule — and it IS checked, by a pause-check in app/admin/index.php's
// verification_send_batch handler, before this section's own "Send batch"
// form is allowed to submit. The reasoning: Pause exists specifically to slow
// or halt the send cadence during the cutover ramp (see the migration's
// enabled=0-everywhere seed and #1885's Pause/Resume control) — an admin who
// paused it to stop mail this week does not want a manual "Send batch now"
// click to undo that pause by another route. isEnabled() has no equivalent
// scenario: it is an emergency-off switch with no ramp/cadence concept, so
// the "recovery tool must always work" argument above applies to it and only
// it. GET-time preview rendering (this section) is unaffected by either gate;
// only the POST-time send is checked.
// ---------------------------------------------------------------------------
/** @var array<int, object> $vsEligible */
$vsEligible       = [];
$vsBatchSize      = 0;
$vsEligibleError  = null;

if (isset($vsSettings)) {
    // Read separately from (and before) the eligible-car query below: the
    // Automatic Sending panel renders this as the value of an editable
    // field, and a preview-query failure must not prevent that render.
    // (batchSize() itself already fails closed to 5 on an ordinary DB error,
    // so this split doesn't change that outcome — it only avoids re-running
    // batchSize()'s own error-log line a second time for the same read.)
    try {
        $vsBatchSize = $vsSettings->batchSize();
    } catch (\Throwable $e) {
        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
            'Verification tab: could not read the configured batch size: ' . $e->getMessage());
    }
}

if ($verificationSendSvc !== null && isset($vsSettings)) {
    try {
        $vsEligible = $verificationSendSvc->findEligible($vsBatchSize, 0);
    } catch (\Throwable $e) {
        $vsEligibleError = 'The list of eligible cars could not be loaded. Check the system log for details.';
        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
            'Verification tab: could not load the eligible car preview [%s]: %s',
            get_class($e),
            $e->getMessage()
        ));
    }
}

// ---------------------------------------------------------------------------
// Reconciliation status probe. This is a distinct fault domain from the
// VerificationSettings probe above — kept in its own try/catch so a failure
// here is logged and rendered independently, not folded into $vsProbeFailed.
// ---------------------------------------------------------------------------
$reconciliationState = CronJobEnabledState::UNREADABLE;
$reconciliationLastRunAt = null;

try {
    $cronJobRunsReader = new CronJobRunsReader(dbi());
    $reconciliationStatus = $cronJobRunsReader->status(BrevoEventReconciliationJob::JOB_NAME);
    $reconciliationState = $reconciliationStatus['state'];
    $reconciliationLastRunAt = $reconciliationStatus['lastRunAt'];
} catch (\Throwable $e) {
    logger($currentUserId, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
        'Reconciliation status probe failed: ' . $e->getMessage());
}

$reconciliationBadge = CronJobRunsReader::badgeFor($reconciliationState, $reconciliationLastRunAt);
$reconciliationBadgeClass = $reconciliationBadge['badgeClass'];
$reconciliationBadgeIcon = $reconciliationBadge['icon'];
$reconciliationBadgeText = $reconciliationBadge['text'];

// ---------------------------------------------------------------------------
// Automatic-sending status probe (#1885). Its own fault domain again, for the
// same reason as the reconciliation probe above: this is a different job's row
// and a failure to read it must not blank out either of the sections above.
//
// $cronJobRunsReader is reused when the reconciliation probe above managed to
// construct it; a separate construction here would be a second connection for
// the same never-throwing reader.
// ---------------------------------------------------------------------------
$autoSendState = CronJobEnabledState::UNREADABLE;
$autoSendLastRunAt = null;
/** @var array{sent: int, skipped: int, failed: int}|null $autoSendCounts */
$autoSendCounts = null;
// True only when the counts read itself failed. A null $autoSendCounts with
// this false is the routine "job has never run" case; with it true the counts
// could not be confirmed and must not be rendered as reassurance. Defaults to
// true so that a throw out of the probe below — which never reaches the
// reader's own never-throws handling — is reported as the fault it is.
$autoSendCountsUnreadable = true;

try {
    $autoSendReader = $cronJobRunsReader ?? new CronJobRunsReader(dbi());
    $autoSendStatus = $autoSendReader->status(SendVerificationBatchJob::JOB_NAME);
    $autoSendState = $autoSendStatus['state'];
    $autoSendLastRunAt = $autoSendStatus['lastRunAt'];
    $autoSendOutcome = $autoSendReader->lastOutcomeCounts(SendVerificationBatchJob::JOB_NAME);
    $autoSendCounts = $autoSendOutcome['counts'];
    $autoSendCountsUnreadable = $autoSendOutcome['unreadable'];
} catch (\Throwable $e) {
    logger($currentUserId, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
        'Automatic verification send status probe failed: ' . $e->getMessage());
}

$autoSendBadge = CronJobRunsReader::badgeFor($autoSendState, $autoSendLastRunAt);
$autoSendPaused = $autoSendState === CronJobEnabledState::DISABLED;

// The button submits the state it wants rather than a blind flip — see the
// verification_toggle_cron handler in index.php. Anything that is not
// positively ENABLED (paused, missing row, unreadable) offers Resume: that is
// the action which can recover the row's state, and it is idempotent.
$autoSendDesiredState = $autoSendState === CronJobEnabledState::ENABLED ? 'disable' : 'enable';

// Admins may toggle; editors see the same status read-only.
$vsCanToggle = hasPerm([2], $currentUserId);

// Turning ON is blocked while Brevo is unconfigured. Turning OFF is never
// blocked — an admin must always be able to switch verification off mid-incident.
$vsToggleDisabled = VerificationSettings::toggleShouldBeDisabled($vsCanToggle, $vsEnabled, $vsBrevoReady);

$vsDisabledReason = '';
if (!$vsCanToggle) {
    $vsDisabledReason = 'Only administrators can change this setting.';
} elseif ($vsToggleDisabled) {
    $vsDisabledReason = 'Verification cannot be enabled until Brevo is configured. '
        . 'Save an API key in the Brevo plugin and put its override file in place, then reload this page.';
}

if (!function_exists('vsEsc')) {
    /**
     * Escape a value for HTML output in this tab.
     *
     * @param mixed $value
     */
    function vsEsc($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
?>

<!-- Verification System Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="text-primary mb-1">
            <i class="fas fa-clipboard-check"></i> Verification System
        </h2>
        <p class="text-muted mb-0">Feature switch and readiness for owner car verification</p>
    </div>
</div>

<?php if ($vsProbeFailed) { ?>
    <div class="alert alert-danger" role="alert">
        <i class="fas fa-exclamation-circle"></i>
        Verification status could not be read. Check the system log for
        <strong>VerificationConfigWarning</strong> entries.
    </div>
<?php } ?>

<div class="card registry-card mb-4">
    <div class="card-header card-header-er-primary">
        <h5 class="mb-0 card-header-er-primary-text">
            <i class="fas fa-clipboard-check"></i> Verification System
        </h5>
    </div>
    <div class="card-body">

        <!-- Status -->
        <h6 class="text-primary mb-3"><i class="fas fa-heartbeat"></i> Status</h6>
        <dl class="row mb-4">

            <dt class="col-sm-4">Brevo configured</dt>
            <dd class="col-sm-8">
                <?php if (!$vsEnabled && !$vsBrevoReady) { ?>
                    <span class="text-muted">
                        <i class="fas fa-circle"></i> Not checked (switch is off)
                    </span>
                <?php } elseif ($vsBrevoReady) { ?>
                    <span class="badge text-bg-success">
                        <i class="fas fa-check-circle"></i> Configured
                    </span>
                <?php } else { ?>
                    <span class="badge text-bg-danger">
                        <i class="fas fa-exclamation-circle"></i> Not configured
                    </span>
                    <small class="text-muted ms-1">
                        Save an API key in the Brevo plugin and put its override file in
                        place. If both are already present, check the system log for
                        <strong>VerificationConfigWarning</strong>.
                    </small>
                <?php } ?>
            </dd>

            <dt class="col-sm-4">Cron running</dt>
            <dd class="col-sm-8">
                <?php if ($vsCronReady) { ?>
                    <span class="badge text-bg-success">
                        <i class="fas fa-check-circle"></i> Running
                    </span>
                    <?php if ($vsLastCronAt !== null) { ?>
                        <small class="text-muted ms-1">
                            <i class="fas fa-clock"></i>
                            last seen <?= htmlspecialchars($vsLastCronAt->format('M j, Y g:i A'), ENT_QUOTES, 'UTF-8') ?>
                        </small>
                    <?php } ?>
                <?php } else { ?>
                    <span class="badge text-bg-warning">
                        <i class="fas fa-exclamation-triangle"></i> Stalled
                    </span>
                    <small class="text-muted ms-1">
                        <?php if ($vsLastCronAt !== null) { ?>
                            <i class="fas fa-clock"></i>
                            last seen <?= htmlspecialchars($vsLastCronAt->format('M j, Y g:i A'), ENT_QUOTES, 'UTF-8') ?>
                        <?php } else { ?>
                            no cron request has ever been logged
                        <?php } ?>
                    </small>
                <?php } ?>
            </dd>

            <dt class="col-sm-4">Webhook receiver</dt>
            <dd class="col-sm-8">
                <span class="badge text-bg-success">
                    <i class="fas fa-check-circle"></i> Live
                </span>
                <small class="text-muted ms-1">
                    Bounce and suppression events from Brevo are recorded in real time
                    (<code>app/api/webhooks/brevo.php</code>); the nightly reconciliation
                    job below catches anything the webhook missed.
                </small>
            </dd>

            <dt class="col-sm-4">Unmatched recipients</dt>
            <dd class="col-sm-8">
                <?php if ($vsUnmatchedUnreadable) { ?>
                    <!-- The counter read failed: an infrastructure or data
                         fault, not a genuinely quiet system. Rendered as a
                         danger badge — never the green zero a healthy read
                         shows — since a rising count is this counter's whole
                         signal and "unreadable" must not look like "healthy". -->
                    <span class="badge text-bg-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        Unavailable
                    </span>
                    <small class="text-muted ms-1">
                        The unmatched-recipient counter could not be read. Check the system
                        log for <strong>VerificationConfigWarning</strong> entries.
                    </small>
                <?php } else { ?>
                <span class="badge <?= $vsUnmatchedCount > 0 ? 'text-bg-warning' : 'text-bg-success' ?>">
                    <?= htmlspecialchars((string) $vsUnmatchedCount, ENT_QUOTES, 'UTF-8') ?>
                </span>
                <small class="text-muted ms-1">
                    Brevo events and suppressed contacts whose email matched no car,
                    across the webhook receiver, nightly reconciliation, and suppression
                    sync. A rising count usually means recipient emails have drifted
                    from <code>cars.email</code>.
                </small>
                <?php } ?>
            </dd>

            <dt class="col-sm-4">Last reconciliation run</dt>
            <dd class="col-sm-8">
                <span class="<?= htmlspecialchars($reconciliationBadgeClass, ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fas <?= htmlspecialchars($reconciliationBadgeIcon, ENT_QUOTES, 'UTF-8') ?>"></i>
                    <?= htmlspecialchars($reconciliationBadgeText, ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php if ($reconciliationLastRunAt !== null) { ?>
                    <small class="text-muted ms-1">
                        <i class="fas fa-clock"></i>
                        last ran
                        <?= htmlspecialchars($reconciliationLastRunAt->format('M j, Y g:i A'), ENT_QUOTES, 'UTF-8') ?>
                    </small>
                <?php } ?>
            </dd>

        </dl>

        <!-- Toggle control -->
        <h6 class="text-primary mb-3"><i class="fas fa-toggle-on"></i> Feature Switch</h6>

        <div id="verificationToggleFeedback" class="mb-2" role="alert" aria-live="polite"></div>

        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox"
                   id="verificationEnabledSwitch"
                   <?= $vsEnabled ? 'checked' : '' ?>
                   <?= $vsToggleDisabled ? 'disabled' : '' ?>
                   <?= $vsDisabledReason !== '' ? 'aria-describedby="verificationToggleHelp"' : '' ?>>
            <label class="form-check-label" for="verificationEnabledSwitch">
                Verification system enabled
            </label>
        </div>

        <?php if ($vsDisabledReason !== '') { ?>
            <small id="verificationToggleHelp" class="form-text text-muted d-block mt-1">
                <i class="fas fa-info-circle"></i>
                <?= htmlspecialchars($vsDisabledReason, ENT_QUOTES, 'UTF-8') ?>
            </small>
        <?php } else { ?>
            <small class="form-text text-muted d-block mt-1">
                <i class="fas fa-info-circle"></i>
                Turning this off hides all verification UI and stops reminder emails, the inbound webhook,
                and both cron jobs (reconciliation and suppression sync) from writing bounce or suppression
                state to car records. It can always be turned off, even while Brevo or cron are unavailable.
            </small>
        <?php } ?>

    </div>
</div>

<!-- Automatic Sending (#1885) -->
<div class="card registry-card mb-4<?= $autoSendPaused ? ' border-warning' : '' ?>">
    <div class="card-header card-header-er-primary">
        <h5 class="mb-0 card-header-er-primary-text">
            <i class="fas fa-robot"></i> Automatic Sending
        </h5>
    </div>
    <div class="card-body">

        <?php if ($autoSendPaused) { ?>
        <!-- The badge alone is easy to miss on a page this long; a paused batch
             sender is a state an admin must not scroll past, so the card also
             carries a warning border and this banner until it is resumed. -->
        <div class="alert alert-warning" role="alert">
            <i class="fas fa-pause-circle"></i>
            <strong>Automatic sending is paused.</strong>
            No verification emails go out on their own, and the manual
            <strong>Send batch</strong> button below is blocked until it is resumed.
        </div>
        <?php } ?>

        <p class="text-muted">
            When running, a batch is sent unattended at most once a day. There is no
            fixed clock time — the job simply declines to run again until enough time
            has passed since its last run.
        </p>

        <dl class="row mb-4">

            <dt class="col-sm-4">Automatic sending</dt>
            <dd class="col-sm-8">
                <span class="<?= vsEsc($autoSendBadge['badgeClass']) ?>">
                    <i class="fas <?= vsEsc($autoSendBadge['icon']) ?>"></i>
                    <?= vsEsc($autoSendBadge['text']) ?>
                </span>
                <?php if ($autoSendLastRunAt !== null) { ?>
                    <small class="text-muted ms-1">
                        <i class="fas fa-clock"></i>
                        last ran <?= vsEsc($autoSendLastRunAt->format('M j, Y g:i A')) ?>
                    </small>
                <?php } ?>
            </dd>

            <dt class="col-sm-4">Last run results</dt>
            <dd class="col-sm-8">
                <?php if ($autoSendCountsUnreadable) { ?>
                    <!-- The counts read failed (see the system log): an
                         infrastructure fault, not a job that has never run.
                         Rendered as the same danger badge badgeFor() uses for
                         MISSING/UNREADABLE so the two never look alike. -->
                    <span class="badge text-bg-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        Counts unavailable
                    </span>
                    <small class="text-muted ms-1">check the system log</small>
                <?php } elseif ($autoSendCounts === null) { ?>
                    <span class="text-muted">No automatic run yet</span>
                <?php } else { ?>
                    <?= vsEsc((string) $autoSendCounts['sent']) ?> sent,
                    <?= vsEsc((string) $autoSendCounts['skipped']) ?> skipped,
                    <?= vsEsc((string) $autoSendCounts['failed']) ?> failed
                <?php } ?>
            </dd>

            <dt class="col-sm-4">Batch size</dt>
            <dd class="col-sm-8">
                <?php if ($vsCanToggle) { ?>
                <form action="index.php?tab=verification" method="POST" class="row g-2 align-items-center">
                    <input type="hidden" name="csrf" value="<?= vsEsc($csrfToken) ?>">
                    <input type="hidden" name="command" value="verification_set_batch_size">
                    <div class="col-auto">
                        <label class="visually-hidden" for="verificationBatchSize">Batch size</label>
                        <input type="number" class="form-control form-control-sm"
                               id="verificationBatchSize" name="batch_size"
                               min="1" max="25" style="width: 6rem;"
                               value="<?= vsEsc((string) $vsBatchSize) ?>">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </div>
                </form>
                <small class="form-text text-muted d-block mt-1">
                    <i class="fas fa-info-circle"></i>
                    Cars per run, for both the automatic job and the manual send below.
                    Values outside 1&ndash;25 are clamped when saved.
                </small>
                <?php } else { ?>
                    <?= vsEsc((string) $vsBatchSize) ?> cars per run
                <?php } ?>
            </dd>

        </dl>

        <?php if ($vsCanToggle) { ?>
        <!-- Gated on $vsCanToggle to match this tab's "read-only for editors"
             contract. The server re-checks hasPerm([2]) on the POST side; this
             only avoids showing an editor a control their click would reject. -->
        <form action="index.php?tab=verification" method="POST">
            <input type="hidden" name="csrf" value="<?= vsEsc($csrfToken) ?>">
            <input type="hidden" name="command" value="verification_toggle_cron">
            <input type="hidden" name="desired_state" value="<?= vsEsc($autoSendDesiredState) ?>">
            <?php if ($autoSendDesiredState === 'disable') { ?>
            <button type="submit" class="btn btn-outline-warning">
                <i class="fas fa-pause"></i> Pause automatic sending
            </button>
            <?php } else { ?>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-play"></i> Resume automatic sending
            </button>
            <?php } ?>
        </form>
        <?php } else { ?>
        <p class="text-muted mb-0">
            <i class="fas fa-lock"></i> Administrator access is required to pause or resume automatic sending.
        </p>
        <?php } ?>

    </div>
</div>

<!-- Send Verification Emails -->
<div class="card registry-card mb-4">
    <div class="card-header card-header-er-primary">
        <h5 class="mb-0 card-header-er-primary-text">
            <i class="fas fa-envelope-circle-check"></i> Send Verification Emails
        </h5>
    </div>
    <div class="card-body">

        <p class="text-muted">
            Review the cars currently due a verification email, then send the batch.
            Nothing is sent until you press <strong>Send batch</strong>.
        </p>

<?php if ($sendBatchJustRan) { ?>

        <h6 class="text-primary mb-3"><i class="fas fa-list-check"></i> Batch results</h6>

        <h6 class="mb-2">Sent (<?= count($sendReportSent) ?>)</h6>
        <?php if ($sendReportSent === []) { ?>
            <p class="text-muted">No emails were sent.</p>
        <?php } else { ?>
            <div class="table-responsive mb-4">
                <table class="table table-sm">
                    <thead>
                        <tr><th scope="col">Car</th><th scope="col">Chassis</th><th scope="col">Email</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sendReportSent as $reportCar) { ?>
                        <tr>
                            <td><?= vsEsc($reportCar->id ?? '') ?></td>
                            <td><?= vsEsc($reportCar->chassis ?? '') ?></td>
                            <td><?= vsEsc($reportCar->email ?? '') ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <?php if ($sendReportUnrecorded !== []) { ?>
        <h6 class="mb-2 text-warning">
            <i class="fas fa-triangle-exclamation"></i> Sent, but not recorded (<?= count($sendReportUnrecorded) ?>)
        </h6>
        <p class="text-muted">
            These emails were delivered, but the follow-up bookkeeping failed — the affected
            car(s) may be re-selected and emailed again in a future batch. See the server log
            for details.
        </p>
        <div class="table-responsive mb-4">
            <table class="table table-sm">
                <thead>
                    <tr><th scope="col">Car</th><th scope="col">Chassis</th><th scope="col">Email</th><th scope="col">Warning</th></tr>
                </thead>
                <tbody>
                <?php foreach ($sendReportUnrecorded as $reportRow) { ?>
                    <tr>
                        <td><?= vsEsc($reportRow['car']->id ?? '') ?></td>
                        <td><?= vsEsc($reportRow['car']->chassis ?? '') ?></td>
                        <td><?= vsEsc($reportRow['car']->email ?? '') ?></td>
                        <td><?= vsEsc($reportRow['reason']) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>

        <h6 class="mb-2">Skipped (<?= count($sendReportSkipped) ?>)</h6>
        <?php if ($sendReportSkipped === []) { ?>
            <p class="text-muted">No cars were skipped.</p>
        <?php } else { ?>
            <div class="table-responsive mb-4">
                <table class="table table-sm">
                    <thead>
                        <tr><th scope="col">Car</th><th scope="col">Chassis</th><th scope="col">Reason</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sendReportSkipped as $reportRow) { ?>
                        <tr>
                            <td><?= vsEsc($reportRow['car']->id ?? '') ?></td>
                            <td><?= vsEsc($reportRow['car']->chassis ?? '') ?></td>
                            <td><?= vsEsc($reportRow['reason']) ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <h6 class="mb-2">Failed (<?= count($sendReportFailed) ?>)</h6>
        <?php if ($sendReportFailed === []) { ?>
            <p class="text-muted">No sends failed.</p>
        <?php } else { ?>
            <div class="table-responsive mb-4">
                <table class="table table-sm">
                    <thead>
                        <tr><th scope="col">Car</th><th scope="col">Chassis</th><th scope="col">Reason</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sendReportFailed as $reportRow) { ?>
                        <tr>
                            <td><?= vsEsc($reportRow['car']->id ?? '') ?></td>
                            <td><?= vsEsc($reportRow['car']->chassis ?? '') ?></td>
                            <td><?= vsEsc($reportRow['reason']) ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <hr>
        <h6 class="text-primary mb-3"><i class="fas fa-envelope"></i> Cars still due a verification email</h6>

<?php } ?>

<?php if ($vsEligibleError !== null) { ?>

        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-circle"></i> <?= vsEsc($vsEligibleError) ?>
        </div>

<?php } elseif ($vsEligible === []) { ?>

        <div class="alert alert-info" role="alert">
            <i class="fas fa-info-circle"></i> No cars are currently due a verification email.
        </div>

<?php } else { ?>

        <p class="text-muted">
            Showing up to the configured batch size (<?= vsEsc((string) $vsBatchSize) ?>)
            of the oldest-verified eligible cars.
        </p>

        <form action="index.php?tab=verification" method="POST">
            <input type="hidden" name="csrf" value="<?= vsEsc($csrfToken) ?>">
            <input type="hidden" name="command" value="verification_send_batch">

            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Car</th>
                            <th scope="col">Chassis</th>
                            <th scope="col">Owner</th>
                            <th scope="col">Email</th>
                            <th scope="col">Owner actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vsEligible as $eligibleCar) {
                        // cars.fname/cars.lname ARE present in this row (findVerificationEligible()
                        // selects cars.*, and fname/lname are denormalized onto cars — see
                        // DATABASE.md), but they are a synced copy that can drift from the
                        // authoritative users/profiles values. The owner name shown here is
                        // resolved per row via Owner::data() rather than trusting the
                        // denormalized cars columns, so this preview matches what the send
                        // actually uses (CarVerificationSendService also loads Owner fresh).
                        // Guarded per row: this loop runs inside an already-open <tbody>, well
                        // past the try/catch that built $vsEligible above — that catch's fault
                        // domain covers only the query that produced the list, not this per-row
                        // lookup. An uncaught throw here would fatal mid-render (unclosed table,
                        // no error shown), so a failure instead logs and falls back to the row's
                        // own denormalized cars.fname/cars.lname rather than aborting the page.
                        try {
                            $vsOwnerRow  = (new Owner((int) $eligibleCar->user_id))->data();
                            $vsOwnerName = trim(($vsOwnerRow->fname ?? '') . ' ' . ($vsOwnerRow->lname ?? ''));
                        } catch (\Throwable $e) {
                            logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
                                'Verification tab: owner %d could not be loaded for the eligible-car preview row of car %d [%s]: %s',
                                (int) $eligibleCar->user_id,
                                (int) $eligibleCar->id,
                                get_class($e),
                                $e->getMessage()
                            ));
                            $vsOwnerName = trim(($eligibleCar->fname ?? '') . ' ' . ($eligibleCar->lname ?? ''));
                        }
                    ?>
                        <tr>
                            <td>
                                <input type="hidden" name="car_ids[]" value="<?= vsEsc($eligibleCar->id) ?>">
                                <?= vsEsc($eligibleCar->id) ?>
                            </td>
                            <td><?= vsEsc($eligibleCar->chassis ?? '') ?></td>
                            <td><?= vsEsc($vsOwnerName !== '' ? $vsOwnerName : "owner #{$eligibleCar->user_id}") ?></td>
                            <td><?= vsEsc($eligibleCar->email ?? '') ?></td>
                            <td>
                                <?php if ($vsCanToggle) { ?>
                                <!-- Rendered outside the batch form via the form= attribute: nested
                                     forms are invalid HTML and would break the batch submission.
                                     Gated on $vsCanToggle (admin-only) to match this tab's own
                                     "read-only for editors" contract — the server independently
                                     enforces the same hasPerm([2]) check on the POST side, this is
                                     purely so an editor isn't shown controls their click would reject. -->
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        name="command" value="mark_bounced"
                                        form="owner-action-<?= vsEsc($eligibleCar->id) ?>">Mark Bounced</button>
                                <button type="submit" class="btn btn-sm btn-outline-secondary"
                                        name="command" value="clear_bounced"
                                        form="owner-action-<?= vsEsc($eligibleCar->id) ?>">Clear Bounced</button>
                                <button type="submit" class="btn btn-sm btn-outline-secondary"
                                        name="command" value="clear_suppression"
                                        form="owner-action-<?= vsEsc($eligibleCar->id) ?>">Clear Suppression</button>
                                <?php } else { ?>
                                <span class="text-muted">&mdash;</span>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>

            <?php if ($vsCanToggle) { ?>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Send batch
            </button>
            <?php } else { ?>
            <p class="text-muted mb-0"><i class="fas fa-lock"></i> Administrator access is required to send.</p>
            <?php } ?>
        </form>

        <?php if ($vsCanToggle) { ?>
        <?php foreach ($vsEligible as $eligibleCar) { ?>
        <form action="index.php?tab=verification" method="POST" id="owner-action-<?= vsEsc($eligibleCar->id) ?>">
            <input type="hidden" name="csrf" value="<?= vsEsc($csrfToken) ?>">
            <input type="hidden" name="car_id" value="<?= vsEsc($eligibleCar->id) ?>">
        </form>
        <?php } ?>
        <?php } ?>

<?php } ?>

    </div>
</div>

<script src="<?= $us_url_root ?>app/admin/assets/js/tab-verification.min.js?v=<?= ASSET_VERSION ?>"></script>
