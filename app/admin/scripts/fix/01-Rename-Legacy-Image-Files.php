<?php

declare(strict_types=1);

use ElanRegistry\Car\CarImageProcessor;
use ElanRegistry\LogCategories;

/**
 * Rename Legacy Image Files Script
 *
 * Renames image files whose filenames contain characters outside the allowlist
 * introduced in issue #1307 (parentheses, +, [, ], Unicode, spaces, colons).
 * These characters are not in [\w\-.] and fail CarImageProcessor::isSafeFilename().
 *
 * For each affected car:
 *   1. If the file exists on disk: renames it to img_[hex32].[ext] and updates the
 *      DB JSON entry. Also renames all resized variants (*-resized-{size}.*).
 *   2. If the file is absent on disk: removes the stale entry from the DB JSON
 *      (the filename was never backed by a real file).
 *
 * Issue #1307: Image filename allowlist (glob hijack + traversal oracle)
 *
 * Run on the local dev environment first, then on production.
 * Must be run after deploying the allowlist code that rejects these filenames.
 */

require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'app/admin/includes/fix-script-core.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if ($errno !== E_DEPRECATED) {
        logger(0, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Error [{$errno}]: {$errstr} in {$errfile}:{$errline}");
    }
    return true;
});

$db = DB::getInstance();

/** Query cars that have any image filename failing isSafeFilename(). */
function buildAffectedList(\DB $db): array
{
    $db->query("SELECT id, image FROM cars WHERE image IS NOT NULL AND image != '' AND image != '[]'");
    if ($db->error()) {
        throw new \RuntimeException('Failed to load car image data: ' . $db->errorString());
    }
    $rows = $db->results();

    $affected = [];
    foreach ($rows as $row) {
        $images = json_decode((string) $row->image, true);
        if (!is_array($images)) {
            continue;
        }
        $invalids = [];
        foreach ($images as $img) {
            if (!CarImageProcessor::isSafeFilename((string) $img)) {
                $invalids[] = (string) $img;
            }
        }
        if (!empty($invalids)) {
            $affected[] = ['car_id' => (int) $row->id, 'all_images' => $images, 'invalids' => $invalids];
        }
    }
    return $affected;
}

$affected = buildAffectedList($db);

?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">

            <?php if (!admin_script_exec_requested()): ?>
            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-file-image-o"></i> Rename Legacy Image Files
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">
                                Renames image files with non-allowlist characters (parentheses, <code>+</code>,
                                brackets, Unicode, spaces, colons) to the secure <code>img_[hex32].[ext]</code>
                                format required by the #1307 filename allowlist.
                            </p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Scans <code>cars.image</code> for filenames that fail <code>isSafeFilename()</code></li>
                                    <li>For files that exist on disk: renames to <code>img_[hex32].[ext]</code></li>
                                    <li>Also renames any <code>*-resized-{size}.*</code> variants found in the same directory</li>
                                    <li>Updates the <code>cars.image</code> JSON column to reference the new filenames</li>
                                    <li>For DB entries with no file on disk: removes the stale entry from the JSON</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>File renames are not transactional — if the script stops mid-run, some files may be renamed but DB not yet updated</li>
                                    <li>Run on the local dev environment first to verify; then run on production</li>
                                    <li>This script is safe to re-run: already-renamed files pass <code>isSafeFilename()</code> and are skipped</li>
                                </ul>
                            </div>

                            <?php if (empty($affected)): ?>
                            <div class="alert alert-success">
                                <i class="fa fa-check-circle"></i> <strong>No action needed.</strong>
                                All image filenames in the database already pass the allowlist.
                            </div>
                            <?php else: ?>
                            <h5><?= count($affected) ?> car(s) with invalid filenames:</h5>
                            <table class="table table-sm table-bordered mb-4">
                                <thead>
                                    <tr><th>Car ID</th><th>Invalid Filename(s)</th><th>File on Disk?</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($affected as $entry):
                                        $imgDir = $abs_us_root . $us_url_root . 'userimages/' . $entry['car_id'] . '/';
                                        foreach ($entry['invalids'] as $inv):
                                            $exists = is_file($imgDir . $inv);
                                    ?>
                                    <tr>
                                        <td><a href="/app/owner/cars/detail.php?carid=<?= (int)$entry['car_id'] ?>" target="_blank"><?= (int)$entry['car_id'] ?></a></td>
                                        <td><code><?= htmlspecialchars($inv, ENT_QUOTES, 'UTF-8') ?></code></td>
                                        <td><?= $exists ? '<span class="text-success">Yes — will rename</span>' : '<span class="text-warning">No — will remove from DB</span>' ?></td>
                                    </tr>
                                    <?php endforeach; endforeach; ?>
                                </tbody>
                            </table>

                            <div class="text-center">
                                <?= admin_script_start_form('Rename Files and Update DB') ?>
                            </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            </div>

            <?php else:
                ob_end_clean();
                header('Content-Type: text/html; charset=utf-8');
                echo str_repeat(' ', 1024);
                flush();
            ?>

            <div class="card registry-card">
                <div class="card-header">
                    <h2 class="mb-0">
                        <i class="fa fa-cogs"></i> Renaming Legacy Image Files
                    </h2>
                    <small class="text-muted">
                        <i class="fa fa-clock-o"></i> Started: <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px;"><?php

                    $results = ['renamed' => 0, 'db_only_fixed' => 0, 'resized_renamed' => 0, 'errors' => 0, 'warnings' => 0];

                    try {
                        if (empty($affected)) {
                            logProgress('No invalid filenames found — nothing to do.', 'success');
                        }

                        foreach ($affected as $entry) {
                            $carid  = $entry['car_id'];
                            $imgDir = $abs_us_root . $us_url_root . 'userimages/' . $carid . '/';

                            logProgress(SECTION_SEPARATOR, 'step');
                            logProgress("Car #{$carid}", 'step');
                            logProgress(SECTION_SEPARATOR, 'step');

                            $allImages = $entry['all_images'];
                            $newImages = [];
                            $carChanged = false;

                            foreach ($allImages as $filename) {
                                $filename = (string) $filename;

                                if (CarImageProcessor::isSafeFilename($filename)) {
                                    $newImages[] = $filename;
                                    continue;
                                }

                                // Reject any filename that contains path separators or traversal
                                // sequences before passing it to filesystem functions. These names
                                // failed isSafeFilename() (which already excludes '/'), but an
                                // explicit check here keeps is_file() / rename() calls safe against
                                // any future isSafeFilename() loosening and makes the guard
                                // auditable without needing to reason about the regex.
                                if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
                                    logProgress("'{$filename}' — traversal in filename; removing from DB", 'warning');
                                    $results['warnings']++;
                                    $carChanged = true;
                                    $results['db_only_fixed']++;
                                    continue;
                                }

                                $mainFile = $imgDir . $filename;

                                if (!is_file($mainFile)) {
                                    logProgress("'{$filename}' — no file on disk; removing from DB", 'warning');
                                    $results['warnings']++;
                                    $carChanged = true;
                                    $results['db_only_fixed']++;
                                    continue;
                                }

                                // Generate new secure filename
                                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                if ($ext === 'jpeg') {
                                    $ext = 'jpg';
                                }
                                // Validate extension is supported. Remove unrecognised extensions
                                // from the DB JSON rather than keeping them — they would otherwise
                                // appear as "affected" on every subsequent run.
                                if (!in_array($ext, CarImageProcessor::ALLOWED_EXTENSIONS, true)) {
                                    logProgress("'{$filename}' — unrecognised extension '{$ext}'; removing from DB", 'warning');
                                    $results['warnings']++;
                                    $carChanged = true;
                                    $results['db_only_fixed']++;
                                    continue;
                                }

                                $newName     = 'img_' . bin2hex(random_bytes(16)) . '.' . $ext;
                                $newMainFile = $imgDir . $newName;

                                if (!rename($mainFile, $newMainFile)) {
                                    logProgress("FAILED to rename '{$filename}'", 'error');
                                    $results['errors']++;
                                    $newImages[] = $filename;
                                    continue;
                                }

                                logProgress("'{$filename}' → '{$newName}'", 'success');
                                $results['renamed']++;
                                $carChanged = true;

                                // Rename resized variants by scanning the directory.
                                // Uses scandir() (not glob()) to avoid glob metacharacter
                                // expansion on basenames that contain (, ), [, ], +, etc.
                                $oldBase = pathinfo($filename, PATHINFO_FILENAME);
                                $newBase = pathinfo($newName, PATHINFO_FILENAME);
                                $prefix  = $oldBase . '-resized-';

                                if (is_dir($imgDir)) {
                                    $dirEntries = scandir($imgDir);
                                    if ($dirEntries !== false) {
                                        foreach ($dirEntries as $dirEntry) {
                                            if (!str_starts_with($dirEntry, $prefix)) {
                                                continue;
                                            }
                                            // e.g. "oldBase-resized-100.jpg" → "newBase-resized-100.jpg"
                                            $suffix         = substr($dirEntry, strlen($oldBase));
                                            $newResized     = $newBase . $suffix;
                                            $oldResizedPath = $imgDir . $dirEntry;
                                            $newResizedPath = $imgDir . $newResized;
                                            if (rename($oldResizedPath, $newResizedPath)) {
                                                logProgress("  resized: '{$dirEntry}' → '{$newResized}'", 'info');
                                                $results['resized_renamed']++;
                                            } else {
                                                logProgress("  FAILED to rename resized '{$dirEntry}'", 'warning');
                                                $results['warnings']++;
                                            }
                                        }
                                    }
                                }

                                $newImages[] = $newName;
                            }

                            if ($carChanged) {
                                $imageJson = empty($newImages) ? '' : json_encode($newImages);
                                $db->query("UPDATE cars SET image = ? WHERE id = ?", [$imageJson, $carid]);
                                if ($db->error() || $db->count() === 0) {
                                    logProgress("FAILED to update DB for car #{$carid}: " . $db->errorString(), 'error');
                                    $results['errors']++;
                                } else {
                                    logProgress("DB updated for car #{$carid}", 'success');
                                    logger(
                                        (int) $user->data()->id,
                                        LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                        "01-Rename-Legacy-Image-Files: updated car #{$carid} image JSON"
                                    );
                                }
                            }

                            logProgress('', 'info');
                        }

                        $db->insert('fix_script_runs', [
                            'script_name'  => '01-Rename-Legacy-Image-Files.php',
                            'completed_at' => date('Y-m-d H:i:s'),
                        ]);

                        logger(
                            (int) $user->data()->id,
                            LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            "01-Rename-Legacy-Image-Files completed — renamed: {$results['renamed']}, "
                            . "db-only fixed: {$results['db_only_fixed']}, "
                            . "resized renamed: {$results['resized_renamed']}, "
                            . "errors: {$results['errors']}, warnings: {$results['warnings']}"
                        );

                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('COMPLETE', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Main files renamed:      {$results['renamed']}", 'success');
                        logProgress("Resized variants renamed: {$results['resized_renamed']}", 'success');
                        logProgress("Stale DB entries removed: {$results['db_only_fixed']}", 'warning');
                        if ($results['warnings'] > 0) {
                            logProgress("Warnings:                {$results['warnings']}", 'warning');
                        }
                        if ($results['errors'] > 0) {
                            logProgress("Errors:                  {$results['errors']}", 'error');
                        }
                        logProgress('', 'info');
                        logProgress('Post-run steps:', 'info');
                        logProgress('  • Verify car pages load correctly for the renamed cars', 'info');
                        logProgress('  • Run Cloudflare cache purge if images appear stale in production', 'info');
                        logProgress('  • Run this script again to confirm "No action needed" (idempotency check)', 'info');

                    } catch (\Throwable $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger((int) $user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, 'Fatal error: ' . $e->getMessage());
                    }

                    ?></pre>

                    <div class="text-center mt-3">
                        <?= admin_script_close_button('', '../../maintenance.php?tab=maintenance') ?>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
