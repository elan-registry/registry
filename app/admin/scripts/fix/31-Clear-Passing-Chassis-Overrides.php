<?php

declare(strict_types=1);

use ElanRegistry\Car\ChassisOverrideNote;
use ElanRegistry\ChassisValidator;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\AdminOperationException;
use ElanRegistry\LogCategories;

/**
 * Clear Passing Chassis Overrides Script
 *
 * Administrative script to clear the chassis override on cars whose chassis
 * now passes validation.
 * Issue #2613: 1972-1974 chassis numbers with 9 characters (YYMMXXXXC) failed
 * validation, so admins saved them with an override.
 *
 * The script selects each car with `chassis_override = 1` or any comment
 * that contains "overrid" (any year), not only cars with a recognised note.
 * It validates the chassis without an override. When the chassis passes, the
 * script sets `chassis_override = 0` and removes the override notes with
 * ChassisOverrideNote::strip(). A comment that still names an override after
 * the removal goes to the manual edit list, and the car stays unchanged. A car
 * that still fails keeps its flag and its note.
 *
 * The GET request shows a preview only. The POST (admin, CSRF) runs all
 * updates in one transaction. Each UPDATE also requires that `comments` is
 * still the value that the script read. When another edit changed it, the
 * script skips that car. `mtime = mtime` keeps the modification time,
 * because this is a data correction, not an owner edit. The `cars_update`
 * trigger writes the `cars_hist` row for each update.
 *
 * After a run with no skipped or errored car, a second run finds no car to
 * change. The run is recorded only when no car errored or was skipped. Cars
 * in the manual and failing lists do not stop the record.
 */

require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'app/admin/includes/fix-script-core.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

// Set up custom error handler to log through UserSpice logger
set_error_handler(function ($errno, $errstr, $errfile, $errline) use ($user) {
    if ($errno !== E_DEPRECATED) {
        logger(isset($user) ? $user->data()->id : 0, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Error [$errno]: $errstr in $errfile:$errline");
    }
    return true;
});

// admin_script_record_completion() (fix-script-core.php) reads `global $db`, a real \DB.
$db = DB::getInstance();
$dbi = dbi();

/**
 * Sort the override cars into the lists that the preview and the run use.
 *
 * - `change`: the chassis passes, and the flag is 1 or strip() removed a note.
 * - `manual`: the chassis passes, but the comment needs a manual edit.
 * - `failing`: the chassis still fails, or the year is empty (null or 0).
 *   The flag and the note stay. `group` is the ChassisValidator::RULE_GROUPS
 *   key of the rule the car uses, or `year_empty`.
 * - `info`: 1972-1974 cars with `chassis_override = 0` that fail the rule.
 *
 * A car that throws during validation is skipped, logged, and added to
 * `errors` with its ID.
 *
 * @param DatabaseInterface $db     Database instance
 * @param int               $userId Acting user ID, for log entries
 * @return array{
 *     change: list<array{id: int, year: int|null, chassis: string, before: string|null, after: string|null}>,
 *     manual: list<array{id: int, year: int|null, chassis: string, before: string|null, after: string|null}>,
 *     failing: list<array{id: int, year: int|null, chassis: string, before: string|null, reason: string, group: string}>,
 *     info: list<array{id: int, year: int|null, chassis: string, reason: string}>,
 *     errors: list<array{id: int, message: string}>
 * }
 */
function fix31_classify_cars(DatabaseInterface $db, int $userId): array
{
    $lists = ['change' => [], 'manual' => [], 'failing' => [], 'info' => [], 'errors' => []];
    $validator = new ChassisValidator();

    $overrideCars = $db->query(
        "SELECT id, year, model, chassis, chassis_override, comments
           FROM cars
          WHERE chassis_override = 1 OR comments LIKE '%OVERRID%'
          ORDER BY id"
    );
    if ($overrideCars->error()) {
        throw new AdminOperationException('Override car query failed: ' . $overrideCars->errorString());
    }

    foreach ($overrideCars->results() as $car) {
        $carId = (int) $car->id;
        $year = $car->year === null ? null : (int) $car->year;
        $chassis = (string) $car->chassis;
        $comments = $car->comments === null ? null : (string) $car->comments;

        try {
            // validate() applies the pre-1970 rule to year 0, so a 4-digit
            // chassis would pass and clear the override. Report it instead.
            if ($year === null || $year === 0) {
                $lists['failing'][] = ['id' => $carId, 'year' => $year, 'chassis' => $chassis, 'before' => $comments, 'reason' => 'Year is empty', 'group' => 'year_empty'];
                continue;
            }

            $result = $validator->validate($chassis, $year, (string) $car->model);
            if (!$result['valid']) {
                $lists['failing'][] = ['id' => $carId, 'year' => $year, 'chassis' => $chassis, 'before' => $comments, 'reason' => $result['error_reason'], 'group' => ChassisValidator::ruleGroup($year, (string) $car->model)];
                continue;
            }

            $note = ChassisOverrideNote::strip($comments);
            $row = ['id' => $carId, 'year' => $year, 'chassis' => $chassis, 'before' => $comments, 'after' => $note->comments];
            if ($note->manual) {
                $lists['manual'][] = $row;
            } elseif ((int) $car->chassis_override === 1 || $note->changed) {
                $lists['change'][] = $row;
            }
        } catch (\Throwable $e) {
            // Last-resort per-row fallback: one bad car must not stop the
            // preview or the run for the other cars.
            $message = get_class($e) . ': ' . $e->getMessage();
            $lists['errors'][] = ['id' => $carId, 'message' => $message];
            logger($userId, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,
                "Chassis override check failed for car {$carId}: {$message}");
        }
    }

    $recentCars = $db->query(
        'SELECT id, year, model, chassis
           FROM cars
          WHERE year BETWEEN 1972 AND 1974 AND chassis_override = 0
          ORDER BY id'
    );
    if ($recentCars->error()) {
        throw new AdminOperationException('1972-1974 car query failed: ' . $recentCars->errorString());
    }

    foreach ($recentCars->results() as $car) {
        $carId = (int) $car->id;
        try {
            $result = $validator->validate((string) $car->chassis, (int) $car->year, (string) $car->model);
            if (!$result['valid']) {
                $lists['info'][] = ['id' => $carId, 'year' => (int) $car->year, 'chassis' => (string) $car->chassis, 'reason' => $result['error_reason']];
            }
        } catch (\Throwable $e) {
            // Last-resort per-row fallback, as above.
            $message = get_class($e) . ': ' . $e->getMessage();
            $lists['errors'][] = ['id' => $carId, 'message' => $message];
            logger($userId, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,
                "Chassis check failed for car {$carId}: {$message}");
        }
    }

    return $lists;
}

/**
 * Group the failing cars by rule group, in ChassisValidator::RULE_GROUPS order.
 * Empty groups are left out.
 *
 * @param list<array{id: int, year: int|null, chassis: string, before: string|null, reason: string, group: string}> $failing Failing cars
 * @return array<string, list<array{id: int, year: int|null, chassis: string, before: string|null, reason: string, group: string}>> Cars by group key
 */
function fix31_group_failing(array $failing): array
{
    $groups = array_fill_keys(array_keys(ChassisValidator::RULE_GROUPS), []);
    foreach ($failing as $row) {
        $groups[$row['group']][] = $row;
    }
    return array_filter($groups, static fn (array $rows): bool => $rows !== []);
}

/**
 * Escape a value for HTML output. Shows "(empty)" for a null or blank comment.
 *
 * @param string|int|null $value Value to show
 * @return string Escaped HTML
 */
function fix31_html(string|int|null $value): string
{
    if ($value === null || trim((string) $value) === '') {
        return '<em class="text-muted">(empty)</em>';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$userId = (int) $user->data()->id;

?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">

            <?php if (!admin_script_exec_requested()):
                $lists = fix31_classify_cars($dbi, $userId);
            ?>
            <!-- Initial Description and Preview -->
            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-check-square-o"></i> Clear Passing Chassis Overrides
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Clears the chassis override on cars whose chassis now passes
                            validation (Issue #2613).</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Selects each car with <code>chassis_override = 1</code> or an override note
                                        in its comments, for all years</li>
                                    <li>Validates each chassis again, without an override, with the current rule for the car's
                                        year (for 1972-1974: 9 characters <code>YYMMXXXXC</code>, or 11 characters when built in 1970 or 1971)</li>
                                    <li>For each chassis that passes, sets <code>chassis_override = 0</code> and removes
                                        the <code>CHASSIS VALIDATION OVERRIDDEN</code> notes from the comments</li>
                                    <li>Keeps the flag and the note on each car that still fails</li>
                                    <li>Runs all updates in one transaction. The <code>cars_update</code> trigger
                                        writes a <code>cars_hist</code> row for each car</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>Read the preview below before you start. The script changes only the cars in
                                        the first list.</li>
                                    <li>Cars in the manual edit list stay unchanged. Edit their comments by hand.</li>
                                    <li>The car modification time (<code>mtime</code>) does not change.</li>
                                    <li>After a run with no skipped or errored car, a second run finds no car to change.</li>
                                </ul>
                            </div>

                            <?php if ($lists['errors'] !== []): ?>
                            <div class="alert alert-danger">
                                <?= count($lists['errors']) ?> car(s) could not be checked. The script skips them.
                            </div>
                            <div class="table-responsive mb-4">
                                <table class="table table-sm table-striped">
                                    <thead><tr><th>Car ID</th><th>Error</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($lists['errors'] as $error): ?>
                                        <tr>
                                            <td><?= fix31_html($error['id']) ?></td>
                                            <td class="text-break"><?= fix31_html($error['message']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <h4>To change (<?= count($lists['change']) ?>)</h4>
                            <?php if ($lists['change'] === []): ?>
                            <p class="text-muted">No car to change.</p>
                            <?php else: ?>
                            <div class="table-responsive mb-4">
                                <table class="table table-sm table-striped">
                                    <thead><tr><th>Car ID</th><th>Year</th><th>Chassis</th><th>Comment before</th><th>Comment after</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($lists['change'] as $row): ?>
                                        <tr>
                                            <td><?= fix31_html($row['id']) ?></td>
                                            <td><?= fix31_html($row['year']) ?></td>
                                            <td><?= fix31_html($row['chassis']) ?></td>
                                            <td class="text-break" style="white-space: pre-wrap;"><?= fix31_html($row['before']) ?></td>
                                            <td class="text-break" style="white-space: pre-wrap;"><?= fix31_html($row['after']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <h4>Manual edit (<?= count($lists['manual']) ?>)</h4>
                            <p class="small text-muted">The chassis passes, but the comment still names an override
                            after the known notes are removed. The script does not change these cars.</p>
                            <?php if ($lists['manual'] === []): ?>
                            <p class="text-muted">No car needs a manual edit.</p>
                            <?php else: ?>
                            <div class="table-responsive mb-4">
                                <table class="table table-sm table-striped">
                                    <thead><tr><th>Car ID</th><th>Year</th><th>Chassis</th><th>Comment</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($lists['manual'] as $row): ?>
                                        <tr>
                                            <td><?= fix31_html($row['id']) ?></td>
                                            <td><?= fix31_html($row['year']) ?></td>
                                            <td><?= fix31_html($row['chassis']) ?></td>
                                            <td class="text-break" style="white-space: pre-wrap;"><?= fix31_html($row['before']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <h4>Still failing (<?= count($lists['failing']) ?>)</h4>
                            <p class="small text-muted">The chassis fails validation. The flag and the note stay.</p>
                            <?php if ($lists['failing'] === []): ?>
                            <p class="text-muted">No override car still fails.</p>
                            <?php else: ?>
                            <?php foreach (fix31_group_failing($lists['failing']) as $groupKey => $groupRows): ?>
                            <h5><?= fix31_html(ChassisValidator::RULE_GROUPS[$groupKey]) ?> (<?= count($groupRows) ?>)</h5>
                            <div class="table-responsive mb-4">
                                <table class="table table-sm table-striped">
                                    <thead><tr><th>Car ID</th><th>Year</th><th>Chassis</th><th>Reason</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($groupRows as $row): ?>
                                        <tr>
                                            <td><?= fix31_html($row['id']) ?></td>
                                            <td><?= fix31_html($row['year']) ?></td>
                                            <td><?= fix31_html($row['chassis']) ?></td>
                                            <td><?= fix31_html($row['reason']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>

                            <h4>Information only: 1972-1974 cars without an override that fail (<?= count($lists['info']) ?>)</h4>
                            <p class="small text-muted">The script does not change these cars.</p>
                            <?php if ($lists['info'] === []): ?>
                            <p class="text-muted">No car in this list.</p>
                            <?php else: ?>
                            <div class="table-responsive mb-4">
                                <table class="table table-sm table-striped">
                                    <thead><tr><th>Car ID</th><th>Year</th><th>Chassis</th><th>Reason</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($lists['info'] as $row): ?>
                                        <tr>
                                            <td><?= fix31_html($row['id']) ?></td>
                                            <td><?= fix31_html($row['year']) ?></td>
                                            <td><?= fix31_html($row['chassis']) ?></td>
                                            <td><?= fix31_html($row['reason']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <div class="text-center">
                                <?= admin_script_start_form('Continue - Clear Passing Overrides') ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else:
                // Processing mode - simple text output
                ob_end_clean(); // Clear template buffering
                header('Content-Type: text/html; charset=utf-8');
                echo str_repeat(' ', 1024); // Pad to force initial flush
                flush();
            ?>

            <div class="card registry-card">
                <div class="card-header">
                    <h2 class="mb-0">
                        <i class="fa fa-cogs"></i> Clearing Passing Chassis Overrides
                    </h2>
                    <small class="text-muted">
                        <i class="fa fa-clock-o"></i> Started: <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px;"><?php

                    try {
                        // STEP 1: Select and classify the cars again, so the run uses the current data
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Validate the override cars', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $lists = fix31_classify_cars($dbi, $userId);
                        logProgress('To change: ' . count($lists['change']), 'info');
                        logProgress('Manual edit (skipped): ' . count($lists['manual']), 'info');
                        logProgress('Still failing (kept): ' . count($lists['failing']), 'info');
                        foreach ($lists['errors'] as $error) {
                            logProgress("Car {$error['id']}: could not check (skipped) - {$error['message']}", 'warning');
                        }

                        // STEP 2: Clear the flag and the notes in one transaction
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: Clear the overrides', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $updated = 0;
                        $skippedIds = [];
                        $dbi->beginTransaction();
                        try {
                            foreach ($lists['change'] as $row) {
                                // mtime = mtime stops ON UPDATE CURRENT_TIMESTAMP: this is
                                // a data correction, not an owner edit. The comments guard
                                // (<=> also matches NULL) skips a car that an owner or admin
                                // edited after the script read it.
                                $dbi->query(
                                    'UPDATE cars SET chassis_override = 0, comments = ?, mtime = mtime WHERE id = ? AND comments <=> ?',
                                    [$row['after'], $row['id'], $row['before']]
                                );
                                if ($dbi->error()) {
                                    throw new AdminOperationException("Update failed for car {$row['id']}: " . $dbi->errorString());
                                }
                                if ($dbi->count() !== 1) {
                                    $skippedIds[] = $row['id'];
                                    logProgress("Car {$row['id']} ({$row['year']}, {$row['chassis']}): skipped - comment changed during this run", 'warning');
                                    logger($userId, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                        "31-Clear-Passing-Chassis-Overrides skipped car {$row['id']}: comments changed during this run");
                                    continue;
                                }
                                $updated++;
                                logProgress("Car {$row['id']} ({$row['year']}, {$row['chassis']}): override cleared", 'success');
                            }
                            $dbi->commit();
                        } catch (\Throwable $e) {
                            if ($dbi->inTransaction()) {
                                $dbi->rollBack();
                            }
                            logProgress('Rolled back. No car changed.', 'error');
                            throw $e;
                        }

                        foreach ($lists['manual'] as $row) {
                            logProgress("Car {$row['id']} ({$row['year']}, {$row['chassis']}): skipped - edit the comment by hand", 'warning');
                        }

                        // logProgress() escapes each line.
                        foreach (fix31_group_failing($lists['failing']) as $groupKey => $groupRows) {
                            logProgress('Still failing - ' . ChassisValidator::RULE_GROUPS[$groupKey] . ' (' . count($groupRows) . ')', 'info');
                            foreach ($groupRows as $row) {
                                logProgress("  Car {$row['id']} ({$row['year']}, {$row['chassis']}): {$row['reason']}", 'info');
                            }
                        }

                        // A run with errors or skipped cars is not complete: record
                        // the run only when no car errored or was skipped. Manual and
                        // failing cars do not stop the record.
                        $hasProblems = $lists['errors'] !== [] || $skippedIds !== [];
                        if (!$hasProblems) {
                            admin_script_record_completion(__FILE__, $userId, 'logProgress');
                        }

                        $manualIds = implode(',', array_column($lists['manual'], 'id'));
                        $errorIds = implode(',', array_column($lists['errors'], 'id'));
                        $skippedList = implode(',', $skippedIds);
                        logger($userId, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            "31-Clear-Passing-Chassis-Overrides completed - cleared: {$updated}, manual edit: "
                            . count($lists['manual']) . ($manualIds !== '' ? " [{$manualIds}]" : '')
                            . ', still failing: ' . count($lists['failing'])
                            . ', skipped (comment changed during this run): ' . count($skippedIds) . ($skippedList !== '' ? " [{$skippedList}]" : '')
                            . ', errors: ' . count($lists['errors']) . ($errorIds !== '' ? " [{$errorIds}]" : ''));

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('Chassis Override Cleanup Complete', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Overrides cleared: {$updated}", 'success');
                        if ($lists['manual'] !== []) {
                            logProgress('Manual edit needed: ' . count($lists['manual']), 'warning');
                        }
                        logProgress('Still failing (kept): ' . count($lists['failing']), 'info');
                        if ($skippedIds !== []) {
                            logProgress('Skipped - comment changed during this run: ' . count($skippedIds) . " [{$skippedList}]", 'error');
                        }
                        if ($lists['errors'] !== []) {
                            logProgress('Errors: ' . count($lists['errors']) . " [{$errorIds}]", 'error');
                        }
                        if ($hasProblems) {
                            logProgress('', 'info');
                            logProgress('Completed with errors - fix the cars listed above, then run the script again.', 'error');
                        }
                        logProgress('', 'info');
                        logProgress('Post-Processing Steps:', 'info');
                        logProgress('  • Edit the comments of the cars in the manual edit list', 'info');
                        logProgress('  • Open this script again: the preview must show 0 cars to change', 'info');

                    } catch (\Throwable $e) {
                        // Last-resort fallback: show and log any failure, so the run
                        // never ends with a blank page and no log line.
                        logProgress('FATAL ERROR: ' . get_class($e) . ': ' . $e->getMessage(), 'error');
                        logger($userId, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR, '31-Clear-Passing-Chassis-Overrides fatal error: ' . get_class($e) . ': ' . $e->getMessage());
                    }

                    ?></pre>

                    <div class="text-center mt-3">
                        <?= admin_script_close_button() ?>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
