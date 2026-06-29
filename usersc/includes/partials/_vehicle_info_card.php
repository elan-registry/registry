<?php
if (count(get_included_files()) == 1) { die(); }

$headingTag       = in_array($headingTag, ['h1','h2','h3','h4','h5','h6'], true) ? $headingTag : 'h3';
$_cardHeaderClass = $headingTag === 'h4' ? ' card-header-er-l2' : '';
$_headingClass    = $headingTag === 'h4' ? 'mb-0 card-header-er-l2-text' : 'mb-0';
$_subHeadingClass = $headingTag === 'h4' ? 'card-header-er-l4-text mb-2' : 'text-muted mb-3';
?>
<div class="card registry-card mb-4">
    <div class="card-header<?= $_cardHeaderClass ?>">
        <<?= $headingTag ?> class="<?= $_headingClass ?>">
            <i class="fas fa-car text-primary" aria-hidden="true"></i> Vehicle Information
        </<?= $headingTag ?>>
    </div>
    <div class="card-body">
        <!-- Basic Vehicle Details -->
        <dl class="row mb-4">
            <dt class="col-sm-4 text-muted">
                <i class="fas fa-calendar text-primary" aria-hidden="true"></i> Model Year
            </dt>
            <dd class="col-sm-8">
                <?= htmlspecialchars((string)($carData->year ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </dd>

            <dt class="col-sm-4 text-muted">
                <i class="fas fa-tag text-primary" aria-hidden="true"></i> Series
            </dt>
            <dd class="col-sm-8">
                <?= htmlspecialchars($carData->series ?? '', ENT_QUOTES, 'UTF-8') ?>
                <?= !empty($carData->variant) ? ' - ' . htmlspecialchars($carData->variant, ENT_QUOTES, 'UTF-8') : '' ?>
            </dd>

            <dt class="col-sm-4 text-muted">
                <i class="fas fa-car text-primary" aria-hidden="true"></i> Type
            </dt>
            <dd class="col-sm-8">
                <?= $carData->type ? htmlspecialchars($carData->type, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Not specified</em>' ?>
            </dd>

            <dt class="col-sm-4 text-muted">
                <i class="fas fa-barcode text-primary" aria-hidden="true"></i> Chassis
            </dt>
            <dd class="col-sm-8">
                <strong><?= $carData->chassis ? htmlspecialchars($carData->chassis, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Not specified</em>' ?></strong>
                <?php if (!empty($carData->chassis_override)): ?>
                    <span class="badge bg-warning text-dark ms-1">
                        <i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Validation Override
                    </span>
                <?php endif; ?>
            </dd>
        </dl>

        <hr>

        <!-- Appearance & Technical -->
        <h6 class="<?= $_subHeadingClass ?>">
            <i class="fas fa-palette text-secondary" aria-hidden="true"></i> Appearance & Technical
        </h6>
        <dl class="row mb-4">
            <dt class="col-sm-4 text-muted">Color</dt>
            <dd class="col-sm-8">
                <?= $carData->color ? htmlspecialchars($carData->color, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Not specified</em>' ?>
            </dd>

            <dt class="col-sm-4 text-muted">Engine</dt>
            <dd class="col-sm-8">
                <?= $carData->engine ? htmlspecialchars($carData->engine, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Not specified</em>' ?>
            </dd>
        </dl>

        <hr>

        <!-- Ownership & History -->
        <h6 class="<?= $_subHeadingClass ?>">
            <i class="fas fa-history text-secondary" aria-hidden="true"></i> Ownership & History
        </h6>
        <dl class="row mb-4">
            <?php if ($purchaseDate) { ?>
            <dt class="col-sm-4 text-muted">Purchase Date</dt>
            <dd class="col-sm-8"><?= $purchaseDate->format('F j, Y') ?></dd>
            <?php } ?>

            <?php if ($soldDate) { ?>
            <dt class="col-sm-4 text-muted">Sold Date</dt>
            <dd class="col-sm-8"><?= $soldDate->format('F j, Y') ?></dd>
            <?php } ?>

            <?php if (!empty($carData->website) && in_array(strtolower((string)parse_url($carData->website, PHP_URL_SCHEME)), ['http', 'https'], true)) { ?>
            <dt class="col-sm-4 text-muted">Website</dt>
            <dd class="col-sm-8">
                <a href="<?= htmlspecialchars($carData->website, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-external-link-alt" aria-hidden="true"></i> Visit Website
                </a>
            </dd>
            <?php } ?>
        </dl>

        <?php if (!empty($carData->comments)) { ?>
        <hr>
        <h6 class="<?= $_subHeadingClass ?>">
            <i class="fas fa-comment text-secondary" aria-hidden="true"></i> Owner Comments
        </h6>
        <div class="bg-light p-3 rounded">
            <?= nl2br(htmlspecialchars($carData->comments, ENT_QUOTES, 'UTF-8')) ?>
        </div>
        <?php } ?>

        <!-- Registry Information -->
        <hr>
        <div class="row text-center">
            <div class="col-6">
                <i class="fas fa-plus-circle text-success d-block mb-1" aria-hidden="true"></i>
                <small class="text-muted d-block">Added to Registry</small>
                <strong>
                    <?php try { echo !empty($carData->ctime) ? (new DateTime($carData->ctime))->format('M j, Y') : ''; } catch (\Exception) { echo ''; } ?>
                </strong>
            </div>
            <div class="col-6">
                <i class="fas fa-edit text-info d-block mb-1" aria-hidden="true"></i>
                <small class="text-muted d-block">Last Updated</small>
                <strong>
                    <?php try { echo !empty($carData->mtime) ? (new DateTime($carData->mtime))->format('M j, Y') : ''; } catch (\Exception) { echo ''; } ?>
                </strong>
            </div>
        </div>
    </div>
</div>
