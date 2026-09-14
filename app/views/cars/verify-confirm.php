<?php
if (count(get_included_files()) == 1) { die(); }

/**
 * verify-confirm.php
 *
 * Confirmation card for `app/verify/verify_car.php?vericode=...&action=verify`.
 *
 * The Sold card's layout minus the date field: the owner is confirming that
 * the record as it stands is still accurate, so there is nothing to collect.
 * A single POST button performs the write; Cancel is a plain GET link back to
 * the landing page, leaving the verification link still usable.
 *
 * NO CSRF TOKEN — see the rationale in app/verify/verify_car.php's docblock.
 * The vericode carried in the hidden field IS the credential; the visitor
 * arrives from an email with no UserSpice session to bind a token to.
 *
 * Caller must set:
 *   $verifyCar     object  Car row resolved from the vericode
 *   $verifyPhoto   ?string Primary photo URL (-resized-300 variant) or null
 *   $verifyCode    string  The plaintext vericode (already format-validated)
 *   $verifySelfUrl string  URL of this page, without query string
 */

$verifyCar ??= null;
$verifyPhoto ??= null;
$verifyCode ??= '';
$verifySelfUrl ??= '';

if ($verifyCar === null) {
    return;
}

$verifyCodeAttr = htmlspecialchars($verifyCode, ENT_QUOTES, 'UTF-8');
$verifySelfAttr = htmlspecialchars($verifySelfUrl, ENT_QUOTES, 'UTF-8');
?>
<div class="card registry-card">
    <div class="card-header card-header-er-primary">
        <h2 class="h5 mb-0 card-header-er-primary-text">
            <i class="fas fa-check-circle me-2" aria-hidden="true"></i>
            Confirm your Elan&rsquo;s details are still correct
        </h2>
    </div>

    <?php include __DIR__ . '/_verify_hero.php'; ?>

    <div class="card-body">
        <p>
            Please check the details below are the right car and still accurate.
            Nothing changes until you press the green button.
        </p>

        <?php include __DIR__ . '/_verify_car_details.php'; ?>

        <form method="POST" action="<?= $verifySelfAttr ?>?vericode=<?= $verifyCodeAttr ?>&amp;action=verify">
            <input type="hidden" name="vericode" value="<?= $verifyCodeAttr ?>">
            <div class="d-flex flex-column flex-sm-row gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-check me-1" aria-hidden="true"></i>
                    Yes, this is still accurate
                </button>
                <a class="btn btn-secondary btn-lg"
                   href="<?= $verifySelfAttr ?>?vericode=<?= $verifyCodeAttr ?>">
                    Cancel &mdash; go back
                </a>
            </div>
        </form>

        <p class="text-muted small mt-4 mb-0">
            Confirming tells the registry your car&rsquo;s record is up to date, so
            we won&rsquo;t need to ask you again for a while.
        </p>
    </div>
</div>
