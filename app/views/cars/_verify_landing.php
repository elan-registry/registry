<?php
if (count(get_included_files()) == 1) { die(); }

/**
 * _verify_landing.php
 *
 * The no-action landing page for app/verify/verify_car.php — what an owner
 * sees when they open the verification link itself rather than one of its
 * two action links. Offers the full option set: Verify, Sold, and Review &
 * Update, followed by the car detail so the owner can review everything the
 * registry holds before deciding. The sections, in order, use the same
 * partials as app/owner/cars/details.php:
 *   Vehicle Information  _vehicle_info_card.php
 *   Photos               _photos_card.php
 *   Owner Information    _owner_info_card.php (no Registry Owner ID)
 *   Location             _location_map_card.php, then _location_map_scripts.php
 * There is no History section (#2144) and no Factory Data section.
 *
 * The CTA buttons are placed ABOVE the detail cards deliberately: the
 * decision (still accurate / sold / needs a full edit) is the reason the
 * owner is here, and the detail below is reference material to check before
 * confirming, not a gate the owner must scroll past to find the buttons.
 * The cards make the page long on a phone, so the Confirm button repeats
 * after the last card, with a link back to all the options at the top.
 *
 * Every option here is a plain GET link to a confirmation step; nothing on
 * this page mutates.
 *
 * cars.email/fname/lname/city/state/country/website/lat/lon are denormalized
 * owner-contact columns synced onto every car row (see DATABASE.md's `cars`
 * table note) — $verifyCar (a plain `cars` row from
 * CarRepository::findByVerificationCode()) already carries them, so no
 * additional Owner lookup is needed to render Owner Information here. The
 * public car details page shows the same owner and car data, photos and map
 * to any visitor, so showing it to the vericode holder discloses nothing new.
 *
 * Caller must set:
 *   $verifyCar         object  Car row resolved from the vericode
 *   $verifyCarModel    Car     The same car as an ElanRegistry\Car\Car
 *   $verifyPhoto       ?string Primary photo URL (-resized-300 variant) or null
 *   $verifyCode        string  The plaintext vericode (already format-validated)
 *   $verifySelfUrl     string  URL of this page, without query string
 *   $verifyEditUrl     string  Edit-page URL, or the login URL when logged out
 *                              (the caller has already called safelyCaptureDest())
 *   $us_url_root       string  UserSpice URL root
 *   $userspice_nonce   string  CSP nonce for the inline map config script
 */

$verifyCar ??= null;
$verifyCarModel ??= null;
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
    } catch (DateMalformedStringException $e) {
        logger(0, ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            "_verify_landing.php: invalid purchase date format for car {$verifyCar->id}: {$verifyCar->purchasedate}");
        $purchaseDate = null;
    }
}

// The name is $soldDate, not $verifySoldDate, because _vehicle_info_card.php
// reads $soldDate.
$soldDate    = ElanRegistry\Car\CarBadges::soldDate($verifyCar->solddate ?? null, $verifyCar->id ?? null);
$alreadySold = ElanRegistry\Car\CarBadges::isSold($verifyCar->solddate ?? null);

// _photos_card.php reads $car. _owner_info_card.php reads $showOwnerId.
$car         = $verifyCarModel;
$showOwnerId = false;
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

        <div id="verify-actions" class="d-grid gap-2 mb-4">
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

        <?php
        include __DIR__ . '/_photos_card.php';
        include __DIR__ . '/_owner_info_card.php';
        include __DIR__ . '/_location_map_card.php';
        // The scripts load here because this partial renders inside the page
        // body, before the footer, and car-details-map.js reads #map at once.
        include __DIR__ . '/_location_map_scripts.php';
        ?>

        <div class="d-grid gap-2 mt-4">
            <a class="btn btn-primary btn-lg"
               href="<?= $verifySelfAttr ?>?vericode=<?= $verifyCodeAttr ?>&amp;action=verify">
                <i class="fas fa-check me-1" aria-hidden="true"></i>
                Yes, everything above is still correct
            </a>
            <a class="btn btn-link" href="#verify-actions">Back to all options</a>
        </div>
    </div>
</div>
