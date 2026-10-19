<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;
use ElanRegistry\Migration\EmailEventRekeyQueries;

/**
 * Email Event Re-key Report (v2.30.8)
 *
 * Read-only preview of migration 20261013000000_key_er_email_events_by_user.
 * It shows the count and the event IDs for each of steps (b) to (d): rows
 * matched by car owner, rows matched by address, rows dropped and rows
 * collapsed. The migration and this report both use
 * {@see EmailEventRekeyQueries}, so the migration does what this report shows.
 *
 * Run it before the deploy, on a copy of production data. Production does not
 * have this script until the deploy. See DEPLOYMENT.md. After the migration
 * ran, it shows "already migrated" and no counts.
 *
 * The script writes nothing: no data change, no completion record and no log
 * line on success. It names event IDs only, never an address.
 *
 * Issue #2358.
 */
require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'app/admin/includes/fix-script-core.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

if (!isAdmin()) {
    logger($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY,
        'Non-admin attempted email event re-key report script');
    echo '<div class="alert alert-danger mt-3">Administrator access required.</div>';
    exit;
}

/**
 * Join a list of event IDs into one line of text, or "none".
 *
 * The result is raw text. Each caller escapes it for HTML.
 *
 * @param list<int> $ids
 */
function rekeyReportIdList(array $ids): string
{
    return $ids === [] ? 'none' : implode(', ', array_map('intval', $ids));
}

?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">
            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-table"></i> Email Event Re-key Report
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Shows what migration <code>20261013000000_key_er_email_events_by_user</code> does to <code>er_email_events</code>. This report changes nothing.</p>
                            <?php
                            try {
                                $rekeyQueries = new EmailEventRekeyQueries(dbi());

                                if ($rekeyQueries->isRekeyed()) {
                                    ?>
                                    <div class="alert alert-info mb-0">
                                        <i class="fa fa-info-circle"></i> Already migrated: <code>er_email_events</code> is keyed by user. This report has no counts to show.
                                    </div>
                                    <?php
                                } else {
                                    $ownerIds = $rekeyQueries->ownerMatchedIds();
                                    $addressIds = $rekeyQueries->addressMatchedIds();
                                    $droppedIds = $rekeyQueries->droppedIds();
                                    $groups = $rekeyQueries->collapseGroups();
                                    $counts = $rekeyQueries->reportCounts();
                                    ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped mb-4">
                                            <thead>
                                                <tr>
                                                    <th scope="col">Step</th>
                                                    <th scope="col" class="text-end">Rows</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr><td>(b) Matched by car owner</td><td class="text-end"><?= $counts['owner_matched'] ?></td></tr>
                                                <tr><td>(b) Matched by address</td><td class="text-end"><?= $counts['address_matched'] ?></td></tr>
                                                <tr><td>(c) Dropped (no user, or <code>noowner</code>)</td><td class="text-end"><?= $counts['dropped'] ?></td></tr>
                                                <tr><td>(d) Collapsed (rows removed, in <?= $counts['collapse_groups'] ?> group(s))</td><td class="text-end"><?= $counts['collapsed_removed'] ?></td></tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h5><i class="fa fa-hashtag"></i> Event IDs</h5>
                                    <p class="mb-1"><strong>Matched by car owner:</strong></p>
                                    <pre class="small"><?= htmlspecialchars(rekeyReportIdList($ownerIds), ENT_QUOTES, 'UTF-8') ?></pre>
                                    <p class="mb-1"><strong>Matched by address:</strong></p>
                                    <pre class="small"><?= htmlspecialchars(rekeyReportIdList($addressIds), ENT_QUOTES, 'UTF-8') ?></pre>
                                    <p class="mb-1"><strong>Dropped:</strong></p>
                                    <pre class="small"><?= htmlspecialchars(rekeyReportIdList($droppedIds), ENT_QUOTES, 'UTF-8') ?></pre>
                                    <p class="mb-1"><strong>Collapsed:</strong></p>
                                    <?php if ($groups === []): ?>
                                    <pre class="small mb-0">none</pre>
                                    <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped mb-0">
                                            <thead>
                                                <tr>
                                                    <th scope="col">Kept ID</th>
                                                    <th scope="col">Removed IDs</th>
                                                    <th scope="col">Earliest occurred_at</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($groups as $group): ?>
                                                <tr>
                                                    <td><?= (int) $group['kept_id'] ?></td>
                                                    <td><?= htmlspecialchars(rekeyReportIdList($group['removed_ids']), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars($group['occurred_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php endif; ?>
                                    <?php
                                }
                            } catch (\Throwable $e) {
                                logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR, sprintf(
                                    'Email event re-key report failed: %s: %s',
                                    get_class($e),
                                    $e->getMessage()
                                ));
                                ?>
                                <div class="alert alert-danger mb-0">
                                    <i class="fa fa-exclamation-triangle"></i> Report failed: <?= htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div> <!-- well -->
    </div><!-- Container -->
</div> <!-- page-wrapper -->

<!-- Return to Admin Console button -->
<div style="margin-top: 20px; text-align: center;">
    <?= admin_script_close_button() ?>
</div>

<!-- footers -->
<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; //custom template footer ?>
