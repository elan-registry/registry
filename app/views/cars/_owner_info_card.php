<?php

declare(strict_types=1);

if (count(get_included_files()) == 1) { die(); }

/**
 * Owner Information card, shared by app/owner/cars/details.php and
 * app/views/cars/_verify_landing.php.
 *
 * The owner name, location and website come from the owner-contact columns
 * that are synced onto every car row (see DATABASE.md, `cars` table), so the
 * card needs no Owner lookup.
 *
 * Caller must set:
 *   $carData     object  Car row (Car::data() or a plain `cars` row)
 *   $headingTag  string  Card heading element, h1-h6 (default h3)
 *
 * Caller may set:
 *   $showOwnerId bool    True shows the "Registry Owner ID" footer.
 *                        Default false.
 *
 * The defaults below let PHPStan analyze this file alone. When $carData is
 * missing or is not an object, the card draws nothing and logs one entry.
 */
$carData ??= null;
$headingTag ??= 'h3';
$showOwnerId ??= false;

if (!is_object($carData)) {
    logger(0, ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        '_owner_info_card.php: $carData is ' . get_debug_type($carData) . ', not an object. Card not drawn.');
    return;
}

$headingTag     = in_array($headingTag, ['h1','h2','h3','h4','h5','h6'], true) ? $headingTag : 'h3';
$_ownerLocation = ElanRegistry\OwnerView::displayLocation($carData);
$_ownerWebsite  = ElanRegistry\OwnerView::websiteUrl($carData->website ?? '');
?>
<div class="card registry-card">
    <div class="card-header">
        <<?= $headingTag ?> class="mb-0"><i class="fas fa-user text-primary" aria-hidden="true"></i> Owner Information</<?= $headingTag ?>>
    </div>
    <div class="card-body">
        <dl class="row<?= $showOwnerId === true ? '' : ' mb-0' ?>">
            <dt class="col-sm-4 text-muted">
                <i class="fas fa-user text-primary" aria-hidden="true"></i> Owner Name
            </dt>
            <dd class="col-sm-8">
                <?= !empty($carData->fname)
                    ? htmlspecialchars(ucfirst((string)$carData->fname), ENT_QUOTES, 'UTF-8')
                    : '<em class="text-muted">Not specified</em>' ?>
            </dd>

            <dt class="col-sm-4 text-muted">
                <i class="fas fa-map-marker-alt text-primary" aria-hidden="true"></i> Location
            </dt>
            <dd class="col-sm-8">
                <?= $_ownerLocation !== ''
                    ? $_ownerLocation
                    : '<em class="text-muted">Location not specified</em>' ?>
            </dd>

            <?php if ($_ownerWebsite !== null): ?>
            <dt class="col-sm-4 text-muted">
                <i class="fas fa-link text-primary" aria-hidden="true"></i> Website
            </dt>
            <dd class="col-sm-8">
                <a href="<?= htmlspecialchars($_ownerWebsite, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                    <?= htmlspecialchars($_ownerWebsite, ENT_QUOTES, 'UTF-8') ?>
                </a>
            </dd>
            <?php endif; ?>
        </dl>

        <?php if ($showOwnerId === true): ?>
        <hr>

        <div class="text-center">
            <small class="text-muted">
                <i class="fas fa-id-card" aria-hidden="true"></i> Registry Owner ID: <?= (int)($carData->user_id ?? 0) ?>
            </small>
        </div>
        <?php endif; ?>
    </div>
</div>
