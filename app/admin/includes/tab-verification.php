<?php
declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailNoticeBuilder;
use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\Cron\BrevoEventReconciliationJob;
use ElanRegistry\Cron\BrevoSuppressionSyncJob;
use ElanRegistry\Cron\CronJobEnabledState;
use ElanRegistry\Cron\CronJobFailureLogReader;
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
 *
 * Layout, top to bottom: summary cards, Automatic Sending, the verification
 * queue, recent activity. The Status / Feature Switch panel is at the top and
 * open when any probe reports a problem or the switch is off. When all is
 * healthy it is at the bottom and closed.
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

// Only the Eligible queue view shows this preview, so other views skip the query.
if ($vsIsEligibleView && $verificationSendSvc !== null && isset($vsSettings)) {
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
} elseif ($vsIsEligibleView) {
    // Without the send service or the settings there is no preview. Say so,
    // so that an empty list does not read as "no cars are due".
    $vsEligibleError = 'The list of eligible cars could not be loaded. Check the system log for details.';
}

// ---------------------------------------------------------------------------
// Reconciliation status probe. This is a distinct fault domain from the
// VerificationSettings probe above — kept in its own try/catch so a failure
// here is logged and rendered independently, not folded into $vsProbeFailed.
// ---------------------------------------------------------------------------
$reconciliationState = CronJobEnabledState::UNREADABLE;
$reconciliationLastRunAt = null;
$reconciliationLastFailureAt = null;

try {
    $cronJobRunsReader = new CronJobRunsReader(dbi());
    $reconciliationStatus = $cronJobRunsReader->status(BrevoEventReconciliationJob::JOB_NAME);
    $reconciliationState = $reconciliationStatus['state'];
    $reconciliationLastRunAt = $reconciliationStatus['lastRunAt'];
    $reconciliationLastFailureAt = $reconciliationStatus['lastFailureAt'];
} catch (\Throwable $e) {
    logger($currentUserId, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
        'Reconciliation status probe failed: ' . $e->getMessage());
}

$reconciliationBadge = CronJobRunsReader::badgeFor(
    $reconciliationState,
    $reconciliationLastRunAt,
    $reconciliationLastFailureAt
);
$reconciliationBadgeClass = $reconciliationBadge['badgeClass'];
$reconciliationBadgeIcon = $reconciliationBadge['icon'];
$reconciliationBadgeText = $reconciliationBadge['text'];

// ---------------------------------------------------------------------------
// Suppression sync status probe. Its own fault domain again, for the same
// reason as the reconciliation probe above: this is a different job's row and
// a failure to read it must not blank out the reconciliation section above.
//
// $cronJobRunsReader is reused when the reconciliation probe above managed to
// construct it; a separate construction here would be a second connection for
// the same never-throwing reader.
// ---------------------------------------------------------------------------
$suppressionSyncState = CronJobEnabledState::UNREADABLE;
$suppressionSyncLastRunAt = null;
$suppressionSyncLastFailureAt = null;

try {
    $suppressionSyncReader = $cronJobRunsReader ?? new CronJobRunsReader(dbi());
    $suppressionSyncStatus = $suppressionSyncReader->status(BrevoSuppressionSyncJob::JOB_NAME);
    $suppressionSyncState = $suppressionSyncStatus['state'];
    $suppressionSyncLastRunAt = $suppressionSyncStatus['lastRunAt'];
    $suppressionSyncLastFailureAt = $suppressionSyncStatus['lastFailureAt'];
} catch (\Throwable $e) {
    logger($currentUserId, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
        'Suppression sync status probe failed: ' . $e->getMessage());
}

$suppressionSyncBadge = CronJobRunsReader::badgeFor(
    $suppressionSyncState,
    $suppressionSyncLastRunAt,
    $suppressionSyncLastFailureAt
);
$suppressionSyncBadgeClass = $suppressionSyncBadge['badgeClass'];
$suppressionSyncBadgeIcon = $suppressionSyncBadge['icon'];
$suppressionSyncBadgeText = $suppressionSyncBadge['text'];

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
$autoSendLastFailureAt = null;
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
    $autoSendLastFailureAt = $autoSendStatus['lastFailureAt'];
    $autoSendOutcome = $autoSendReader->lastOutcomeCounts(SendVerificationBatchJob::JOB_NAME);
    $autoSendCounts = $autoSendOutcome['counts'];
    $autoSendCountsUnreadable = $autoSendOutcome['unreadable'];
} catch (\Throwable $e) {
    logger($currentUserId, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
        'Automatic verification send status probe failed: ' . $e->getMessage());
}

$autoSendBadge = CronJobRunsReader::badgeFor($autoSendState, $autoSendLastRunAt, $autoSendLastFailureAt);
$autoSendPaused = $autoSendState === CronJobEnabledState::DISABLED;

// True when the most recent claimed run is the one that threw. The counts
// below are written only at the END of a successful execute(), so in this
// state they are the previous successful run's numbers standing next to a
// "last ran" timestamp minutes old — the precise combination that reads as a
// clean run that never happened. The render branches on this to say so rather
// than silently showing stale figures.
$autoSendLastRunFailed = $autoSendState === CronJobEnabledState::ENABLED
    && $autoSendLastFailureAt !== null
    && ($autoSendLastRunAt === null || $autoSendLastFailureAt >= $autoSendLastRunAt);

// ---------------------------------------------------------------------------
// Cron failure log summary. Its own fault domain once more, and its own
// never-throwing reader: LOG_CATEGORY_CRON_JOB_FAILURE was written from five
// places across this subsystem and read from none, so several of its own
// messages told an operator to "check the system log" with nothing on this
// page pointing at it. The per-job badges above report only each job's most
// recent run; this reports the fault channel as a whole, including faults from
// jobs whose bookkeeping row could not be read at all.
//
// A null count means the summary itself could not be read — rendered as an
// explicit "unavailable", never as zero. Defaults to null so a throw here,
// which never reaches the reader's own handling, reports the fault it is.
// ---------------------------------------------------------------------------
/** @var int|null $cronFailureCount */
$cronFailureCount = null;
/** @var list<array{loggedAt: string, message: string}> $cronFailureRecent */
$cronFailureRecent = [];

try {
    $cronFailureSummary = (new CronJobFailureLogReader(dbi()))->recentFailures();
    $cronFailureCount = $cronFailureSummary['count'];
    $cronFailureRecent = $cronFailureSummary['recent'];
} catch (\Throwable $e) {
    logger($currentUserId, LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
        'Cron failure log summary probe failed: ' . $e->getMessage());
}

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

if (!function_exists('vsStatusChip')) {
    /**
     * Give the Status chip for one queue row
     *
     * The chip shows the car's latest email event, as chosen by
     * CarRepository::findLatestEmailEventPerCarWithPrecedence(). A
     * suppressed car instead shows its suppression cause, from
     * EmailNoticeBuilder::resolveSuppressionCause(), so the chip and the
     * owner's account notice cannot disagree. That rule uses the latest
     * suppression event of any send cycle. A current `spam` event shows
     * Spam complaint. Any other cause shows Opted out.
     *
     * @param object|null $event The chip event row, or null if the car has none
     * @param bool $suppressed True when the car or its owner profile is suppressed
     * @param object|null $suppressionEvent The car's latest suppression event of any send cycle, if read
     * @param object|null $suppressedHist The latest `EMAIL SUPPRESSED` cars_hist row, if read
     * @return array{kind: string, label: string, class: string, reason: string}
     *         `kind` is '' when there is no event to show
     */
    function vsStatusChip(?object $event, bool $suppressed, ?object $suppressionEvent, ?object $suppressedHist): array
    {
        $name   = isset($event->event) ? (string) $event->event : null;
        $reason = trim((string) ($event->reason ?? ''));

        $optedOut = ['kind' => 'opted_out', 'label' => 'Opted out', 'class' => 'text-bg-secondary', 'reason' => ''];
        $spam     = ['kind' => 'spam', 'label' => 'Spam complaint', 'class' => 'text-bg-danger', 'reason' => ''];

        if ($suppressed) {
            $cause = EmailNoticeBuilder::resolveSuppressionCause($suppressionEvent, $suppressedHist);

            return $cause['cause'] === EmailNoticeBuilder::CAUSE_BREVO_COMPLAINT
                && ($suppressionEvent->event ?? null) === 'spam'
                ? $spam : $optedOut;
        }

        if ($name === null) {
            return ['kind' => '', 'label' => '', 'class' => '', 'reason' => ''];
        }
        if (in_array($name, EmailEventApplier::HARD_BOUNCE_EVENTS, true)) {
            return ['kind' => 'bounced', 'label' => 'Bounced', 'class' => 'text-bg-danger', 'reason' => $reason];
        }

        return match ($name) {
            'sent'         => ['kind' => 'sent', 'label' => 'Sent', 'class' => 'text-bg-secondary', 'reason' => ''],
            // An open or a click proves the message arrived.
            'delivered', 'unique_opened', 'opened', 'click'
                           => ['kind' => 'delivered', 'label' => 'Delivered', 'class' => 'text-bg-success', 'reason' => ''],
            'soft_bounce'  => ['kind' => 'soft_bounce', 'label' => 'Soft bounce', 'class' => 'text-bg-warning', 'reason' => $reason],
            'spam'         => $spam,
            'unsubscribed' => $optedOut,
            // Brevo can add event names. Show the stored name, not a guess.
            default        => ['kind' => 'other', 'label' => ucfirst(str_replace('_', ' ', $name)),
                               'class' => 'text-bg-light', 'reason' => $reason],
        };
    }
}

// ---------------------------------------------------------------------------
// Dashboard summary counts. Their own fault domain: a failure here shows
// "unavailable" on the cards and pills, and the sections below still render.
// $vsRepo is reused by the probes below when this construction succeeds.
// ---------------------------------------------------------------------------
/** @var array{all: int, eligible: int, pending: int, bounced: int, suppressed: int, verified: int, sold: int}|null $vsSummary */
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
// reason as $autoSendCountsUnreadable below.
// ---------------------------------------------------------------------------
$vsQueueCarIds           = [];
$vsQueueSuppressedCarIds = [];
foreach ($vsQueueRows as $vsQueueRow) {
    $vsQueueRowId = (int) ($vsQueueRow->id ?? 0);
    if ($vsQueueRowId > 0) {
        $vsQueueCarIds[] = $vsQueueRowId;
        if ((int) ($vsQueueRow->email_suppressed ?? 0) === 1
            || (int) ($vsQueueRow->profile_email_suppressed ?? 0) === 1) {
            $vsQueueSuppressedCarIds[] = $vsQueueRowId;
        }
    }
}

/** @var array<int, object> $vsChipEvents */
$vsChipEvents = [];
/** @var array<int, object> $vsSuppressionEvents */
$vsSuppressionEvents = [];
/** @var array<int, object> $vsSuppressedHist */
$vsSuppressedHist = [];
$vsChipUnreadable = $vsQueueCarIds !== [];

if ($vsQueueCarIds !== []) {
    try {
        $vsChipEvents = $vsRepo->findLatestEmailEventPerCarWithPrecedence($vsQueueCarIds);

        // A suppressed car's chip shows its cause. It uses the same reads as
        // EmailNoticeBuilder: the latest suppression event of any send cycle,
        // and the latest EMAIL SUPPRESSED history row.
        if ($vsQueueSuppressedCarIds !== []) {
            $vsSuppressionEvents = $vsRepo->findLatestEmailEventsByCarIdsAndEvents(
                $vsQueueSuppressedCarIds,
                EmailEventApplier::SUPPRESSION_EVENTS
            );
            $vsSuppressedHist = $vsRepo->findLatestHistoryOperationByCarIds(
                $vsQueueSuppressedCarIds,
                [EmailNoticeBuilder::OPERATION_SUPPRESSED]
            );
        }
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

// ---------------------------------------------------------------------------
// Next-eligible time for automatic sending: the guard declines a run until
// GUARD_INTERVAL_HOURS have passed since the last claimed run. The real send
// time is the first cron heartbeat after that.
// ---------------------------------------------------------------------------
$autoSendNextEligibleAt = $autoSendLastRunAt?->add(
    new \DateInterval('PT' . SendVerificationBatchJob::GUARD_INTERVAL_HOURS . 'H')
);

// ---------------------------------------------------------------------------
// One healthy flag for the Status panel's place on the page, from the probe
// results above. An unreadable probe is not healthy: an unreadable unmatched
// counter keeps its initial 0, and an unreadable failure log is null.
// ---------------------------------------------------------------------------
$vsAllHealthy = !$vsProbeFailed
    && $vsEnabled
    && $vsBrevoReady
    && $vsCronReady
    && !$vsUnmatchedUnreadable
    && $vsUnmatchedCount === 0
    && $cronFailureCount === 0;

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
    'suppressed' => 'Suppressed',
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

<?php if ($vsProbeFailed) { ?>
    <div class="alert alert-danger" role="alert">
        <i class="fas fa-exclamation-circle"></i>
        Verification status could not be read. Check the system log for
        <strong>VerificationConfigWarning</strong> entries.
    </div>
<?php } ?>

<?php
// The Status panel is rendered once into a buffer, then output at the top or
// the bottom of the tab. See $vsAllHealthy.
ob_start();
?>
<details class="card registry-card mb-4" id="verificationStatusPanel"<?= $vsAllHealthy ? '' : ' open' ?>>
    <summary class="card-header card-header-er-l2">
        <h5 class="d-inline mb-0 card-header-er-l2-text">
            <i class="fas fa-heartbeat"></i> System Status &amp; Feature Switch
        </h5>
        <?php if ($vsAllHealthy) { ?>
        <span class="badge text-bg-success ms-2"><i class="fas fa-check-circle"></i> All healthy</span>
        <?php } else { ?>
        <span class="badge text-bg-warning ms-2"><i class="fas fa-exclamation-triangle"></i> Needs attention</span>
        <?php } ?>
    </summary>
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

            <dt class="col-sm-4">Last suppression sync run</dt>
            <dd class="col-sm-8">
                <span class="<?= htmlspecialchars($suppressionSyncBadgeClass, ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fas <?= htmlspecialchars($suppressionSyncBadgeIcon, ENT_QUOTES, 'UTF-8') ?>"></i>
                    <?= htmlspecialchars($suppressionSyncBadgeText, ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php if ($suppressionSyncLastRunAt !== null) { ?>
                    <small class="text-muted ms-1">
                        <i class="fas fa-clock"></i>
                        last ran
                        <?= htmlspecialchars($suppressionSyncLastRunAt->format('M j, Y g:i A'), ENT_QUOTES, 'UTF-8') ?>
                    </small>
                <?php } ?>
            </dd>

            <dt class="col-sm-4">Cron job failures</dt>
            <dd class="col-sm-8">
                <?php if ($cronFailureCount === null) { ?>
                    <!-- The summary read failed. Rendered as a danger badge,
                         never as a zero: this is the fault channel itself, and
                         "no failures" is the one reading that tells an admin
                         to stop looking. -->
                    <span class="badge text-bg-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        Unavailable
                    </span>
                    <small class="text-muted ms-1">
                        The cron failure log could not be read. Check the system log for
                        <strong>CronJobFailure</strong> entries directly.
                    </small>
                <?php } else { ?>
                    <span class="badge <?= $cronFailureCount > 0 ? 'text-bg-danger' : 'text-bg-success' ?>">
                        <?= vsEsc((string) $cronFailureCount) ?>
                    </span>
                    <small class="text-muted ms-1">
                        <strong>CronJobFailure</strong> log entries in the last
                        <?= vsEsc((string) CronJobFailureLogReader::LOOKBACK_DAYS) ?> days, across every
                        verification cron job. A repeating count here means an unattended job is
                        failing every night — the per-job badges above only report each job's most
                        recent run.
                    </small>
                    <?php if ($cronFailureRecent !== []) { ?>
                    <!-- The count alone says only that something is wrong. The
                         most recent few entries are enough to tell one broken
                         job from all of them before going to the full log. -->
                    <ul class="list-unstyled small mt-2 mb-0">
                        <?php foreach ($cronFailureRecent as $cronFailureEntry) { ?>
                        <li class="text-muted">
                            <i class="fas fa-triangle-exclamation text-danger"></i>
                            <span class="text-nowrap"><?= vsEsc($cronFailureEntry['loggedAt']) ?></span>
                            &mdash; <?= vsEsc($cronFailureEntry['message']) ?>
                        </li>
                        <?php } ?>
                    </ul>
                    <?php } ?>
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
</details>
<?php
$vsStatusPanelHtml = (string) ob_get_clean();

if (!$vsAllHealthy) {
    echo $vsStatusPanelHtml;
}
?>

<!-- Summary cards. Verified and Sold use the queue Window; the others are current state. -->
<div class="row g-3 mb-4" id="verificationSummaryCards">
    <?php foreach (['eligible', 'pending', 'verified', 'sold', 'bounced', 'suppressed'] as $vsSummaryKey) { ?>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="er-stat-tile h-100" data-summary="<?= vsEsc($vsSummaryKey) ?>">
            <div class="er-stat-number">
                <?= $vsSummary !== null ? vsEsc(number_format($vsSummary[$vsSummaryKey])) : '&mdash;' ?>
            </div>
            <div class="er-stat-label"><?= vsEsc($vsStatusLabels[$vsSummaryKey]) ?></div>
            <?php if ($vsSummaryKey === 'verified' || $vsSummaryKey === 'sold') { ?>
            <div class="er-stat-label"><?= vsEsc(vsWindowLabel($queueWindowDays)) ?></div>
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

<!-- Automatic Sending (#1885) -->
<div class="card registry-card mb-4<?= $autoSendPaused ? ' border-warning' : '' ?>">
    <div class="card-header card-header-er-l2">
        <h5 class="mb-0 card-header-er-l2-text">
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

            <dt class="col-sm-4">Next eligible</dt>
            <dd class="col-sm-8">
                <?php if ($autoSendPaused) { ?>
                    <span class="text-muted">Not scheduled while automatic sending is paused</span>
                <?php } elseif ($autoSendState !== CronJobEnabledState::ENABLED) { ?>
                    <span class="text-muted">Unknown &mdash; the job status could not be read</span>
                <?php } elseif ($autoSendNextEligibleAt === null) { ?>
                    On the next cron heartbeat (no automatic run yet)
                <?php } else { ?>
                    After <?= vsEsc($autoSendNextEligibleAt->format('M j, Y g:i A')) ?>
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
                    <?php if ($autoSendLastRunFailed) { ?>
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
            Showing <strong><?= vsEsc($vsStatusLabels[$queueStatus] ?? $queueStatus) ?></strong>,
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
                            <th scope="col">Sent</th>
                            <th scope="col">Days</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vsQueueRows as $queueCar) {
                        $queueCarId = (int) ($queueCar->id ?? 0);

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

                        $queueSentAt = !empty($queueCar->vericode_sent_at)
                            ? date_create_immutable((string) $queueCar->vericode_sent_at)
                            : false;
                        $queueDays = $queueSentAt !== false ? (int) $queueSentAt->diff($vsNow)->days : null;

                        $queueBounced    = (int) ($queueCar->email_bounced ?? 0) === 1;
                        $queueSuppressed = (int) ($queueCar->email_suppressed ?? 0) === 1
                            || (int) ($queueCar->profile_email_suppressed ?? 0) === 1;

                        $queueChip = vsStatusChip(
                            $vsChipEvents[$queueCarId] ?? null,
                            $queueSuppressed,
                            $vsSuppressionEvents[$queueCarId] ?? null,
                            $vsSuppressedHist[$queueCarId] ?? null
                        );

                        // One action per row, from the car's state. Suppressed comes first:
                        // a suppressed car that is not bounced needs Clear Suppression, not
                        // Mark Bounced. A single soft bounce keeps the car Pending (Brevo
                        // escalates repeated soft bounces), so Mark Bounced is disabled there.
                        if ($queueSuppressed) {
                            $queueAction = ['command' => 'clear_suppression', 'label' => 'Clear Suppression',
                                            'class' => 'btn-outline-secondary', 'disabled' => false];
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
                                <?php if ($vsCanToggle) { ?>
                                <!-- Rendered outside the batch form via the form= attribute: nested
                                     forms are invalid HTML and would break the batch submission.
                                     Gated on $vsCanToggle (admin-only) to match this tab's own
                                     "read-only for editors" contract — the server independently
                                     enforces the same hasPerm([2]) check on the POST side, this is
                                     purely so an editor isn't shown controls their click would reject. -->
                                <button type="submit" class="btn btn-sm <?= vsEsc($queueAction['class']) ?>"
                                        name="command" value="<?= vsEsc($queueAction['command']) ?>"
                                        form="owner-action-<?= vsEsc($queueCarId) ?>"
                                        <?= $queueAction['disabled']
                                            ? 'disabled title="One soft bounce does not confirm a dead address."'
                                            : '' ?>><?= vsEsc($queueAction['label']) ?></button>
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
                        ? vsEsc($vsActivityAt->format('M j, Y g:i A'))
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

<?php
if ($vsAllHealthy) {
    echo $vsStatusPanelHtml;
}
?>

<script src="<?= $us_url_root ?>app/admin/assets/js/tab-verification.min.js?v=<?= ASSET_VERSION ?>"></script>
