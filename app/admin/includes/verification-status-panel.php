<?php
declare(strict_types=1);

use ElanRegistry\Car\VerificationTabStatus;

/*
 * The "System Status & Feature Switch" panel of the Verification tab.
 *
 * tab-verification.php includes it once, directly before the Automatic
 * Sending card. Its place does not depend on health (#2364). It is open
 * when the status is not healthy.
 *
 * Expects from the including scope:
 *   $vsStatus    VerificationTabStatus, from VerificationTabStatus::read()
 *   $vsCanToggle bool, true when the viewer may change the switch (admin)
 *
 * The `disabled` attribute on the switch is a UI aid, not a security control.
 * The toggle endpoint checks permissions on the server.
 */

/** @var VerificationTabStatus $vsStatus */
// Fail closed: without the flag the viewer gets the read-only switch.
$vsCanToggle  = $vsCanToggle ?? false;
$vsHealthy    = $vsStatus->allHealthy();
$vsSwitch     = $vsStatus->featureSwitch($vsCanToggle);
$vsPanelEsc   = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<details class="card registry-card mb-4" id="verificationStatusPanel"<?= $vsHealthy ? '' : ' open' ?>>
    <summary class="card-header card-header-er-l2">
        <h5 class="d-inline mb-0 card-header-er-l2-text">
            <i class="fas fa-heartbeat"></i> System Status &amp; Feature Switch
        </h5>
        <?php if ($vsHealthy) { ?>
        <span class="badge text-bg-success ms-2"><i class="fas fa-check-circle"></i> All healthy</span>
        <?php } else { ?>
        <span class="badge text-bg-warning ms-2"><i class="fas fa-exclamation-triangle"></i> Needs attention</span>
        <?php } ?>
    </summary>
    <div class="card-body">

        <!-- Status -->
        <h6 class="text-primary mb-3"><i class="fas fa-heartbeat"></i> Status</h6>
        <dl class="row mb-4">
            <?php foreach ($vsStatus->rows() as $vsRow) { ?>
            <dt class="col-sm-4"><?= $vsPanelEsc($vsRow['label']) ?></dt>
            <dd class="col-sm-8" data-status-row="<?= $vsPanelEsc($vsRow['key']) ?>">
                <span class="<?= $vsPanelEsc($vsRow['badgeClass']) ?>">
                    <?php if ($vsRow['icon'] !== '') { ?>
                    <i class="fas <?= $vsPanelEsc($vsRow['icon']) ?>"></i>
                    <?php } ?>
                    <?= $vsPanelEsc($vsRow['badgeText']) ?>
                </span>
                <?php if ($vsRow['detail'] !== '') { ?>
                <small class="text-muted ms-1">
                    <?php if ($vsRow['detailIcon'] !== '') { ?>
                    <i class="fas <?= $vsPanelEsc($vsRow['detailIcon']) ?>"></i>
                    <?php } ?>
                    <?= $vsPanelEsc($vsRow['detail']) ?>
                </small>
                <?php } ?>
                <?php if ($vsRow['recent'] !== []) { ?>
                <!-- The count alone says only that something is wrong. The
                     most recent entries tell one broken job from all of them. -->
                <ul class="list-unstyled small mt-2 mb-0">
                    <?php foreach ($vsRow['recent'] as $vsRecentEntry) { ?>
                    <li class="text-muted">
                        <i class="fas fa-triangle-exclamation text-danger"></i>
                        <span class="text-nowrap"><?= $vsPanelEsc($vsRecentEntry['loggedAt']) ?></span>
                        &mdash; <?= $vsPanelEsc($vsRecentEntry['message']) ?>
                    </li>
                    <?php } ?>
                </ul>
                <?php } ?>
            </dd>
            <?php } ?>
        </dl>

        <!-- Toggle control -->
        <h6 class="text-primary mb-3"><i class="fas fa-toggle-on"></i> Feature Switch</h6>

        <div id="verificationToggleFeedback" class="mb-2" role="alert" aria-live="polite"></div>

        <?php if ($vsSwitch['unavailable']) { ?>
        <!-- No switch: its state is unknown, and a toggle with an unknown state is not safe. -->
        <span class="badge text-bg-danger" id="verificationSwitchUnavailable"
              aria-describedby="verificationToggleHelp">
            <i class="fas fa-exclamation-circle"></i>
            Unavailable
        </span>
        <?php } else { ?>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox"
                   id="verificationEnabledSwitch"
                   <?= $vsSwitch['checked'] ? 'checked' : '' ?>
                   <?= $vsSwitch['disabled'] ? 'disabled' : '' ?>
                   <?= $vsSwitch['disabledReason'] !== '' ? 'aria-describedby="verificationToggleHelp"' : '' ?>>
            <label class="form-check-label" for="verificationEnabledSwitch">
                Verification system enabled
            </label>
        </div>
        <?php } ?>

        <?php if ($vsSwitch['disabledReason'] !== '') { ?>
            <small id="verificationToggleHelp" class="form-text text-muted d-block mt-1">
                <i class="fas fa-info-circle"></i>
                <?= $vsPanelEsc($vsSwitch['disabledReason']) ?>
            </small>
        <?php } else { ?>
            <small class="form-text text-muted d-block mt-1">
                <i class="fas fa-info-circle"></i>
                Turning this off hides all verification UI and stops the automatic send batch. It stops
                nothing else. The webhook and both cron jobs (reconciliation and suppression sync) still
                write delivery events and bounce or suppression state. It can always be turned off, even
                while Brevo or cron are unavailable.
            </small>
        <?php } ?>

        <?php // The switch does not stop event writes, so these manual steps are the only way.
        // They show when the switch has no reason to show, which is never true
        // for an editor. A failed probe also shows them to an admin, because
        // the panel cannot tell the real state, and a fault is when they matter.
        if ($vsSwitch['disabledReason'] === '' || ($vsCanToggle && $vsStatus->probeFailed())) { ?>
            <small class="form-text text-muted d-block mt-2"
                   id="verificationIncidentSteps">
                <i class="fas fa-exclamation-triangle"></i>
                To stop event writes in an incident, do all three steps:
            </small>
            <ol class="small text-muted mb-0">
                <li>Set <code>BREVO_WEBHOOK_TOKEN</code> to an empty value. The webhook then refuses every
                    call with 401, and Brevo retries.</li>
                <li>Set <code>er_cron_job_runs.enabled = 0</code> for the reconciliation job and the
                    suppression import. This also stops the retention prune of the reconciliation job.</li>
                <li>Do not use Run Now for the two Brevo jobs, <code>27-Reconcile-Brevo-Events.php</code> or
                    <code>28-Reconcile-Brevo-Suppressions.php</code>. These paths read neither the switch
                    nor <code>enabled</code>.</li>
            </ol>
            <small class="form-text text-muted d-block mt-1">
                After the incident, reconciliation must run within 48 hours of the empty token. The job
                fetches the last 48 hours only.
            </small>
        <?php } ?>

    </div>
</details>
