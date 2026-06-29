<?php
if (count(get_included_files()) == 1) { die(); }

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
