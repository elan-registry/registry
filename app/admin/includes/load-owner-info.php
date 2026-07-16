<?php
declare(strict_types=1);

use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Exceptions\OwnerNotFoundException;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;
use ElanRegistry\OwnerView;

require_once '../../../users/init.php';

header('Content-Type: application/json');

requireAdminAjax('owner info', false);

$ownerId = (int)($_POST['owner_id'] ?? 0);
if ($ownerId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid owner ID']);
    exit;
}

$obLevelBefore = ob_get_level();
try {
    ob_start();
    $owner = new Owner($ownerId);
    $ownerData = $owner->data();

    if (!$ownerData) {
        if (ob_get_level() > $obLevelBefore) ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Owner not found']);
        exit;
    }

    $ownedCars = $owner->getCarsOwned();
    $carCount = count($ownedCars);

    $ownershipHistory = $owner->getOwnershipHistory();
    $historyCount = count($ownershipHistory);

    $qualityScore = $owner->getProfileQualityScore();
    $missingFields = $owner->validateProfileCompleteness();

    ?>
    <!-- Owner Summary Card -->
    <div class="card border-primary mb-3">
        <div class="card-header bg-primary text-white">
            <h6 class="mb-0">
                <i class="fas fa-user"></i>
                <?= OwnerView::displayName($ownerData) // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>
            </h6>
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-4">
                    <h4 class="text-primary"><?= $carCount // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?></h4>
                    <small>Cars Owned</small>
                </div>
                <div class="col-4">
                    <h4 class="text-<?= OwnerView::qualityBadgeClass($qualityScore) ?>">
                        <?= (int)$qualityScore // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>%
                    </h4>
                    <small>Profile Quality</small>
                </div>
                <div class="col-4">
                    <h4 class="text-info"><?= $historyCount // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?></h4>
                    <small>History Records</small>
                </div>
            </div>

            <hr>

            <div class="text-center">
                <strong>User ID:</strong> <?= $ownerId // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?><br>
                <strong>Contact:</strong> <?= OwnerView::displayContactInfo($ownerData) // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?><br>
                <strong>Location:</strong>
                <?= OwnerView::displayLocation($ownerData) ?: 'Not specified' // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>
            </div>
        </div>
    </div>

    <!-- Data Quality Card -->
    <div class="card border-<?= OwnerView::qualityBadgeClass($qualityScore) ?> mb-3">
        <div class="card-header bg-<?= OwnerView::qualityBadgeClass($qualityScore) ?> text-white">
            <h6 class="mb-0">
                <i class="fas fa-clipboard-check"></i> Data Quality
            </h6>
        </div>
        <div class="card-body">
            <div class="mb-2">
                <?= OwnerView::displayQualityProgressBar($qualityScore, '1rem') // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>
            </div>

            <?php if (!empty($missingFields)): ?>
                <h6 class="text-danger">Missing Fields:</h6>
                <?= OwnerView::displayMissingFields($missingFields) // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>
            <?php else: ?>
                <div class="text-success">
                    <i class="fas fa-check-circle"></i> Profile is complete!
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Car Ownership Card -->
    <div class="card border-info mb-3">
        <div class="card-header bg-info text-white">
            <h6 class="mb-0">
                <i class="fas fa-car"></i> Car Ownership (<?= $carCount // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>)
            </h6>
        </div>
        <div class="card-body">
            <?php if ($carCount > 0): ?>
                <div class="list-group list-group-flush">
                    <?php foreach (array_slice($ownedCars, 0, 5) as $car): ?>
                        <div class="list-group-item border-0 px-0 py-1">
                            <small>
                                <strong><?= htmlspecialchars($car->chassis ?? 'Unknown') ?></strong><br>
                                <?= htmlspecialchars(($car->year ?? '') . ' ' . ($car->model ?? '')) ?>
                                <?php if (!empty($car->variant)): ?>
                                    <span class="text-muted">(<?= htmlspecialchars($car->variant) ?>)</span>
                                <?php endif; ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($carCount > 5): ?>
                        <div class="list-group-item border-0 px-0 py-1">
                            <small class="text-muted">... and <?= $carCount - 5 // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?> more</small>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <div class="text-muted text-center">
                    <i class="fas fa-car fa-2x mb-2"></i>
                    <p>No cars registered</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity Card -->
    <?php if ($historyCount > 0): ?>
        <div class="card border-secondary mb-3">
            <div class="card-header bg-secondary text-white">
                <h6 class="mb-0">
                    <i class="fas fa-history"></i> Recent Activity
                </h6>
            </div>
            <div class="card-body">
                <div class="list-group list-group-flush">
                    <?php foreach (array_slice($ownershipHistory, 0, 3) as $record): ?>
                        <div class="list-group-item border-0 px-0 py-1">
                            <small>
                                <strong><?= htmlspecialchars($record->operation ?? 'Update') ?></strong><br>
                                <?php if (!empty($record->chassis)): ?>
                                    <?= htmlspecialchars($record->chassis) ?>
                                    <?= htmlspecialchars(($record->year ?? '') . ' ' . ($record->model ?? '')) ?><br>
                                <?php endif; ?>
                                <span class="text-muted">
                                    <?php
                                    $timestamp = strtotime($record->ctime ?? '');
                                    echo $timestamp !== false // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag
                                        ? htmlspecialchars(date('M j, Y', $timestamp), ENT_QUOTES, 'UTF-8')
                                        : 'Unknown date';
                                    ?>
                                </span>
                            </small>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </div>
    <?php endif; ?>

    <!-- Quick Actions Card -->
    <div class="card border-dark">
        <div class="card-header bg-dark text-white">
            <h6 class="mb-0">
                <i class="fas fa-tools"></i> Quick Actions
            </h6>
        </div>
        <div class="card-body">
            <div class="d-grid gap-2">
                <?php if ($carCount > 0): ?>
                    <button class="btn btn-sm btn-outline-success" data-sync-owner-id="<?= $ownerId // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>">
                        <i class="fas fa-sync"></i> Sync Location to Cars
                    </button>
                <?php endif; ?>
                <a href="../../users/admin.php?view=user&id=<?= $ownerId // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-external-link-alt"></i> UserSpice Admin
                </a>
            </div>
        </div>
    </div>


    <?php

    $html = ob_get_clean();
    $encoded = json_encode(['success' => true, 'html' => $html]);
    if ($encoded === false) {
        logger($user->data()->id, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Owner info json_encode failed for owner ' . $ownerId . ': ' . json_last_error_msg());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to encode owner info response.']);
        exit;
    }
    echo $encoded;

} catch (OwnerNotFoundException $e) {
    if (ob_get_level() > $obLevelBefore) ob_end_clean();
    logger($user->data()->id, $e->getLogCategory(), 'Owner not found: ' . $e->getMessage());
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Owner not found. Please check the ID and try again.']);
} catch (ElanRegistryException $e) {
    if (ob_get_level() > $obLevelBefore) ob_end_clean();
    logger($user->data()->id, $e->getLogCategory(), 'Owner info load failed: ' . $e->getMessage());
    http_response_code($e->getHttpStatusCode());
    echo json_encode(['success' => false, 'message' => $e->getUserMessage()]);
} catch (Exception $e) {
    if (ob_get_level() > $obLevelBefore) ob_end_clean();
    logger($user->data()->id, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Owner info load unexpected error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load owner information. Please try again.']);
}
?>