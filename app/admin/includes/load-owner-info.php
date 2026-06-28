<?php
declare(strict_types=1);

use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Exceptions\OwnerNotFoundException;

/**
 * load-owner-info.php
 * AJAX endpoint for loading owner information sidebar
 *
 * Returns HTML sidebar with owner statistics, car ownership, and data quality information
 */

// Include required files
require_once '../../../users/init.php';

// Security check - admin permission required
if (!$user->isLoggedIn() || !isRegistryAdmin($user->data()->id)) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Unauthorized access</div>';
    exit;
}

// CSRF protection
if (!isset($_POST['csrf']) || !Token::check($_POST['csrf'])) {
    http_response_code(400);
    echo '<div class="alert alert-danger">Invalid CSRF token</div>';
    exit;
}

// Validate owner ID
$ownerId = (int)($_POST['owner_id'] ?? 0);
if ($ownerId <= 0) {
    http_response_code(400);
    echo '<div class="alert alert-danger">Invalid owner ID</div>';
    exit;
}

try {
    // Load owner data using ElanRegistryOwner
    $owner = new ElanRegistryOwner($ownerId);
    $ownerData = $owner->data();

    if (!$ownerData) {
        http_response_code(404);
        echo '<div class="alert alert-warning">Owner not found</div>';
        exit;
    }

    // Get cars owned
    $ownedCars = $owner->getCarsOwned();
    $carCount = count($ownedCars);

    // Get ownership history
    $ownershipHistory = $owner->getOwnershipHistory();
    $historyCount = count($ownershipHistory);

    // Get quality information
    $qualityScore = $owner->getProfileQualityScore();
    $missingFields = $owner->validateProfileCompleteness();

    ?>
    <!-- Owner Summary Card -->
    <div class="card border-primary mb-3">
        <div class="card-header bg-primary text-white">
            <h6 class="mb-0">
                <i class="fas fa-user"></i>
                <?= htmlspecialchars($ownerData->fname . ' ' . $ownerData->lname) ?>
            </h6>
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-4">
                    <h4 class="text-primary"><?= $carCount // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?></h4>
                    <small>Cars Owned</small>
                </div>
                <div class="col-4">
                    <h4 class="text-<?= ElanRegistryOwner::getQualityBadgeClass($qualityScore) ?>">
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
                <strong>Email:</strong> <a href="mailto:<?= htmlspecialchars($ownerData->email) ?>"><?= htmlspecialchars($ownerData->email) ?></a><br>
                <?php
                $ownerWebsiteScheme = !empty($ownerData->website)
                    ? strtolower((string) parse_url((string) $ownerData->website, PHP_URL_SCHEME))
                    : '';
                if (!empty($ownerData->website) && in_array($ownerWebsiteScheme, ['http', 'https'], true)):
                ?>
                    <strong>Website:</strong> <a href="<?= htmlspecialchars($ownerData->website, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($ownerData->website, ENT_QUOTES, 'UTF-8') ?></a><br>
                <?php endif; ?>
                <strong>Location:</strong>
                <?php
                $location = array_filter([$ownerData->city, $ownerData->state, $ownerData->country]);
                echo !empty($location) ? htmlspecialchars(implode(', ', $location)) : 'Not specified';
                ?>
            </div>
        </div>
    </div>

    <!-- Data Quality Card -->
    <div class="card border-<?= ElanRegistryOwner::getQualityBadgeClass($qualityScore) ?> mb-3">
        <div class="card-header bg-<?= ElanRegistryOwner::getQualityBadgeClass($qualityScore) ?> text-white">
            <h6 class="mb-0">
                <i class="fas fa-clipboard-check"></i> Data Quality
            </h6>
        </div>
        <div class="card-body">
            <div class="progress mb-2">
                <div class="progress-bar bg-<?= ElanRegistryOwner::getQualityBadgeClass($qualityScore) ?>"
                     style="width: <?= (int)$qualityScore // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>%">
                    <?= (int)$qualityScore // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>%
                </div>
            </div>

            <?php if (!empty($missingFields)): ?>
                <h6 class="text-danger">Missing Fields:</h6>
                <ul class="list-unstyled">
                    <?php foreach ($missingFields as $field): ?>
                        <li><i class="fas fa-exclamation-triangle text-warning"></i> <?= htmlspecialchars($field) ?></li>
                    <?php endforeach; ?>
                </ul>
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
                    <button class="btn btn-sm btn-outline-success" onclick="syncLocationToCars(<?= $ownerId // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>)">
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

} catch (OwnerNotFoundException $e) {
    logger($user->data()->id, $e->getLogCategory(), 'Owner not found: ' . $e->getMessage());
    echo '<div class="alert alert-danger">Owner not found. Please check the ID and try again.</div>';
} catch (ElanRegistryException $e) {
    logger($user->data()->id, $e->getLogCategory(), 'Owner info load failed: ' . $e->getMessage());
    echo '<div class="alert alert-danger">' . htmlspecialchars($e->getUserMessage()) . '</div>';
} catch (Exception $e) {
    logger($user->data()->id, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Owner info load unexpected error: ' . $e->getMessage());
    echo '<div class="alert alert-danger">Failed to load owner information. Please try again.</div>';
}
?>