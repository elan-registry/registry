<?php
if (count(get_included_files()) == 1) { die(); }

/**
 * Factory Data card, shared by app/owner/cars/details.php,
 * usersc/account.php, and app/views/cars/_verify_landing.php.
 *
 * Caller must set:
 *   $factoryData object Factory row (Car::factory()). A null value logs an
 *                       error, so include the card only when it is not null.
 *   $headingTag  string Card heading element, h1-h6 (default h3)
 *
 * Caller may set:
 *   $carId       int    Car ID for the log lines. Default 0.
 *
 * The defaults below let PHPStan analyze this file alone. When $factoryData is
 * missing or is not an object, the card draws nothing and logs one entry.
 */
$factoryData ??= null;
$headingTag ??= 'h3';
$carId ??= 0;

if (!is_object($factoryData)) {
    logger(0, ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        "_factory_data_card.php: \$factoryData is " . get_debug_type($factoryData) . " for car ID $carId, not an object. Card not drawn.");
    return;
}

// The card parses the build date itself, so each caller does not repeat the
// parse. A bad value hides the Build Date row and does not stop the page.
$buildDate = null;
if (!empty($factoryData->builddate)) {
    try {
        $buildDate = new DateTime($factoryData->builddate);
    } catch (DateMalformedStringException $e) {
        logger(0, ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            "_factory_data_card.php: invalid build date format for car ID $carId: " . $factoryData->builddate . ' (' . $e->getMessage() . ')');
    }
}

$headingTag       = in_array($headingTag, ['h1','h2','h3','h4','h5','h6'], true) ? $headingTag : 'h3';
$_cardHeaderClass = $headingTag === 'h4' ? ' card-header-er-l2' : '';
$_headingClass    = $headingTag === 'h4' ? 'mb-0 card-header-er-l2-text' : 'mb-0';
?>
<div class="card registry-card">
    <div class="card-header<?= $_cardHeaderClass ?>">
        <<?= $headingTag ?> class="<?= $_headingClass ?>">
            <i class="fas fa-industry text-primary" aria-hidden="true"></i> Factory Data
            <span class="badge bg-warning ms-2">Unverified</span>
        </<?= $headingTag ?>>
    </div>
    <div class="card-body">
        <div class="alert alert-primary mb-3">
            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
            <strong>Note:</strong> This information has not been verified against the official Lotus archives.
        </div>

        <dl class="row">
            <dt class="col-sm-5 text-muted">Production Year</dt>
            <dd class="col-sm-7"><?= $factoryData->year ? htmlspecialchars((string)$factoryData->year, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></dd>

            <dt class="col-sm-5 text-muted">Production Month</dt>
            <dd class="col-sm-7"><?= $factoryData->month ? htmlspecialchars((string)$factoryData->month, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></dd>

            <dt class="col-sm-5 text-muted">Production Batch</dt>
            <dd class="col-sm-7"><?= $factoryData->batch ? htmlspecialchars((string)$factoryData->batch, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></dd>

            <dt class="col-sm-5 text-muted">Factory Type</dt>
            <dd class="col-sm-7"><?= $factoryData->type ? htmlspecialchars((string)$factoryData->type, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></dd>

            <dt class="col-sm-5 text-muted">Factory Chassis</dt>
            <dd class="col-sm-7">
                <strong><?= $factoryData->serial ? htmlspecialchars((string)$factoryData->serial, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></strong>
            </dd>

            <dt class="col-sm-5 text-muted">Chassis Suffix</dt>
            <dd class="col-sm-7"><?= $factoryData->suffix ? htmlspecialchars((string)$factoryData->suffix, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></dd>

            <dt class="col-sm-5 text-muted">Factory Engine</dt>
            <dd class="col-sm-7">
                <?= htmlspecialchars((string)($factoryData->engineletter ?? ''), ENT_QUOTES, 'UTF-8') ?><?= htmlspecialchars((string)($factoryData->enginenumber ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </dd>

            <dt class="col-sm-5 text-muted">Gearbox</dt>
            <dd class="col-sm-7"><?= $factoryData->gearbox ? htmlspecialchars((string)$factoryData->gearbox, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></dd>

            <dt class="col-sm-5 text-muted">Factory Color</dt>
            <dd class="col-sm-7"><?= $factoryData->color ? htmlspecialchars((string)$factoryData->color, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></dd>

            <?php if ($buildDate) { ?>
            <dt class="col-sm-5 text-muted">Build Date</dt>
            <dd class="col-sm-7"><?= $buildDate->format('F j, Y') ?></dd>
            <?php } ?>
        </dl>

        <?php if (!empty($factoryData->note)) { ?>
        <hr>
        <h6 class="text-muted mb-2">Factory Notes</h6>
        <div class="bg-light p-3 rounded">
            <small><?= htmlspecialchars($factoryData->note, ENT_QUOTES, 'UTF-8') ?></small>
        </div>
        <?php } ?>
    </div>
</div>
