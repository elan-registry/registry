<?php
declare(strict_types=1);

use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\Cron\BrevoEventReconciliationJob;
use ElanRegistry\Cron\CronJobEnabledState;
use ElanRegistry\Cron\CronJobRunsReader;
use ElanRegistry\LogCategories;

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

// ---------------------------------------------------------------------------
// Readiness probes. VerificationSettings never throws from its probes, but a
// construction or connection failure must not take the whole admin page down.
// ---------------------------------------------------------------------------
$vsEnabled     = false;
$vsBrevoReady  = false;
$vsCronReady   = false;
$vsLastCronAt  = null;
$vsProbeFailed = false;

try {
    $vsSettings   = new VerificationSettings(dbi());
    $vsEnabled    = $vsSettings->isEnabled();
    $vsBrevoReady = $vsSettings->brevoReady();
    $vsCronReady  = $vsSettings->cronReady();
    $vsLastCronAt = $vsSettings->lastCronRequestAt();
} catch (\Throwable $e) {
    $vsProbeFailed = true;
    logger($currentUserId, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
        'Verification tab status probe failed: ' . $e->getMessage());
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

            <dt class="col-sm-4">Last webhook received</dt>
            <dd class="col-sm-8">
                <?php if ($vsEnabled) { ?>
                    <span class="badge text-bg-warning">
                        <i class="fas fa-exclamation-triangle"></i> Events discarded
                    </span>
                    <small class="text-muted ms-1">
                        The receiver is a placeholder until #1887 — bounces and unsubscribes
                        are not recorded.
                    </small>
                <?php } else { ?>
                    <span class="text-muted">Not yet implemented (#1887)</span>
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
                Turning this off hides all verification UI and stops reminder emails. It can always be turned off,
                even while Brevo or cron are unavailable.
            </small>
        <?php } ?>

    </div>
</div>

<script src="<?= $us_url_root ?>app/admin/assets/js/tab-verification.min.js?v=<?= ASSET_VERSION ?>"></script>
