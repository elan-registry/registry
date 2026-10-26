<?php

declare(strict_types=1);

use ElanRegistry\AccountDeletionPrivacyStep;
use ElanRegistry\Input;
use ElanRegistry\LogCategories;

/**
 * Rerun Deletion Privacy Step (v2.30.10)
 *
 * Runs the account-deletion privacy step again for one deleted user ID. Use it
 * when the UserDeletion log has the CRITICAL "privacy step failed" line for
 * that ID. The step reads the addresses from the user's
 * `er_deleted_user_addresses` holding row, deletes the plugin mail rows, the
 * email events and the profile, then deletes the holding row.
 *
 * {@see AccountDeletionPrivacyStep::run()} refuses an ID that still has a
 * `users` row, because it would then delete a live owner's profile. The page
 * and the log show the user ID and the row counts only, never an address.
 */
require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'app/admin/includes/fix-script-core.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

if (!isAdmin()) {
    logger($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY,
        'Non-admin attempted deletion privacy step rerun script');
    echo '<div class="alert alert-danger mt-3">Administrator access required.</div>';
    exit;
}

$is_exec = admin_script_exec_requested();
$tokenRejected = $method === 'POST' && !$is_exec;

$rawUserId = $is_exec ? (string) Input::raw('user_id') : '';
$targetUserId = filter_var($rawUserId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userIdRejected = $is_exec && $targetUserId === false;

?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">

            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-user-shield"></i> Rerun Deletion Privacy Step
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Deletes the remaining personal data of one deleted user that it can find. Addresses that were only on the user's cars are not found. Run it when the <code>UserDeletion</code> log shows <code>CRITICAL: privacy step failed for user N</code>.</p>

                            <div class="alert alert-primary">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Reads the addresses from the user's <code>er_deleted_user_addresses</code> row, the profile, the email events and the cars</li>
                                    <li>Deletes the user's <code>plg_sendinblue_messages</code> rows, <code>er_email_events</code> rows and <code>profiles</code> row</li>
                                    <li>Deletes the <code>er_deleted_user_addresses</code> row last, in the same transaction</li>
                                    <li>Refuses a user ID that still has a <code>users</code> row</li>
                                    <li>Safe to run again: a second run finds no rows to delete</li>
                                </ul>
                            </div>

                            <?php if ($tokenRejected): ?>
                                <div class="alert alert-danger">
                                    <i class="fa fa-exclamation-triangle"></i> The request was refused: the form token is not valid. Reload the page and try again.
                                </div>
                            <?php endif; ?>

                            <?php if ($userIdRejected): ?>
                                <div class="alert alert-danger">
                                    <i class="fa fa-exclamation-triangle"></i> Enter a user ID that is a whole number of 1 or more.
                                </div>
                            <?php endif; ?>

                            <form method="POST" class="row g-2 justify-content-center align-items-end">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars(Token::generate(), ENT_QUOTES, 'UTF-8') ?>">
                                <div class="col-auto">
                                    <label for="user_id" class="form-label">Deleted user ID</label>
                                    <input type="number" class="form-control" id="user_id" name="user_id" min="1" step="1" required>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-success">
                                        <i class="fa fa-play"></i> Run Privacy Step
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($is_exec && $targetUserId !== false): ?>
                <div class="row mb-4">
                    <div class="col-lg-12">
                        <div class="card registry-card">
                            <div class="card-header">
                                <h2 class="mb-0">
                                    <i class="fa fa-check-circle"></i> Result for user <?= (int) $targetUserId ?>
                                </h2>
                            </div>
                            <div class="card-body">
                                <?php
                                $adminId = (int) $user->data()->id;
                                try {
                                    $result = (new AccountDeletionPrivacyStep(dbi()))->run($targetUserId);

                                    if ($result->refused) {
                                        logger($adminId, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE,
                                            "Deletion privacy step rerun refused for user {$targetUserId}");
                                        ?>
                                        <div class="alert alert-warning mb-0">
                                            <i class="fa fa-ban"></i> Refused: user <?= (int) $targetUserId ?> still has a <code>users</code> row, or the check failed. Nothing was deleted. See the <code>UserDeletion</code> log.
                                        </div>
                                        <?php
                                    } elseif (!$result->committed) {
                                        logger($adminId, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,
                                            "Deletion privacy step rerun rolled back for user {$targetUserId} at stage {$result->failedStage}");
                                        ?>
                                        <div class="alert alert-danger mb-0">
                                            <i class="fa fa-exclamation-triangle"></i> Failed at stage <strong><?= htmlspecialchars((string) $result->failedStage, ENT_QUOTES, 'UTF-8') ?></strong> and rolled back. The personal data remains. See the <code>UserDeletion</code> log for the exception class and code.
                                        </div>
                                        <?php
                                    } else {
                                        logger($adminId, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, sprintf(
                                            'Deletion privacy step rerun committed for user %d: %d er_email_events, %d plg_sendinblue_messages, %d er_deleted_user_addresses rows deleted',
                                            $targetUserId,
                                            $result->deletedEmailEventCount,
                                            $result->deletedPluginMessageCount,
                                            $result->deletedHoldingRowCount
                                        ));
                                        $recordingWarning = null;
                                        admin_script_record_completion(__FILE__, $adminId, function (string $msg) use (&$recordingWarning) {
                                            $recordingWarning = $msg;
                                        });
                                        ?>
                                        <div class="alert alert-success">
                                            <i class="fa fa-check-circle"></i> Committed. Rows deleted:
                                        </div>
                                        <table class="table table-sm mb-0">
                                            <tbody>
                                                <tr><th scope="row"><code>plg_sendinblue_messages</code></th><td><?= (int) $result->deletedPluginMessageCount ?></td></tr>
                                                <tr><th scope="row"><code>er_email_events</code></th><td><?= (int) $result->deletedEmailEventCount ?></td></tr>
                                                <tr><th scope="row"><code>er_deleted_user_addresses</code></th><td><?= (int) $result->deletedHoldingRowCount ?></td></tr>
                                            </tbody>
                                        </table>
                                        <div class="alert alert-primary mt-2 mb-0">
                                            <i class="fa fa-info-circle"></i> Addresses that were only on the deleted user's cars cannot be found after the car cleanup, so their <code>plg_sendinblue_messages</code> rows can remain. The daily retention purge deletes those rows once they are older than 90 days.
                                        </div>
                                        <?php // With the trigger in place, a failed step leaves a holding row. None means a wrong ID, a second run, or a missing trigger. ?>
                                        <?php if ($result->deletedHoldingRowCount === 0): ?>
                                            <div class="alert alert-warning mt-2 mb-0">
                                                No holding row was found for user <?= (int) $targetUserId ?>. This ID may never have had a failed privacy step. Check the ID in the CRITICAL log line.
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($result->pluginTableAbsent): ?>
                                            <div class="alert alert-primary mt-2 mb-0">
                                                <code>plg_sendinblue_messages</code> does not exist on this site, so no plugin rows were deleted.
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($recordingWarning !== null): ?>
                                            <div class="alert alert-warning mt-2 mb-0">
                                                <?= htmlspecialchars($recordingWarning, ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php
                                    }
                                } catch (\Throwable $e) {
                                    // The exception message can contain an address, so only the class and code go to the log and the page.
                                    logger($adminId, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR, sprintf(
                                        'Deletion privacy step rerun failed for user %d: %s (code %s)',
                                        $targetUserId,
                                        get_class($e),
                                        (string) $e->getCode()
                                    ));
                                    ?>
                                    <div class="alert alert-danger mb-0">
                                        <i class="fa fa-exclamation-triangle"></i> The rerun failed: <?= htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8') ?>. See the log.
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
