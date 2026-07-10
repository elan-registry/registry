<?php

declare(strict_types=1);

use ElanRegistry\Car\Car;
use ElanRegistry\CarView;
use ElanRegistry\Owner;
use ElanRegistry\OwnerView;

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

if (!securePage($php_self)) {
    die();
}

// Handle uncloak POST — form submits to this page with action=""
if (!empty($_POST['uncloak']) && Token::check(\Input::get('token'))) {
    endCloak();
    Redirect::to($us_url_root . 'usersc/account.php');
}

$ownerId    = (int)$user->data()->id;
$owner      = new Owner($ownerId);
$ownerData  = $owner->data();
$cars       = Car::findByOwner($ownerId);
$carCount   = count($cars);
if ($ownerData !== null) {
    try {
        $signupDate = !empty($ownerData->join_date) ? new DateTime($ownerData->join_date) : null;
    } catch (\Exception) {
        $signupDate = null;
    }
    $lastLogin   = !empty($ownerData->last_login) ? new DateTime($ownerData->last_login) : null;
    $hasOwnerMap = is_numeric($ownerData->lat ?? null)
        && is_numeric($ownerData->lon ?? null)
        && (float)($ownerData->lat ?? 0) !== 0.0
        && (float)($ownerData->lon ?? 0) !== 0.0;
} else {
    $signupDate  = null;
    $lastLogin   = null;
    $hasOwnerMap = false;
}

$qualityScore = $owner->getProfileQualityScore();

// Owner website (only display for http/https)
$ownerWebsite       = $ownerData->website ?? '';
$ownerWebsiteScheme = !empty($ownerWebsite) ? strtolower((string)parse_url((string)$ownerWebsite, PHP_URL_SCHEME)) : '';
$hasOwnerWebsite    = in_array($ownerWebsiteScheme, ['http', 'https'], true);

$_baseUrl = htmlspecialchars($us_url_root, ENT_QUOTES, 'UTF-8');
?>

<style>
.collapse-toggle-btn {
    background: none;
    border: 1px solid rgba(var(--er-primary-rgb), 0.25);
    border-radius: 0.375rem;
    padding: 0.45rem 1rem;
    color: var(--er-primary);
    font-size: 0.875rem;
    text-align: left;
    width: 100%;
    cursor: pointer;
}
.collapse-toggle-btn:hover { background-color: var(--er-primary-light); }
.er-section-heading { border-bottom: 2px solid var(--er-primary); padding-bottom: 0.5rem; margin-bottom: 1rem; }
.badge-er-account { background-color: var(--er-primary); }
.text-white-75 { color: rgba(255, 255, 255, 0.75); }
</style>

<div id="page-wrapper">
    <div class="container py-4">
        <div class="well">

            <!-- ================================================================
                 PROFILE CARD
                 ================================================================ -->
            <div class="card registry-card mb-4">
                <div class="card-header card-header-er-primary">
                    <h1 class="mb-0 card-header-er-primary-text fs-5">
                        <i class="fas fa-user-circle me-2" aria-hidden="true"></i>My Account
                    </h1>
                </div>
                <div class="card-body">
                    <div class="row align-items-start">

                        <!-- Left: identity + stats + actions -->
                        <div class="<?= $hasOwnerMap ? 'col-md-6' : 'col-12' ?> mb-3 mb-md-0">
                            <?php $displayName = OwnerView::displayName($ownerData); ?>
                            <?php if ($displayName !== ''): ?>
                            <h2 class="h5 mb-1 text-primary fw-bold"><?= $displayName ?></h2>
                            <?php endif; ?>
                            <div class="text-muted small mb-2">@<?= htmlspecialchars($ownerData->username ?? '', ENT_QUOTES, 'UTF-8') ?></div>

                            <div class="small mb-1">
                                <i class="fas fa-map-marker-alt text-primary me-1" aria-hidden="true"></i>
                                <?php $location = OwnerView::displayLocation($ownerData); ?>
                                <?= $location !== '' ? $location : '<span class="text-muted fst-italic">Not specified</span>' ?>
                            </div>
                            <?php if (!empty($ownerData->email)): ?>
                            <div class="small mb-1">
                                <i class="fas fa-envelope text-primary me-1" aria-hidden="true"></i>
                                <a href="mailto:<?= htmlspecialchars($ownerData->email, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($ownerData->email, ENT_QUOTES, 'UTF-8') ?></a>
                            </div>
                            <?php endif; ?>
                            <?php if ($hasOwnerWebsite): ?>
                            <div class="small mb-1">
                                <i class="fas fa-link text-primary me-1" aria-hidden="true"></i>
                                <a href="<?= htmlspecialchars($ownerWebsite, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($ownerWebsite, ENT_QUOTES, 'UTF-8') ?></a>
                            </div>
                            <?php endif; ?>

                            <div class="mt-3 mb-1">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <small class="text-muted">Profile completeness</small>
                                    <small class="fw-bold text-<?= OwnerView::qualityBadgeClass($qualityScore) ?>"><?= (int)$qualityScore ?>%</small>
                                </div>
                                <?= OwnerView::displayQualityProgressBar($qualityScore, '6px') ?>
                            </div>

                            <div class="row text-center g-2 mb-3 mt-3">
                                <div class="col-4">
                                    <div class="text-muted small">Member since</div>
                                    <div class="fw-bold text-primary"><?= $signupDate ? $signupDate->format('M Y') : '' ?></div>
                                </div>
                                <div class="col-4">
                                    <div class="text-muted small">Cars</div>
                                    <div class="fw-bold text-primary"><?= $carCount ?></div>
                                </div>
                                <div class="col-4">
                                    <div class="text-muted small">Last login</div>
                                    <div class="fw-bold text-primary"><?= $lastLogin ? $lastLogin->format('M j') : '—' ?></div>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <a href="<?= $_baseUrl ?>users/user_settings.php" class="btn btn-primary">
                                    <i class="fas fa-user-edit me-1" aria-hidden="true"></i>Update Account
                                </a>
                                <?php if (!empty($settings->passkeys)): ?>
                                <a href="<?= $_baseUrl ?>users/passkeys.php" class="btn btn-outline-secondary">
                                    <?= htmlspecialchars(lang('PASSKEYS_MANAGE_TITLE'), ENT_QUOTES, 'UTF-8') ?>
                                </a>
                                <?php endif; ?>
                                <?php if (!empty($settings->totp)): ?>
                                <a href="<?= $_baseUrl ?>users/totp_management.php" class="btn btn-outline-secondary">
                                    <?= htmlspecialchars(lang('ACCT_2FA'), ENT_QUOTES, 'UTF-8') ?>
                                </a>
                                <?php endif; ?>
                                <?php if (isCloaked()): ?>
                                <form class="d-inline" action="" method="post">
                                    <?= tokenHere() ?>
                                    <input type="hidden" name="uncloak" value="Uncloak!">
                                    <button class="btn btn-danger" type="submit">Uncloak</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Right: owner location map (only when lat/lon are set) -->
                        <?php if ($hasOwnerMap): ?>
                        <div class="col-md-6">
                            <div id="ownerMap" style="height:220px; border-radius:0.375rem; border:1px solid rgba(0,86,63,0.2);"></div>
                            <div class="text-center mt-1">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle" aria-hidden="true"></i> Approximate location
                                </small>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>

            <!-- ================================================================
                 MY CARS SECTION HEADER
                 ================================================================ -->
            <div class="d-flex justify-content-between align-items-center er-section-heading">
                <h2 class="h5 mb-0 text-primary">
                    <i class="fas fa-car me-2" aria-hidden="true"></i>My Cars
                    <span class="badge badge-er-account rounded-pill ms-1" style="font-size:0.7em; vertical-align:middle;"><?= $carCount ?></span>
                </h2>
                <a href="<?= $_baseUrl ?>app/owner/cars/edit.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus me-1" aria-hidden="true"></i>Add a Car
                </a>
            </div>

            <!-- ================================================================
                 CAR LIST
                 ================================================================ -->
            <?php if (empty($cars)): ?>
            <div class="text-center py-5">
                <i class="fas fa-car fa-3x text-muted mb-3 d-block" aria-hidden="true"></i>
                <p class="text-muted mb-3">You haven't registered any cars yet. Add your first Lotus Elan to get started!</p>
                <a class="btn btn-primary btn-lg" href="<?= $_baseUrl ?>app/owner/cars/edit.php">
                    <i class="fas fa-plus me-2" aria-hidden="true"></i>Add Your First Car
                </a>
            </div>
            <?php else: ?>

            <?php foreach ($cars as $car):
                $carData      = $car->data();
                $factoryData  = $car->factory();
                $carId        = (int)$carData->id;
                $collapseId   = 'car-details-' . $carId;
                $purchaseDate = null;
                if (!empty($carData->purchasedate)) {
                    try { $purchaseDate = new DateTime($carData->purchasedate); } catch (\Exception) {}
                }
                $soldDate = null;
                if (!empty($carData->solddate)) {
                    try { $soldDate = new DateTime($carData->solddate); } catch (\Exception) {}
                }
                $buildDate = null;
                if ($factoryData && !empty($factoryData->builddate)) {
                    try { $buildDate = new DateTime($factoryData->builddate); } catch (\Exception) {}
                }
                $isExpanded   = $carCount === 1;

                // 4th quick-fact: Sold date or owner location
                if ($soldDate) {
                    $quickFactLabel = 'Sold';
                    $quickFactValue = htmlspecialchars($soldDate->format('M Y'), ENT_QUOTES, 'UTF-8');
                } elseif (!empty($carData->city)) {
                    $quickFactLabel = 'Location';
                    $quickFactValue = htmlspecialchars((string)$carData->city, ENT_QUOTES, 'UTF-8')
                        . (!empty($carData->state) ? ', ' . htmlspecialchars((string)$carData->state, ENT_QUOTES, 'UTF-8') : '');
                } else {
                    $quickFactLabel = 'Location';
                    $quickFactValue = '<span class="opacity-50 fst-italic">Not specified</span>';
                }
            ?>
            <div class="card registry-card mb-4">

                <!-- Hero strip -->
                <div style="background-color: var(--er-primary); border-top: 5px solid var(--er-accent);">
                    <div class="card-body text-white">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h3 class="mb-2 card-header-er-primary-text">
                                    <i class="fas fa-car me-2" aria-hidden="true"></i><?= htmlspecialchars((string)($carData->year ?? ''), ENT_QUOTES, 'UTF-8') ?> Lotus Elan <?= htmlspecialchars($carData->series ?? '', ENT_QUOTES, 'UTF-8') ?><?php if (!empty($carData->variant)): ?> <small class="fw-normal opacity-75">(<?= htmlspecialchars($carData->variant, ENT_QUOTES, 'UTF-8') ?>)</small><?php endif; ?>
                                </h3>
                                <div class="row g-2 mt-1">
                                    <div class="col-6 col-lg-3">
                                        <div class="text-white-75 small">Registry ID</div>
                                        <div class="fw-bold">#<?= $carId ?></div>
                                    </div>
                                    <div class="col-6 col-lg-3">
                                        <div class="text-white-75 small">Chassis</div>
                                        <div class="fw-bold"><?= htmlspecialchars($carData->chassis ?: 'Not specified', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="col-6 col-lg-3">
                                        <div class="text-white-75 small">Color</div>
                                        <div class="fw-bold"><?= htmlspecialchars($carData->color ?: 'Not specified', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="col-6 col-lg-3">
                                        <div class="text-white-75 small"><?= htmlspecialchars($quickFactLabel, ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="fw-bold"><?= $quickFactValue ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 mt-3 mt-md-0 text-md-end">
                                <?php
                                $context   = 'owner_account';
                                $heroCarId = $carId;
                                include $abs_us_root . $us_url_root . 'app/views/cars/_car_hero_actions.php';
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Collapse toggle row -->
                <div class="px-3 py-2 border-top bg-white">
                    <button class="collapse-toggle-btn"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#<?= $collapseId ?>"
                            data-car-id="<?= $carId ?>"
                            aria-expanded="<?= $isExpanded ? 'true' : 'false' ?>"
                            aria-controls="<?= $collapseId ?>">
                        <i class="fas fa-chevron-<?= $isExpanded ? 'down' : 'right' ?> me-2 toggle-icon" aria-hidden="true"></i>
                        <span class="toggle-label"><?= $isExpanded ? 'Hide Details' : 'Show Details' ?></span>
                    </button>
                </div>

                <!-- Collapsible details -->
                <div class="collapse<?= $isExpanded ? ' show' : '' ?>" id="<?= $collapseId ?>">
                    <div class="card-body pt-2">
                        <div class="row">
                            <div class="col-lg-6 mb-3">
                                <?php
                                $headingTag = 'h4';
                                include $abs_us_root . $us_url_root . 'app/views/cars/_vehicle_info_card.php';
                                ?>
                            </div>
                            <div class="col-lg-6 mb-3">
                                <!-- Photos card -->
                                <div class="card registry-card mb-3">
                                    <div class="card-header card-header-er-l2 py-2">
                                        <h4 class="mb-0 card-header-er-l2-text small">
                                            <i class="fas fa-images me-1" aria-hidden="true"></i>Photos
                                        </h4>
                                    </div>
                                    <div class="card-body">
                                        <?= CarView::displayCarousel($car, $carId) ?>
                                    </div>
                                </div>
                                <!-- Factory Data card (only when factory data exists) -->
                                <?php if (!is_null($factoryData)): ?>
                                <?php
                                $headingTag = 'h4';
                                include $abs_us_root . $us_url_root . 'app/views/cars/_factory_data_card.php';
                                ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /.card -->
            <?php endforeach; ?>

            <?php endif; ?>

        </div><!-- /.well -->
    </div><!-- /.container -->
</div><!-- /#page-wrapper -->

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>

<?php if ($hasOwnerMap): ?>
<link rel="stylesheet" href="<?= $_baseUrl ?>usersc/css/maplibre-gl.css">
<script src="<?= $_baseUrl ?>usersc/js/maplibre-gl.min.js" data-cfasync="false"></script>
<script nonce="<?= htmlspecialchars($usespice_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
(function () {
    if (typeof maplibregl === 'undefined') {
        var mapEl = document.getElementById('ownerMap');
        if (mapEl) {
            mapEl.innerHTML = '<div class="d-flex align-items-center justify-content-center h-100 text-muted small"><i class="fas fa-exclamation-triangle me-2"></i>Map unavailable</div>';
        }
        return;
    }
    var lat      = <?= json_encode((float)$ownerData->lat) ?>;
    var lon      = <?= json_encode((float)$ownerData->lon) ?>;
    var styleUrl = <?= json_encode($us_url_root . 'usersc/js/versatiles-colorful.json') ?>;
    var map = new maplibregl.Map({
        container: 'ownerMap',
        style: styleUrl,
        center: [lon, lat],
        zoom: 9,
        scrollZoom: false,
        attributionControl: false
    });
    map.addControl(new maplibregl.AttributionControl({ compact: true }), 'bottom-right');
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    map.on('load', function () {
        var el = document.createElement('div');
        el.style.cssText = 'width:14px;height:14px;border-radius:50%;background:#00563f;border:2px solid #fff200;box-shadow:0 1px 4px rgba(0,0,0,0.4);';
        new maplibregl.Marker({ element: el }).setLngLat([lon, lat]).addTo(map);
    });
    map.on('error', function (e) {
        if (e.sourceId || e.tile) { console.warn('Map tile error:', e.error); return; }
        map.remove();
        var mapEl = document.getElementById('ownerMap');
        if (mapEl) {
            mapEl.innerHTML = '<div class="d-flex align-items-center justify-content-center h-100 text-muted small"><i class="fas fa-exclamation-triangle me-2"></i>Map unavailable</div>';
        }
    });
}());
</script>
<?php endif; ?>

<script nonce="<?= htmlspecialchars($usespice_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
(function () {
    // Restore localStorage collapse states and sync button UI on page load
    document.querySelectorAll('.collapse[id^="car-details-"]').forEach(function (el) {
        var carId = el.id.replace('car-details-', '');
        var state = localStorage.getItem('er.account.collapse.' + carId);
        if (state === 'collapsed') {
            el.classList.remove('show');
        } else if (state === 'expanded') {
            el.classList.add('show');
        }
        var btn = document.querySelector('[data-bs-target="#' + el.id + '"]');
        if (!btn) return;
        if (el.classList.contains('show')) {
            btn.querySelector('.toggle-icon').className = 'fas fa-chevron-down me-2 toggle-icon';
            btn.querySelector('.toggle-label').textContent = 'Hide Details';
            btn.setAttribute('aria-expanded', 'true');
        } else {
            btn.querySelector('.toggle-icon').className = 'fas fa-chevron-right me-2 toggle-icon';
            btn.querySelector('.toggle-label').textContent = 'Show Details';
            btn.setAttribute('aria-expanded', 'false');
        }
    });

    // On collapse events: swap chevron/label and persist state
    document.querySelectorAll('[data-bs-toggle="collapse"][data-car-id]').forEach(function (btn) {
        var target = document.querySelector(btn.getAttribute('data-bs-target'));
        if (!target) return;
        var carId = btn.dataset.carId;
        target.addEventListener('shown.bs.collapse', function () {
            btn.querySelector('.toggle-icon').className = 'fas fa-chevron-down me-2 toggle-icon';
            btn.querySelector('.toggle-label').textContent = 'Hide Details';
            btn.setAttribute('aria-expanded', 'true');
            localStorage.setItem('er.account.collapse.' + carId, 'expanded');
        });
        target.addEventListener('hidden.bs.collapse', function () {
            btn.querySelector('.toggle-icon').className = 'fas fa-chevron-right me-2 toggle-icon';
            btn.querySelector('.toggle-label').textContent = 'Show Details';
            btn.setAttribute('aria-expanded', 'false');
            localStorage.setItem('er.account.collapse.' + carId, 'collapsed');
        });
    });
}());
</script>
