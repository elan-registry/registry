<?php
declare(strict_types=1);

use ElanRegistry\Email\DeliveryLogFilter;
use ElanRegistry\Email\DeliveryLogReport;
use ElanRegistry\Email\DeliveryLogRepository;
use ElanRegistry\Email\DeliveryOutcome;
use ElanRegistry\Exceptions\AdminOperationException;
use ElanRegistry\Exceptions\ExceptionLogSummary;
use ElanRegistry\Input as ElanInput;
use ElanRegistry\LogCategories;

/**
 * tab-email_delivery.php
 * Email Delivery tab for the consolidated admin interface (#2102).
 *
 * Admin only. index.php removes this tab from $validTabs for a non-admin, and
 * this file checks again, so a direct include renders nothing for an editor.
 *
 * Read only: it lists the mail in plg_sendinblue_messages with the outcome of
 * the stored webhook events. It writes nothing and does not call the Brevo API.
 * DeliveryLogFilter and DeliveryLogReport hold the filter, outcome and paging
 * rules. This file only renders them.
 *
 * Included by app/admin/index.php, which has already run init.php and
 * securePage(), and set $currentUserId.
 */

$currentUserId = $currentUserId ?? currentUserId();

if (!hasPerm([2], $currentUserId)) {
    return;
}

$edRawEmail = ElanInput::raw('email');
$edFilter = DeliveryLogFilter::fromInput(
    $edRawEmail,
    ElanInput::raw('tag'),
    ElanInput::raw('outcome'),
    ElanInput::raw('hours'),
    ElanInput::raw('page'),
);
$edEmailIgnored = ($edRawEmail ?? '') !== '' && $edFilter->recipient === null;

$edPluginReady = false;
$edHasTags     = false;
$edReport      = null;
$edLoadError   = null;

try {
    $edRepository  = new DeliveryLogRepository(dbi());
    $edPluginReady = $edRepository->tablesReady();
    if ($edPluginReady) {
        $edHasTags = $edRepository->hasTagsColumn();
        // PHP time, because the plugin writes accepted_at with PHP's date().
        $edNow    = new DateTimeImmutable();
        $edCutoff = $edNow->modify('-' . RETENTION_SENDINBLUE_MESSAGES_DAYS . ' days');
        $edReport = DeliveryLogReport::build(
            $edRepository->loadWindow($edCutoff, $edFilter->recipient, $edHasTags ? $edFilter->tag : null),
            $edFilter,
            $edNow
        );
    }
} catch (AdminOperationException $e) {
    // The summary has no exception message: driver text can echo an address.
    logger($currentUserId, LogCategories::LOG_CATEGORY_DATABASE_ERROR, sprintf(
        'Email delivery tab: could not load the delivery log (outcome=%s, hours=%d, page=%d, recipient filter=%s, tag filter=%s): %s',
        $edFilter->outcome,
        $edFilter->hours,
        $edFilter->page,
        $edFilter->recipient !== null ? 'yes' : 'no',
        $edFilter->tag !== null ? 'yes' : 'no',
        ExceptionLogSummary::of($e)
    ));
    $edLoadError = 'The email delivery log could not be loaded. Check the system log for details.';
}

/**
 * Build the query string of this tab for a page, with the current filters.
 *
 * @param DeliveryLogFilter $filter The current filters
 * @param int               $page   The page to link to
 * @return string The query string, with a leading "?"
 */
$edPageUrl = static function (DeliveryLogFilter $filter, int $page): string {
    return '?' . http_build_query(array_filter([
        'tab'     => 'email-delivery',
        'email'   => $filter->recipient,
        'tag'     => $filter->tag,
        'outcome' => $filter->outcome,
        'hours'   => $filter->hours,
        'page'    => $page,
    ], static fn(string|int|null $value): bool => $value !== null));
};

$edBadgeClass = [
    DeliveryOutcome::Delivered->value     => 'text-bg-success',
    DeliveryOutcome::HardBounce->value    => 'text-bg-danger',
    DeliveryOutcome::SoftBounce->value    => 'text-bg-warning',
    DeliveryOutcome::Blocked->value       => 'text-bg-danger',
    DeliveryOutcome::Unsubscribed->value  => 'text-bg-secondary',
    DeliveryOutcome::SpamComplaint->value => 'text-bg-danger',
    DeliveryOutcome::None->value          => 'text-bg-light border',
];
$edTimeFormat = 'Y-m-d H:i';
?>
<h2 class="text-primary mb-1"><i class="fas fa-envelope-open-text" aria-hidden="true"></i> Email Delivery</h2>
<p class="text-muted">Delivery outcome of each mail sent through Brevo.</p>

<div class="card registry-card mb-4" id="emailDeliveryLog">
    <div class="card-header card-header-er-primary">
        <h5 class="mb-0 card-header-er-primary-text">
            <i class="fas fa-list" aria-hidden="true"></i> Delivery Log
        </h5>
    </div>
    <div class="card-body">

        <p class="mb-2">
            <a href="<?= htmlspecialchars($us_url_root . 'usersc/plugins/sendinblue/delivery_logs.php', ENT_QUOTES, 'UTF-8') ?>"
               target="_blank" rel="noopener" data-testid="email-delivery-plugin-link">Brevo delivery evidence</a>
            <small class="text-muted">(master accounts only). That page can ask Brevo for the current status of one message.</small>
        </p>
        <p class="small text-muted mb-1">
            History is limited to the <code>plg_sendinblue_messages</code> retention window,
            <?= htmlspecialchars((string) RETENTION_SENDINBLUE_MESSAGES_DAYS, ENT_QUOTES, 'UTF-8') ?> days.
        </p>
        <p class="small text-muted mb-3">
            This tab shows only the stored webhook events and does not ask Brevo.
            An event for an address that no single user holds shows with no owner link.
            A mail can show No outcome although Brevo delivered it, when no event was stored
            (for example, the event never arrived, or it arrived before v2.31.1).
        </p>

        <?php if ($edLoadError !== null) { ?>
        <div class="alert alert-danger" role="alert" data-testid="email-delivery-error">
            <i class="fas fa-exclamation-circle" aria-hidden="true"></i> <?= htmlspecialchars($edLoadError, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php } elseif (!$edPluginReady) { ?>
        <div class="alert alert-warning" role="alert" data-testid="email-delivery-plugin-missing">
            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
            The table <code>plg_sendinblue_messages</code> is missing. Install the sendinblue plugin to see the delivery log.
        </div>
        <?php } elseif ($edReport !== null) { ?>

        <form action="index.php" method="GET" class="row g-2 align-items-end mb-3" data-testid="email-delivery-filters">
            <input type="hidden" name="tab" value="email-delivery">
            <div class="col-md-3">
                <label class="form-label small mb-1" for="emailDeliveryRecipient">Recipient email</label>
                <input type="email" class="form-control form-control-sm<?= $edEmailIgnored ? ' is-invalid' : '' ?>" id="emailDeliveryRecipient" name="email"
                       value="<?= htmlspecialchars($edRawEmail ?? '', ENT_QUOTES, 'UTF-8') ?>"<?= $edEmailIgnored ? ' aria-describedby="emailDeliveryRecipientHelp"' : '' ?>>
                <?php if ($edEmailIgnored) { ?>
                <div id="emailDeliveryRecipientHelp" class="invalid-feedback d-block">The recipient email is not a valid address, so the tab ignored it.</div>
                <?php } ?>
            </div>
            <?php if ($edHasTags) { ?>
            <div class="col-md-2">
                <label class="form-label small mb-1" for="emailDeliveryTag">Tag</label>
                <input type="text" class="form-control form-control-sm" id="emailDeliveryTag" name="tag"
                       value="<?= htmlspecialchars($edFilter->tag ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <?php } ?>
            <div class="col-md-3">
                <label class="form-label small mb-1" for="emailDeliveryOutcome">Outcome</label>
                <select class="form-select form-select-sm" id="emailDeliveryOutcome" name="outcome">
                    <option value="<?= htmlspecialchars(DeliveryLogFilter::OUTCOME_ALL, ENT_QUOTES, 'UTF-8') ?>"<?= $edFilter->outcome === DeliveryLogFilter::OUTCOME_ALL ? ' selected' : '' ?>>All</option>
                    <?php foreach (DeliveryOutcome::cases() as $edOutcome) { ?>
                    <option value="<?= htmlspecialchars($edOutcome->value, ENT_QUOTES, 'UTF-8') ?>"<?= $edFilter->outcome === $edOutcome->value ? ' selected' : '' ?>>
                        <?= htmlspecialchars($edOutcome->label(), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php } ?>
                    <option value="<?= htmlspecialchars(DeliveryLogFilter::OUTCOME_STALE, ENT_QUOTES, 'UTF-8') ?>"<?= $edFilter->outcome === DeliveryLogFilter::OUTCOME_STALE ? ' selected' : '' ?>>No outcome after N hours</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1" for="emailDeliveryHours">N (hours)</label>
                <input type="number" class="form-control form-control-sm" id="emailDeliveryHours" name="hours"
                       min="<?= DeliveryLogFilter::HOURS_MIN ?>" max="<?= DeliveryLogFilter::HOURS_MAX ?>" step="1"
                       value="<?= htmlspecialchars((string) $edFilter->hours, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary">Apply filters</button>
            </div>
        </form>

        <p class="small mb-2" data-testid="email-delivery-counts">
            <?php foreach (DeliveryOutcome::cases() as $edOutcome) { ?>
            <span class="me-3"><?= htmlspecialchars($edOutcome->label(), ENT_QUOTES, 'UTF-8') ?>:
                <strong><?= htmlspecialchars((string) $edReport->counts[$edOutcome->value], ENT_QUOTES, 'UTF-8') ?></strong></span>
            <?php } ?>
            <span>Total: <strong><?= htmlspecialchars((string) $edReport->total, ENT_QUOTES, 'UTF-8') ?></strong></span>
        </p>

        <?php if ($edReport->entries === []) { ?>
        <p class="text-muted mb-0" data-testid="email-delivery-empty">No mail matches these filters</p>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle" data-testid="email-delivery-table">
                <caption class="visually-hidden">Mail sent through Brevo, newest first</caption>
                <thead>
                    <tr>
                        <th scope="col">Submitted</th>
                        <th scope="col">Recipient</th>
                        <th scope="col">Owner</th>
                        <th scope="col">Car</th>
                        <?php if ($edHasTags) { ?>
                        <th scope="col">Tags</th>
                        <?php } ?>
                        <th scope="col">Webhook outcome</th>
                        <th scope="col">Outcome time</th>
                        <th scope="col">Reason</th>
                        <th scope="col">Message ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($edReport->entries as $edEntry) { ?>
                    <tr>
                        <td class="text-nowrap"><?= htmlspecialchars($edEntry->acceptedAt->format($edTimeFormat), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($edEntry->recipient, ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($edEntry->userId !== null) { ?>
                            <a href="<?= htmlspecialchars($us_url_root . 'app/admin/index.php?tab=owner-mgmt&owner_id=' . $edEntry->userId, ENT_QUOTES, 'UTF-8') ?>"
                               target="_blank" rel="noopener"><?= htmlspecialchars('owner #' . $edEntry->userId, ENT_QUOTES, 'UTF-8') ?></a>
                            <?php } else { ?>
                            &mdash;
                            <?php } ?>
                        </td>
                        <td>
                            <?php if ($edEntry->carId !== null) { ?>
                            <a href="<?= htmlspecialchars($us_url_root . 'app/owner/cars/details.php?car_id=' . $edEntry->carId, ENT_QUOTES, 'UTF-8') ?>"
                               target="_blank" rel="noopener"><?= htmlspecialchars((string) $edEntry->carId, ENT_QUOTES, 'UTF-8') ?></a>
                            <?php } else { ?>
                            &mdash;
                            <?php } ?>
                        </td>
                        <?php if ($edHasTags) { ?>
                        <td><?= htmlspecialchars($edEntry->tags ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <?php } ?>
                        <td>
                            <span class="badge <?= htmlspecialchars($edBadgeClass[$edEntry->outcome->value], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($edEntry->outcome->label(), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td class="text-nowrap">
                            <?= $edEntry->outcomeAt !== null
                                ? htmlspecialchars($edEntry->outcomeAt->format($edTimeFormat), ENT_QUOTES, 'UTF-8')
                                : '&mdash;' ?>
                        </td>
                        <td><?= htmlspecialchars($edEntry->reason ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><code><?= htmlspecialchars($edEntry->messageId, ENT_QUOTES, 'UTF-8') ?></code></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <?php if ($edReport->pageCount > 1) { ?>
        <nav aria-label="Email delivery pages" data-testid="email-delivery-pager">
            <ul class="pagination pagination-sm mb-0">
                <?php if ($edReport->page <= 1) { ?>
                <li class="page-item disabled"><span class="page-link">Previous</span></li>
                <?php } else { ?>
                <li class="page-item">
                    <a class="page-link" href="<?= htmlspecialchars($edPageUrl($edFilter, $edReport->page - 1), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
                </li>
                <?php } ?>
                <?php for ($edPage = 1; $edPage <= $edReport->pageCount; $edPage++) { ?>
                <li class="page-item<?= $edPage === $edReport->page ? ' active' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars($edPageUrl($edFilter, $edPage), ENT_QUOTES, 'UTF-8') ?>"<?= $edPage === $edReport->page ? ' aria-current="page"' : '' ?>><?= $edPage ?></a>
                </li>
                <?php } ?>
                <?php if ($edReport->page >= $edReport->pageCount) { ?>
                <li class="page-item disabled"><span class="page-link">Next</span></li>
                <?php } else { ?>
                <li class="page-item">
                    <a class="page-link" href="<?= htmlspecialchars($edPageUrl($edFilter, $edReport->page + 1), ENT_QUOTES, 'UTF-8') ?>">Next</a>
                </li>
                <?php } ?>
            </ul>
        </nav>
        <?php } ?>
        <?php } ?>

        <?php } ?>

    </div>
</div>
