<?php
if (count(get_included_files()) == 1) { die(); }

/**
 * _verify_landing.php
 *
 * The no-action landing page for app/verify/verify_car.php — what an owner
 * sees when they open the verification link itself rather than one of its
 * two action links. Offers the full option set: Verify, Sold, and Review &
 * Update.
 *
 * Every option here is a plain GET link to a confirmation step; nothing on
 * this page mutates.
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
$alreadySold    = !empty($verifyCar->solddate);
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
            accurate. Please pick whichever of these is true &mdash; nothing
            changes until you confirm on the next screen.
        </p>

        <?php include __DIR__ . '/_verify_car_details.php'; ?>

        <div class="d-grid gap-2">
            <a class="btn btn-primary btn-lg"
               href="<?= $verifySelfAttr ?>?vericode=<?= $verifyCodeAttr ?>&amp;action=verify">
                <i class="fas fa-check me-1" aria-hidden="true"></i>
                Yes, everything above is still correct
            </a>

            <?php if ($alreadySold): ?>
                <button type="button" class="btn btn-secondary btn-lg" disabled>
                    <i class="fas fa-handshake me-1" aria-hidden="true"></i>
                    Already recorded as sold
                </button>
            <?php else: ?>
                <a class="btn btn-outline-primary btn-lg"
                   href="<?= $verifySelfAttr ?>?vericode=<?= $verifyCodeAttr ?>&amp;action=sold">
                    <i class="fas fa-handshake me-1" aria-hidden="true"></i>
                    I&rsquo;ve sold this car
                </a>
            <?php endif; ?>

            <a class="btn btn-outline-primary btn-lg" href="<?= $verifyEditAttr ?>">
                <i class="fas fa-pen-to-square me-1" aria-hidden="true"></i>
                Review &amp; update the full record
            </a>
        </div>

        <p class="text-muted small mt-4 mb-0">
            Reviewing the full record needs you to sign in, because it shows and
            edits everything we hold about your car. The two options above it work
            straight from this link.
        </p>
    </div>
</div>
