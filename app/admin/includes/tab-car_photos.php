<?php
declare(strict_types=1);

use ElanRegistry\Car\CarPhotoRecovery;
use ElanRegistry\Car\CarPhotoRecoveryRow;
use ElanRegistry\Car\DetachedHow;
use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\Input as ElanInput;
use ElanRegistry\Owner;
use ElanRegistry\OwnerView;

/**
 * tab-car_photos.php
 * Car Photos tab for the consolidated admin interface (#2644).
 *
 * Admins and editors. Render only: a GET changes nothing. The form posts
 * command=car_photos_apply, which index.php handles after its CSRF gate.
 * The checkboxes are in the main table only. Each detached photo with a safe
 * name has its own reattach[carId][] checkbox, with the file name as its
 * value. A row with an earlier order has a restore[] checkbox. Each readable
 * row sends a seen[carId] token of its `cars.image`. The per-row Apply,
 * Ignore and Undo ignore buttons send apply_car, ignore_car or unignore_car
 * with the car ID. The server finds each row again and attaches only the
 * names that it lists.
 *
 * The tab hides an ignored row. `?ignored=1` also lists the ignored rows,
 * with who ignored each one and an Undo ignore button.
 *
 * An ImageProcessingException (an unreadable image root) shows an alert in
 * the card and writes no log row. A database failure is not caught.
 *
 * Included by app/admin/index.php, which has already run init.php and
 * securePage(), and set $csrfToken.
 */

$csrfToken = $csrfToken ?? Token::generate();

$cpEsc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

// Only the exact value '1' shows the ignored rows.
$cpShowIgnored = ElanInput::get('ignored', true, false, '') === '1';

$cpRecovery  = new CarPhotoRecovery(dbi());
$cpLoadError = false;
$cpRows      = [];
try {
    $cpRows = $cpRecovery->findRows();
} catch (ImageProcessingException) {
    // A GET writes no log row. The alert names the cause.
    $cpLoadError = true;
}

/** @var list<CarPhotoRecoveryRow> $cpVisibleRows */
$cpVisibleRows = [];
/** @var list<array{row: CarPhotoRecoveryRow, by: string, at: string}> $cpIgnoredRows `by` is HTML-escaped. */
$cpIgnoredRows = [];
foreach ($cpRows as $cpRow) {
    $cpIgnoredBy = $cpRecovery->ignoredBy($cpRow);
    if ($cpIgnoredBy === null) {
        $cpVisibleRows[] = $cpRow;
        continue;
    }
    $cpByName = '';
    if ($cpShowIgnored) {
        $cpByData = (new Owner($cpIgnoredBy['userId']))->data();
        $cpByName = $cpByData !== null ? OwnerView::displayName($cpByData) : '';
    }
    $cpIgnoredRows[] = [
        'row' => $cpRow,
        'by'  => $cpByName !== '' ? $cpByName : 'user #' . $cpIgnoredBy['userId'],
        'at'  => $cpIgnoredBy['at'],
    ];
}

$cpUnreadableCount = count(array_filter($cpVisibleRows, static fn(CarPhotoRecoveryRow $row): bool => $row->unreadable !== []));

$cpHowLeftBadge = [
    DetachedHow::Removed->value       => 'text-bg-danger',
    DetachedHow::Replaced->value      => 'text-bg-warning',
    DetachedHow::PartlyRemoved->value => 'text-bg-secondary',
];

/**
 * Render one photo thumbnail that links to the full photo.
 *
 * @param CarPhotoRecovery $recovery The service, for the thumbnail name
 * @param int              $carId    The car ID
 * @param string           $name     The photo file name, for the link and the title
 * @param string           $alt      The alt text
 * @return string Escaped HTML
 */
$cpThumbnail = static function (CarPhotoRecovery $recovery, int $carId, string $name, string $alt) use ($us_url_root, $cpEsc): string {
    $base  = $us_url_root . ELAN_IMAGE_DIR . $carId . '/';
    $full  = $base . rawurlencode($name);
    $thumb = $base . rawurlencode($recovery->thumbnailName($carId, $name));

    return '<a href="' . $cpEsc($full) . '" target="_blank" rel="noopener">'
        . '<img src="' . $cpEsc($thumb) . '" alt="' . $cpEsc($alt)
        . '" title="' . $cpEsc($name) . '" class="img-thumbnail me-1 mb-1" width="100" loading="lazy">'
        . '<span class="visually-hidden"> (opens in a new tab)</span></a>';
};

$cpMainPhotoBadge = '<span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle mb-1" data-testid="car-photos-main">Main photo</span>';

$cpTabUrl = '?tab=car-photos' . ($cpShowIgnored ? '&ignored=1' : '');

// The main table, then the ignored table when the GET flag asks for it.
$cpTables = ['main' => array_map(static fn(CarPhotoRecoveryRow $row): array => ['row' => $row, 'by' => '', 'at' => ''], $cpVisibleRows)];
if ($cpShowIgnored && $cpIgnoredRows !== []) {
    $cpTables['ignored'] = $cpIgnoredRows;
}
?>
<h2 class="text-primary mb-1"><i class="fas fa-images" aria-hidden="true"></i> Car Photos</h2>
<p class="text-muted mb-1">
    Photos that are on disk and in the car history, but not in the car's photo list now. Also cars whose
    photo order is the reverse of an earlier order. Compare the thumbnails and tick the photos to reattach
    and the orders to restore. Click <strong>Apply</strong> in a row to change only that car, or
    <strong>Apply selected</strong> to change every car that has a selection. Click <strong>Ignore</strong> to hide a car that
    needs no change. The first photo is the car's main photo. Reattached photos go after the current photos.
    If you also restore the order, they go after the restored order. Your changes update only the car's
    photo list. They do not delete files.
</p>
<p class="text-muted small" data-testid="car-photos-legend">
    The date under each label is the save that took the photo off the car.
    <span class="badge <?= $cpEsc($cpHowLeftBadge[DetachedHow::Removed->value]) ?>"><?= $cpEsc(DetachedHow::Removed->value) ?></span>:
    the car had no photos after that save.
    <span class="badge <?= $cpEsc($cpHowLeftBadge[DetachedHow::Replaced->value]) ?>"><?= $cpEsc(DetachedHow::Replaced->value) ?></span>:
    the save swapped out every earlier photo.
    <span class="badge <?= $cpEsc($cpHowLeftBadge[DetachedHow::PartlyRemoved->value]) ?>"><?= $cpEsc(DetachedHow::PartlyRemoved->value) ?></span>:
    the save kept some earlier photos but not this one.
</p>

<div class="card registry-card mb-4" id="carPhotos" data-testid="car-photos-card">
    <div class="card-header card-header-er-primary">
        <h5 class="mb-0 card-header-er-primary-text">
            <i class="fas fa-images" aria-hidden="true"></i> Detached Photos and Photo Order
        </h5>
    </div>
    <div class="card-body">
        <?php if ($cpLoadError) { ?>
        <div class="alert alert-danger mb-0" role="alert" data-testid="car-photos-error">
            <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
            The server could not read the car photo folder, so the tab cannot show the cars.
            Check that the folder exists and that the web server can read it.
        </div>
        <?php } else { ?>
        <?php if ($cpUnreadableCount > 0) { ?>
        <div class="alert alert-warning mb-3" role="status" data-testid="car-photos-unreadable-banner">
            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
            The server could not read the photos of <?= $cpEsc((string) $cpUnreadableCount) ?> car(s).
            Those rows show "Could not read" and have no actions. Check the file permissions.
        </div>
        <?php } ?>
        <?php if ($cpIgnoredRows !== []) { ?>
        <p class="mb-3">
            <?php if ($cpShowIgnored) { ?>
            <a href="?tab=car-photos" data-testid="car-photos-hide-ignored">Hide ignored cars</a>
            <?php } else { ?>
            <a href="?tab=car-photos&amp;ignored=1#cp-ignored-heading" data-testid="car-photos-show-ignored">Show ignored cars (<?= $cpEsc((string) count($cpIgnoredRows)) ?>)</a>
            <?php } ?>
        </p>
        <?php } ?>
        <?php if ($cpVisibleRows === []) { ?>
        <p class="text-muted" data-testid="car-photos-empty">No cars need a photo check.<?= $cpIgnoredRows !== [] && !isset($cpTables['ignored'])
            ? ' ' . $cpEsc((string) count($cpIgnoredRows)) . ' ignored car(s) are hidden.'
            : '' ?></p>
        <?php } ?>
        <?php if ($cpVisibleRows !== [] || isset($cpTables['ignored'])) { ?>
        <form method="post" action="<?= $cpEsc($cpTabUrl) ?>" data-testid="car-photos-form">
            <input type="hidden" name="csrf" value="<?= $cpEsc($csrfToken) ?>">
            <input type="hidden" name="command" value="car_photos_apply">

            <?php foreach ($cpTables as $cpTableKind => $cpTableRows) { ?>
            <?php if ($cpTableRows === []) {
                continue;
            } ?>
            <?php $cpIsIgnoredTable = $cpTableKind === 'ignored'; ?>
            <?php if ($cpIsIgnoredTable) { ?>
            <h6 class="mt-4" id="cp-ignored-heading">Ignored cars</h6>
            <?php } ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle car-photos-table"
                       <?= $cpIsIgnoredTable ? 'aria-labelledby="cp-ignored-heading"' : '' ?>
                       data-testid="<?= $cpIsIgnoredTable ? 'car-photos-ignored-table' : 'car-photos-table' ?>">
                    <caption class="visually-hidden"><?= $cpIsIgnoredTable
                        ? 'Ignored cars'
                        : 'Cars with detached photos or a reversed photo order' ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">Car</th>
                            <th scope="col">Photos now</th>
                            <th scope="col">Detached photos</th>
                            <th scope="col">Earlier order</th>
                            <th scope="col"><?= $cpIsIgnoredTable ? 'Ignored' : 'Actions' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cpTableRows as $cpEntry) { ?>
                        <?php
                        $cpRow             = $cpEntry['row'];
                        $cpCarId           = $cpEsc((string) $cpRow->carId);
                        $cpAttachableCount = count($cpRow->attachableNames());
                        $cpCanApply        = $cpAttachableCount > 0 || $cpRow->earlierOrder !== null;
                        $cpDetachedCount   = count($cpRow->detached);
                        $cpEarlierCount    = count($cpRow->earlierOrder ?? []);
                        ?>
                        <tr data-testid="<?= $cpIsIgnoredTable ? 'car-photos-ignored-row' : 'car-photos-row' ?>" data-car-id="<?= $cpCarId ?>">
                            <td>
                                <a href="<?= $cpEsc($us_url_root . 'app/owner/cars/details.php?car_id=' . $cpRow->carId) ?>"
                                   target="_blank" rel="noopener"><span class="visually-hidden">Car </span><?= $cpCarId ?><span class="visually-hidden"> (opens in a new tab)</span></a>
                            </td>
                            <td>
                                <?php if ($cpRow->currentNames === []) { ?>
                                <span class="text-muted">No photos</span>
                                <?php } ?>
                                <div class="d-flex flex-column align-items-start">
                                    <?php foreach ($cpRow->currentNames as $cpIndex => $cpName) { ?>
                                    <?= $cpIndex === 0 ? $cpMainPhotoBadge : '' ?>
                                    <?= $cpThumbnail($cpRecovery, $cpRow->carId, $cpName, sprintf('Car %d, current photo %d of %d', $cpRow->carId, $cpIndex + 1, count($cpRow->currentNames))) ?>
                                    <?php } ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($cpRow->unreadable !== []) { ?>
                                <div class="text-danger" data-testid="car-photos-unreadable">
                                    Could not read: <?= $cpEsc(implode(', ', $cpRow->unreadable)) ?>
                                </div>
                                <?php } elseif ($cpRow->detached === []) { ?>
                                <span class="text-muted">&mdash;</span>
                                <?php } ?>
                                <?php foreach ($cpRow->detached as $cpIndex => $cpPhoto) { ?>
                                <?php $cpCheckId = 'cp-reattach-' . $cpRow->carId . '-' . $cpIndex; ?>
                                <div class="d-inline-block align-top me-2 mb-2">
                                    <?= $cpThumbnail($cpRecovery, $cpRow->carId, $cpPhoto->name, sprintf('Car %d, detached photo %d of %d', $cpRow->carId, $cpIndex + 1, $cpDetachedCount)) ?>
                                    <div class="small">
                                        <span class="badge <?= $cpEsc($cpHowLeftBadge[$cpPhoto->howLeft->value]) ?>"><?= $cpEsc($cpPhoto->howLeft->value) ?></span>
                                        <div class="text-nowrap"><?= $cpEsc($cpPhoto->leftAt) ?></div>
                                        <?php if (!$cpPhoto->safe) { ?>
                                        <div class="text-danger" data-testid="car-photos-unsafe">Name not allowed. This photo cannot be reattached.</div>
                                        <?php } elseif (!$cpIsIgnoredTable) { ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="reattach[<?= $cpCarId ?>][]"
                                                   id="<?= $cpEsc($cpCheckId) ?>" value="<?= $cpEsc($cpPhoto->name) ?>"
                                                   data-testid="car-photos-reattach">
                                            <label class="form-check-label" for="<?= $cpEsc($cpCheckId) ?>">Reattach<span class="visually-hidden"> detached photo <?= $cpEsc((string) ($cpIndex + 1)) ?> of car <?= $cpCarId ?></span></label>
                                        </div>
                                        <?php } ?>
                                    </div>
                                </div>
                                <?php } ?>
                            </td>
                            <td data-testid="car-photos-earlier">
                                <?php if ($cpRow->earlierOrder === null) { ?>
                                <span class="text-muted">&mdash;</span>
                                <?php } else { ?>
                                <div class="d-flex flex-column align-items-start">
                                    <?php foreach ($cpRow->earlierOrder as $cpIndex => $cpName) { ?>
                                    <?= $cpIndex === 0 ? $cpMainPhotoBadge : '' ?>
                                    <?= $cpThumbnail($cpRecovery, $cpRow->carId, $cpName, sprintf('Car %d, earlier order photo %d of %d', $cpRow->carId, $cpIndex + 1, $cpEarlierCount)) ?>
                                    <?php } ?>
                                </div>
                                <div class="small text-nowrap" data-testid="car-photos-earlier-at">Saved <?= $cpEsc((string) $cpRow->earlierOrderAt) ?></div>
                                <?php if (!$cpIsIgnoredTable) { ?>
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" name="restore[]"
                                           id="cp-restore-<?= $cpCarId ?>" value="<?= $cpCarId ?>"
                                           data-testid="car-photos-restore">
                                    <label class="form-check-label" for="cp-restore-<?= $cpCarId ?>">Restore order<span class="visually-hidden"> of car <?= $cpCarId ?></span></label>
                                </div>
                                <?php } ?>
                                <?php } ?>
                            </td>
                            <td class="text-nowrap">
                                <?php if ($cpRow->unreadable !== []) { ?>
                                <span class="text-muted small">No action available</span>
                                <?php } else { ?>
                                <input type="hidden" name="seen[<?= $cpCarId ?>]"
                                       value="<?= $cpEsc(CarPhotoRecovery::versionToken($cpRow->rawImage)) ?>">
                                <?php if ($cpIsIgnoredTable) { ?>
                                <div class="small mb-2" data-testid="car-photos-ignored-by">Ignored by <?= $cpEntry['by'] // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?> on <?= $cpEsc($cpEntry['at']) ?></div>
                                <button type="submit" name="unignore_car" value="<?= $cpCarId ?>"
                                        class="btn btn-sm btn-outline-secondary" data-testid="car-photos-unignore">Undo ignore<span class="visually-hidden"> for car <?= $cpCarId ?></span></button>
                                <?php } else { ?>
                                <div class="d-flex gap-2">
                                    <?php if ($cpCanApply) { ?>
                                    <button type="submit" name="apply_car" value="<?= $cpCarId ?>"
                                            class="btn btn-sm btn-outline-primary" data-testid="car-photos-apply-row">Apply<span class="visually-hidden"> to car <?= $cpCarId ?></span></button>
                                    <?php } ?>
                                    <button type="submit" name="ignore_car" value="<?= $cpCarId ?>"
                                            class="btn btn-sm btn-outline-secondary" data-testid="car-photos-ignore">Ignore<span class="visually-hidden"> car <?= $cpCarId ?></span></button>
                                </div>
                                <?php } ?>
                                <?php } ?>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$cpIsIgnoredTable) { ?>
            <button type="submit" class="btn btn-primary mt-2 mb-3" data-testid="car-photos-apply">Apply selected</button>
            <?php } ?>
            <?php } ?>
        </form>
        <?php } ?>
        <?php } ?>
    </div>
</div>
