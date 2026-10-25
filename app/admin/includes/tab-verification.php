<?php
declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\Car\VerificationTabStatus;
use ElanRegistry\Cron\CronJobEnabledState;
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
 *
 * Layout, top to bottom: summary cards, the Status / Feature Switch panel,
 * Automatic Sending, the verification queue, recent activity. The panel has
 * the same place in every health state (#2364). VerificationTabStatus holds
 * the status rules; this file only renders them.
 */

// THE VERIFICATION SWITCH STOPS ONLY THE SEND BATCH. The stop for event writes
// is in the Feature Switch help text in verification-status-panel.php (shown
// only when the switch has no disabled reason) and in
// docs/development/EMAIL_SYSTEM.md.

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
$verificationRepo    = $verificationRepo ?? null;

// Dashboard URL state (?status=&window=&show=&activity_window=). index.php
// checks each value against its allow-list in CarRepository. The window
// defaults here are null, not 30: null is the valid "all time" value, and
// `?? 30` would replace it.
$queueStatus        = $queueStatus ?? 'all';
$queueWindowDays    = $queueWindowDays ?? null;
$queueShowLimit     = $queueShowLimit ?? 25;
$activityWindowDays = $activityWindowDays ?? null;

$vsIsEligibleView = $queueStatus === 'eligible';

// ---------------------------------------------------------------------------
// Status probes. VerificationTabStatus runs each probe in its own fault domain
// and holds every status rule that the panel and Automatic Sending show.
// ---------------------------------------------------------------------------
$vsStatus = VerificationTabStatus::read(dbi(), $currentUserId);

// ---------------------------------------------------------------------------
// Eligible-car preview (read-only). Its own fault domain: a failure here must
// not blank out the status panel.
//
// DELIBERATELY DOES NOT CONSULT VerificationSettings::isEnabled(). That switch
// stops only the automatic send batch (see the note at the top of this file
// and docs/development/EMAIL_SYSTEM.md, "Verification System Feature Switch").
// This section is a manual admin tool gated by
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
$vsSettings       = null;
$vsEligibleLoadError = 'The list of eligible cars could not be loaded. Check the system log for details.';

// Read separately from (and before) the eligible-car query below: the
// Automatic Sending panel renders this as the value of an editable
// field, and a preview-query failure must not prevent that render.
// (batchSize() itself already fails closed to 5 on an ordinary DB error,
// so this split doesn't change that outcome — it only avoids re-running
// batchSize()'s own error-log line a second time for the same read.)
try {
    $vsSettings  = new VerificationSettings(dbi());
    $vsBatchSize = $vsSettings->batchSize();
} catch (\Throwable $e) {
    // Without a batch size the preview would run with LIMIT 0 and show
    // "no cars are due". Clear the settings so the preview shows an error.
    $vsSettings = null;
    logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
        'Verification tab: could not read the configured batch size: ' . $e->getMessage());
}

// Only the Eligible queue view shows this preview, so other views skip the query.
if ($vsIsEligibleView && $verificationSendSvc !== null && $vsSettings !== null) {
    try {
        $vsEligible = $verificationSendSvc->findEligible($vsBatchSize, 0);
    } catch (\Throwable $e) {
        $vsEligibleError = $vsEligibleLoadError;
        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
            'Verification tab: could not load the eligible car preview [%s]: %s',
            get_class($e),
            $e->getMessage()
        ));
    }
} elseif ($vsIsEligibleView) {
    // Without the send service or the settings there is no preview. Say so,
    // so that an empty list does not read as "no cars are due".
    $vsEligibleError = $vsEligibleLoadError;
}


// Admins may toggle; editors see the same status read-only.
$vsCanToggle = hasPerm([2], $currentUserId);


if (!function_exists('vsEsc')) {
    /**
     * Escape a value for HTML output in this tab.
     *
     * @param mixed $value
     */
    function vsEsc(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('vsWindowLabel')) {
    /**
     * Give the display text for a queue or activity window
     *
     * @param int|null $windowDays Days back from now, or null for all time
     */
    function vsWindowLabel(?int $windowDays): string
    {
        return $windowDays === null ? 'All time' : "Last {$windowDays} days";
    }
}

// ---------------------------------------------------------------------------
// Dashboard summary counts. Their own fault domain: a failure here shows an
// em dash on the cards and leaves the count off the pills. The sections below
// still render.
// ---------------------------------------------------------------------------
/**
 * @var array{all: int, eligible: int, pending: int, bounced: int, blocked: int, opted_out: int,
 *     verified: int, sold: int, bounced_owners: int, blocked_owners: int, opted_out_owners: int}|null $vsSummary
 */
$vsSummary = null;
$vsRepo    = $verificationRepo ?? new CarRepository(dbi());

try {
    $vsSummary = $vsRepo->countVerificationSummary($queueWindowDays);
} catch (\Throwable $e) {
    logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
        'Verification tab: could not load the summary counts [%s]: %s',
        get_class($e),
        $e->getMessage()
    ));
}

// ---------------------------------------------------------------------------
// Verification queue rows. Their own fault domain. The Eligible view uses the
// eligible-car preview above (the same rows the batch form sends), so it does
// not run a second query.
// ---------------------------------------------------------------------------
/** @var array<int, object> $vsQueueRows */
$vsQueueRows  = [];
$vsQueueError = null;

if ($vsIsEligibleView) {
    $vsQueueRows  = $vsEligible;
    $vsQueueError = $vsEligibleError;
} else {
    try {
        $vsQueueRows = $vsRepo->findVerificationQueue($queueStatus, $queueWindowDays, $queueShowLimit);
    } catch (\Throwable $e) {
        $vsQueueError = 'The verification queue could not be loaded. Check the system log for details.';
        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
            'Verification tab: could not load the %s queue [%s]: %s',
            $queueStatus,
            get_class($e),
            $e->getMessage()
        ));
    }
}

// ---------------------------------------------------------------------------
// Status chip events for the queue rows. Their own fault domain: a failure
// shows "Unavailable" in the Status column, never an empty chip that reads as
// "no events". Defaults to unreadable when there are rows, for the same
// reason as the unreadable auto-send counts.
// ---------------------------------------------------------------------------
$vsQueueCarIds = [];
foreach ($vsQueueRows as $vsQueueRow) {
    $vsQueueRowId = (int) ($vsQueueRow->id ?? 0);
    if ($vsQueueRowId > 0) {
        $vsQueueCarIds[] = $vsQueueRowId;
    }
}

/** @var array<int, object> $vsChipEvents */
$vsChipEvents     = [];
$vsChipUnreadable = $vsQueueCarIds !== [];

if ($vsQueueCarIds !== []) {
    try {
        $vsChipEvents     = $vsRepo->findLatestEmailEventPerCarWithPrecedence($vsQueueCarIds);
        $vsChipUnreadable = false;
    } catch (\Throwable $e) {
        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
            'Verification tab: could not load the queue status chips [%s]: %s',
            get_class($e),
            $e->getMessage()
        ));
    }
}

// ---------------------------------------------------------------------------
// Recent activity. Its own fault domain, with its own window.
// ---------------------------------------------------------------------------
/** @var array<int, object> $vsActivity */
$vsActivity      = [];
$vsActivityError = null;

try {
    $vsActivity = $vsRepo->findRecentVerificationActivity($activityWindowDays, 20);
} catch (\Throwable $e) {
    $vsActivityError = 'Recent activity could not be loaded. Check the system log for details.';
    logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
        'Verification tab: could not load recent activity [%s]: %s',
        get_class($e),
        $e->getMessage()
    ));
}

// Current URL state, so that each link and form keeps the other settings.
$vsUrlState = [
    'tab'             => 'verification',
    'status'          => $queueStatus,
    'window'          => (string) ($queueWindowDays ?? 0),
    'show'            => (string) $queueShowLimit,
    'activity_window' => (string) ($activityWindowDays ?? 0),
];
$vsTabUrl = static fn (array $overrides = []): string
    => 'index.php?' . http_build_query(array_merge($vsUrlState, $overrides));

$vsStatusLabels = [
    'all'        => 'All',
    'eligible'   => 'Eligible',
    'pending'    => 'Pending',
    'bounced'    => 'Bounced',
    'blocked'    => 'Blocked',
    'opted_out'  => 'Opted out',
    'verified'   => 'Verified',
    'sold'       => 'Sold',
];

$vsNow = new \DateTimeImmutable();
?>

<!-- Verification System Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="text-primary mb-1">
            <i class="fas fa-clipboard-check"></i> Verification System
        </h2>
        <p class="text-muted mb-0">Owner car verification: counts, sending, queue, and system status</p>
    </div>
</div>

<?php if ($vsStatus->probeFailed()) { ?>
    <div class="alert alert-danger" role="alert">
        <i class="fas fa-exclamation-circle"></i>
        Verification status could not be read. Check the system log for
        <strong>VerificationConfigWarning</strong> entries.
    </div>
<?php } ?>

<!-- Summary cards. Verified and Sold count cars in the queue Window. Eligible and
     Pending count cars now. Bounced, Blocked and Opted out count owners now, so an
     owner with several cars counts once (#2361). -->
<div class="row g-3 mb-4" id="verificationSummaryCards">
    <?php foreach ([
        'eligible'  => 'eligible',
        'pending'   => 'pending',
        'verified'  => 'verified',
        'sold'      => 'sold',
        'bounced'   => 'bounced_owners',
        'blocked'   => 'blocked_owners',
        'opted_out' => 'opted_out_owners',
    ] as $vsSummaryKey => $vsSummaryCountKey) { ?>
    <div class="col-6 col-md-3 col-xl">
        <div class="er-stat-tile h-100" data-summary="<?= vsEsc($vsSummaryKey) ?>">
            <div class="er-stat-number">
                <?= $vsSummary !== null ? vsEsc(number_format($vsSummary[$vsSummaryCountKey])) : '&mdash;' ?>
            </div>
            <div class="er-stat-label"><?= vsEsc($vsStatusLabels[$vsSummaryKey]) ?></div>
            <?php if ($vsSummaryKey === 'verified' || $vsSummaryKey === 'sold') { ?>
            <div class="er-stat-label"><?= vsEsc(vsWindowLabel($queueWindowDays)) ?></div>
            <?php } elseif ($vsSummaryKey !== $vsSummaryCountKey) { ?>
            <div class="er-stat-label">owners</div>
            <?php } ?>
        </div>
    </div>
    <?php } ?>
</div>

<?php if ($vsSummary === null) { ?>
    <div class="alert alert-danger" role="alert">
        <i class="fas fa-exclamation-circle"></i>
        The summary counts could not be loaded. Check the system log for
        <strong>CarVerification</strong> entries.
    </div>
<?php } ?>

<?php require __DIR__ . '/verification-status-panel.php'; ?>

<!-- Automatic Sending (#1885) -->
<?php
$autoSendBadge     = $vsStatus->autoSendBadge();
$autoSendLastRunAt = $vsStatus->autoSendLastRunAt();
$autoSendNextAt    = $vsStatus->autoSendNextEligibleAt();
$autoSendCounts    = $vsStatus->autoSendCounts();
?>
<div class="card registry-card mb-4<?= $vsStatus->autoSendPaused() ? ' border-warning' : '' ?>">
    <div class="card-header card-header-er-l2">
        <h5 class="mb-0 card-header-er-l2-text">
            <i class="fas fa-robot"></i> Automatic Sending
        </h5>
    </div>
    <div class="card-body">

        <?php if ($vsStatus->autoSendPaused()) { ?>
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
                        last ran <?= vsEsc($autoSendLastRunAt->format(VerificationTabStatus::TIME_FORMAT)) ?>
                    </small>
                <?php } ?>
            </dd>

            <dt class="col-sm-4">Next eligible</dt>
            <dd class="col-sm-8">
                <?php if ($vsStatus->autoSendPaused()) { ?>
                    <span class="text-muted">Not scheduled while automatic sending is paused</span>
                <?php } elseif ($vsStatus->autoSendState() !== CronJobEnabledState::ENABLED) { ?>
                    <span class="text-muted">Unknown &mdash; the job status could not be read</span>
                <?php } elseif ($autoSendNextAt === null) { ?>
                    On the next cron heartbeat (no automatic run yet)
                <?php } else { ?>
                    After <?= vsEsc($autoSendNextAt->format(VerificationTabStatus::TIME_FORMAT)) ?>
                <?php } ?>
                <small class="form-text text-muted d-block mt-1">
                    <i class="fas fa-info-circle"></i>
                    The job runs at most once every
                    <?= vsEsc((string) SendVerificationBatchJob::GUARD_INTERVAL_HOURS) ?> hours. The actual send
                    time depends on when the server's cron heartbeat next fires.
                </small>
            </dd>

            <dt class="col-sm-4">Last run results</dt>
            <dd class="col-sm-8">
                <?php if ($vsStatus->autoSendCountsUnreadable()) { ?>
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
                    <?php if ($vsStatus->autoSendLastRunFailed()) { ?>
                    <!-- These three counts are written only at the end of a
                         successful execute(), so when the most recent claimed
                         run threw they are the PREVIOUS run's numbers — sitting
                         next to a "last ran" timestamp from that failed run.
                         Left unqualified, that combination asserts a clean run
                         that did not happen, which is the compounding half of
                         the bug the failure badge above fixes. -->
                    <div class="text-danger mt-1">
                        <i class="fas fa-triangle-exclamation"></i>
                        From an earlier successful run &mdash; the most recent run failed
                        before it recorded any counts. Check the system log for
                        <strong>CronJobFailure</strong> entries.
                    </div>
                    <?php } ?>
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
             only avoids showing an editor a control their click would reject.
             Send Batch Now is a link, not a POST: it opens the Eligible view,
             which previews the batch before its Send batch button sends it. -->
        <div class="d-flex flex-wrap gap-2 align-items-center">
        <a class="btn btn-outline-primary" href="<?= vsEsc($vsTabUrl(['status' => 'eligible'])) ?>#verificationQueue">
            <i class="fas fa-paper-plane"></i> Send Batch Now
        </a>
        <form action="index.php?tab=verification" method="POST">
            <input type="hidden" name="csrf" value="<?= vsEsc($csrfToken) ?>">
            <input type="hidden" name="command" value="verification_toggle_cron">
            <input type="hidden" name="desired_state" value="<?= vsEsc($vsStatus->autoSendDesiredState()) ?>">
            <?php if ($vsStatus->autoSendDesiredState() === 'disable') { ?>
            <button type="submit" class="btn btn-outline-warning">
                <i class="fas fa-pause"></i> Pause automatic sending
            </button>
            <?php } else { ?>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-play"></i> Resume automatic sending
            </button>
            <?php } ?>
        </form>
        </div>
        <?php } else { ?>
        <p class="text-muted mb-0">
            <i class="fas fa-lock"></i> Administrator access is required to pause or resume automatic sending.
        </p>
        <?php } ?>

    </div>
</div>

<!-- Verification Queue -->
<div class="card registry-card mb-4" id="verificationQueue">
    <div class="card-header card-header-er-l2">
        <h5 class="mb-0 card-header-er-l2-text">
            <i class="fas fa-list-check"></i> Verification Queue
        </h5>
    </div>
    <div class="card-body">

        <!-- Filter pills. Each pill is a GET link, so each view has its own bookmarkable URL. -->
        <div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Filter the verification queue by status">
            <?php foreach (CarRepository::QUEUE_STATUSES as $vsPillStatus) {
                $vsPillActive = $vsPillStatus === $queueStatus;
            ?>
            <a class="btn btn-sm filter-pill <?= $vsPillActive ? 'btn-primary active' : 'btn-outline-secondary' ?>"
               href="<?= vsEsc($vsTabUrl(['status' => $vsPillStatus])) ?>#verificationQueue"
               data-status="<?= vsEsc($vsPillStatus) ?>"
               <?= $vsPillActive ? 'aria-current="page"' : '' ?>>
                <?= vsEsc($vsStatusLabels[$vsPillStatus]) ?>
                <?php if ($vsSummary !== null) { ?>
                <span class="ms-1">(<?= vsEsc(number_format($vsSummary[$vsPillStatus])) ?>)</span>
                <?php } ?>
            </a>
            <?php } ?>
        </div>

        <!-- Window and Show. A plain GET form; the other URL state goes in hidden fields. -->
        <form action="index.php" method="GET" class="row g-2 align-items-center mb-1" id="verificationQueueControls">
            <input type="hidden" name="tab" value="verification">
            <input type="hidden" name="status" value="<?= vsEsc($queueStatus) ?>">
            <input type="hidden" name="activity_window" value="<?= vsEsc($vsUrlState['activity_window']) ?>">
            <div class="col-auto">
                <label class="col-form-label col-form-label-sm" for="verificationQueueWindow">Window</label>
            </div>
            <div class="col-auto">
                <select class="form-select form-select-sm" id="verificationQueueWindow" name="window">
                    <?php foreach (CarRepository::QUEUE_WINDOW_CHOICES as $vsWindowKey => $vsWindowValue) { ?>
                    <option value="<?= vsEsc((string) $vsWindowKey) ?>"<?= $vsWindowValue === $queueWindowDays ? ' selected' : '' ?>>
                        <?= vsEsc(vsWindowLabel($vsWindowValue)) ?>
                    </option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="col-form-label col-form-label-sm" for="verificationQueueShow">Show</label>
            </div>
            <div class="col-auto">
                <select class="form-select form-select-sm" id="verificationQueueShow" name="show">
                    <?php foreach (CarRepository::QUEUE_SHOW_CHOICES as $vsShowValue) { ?>
                    <option value="<?= vsEsc((string) $vsShowValue) ?>"<?= $vsShowValue === $queueShowLimit ? ' selected' : '' ?>>
                        <?= vsEsc((string) $vsShowValue) ?> rows
                    </option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>
            </div>
        </form>
        <small class="form-text text-muted d-block mb-3">
            <i class="fas fa-info-circle"></i>
            Window applies to Verified and Sold (and to their summary cards). The other views show the
            current state. Show does not apply to Eligible, which previews one batch.
        </small>

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

<?php } ?>

<?php if ($vsIsEligibleView) { ?>
        <h6 class="text-primary mb-2"><i class="fas fa-envelope-circle-check"></i> Send Verification Emails</h6>
        <p class="text-muted">
            Review the cars currently due a verification email, then send the batch.
            Nothing is sent until you press <strong>Send batch</strong>.
        </p>
<?php } elseif ($queueStatus === 'pending') { ?>
        <p class="text-muted">Showing <strong>Pending</strong>, longest-waiting first.</p>
<?php } else { ?>
        <p class="text-muted">
            Showing <strong><?= vsEsc($vsStatusLabels[$queueStatus] ?? $vsStatusLabels['all']) ?></strong>,
            up to <?= vsEsc((string) $queueShowLimit) ?> rows.
        </p>
<?php } ?>

<?php if ($vsQueueError !== null) { ?>

        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-circle"></i> <?= vsEsc($vsQueueError) ?>
        </div>

<?php } elseif ($vsQueueRows === []) { ?>

        <div class="alert alert-info" role="alert">
            <i class="fas fa-info-circle"></i>
            <?= $vsIsEligibleView ? 'No cars are currently due a verification email.' : 'No cars match this view.' ?>
        </div>

<?php } else { ?>

        <?php if ($vsIsEligibleView) { ?>
        <p class="text-muted">
            Showing up to the configured batch size (<?= vsEsc((string) $vsBatchSize) ?>)
            of the oldest-verified eligible cars.
        </p>
        <?php } ?>

        <?php if ($vsChipUnreadable) { ?>
        <div class="alert alert-warning" role="alert">
            <i class="fas fa-exclamation-triangle"></i>
            Email status could not be read, so the Status column is unavailable. Check the system log for
            <strong>CarVerification</strong> entries.
        </div>
        <?php } ?>

        <?php if ($vsIsEligibleView) { ?>
        <!-- The batch form wraps the Eligible table, so the previewed rows are the rows sent.
             The per-row action buttons submit their own sibling forms through form=, so
             Send batch is the only submit button in this form with no form= attribute. -->
        <form action="<?= vsEsc($vsTabUrl(['status' => 'eligible'])) ?>" method="POST">
            <input type="hidden" name="csrf" value="<?= vsEsc($csrfToken) ?>">
            <input type="hidden" name="command" value="verification_send_batch">
        <?php } ?>

            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Car</th>
                            <th scope="col">Chassis</th>
                            <th scope="col">Owner</th>
                            <th scope="col">User ID</th>
                            <th scope="col">Sent</th>
                            <th scope="col">Days</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vsQueueRows as $queueCar) {
                        $queueCarId = (int) ($queueCar->id ?? 0);
                        $queueOwnerId = (int) ($queueCar->user_id ?? 0);

                        // cars.fname/cars.lname ARE present in this row (both queue queries
                        // select them, and fname/lname are denormalized onto cars — see
                        // DATABASE.md), but they are a synced copy that can drift from the
                        // authoritative users/profiles values. The owner name shown here is
                        // resolved per row via Owner::data() rather than trusting the
                        // denormalized cars columns, so the Eligible preview matches what the
                        // send actually uses (CarVerificationSendService also loads Owner fresh).
                        // Guarded per row: this loop runs inside an already-open <tbody>, well
                        // past the try/catch that built the row list above — that catch's fault
                        // domain covers only the query that produced the list, not this per-row
                        // lookup. An uncaught throw here would fatal mid-render (unclosed table,
                        // no error shown), so a failure instead logs and falls back to the row's
                        // own denormalized cars.fname/cars.lname rather than aborting the page.
                        // Null when the owner row could not be read, so the action
                        // buttons below have something to gate on even when the
                        // try/catch falls back to the row's denormalized name.
                        $vsOwnerRow = null;
                        try {
                            $vsOwnerRow  = (new Owner((int) $queueCar->user_id))->data();
                            $vsOwnerName = trim(($vsOwnerRow->fname ?? '') . ' ' . ($vsOwnerRow->lname ?? ''));
                        } catch (\Throwable $e) {
                            logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
                                'Verification tab: owner %d could not be loaded for the queue row of car %d [%s]: %s',
                                (int) $queueCar->user_id,
                                $queueCarId,
                                get_class($e),
                                $e->getMessage()
                            ));
                            $vsOwnerName = trim(($queueCar->fname ?? '') . ' ' . ($queueCar->lname ?? ''));
                        }

                        // The `noowner` system account (GDPR-erasure reassignment
                        // target, see CarAdministrationService) and a row with no
                        // live owner at all are not real owners: each row action
                        // acts on the WHOLE
                        // owner (CarVerificationManager::*ForOwner()). A click here
                        // would write the flag on the `noowner` profile row, which
                        // stands for many unrelated cars. Same username check
                        // CarVerificationSendService uses to skip sending to this
                        // account.
                        $queueOwnerIsActionable = $vsOwnerRow !== null
                            && ($vsOwnerRow->username ?? '') !== 'noowner';
                        // Null covers a missing users row and a failed lookup. Neither must read like a GDPR-erasure car.
                        $queueNoActionTitle = $vsOwnerRow === null
                            ? 'The owner record is missing or could not be loaded. No action is available.'
                            : 'This car has no individual owner to act on.';

                        $queueSentAt = !empty($queueCar->vericode_sent_at)
                            ? date_create_immutable((string) $queueCar->vericode_sent_at)
                            : false;
                        $queueDays = $queueSentAt !== false ? (int) $queueSentAt->diff($vsNow)->days : null;

                        // The flags come from the owner's profiles row (#2101). The
                        // Eligible rows are cars.* only: an eligible car has a
                        // profile and no flag that applies, so a missing has_profile
                        // means 1. A block or bounce counts only while it applies to
                        // the owner's current email (the `_active` columns, #2356).
                        //
                        // The stored cause stays in the row when the block does not
                        // apply, so it is read only for an active block.
                        $queueHasProfile  = (int) ($queueCar->has_profile ?? 1) === 1;
                        $queueBounced     = (int) ($queueCar->email_bounced_active ?? 0) === 1;
                        $queueBlocked     = (int) ($queueCar->email_blocked_active ?? 0) === 1;
                        $queueOptedOut    = (int) ($queueCar->email_opted_out ?? 0) === 1;
                        $queueBlockedCause = $queueBlocked && isset($queueCar->email_blocked_cause)
                            ? (string) $queueCar->email_blocked_cause : null;

                        $queueChip = VerificationTabStatus::chip(
                            $vsChipEvents[$queueCarId] ?? null,
                            $queueHasProfile,
                            $queueBlocked,
                            $queueBlockedCause,
                            $queueOptedOut,
                            $queueBounced
                        );

                        // The owner actions write the profiles row, so an owner
                        // with no row has no action until an admin fixes the row.
                        if (!$queueHasProfile) {
                            $queueOwnerIsActionable = false;
                            $queueNoActionTitle = 'This owner has no profile row. Fix the profile before you change email state.';
                        }

                        // One action per row, from the owner's state, in the order Clear Block,
                        // Clear Opt-out, Clear Bounced, Mark Bounced. This is a clear order, not
                        // the chip order (#2461). A blocked or opted-out owner who is not
                        // bounced needs a clear, not Mark Bounced. After Clear Block, the row
                        // shows the next action that still applies. A single soft bounce keeps
                        // the car Pending (Brevo escalates repeated soft bounces), so Mark
                        // Bounced is disabled there. Clear Block and Clear Opt-out need the
                        // owner's request, so they open the confirm modal ('ownerRequest').
                        if ($queueBlocked) {
                            $queueAction = ['command' => 'clear_block', 'label' => 'Clear Block',
                                            'class' => 'btn-outline-secondary', 'disabled' => false,
                                            'ownerRequest' => true];
                        } elseif ($queueOptedOut) {
                            $queueAction = ['command' => 'clear_opt_out', 'label' => 'Clear Opt-out',
                                            'class' => 'btn-outline-secondary', 'disabled' => false,
                                            'ownerRequest' => true];
                        } elseif ($queueBounced) {
                            $queueAction = ['command' => 'clear_bounced', 'label' => 'Clear Bounced',
                                            'class' => 'btn-outline-secondary', 'disabled' => false];
                        } else {
                            $queueAction = ['command' => 'mark_bounced', 'label' => 'Mark Bounced',
                                            'class' => 'btn-outline-danger',
                                            'disabled' => $queueChip['kind'] === 'soft_bounce'];
                        }
                    ?>
                        <tr>
                            <td>
                                <?php if ($vsIsEligibleView) { ?>
                                <input type="hidden" name="car_ids[]" value="<?= vsEsc($queueCarId) ?>">
                                <?php } ?>
                                <?= vsEsc($queueCarId) ?>
                            </td>
                            <td><?= vsEsc($queueCar->chassis ?? '') ?></td>
                            <td><?= vsEsc($vsOwnerName !== '' ? $vsOwnerName : "owner #{$queueCar->user_id}") ?></td>
                            <td>
                                <?php if ($queueOwnerId > 0) { ?>
                                <a href="<?= vsEsc($us_url_root . 'users/admin.php?view=user&id=' . $queueOwnerId) ?>" target="_blank" rel="noopener"><?= vsEsc($queueOwnerId) ?></a>
                                <?php } else { ?>
                                &mdash;
                                <?php } ?>
                            </td>
                            <td><?= $queueSentAt !== false ? vsEsc($queueSentAt->format('Y-m-d')) : '&mdash;' ?></td>
                            <td><?= $queueDays !== null ? vsEsc((string) $queueDays) : '&mdash;' ?></td>
                            <td>
                                <?php if ($vsChipUnreadable) { ?>
                                <span class="text-muted">Unavailable</span>
                                <?php } elseif ($queueChip['kind'] === '') { ?>
                                <span class="text-muted">&mdash;</span>
                                <?php } else { ?>
                                <span class="badge <?= vsEsc($queueChip['class']) ?>" data-chip="<?= vsEsc($queueChip['kind']) ?>">
                                    <?= vsEsc($queueChip['label']) ?>
                                </span>
                                <?php if ($queueChip['reason'] !== '') { ?>
                                <small class="text-muted d-block"><?= vsEsc($queueChip['reason']) ?></small>
                                <?php } ?>
                                <?php } ?>
                            </td>
                            <td>
                                <?php if ($vsCanToggle && $queueOwnerIsActionable) { ?>
                                <!-- Rendered outside the batch form via the form= attribute: nested
                                     forms are invalid HTML and would break the batch submission.
                                     Gated on $vsCanToggle (admin-only) to match this tab's own
                                     "read-only for editors" contract — the server independently
                                     enforces the same hasPerm([2]) check on the POST side, this is
                                     purely so an editor isn't shown controls their click would reject. -->
                                <?php if (!empty($queueAction['ownerRequest'])) { ?>
                                <!-- No name/value: the confirm modal adds the command and
                                     owner_requested=1 to the form (tab-verification.js). -->
                                <button type="button" class="btn btn-sm <?= vsEsc($queueAction['class']) ?>"
                                        data-owner-request-command="<?= vsEsc($queueAction['command']) ?>"
                                        form="owner-action-<?= vsEsc($queueCarId) ?>"><?= vsEsc($queueAction['label']) ?></button>
                                <?php } else { ?>
                                <button type="submit" class="btn btn-sm <?= vsEsc($queueAction['class']) ?>"
                                        name="command" value="<?= vsEsc($queueAction['command']) ?>"
                                        form="owner-action-<?= vsEsc($queueCarId) ?>"
                                        <?= $queueAction['disabled']
                                            ? 'disabled title="One soft bounce does not confirm a dead address."'
                                            : '' ?>><?= vsEsc($queueAction['label']) ?></button>
                                <?php } ?>
                                <?php } elseif ($vsCanToggle) { ?>
                                <!-- No live, non-system owner: each row action acts on the
                                     whole owner, and acting on
                                     `noowner` or a row with no owner would reach every other car
                                     that account holds. -->
                                <span class="text-muted" title="<?= vsEsc($queueNoActionTitle) ?>">&mdash;</span>
                                <?php } else { ?>
                                <span class="text-muted">&mdash;</span>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>

        <?php if ($vsIsEligibleView) { ?>
            <?php if ($vsCanToggle) { ?>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Send batch
            </button>
            <?php } else { ?>
            <p class="text-muted mb-0"><i class="fas fa-lock"></i> Administrator access is required to send.</p>
            <?php } ?>
        </form>
        <?php } ?>

        <?php if ($vsCanToggle) { ?>
        <?php foreach ($vsQueueRows as $queueCar) { ?>
        <form action="<?= vsEsc($vsTabUrl()) ?>" method="POST" id="owner-action-<?= vsEsc((int) ($queueCar->id ?? 0)) ?>">
            <input type="hidden" name="csrf" value="<?= vsEsc($csrfToken) ?>">
            <input type="hidden" name="car_id" value="<?= vsEsc((int) ($queueCar->id ?? 0)) ?>">
        </form>
        <?php } ?>

        <!-- Clear Block and Clear Opt-out confirm step (#2357). The server
             refuses a POST without owner_requested=1. -->
        <div class="modal fade" id="ownerRequestConfirmModal" tabindex="-1" aria-labelledby="ownerRequestConfirmModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="ownerRequestConfirmModalLabel">Owner request</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger mb-0"><?= vsEsc('Confirm that the owner asked for this change.') ?></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="ownerRequestConfirmButton">Confirm</button>
                    </div>
                </div>
            </div>
        </div>
        <?php } ?>

<?php } ?>

    </div>
</div>

<!-- Recent Activity -->
<div class="card registry-card mb-4" id="verificationRecentActivity">
    <div class="card-header card-header-er-l2">
        <h5 class="mb-0 card-header-er-l2-text">
            <i class="fas fa-clock-rotate-left"></i> Recent Activity
        </h5>
    </div>
    <div class="card-body">

        <form action="index.php" method="GET" class="row g-2 align-items-center mb-3" id="verificationActivityControls">
            <input type="hidden" name="tab" value="verification">
            <input type="hidden" name="status" value="<?= vsEsc($queueStatus) ?>">
            <input type="hidden" name="window" value="<?= vsEsc($vsUrlState['window']) ?>">
            <input type="hidden" name="show" value="<?= vsEsc($vsUrlState['show']) ?>">
            <div class="col-auto">
                <label class="col-form-label col-form-label-sm" for="verificationActivityWindow">Window</label>
            </div>
            <div class="col-auto">
                <select class="form-select form-select-sm" id="verificationActivityWindow" name="activity_window">
                    <?php foreach (CarRepository::QUEUE_WINDOW_CHOICES as $vsWindowKey => $vsWindowValue) { ?>
                    <option value="<?= vsEsc((string) $vsWindowKey) ?>"<?= $vsWindowValue === $activityWindowDays ? ' selected' : '' ?>>
                        <?= vsEsc(vsWindowLabel($vsWindowValue)) ?>
                    </option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>
            </div>
            <div class="col-auto">
                <small class="text-muted">Latest 20 VERIFIED and VERIFIED SOLD events</small>
            </div>
        </form>

        <?php if ($vsActivityError !== null) { ?>
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-circle"></i> <?= vsEsc($vsActivityError) ?>
        </div>
        <?php } elseif ($vsActivity === []) { ?>
        <p class="text-muted mb-0">No verification activity in this window.</p>
        <?php } else { ?>
        <ul class="list-unstyled mb-0">
            <?php foreach ($vsActivity as $vsActivityRow) {
                $vsActivityAt   = date_create_immutable((string) ($vsActivityRow->timestamp ?? ''));
                $vsActivityName = trim(($vsActivityRow->fname ?? '') . ' ' . ($vsActivityRow->lname ?? ''));
            ?>
            <li class="mb-1">
                <span class="text-nowrap text-muted">
                    <?= $vsActivityAt !== false
                        ? vsEsc($vsActivityAt->format(VerificationTabStatus::TIME_FORMAT))
                        : vsEsc($vsActivityRow->timestamp ?? '') ?>
                </span>
                &mdash; <strong><?= vsEsc($vsActivityRow->operation ?? '') ?></strong>
                &mdash; car <?= vsEsc((int) ($vsActivityRow->car_id ?? 0)) ?><?= $vsActivityName !== '' ? ', ' . vsEsc($vsActivityName) : '' ?>
            </li>
            <?php } ?>
        </ul>
        <?php } ?>

    </div>
</div>

<script src="<?= $us_url_root ?>app/admin/assets/js/tab-verification.min.js?v=<?= ASSET_VERSION ?>"></script>
