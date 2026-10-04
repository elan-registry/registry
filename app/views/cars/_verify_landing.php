<?php
if (count(get_included_files()) == 1) { die(); }

/**
 * _verify_landing.php
 *
 * The no-action landing page for app/verify/verify_car.php — what an owner
 * sees when they open the verification link itself rather than one of its
 * two action links. Offers the full option set: Verify, Sold, and Review &
 * Update, followed by the full Vehicle Information and Owner Information
 * detail (reusing app/views/cars/_vehicle_info_card.php, the same partial
 * app/owner/cars/details.php uses) so the owner can review everything the
 * registry holds before deciding.
 *
 * The CTA buttons are placed ABOVE the detail cards deliberately: the
 * decision (still accurate / sold / needs a full edit) is the reason the
 * owner is here, and the detail below is reference material to check before
 * confirming, not a gate the owner must scroll past to find the buttons.
 *
 * Every option here is a plain GET link to a confirmation step; nothing on
 * this page mutates.
 *
 * cars.email/fname/lname/city/state/country/website/lat/lon are denormalized
 * owner-contact columns synced onto every car row (see DATABASE.md's `cars`
 * table note) — $verifyCar (a plain `cars` row from
 * CarRepository::findByVerificationCode()) already carries them, so no
 * additional Owner lookup is needed to render Owner Information here. This
 * is the same data the verification email itself already sent the owner
 * (see the email mockup's Owner Information / Car Information boxes), so
 * showing it back to the vericode holder discloses nothing new.
 *
 * Caller must set:
 *   $verifyCar     object  Car row resolved from the vericode
 *   $verifyPhoto   ?string Primary photo URL (-resized-300 variant) or null
 *   $verifyCode    string  The plaintext vericode (already format-validated)
 *   $verifySelfUrl string  URL of this page, without query string
 *   $verifyEditUrl string  Edit-page URL, or the login URL when logged out
 *                          (the caller has already called safelyCaptureDest())
 */

$verifyCar ??= null;
$verifyPhoto ??= null;
$verifyCode ??= '';
$verifySelfUrl ??= '';
$verifyEditUrl ??= '';

if ($verifyCar === null) {
    return;
}

$verifyCodeAttr = htmlspecialchars($verifyCode, ENT_QUOTES, 'UTF-8');
$verifySelfAttr = htmlspecialchars($verifySelfUrl, ENT_QUOTES, 'UTF-8');
$verifyEditAttr = htmlspecialchars($verifyEditUrl, ENT_QUOTES, 'UTF-8');

// _vehicle_info_card.php's contract: $carData (the car row itself — same
// shape $verifyCar already is) plus $purchaseDate/$soldDate as date
// objects or null. Mirrors app/owner/cars/details.php's own construction of
// these. A bad purchase date degrades to null. Sold uses CarBadges::isSold(),
// the same rule as verify_car.php's $isSold.
$carData = $verifyCar;

$purchaseDate = null;
if (!empty($verifyCar->purchasedate)) {
    try {
        $purchaseDate = new DateTime($verifyCar->purchasedate);
    } catch (Exception $e) {
        logger(0, ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            "verify_car.php: invalid purchase date format for car {$verifyCar->id}: {$verifyCar->purchasedate}");
        $purchaseDate = null;
    }
}

// Named $soldDate (not $verifySoldDate) deliberately — this is
// _vehicle_info_card.php's actual expected variable name (see its own
// docblock). verify_car.php's dispatch-time globals ($soldDateValue,
// $soldDateMin, $soldDateMax, $soldDateError) are all distinct names, so
// this doesn't collide with renderVerifyPage()'s `global` list.
$soldDate    = ElanRegistry\Car\CarBadges::soldDate($verifyCar->solddate ?? null, $verifyCar->id ?? null);
$alreadySold = ElanRegistry\Car\CarBadges::isSold($verifyCar->solddate ?? null);

$verifyOwnerLocation = ElanRegistry\OwnerView::displayLocation($verifyCar);
$verifyOwnerWebsite  = ElanRegistry\OwnerView::websiteUrl($verifyCar->website ?? '');
?>
<div class="card registry-card">
    <div class="card-header card-header-er-primary">
        <h2 class="h5 mb-0 card-header-er-primary-text">
            <i class="fas fa-clipboard-check me-2" aria-hidden="true"></i>
            Is your Elan&rsquo;s registry entry still correct?
        </h2>
    </div>

    <?php include __DIR__ . '/_verify_hero.php'; ?>

    <div class="card-body">
        <p>
            We check in with owners from time to time so the registry stays
            accurate. Please pick whichever is true &mdash; nothing changes
            until you confirm on the next screen. The full record we hold is
            below if you&rsquo;d like to check it first.
        </p>

        <div class="d-grid gap-2 mb-4">
            <a class="btn btn-primary btn-lg"
               href="<?= $verifySelfAttr ?>?vericode=<?= $verifyCodeAttr ?>&amp;action=verify">
                <i class="fas fa-check me-1" aria-hidden="true"></i>
                Yes, everything below is still correct
            </a>

            <?php if ($alreadySold): ?>
                <button type="button" class="btn btn-secondary btn-lg" disabled>
                    <i class="fas fa-handshake me-1" aria-hidden="true"></i>
                    Already recorded as sold
                </button>
            <?php else: ?>
                <a class="btn btn-danger btn-lg"
                   href="<?= $verifySelfAttr ?>?vericode=<?= $verifyCodeAttr ?>&amp;action=sold">
                    <i class="fas fa-handshake me-1" aria-hidden="true"></i>
                    I&rsquo;ve sold this car
                </a>
            <?php endif; ?>

            <a class="btn btn-warning btn-lg" href="<?= $verifyEditAttr ?>">
                <i class="fas fa-pen-to-square me-1" aria-hidden="true"></i>
                Login and update your car
            </a>
        </div>

        <?php
        $headingTag = 'h3';
        include __DIR__ . '/_vehicle_info_card.php';
        ?>

        <div class="card registry-card">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-user text-primary" aria-hidden="true"></i> Owner Information</h3>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4 text-muted">
                        <i class="fas fa-user text-primary" aria-hidden="true"></i> Owner Name
                    </dt>
                    <dd class="col-sm-8">
                        <?= !empty($verifyCar->fname)
                            ? htmlspecialchars(ucfirst($verifyCar->fname), ENT_QUOTES, 'UTF-8')
                            : '<em class="text-muted">Not specified</em>' ?>
                    </dd>

                    <dt class="col-sm-4 text-muted">
                        <i class="fas fa-map-marker-alt text-primary" aria-hidden="true"></i> Location
                    </dt>
                    <dd class="col-sm-8">
                        <?= $verifyOwnerLocation !== ''
                            ? $verifyOwnerLocation
                            : '<em class="text-muted">Location not specified</em>' ?>
                    </dd>

                    <?php if ($verifyOwnerWebsite !== null): ?>
                    <dt class="col-sm-4 text-muted">
                        <i class="fas fa-link text-primary" aria-hidden="true"></i> Website
                    </dt>
                    <dd class="col-sm-8">
                        <a href="<?= htmlspecialchars($verifyOwnerWebsite, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">
                            <?= htmlspecialchars($verifyOwnerWebsite, ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
</div>
