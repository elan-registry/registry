<?php
if (count(get_included_files()) == 1) { die(); }

/**
 * sold-confirm.php
 *
 * Confirmation card for `app/verify/verify_car.php?vericode=...&action=sold`.
 *
 * A simplified, read-only echo of the edit page with exactly one interactive
 * field: the date of sale. Owners often report a sale months after the fact,
 * so the record must carry the real date rather than "today" — capped at
 * today and floored at the car's build year. Both bounds are set as HTML
 * attributes here AND re-validated server-side in verify_car.php; the
 * attributes are a convenience for the date picker, never the enforcement.
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
 *   $soldDateValue string  Y-m-d value to pre-fill (today, or the rejected
 *                          submission being re-rendered)
 *   $soldDateMin   string  Y-m-d earliest permitted date (Jan 1 of build year)
 *   $soldDateMax   string  Y-m-d latest permitted date (today)
 *   $soldDateError ?string Validation message to render, or null
 */

$verifyCar ??= null;
$verifyPhoto ??= null;
$verifyCode ??= '';
$verifySelfUrl ??= '';
$soldDateValue ??= '';
$soldDateMin ??= '';
$soldDateMax ??= '';
$soldDateError ??= null;

if ($verifyCar === null) {
    return;
}

$verifyCodeAttr = htmlspecialchars($verifyCode, ENT_QUOTES, 'UTF-8');
$verifySelfAttr = htmlspecialchars($verifySelfUrl, ENT_QUOTES, 'UTF-8');
$hasError = $soldDateError !== null;
?>
<div class="card registry-card">
    <div class="card-header card-header-er-primary">
        <h2 class="h5 mb-0 card-header-er-primary-text">
            <i class="fas fa-handshake me-2" aria-hidden="true"></i>
            Confirm your Elan&rsquo;s sale
        </h2>
    </div>

    <?php include __DIR__ . '/_verify_hero.php'; ?>

    <div class="card-body">
        <p>
            You told us this car has been sold. Please check the details below are
            the right car, tell us when it was sold, and confirm. Nothing changes
            until you press the green button.
        </p>

        <?php include __DIR__ . '/_verify_car_details.php'; ?>

        <form method="POST" action="<?= $verifySelfAttr ?>?vericode=<?= $verifyCodeAttr ?>&amp;action=sold">
            <input type="hidden" name="vericode" value="<?= $verifyCodeAttr ?>">

            <div class="form-section-heading">Date of Sale</div>

            <?php if ($hasError): ?>
                <div class="alert alert-danger" role="alert">
                    There&rsquo;s a problem with the date.
                </div>
            <?php endif; ?>

            <div class="mb-2">
                <label for="solddate" class="form-label">When was the car sold?</label>
                <div class="input-group<?= $hasError ? ' has-error' : '' ?>">
                    <span class="input-group-text">
                        <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                    </span>
                    <input type="date"
                           class="form-control<?= $hasError ? ' is-invalid' : '' ?>"
                           id="solddate"
                           name="solddate"
                           value="<?= htmlspecialchars($soldDateValue, ENT_QUOTES, 'UTF-8') ?>"
                           min="<?= htmlspecialchars($soldDateMin, ENT_QUOTES, 'UTF-8') ?>"
                           max="<?= htmlspecialchars($soldDateMax, ENT_QUOTES, 'UTF-8') ?>"
                           required
                           <?= $hasError
                               ? 'autofocus aria-invalid="true" aria-describedby="solddate-error solddate-help"'
                               : 'aria-describedby="solddate-help"' ?>>
                </div>
                <?php if ($hasError): ?>
                    <div id="solddate-error" class="invalid-feedback d-block">
                        <?= htmlspecialchars($soldDateError, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
                <div id="solddate-help" class="form-text">
                    If you don&rsquo;t remember the exact day, the nearest month is
                    fine &mdash; pick any day in it. We&rsquo;ve filled in today&rsquo;s
                    date to start you off.
                </div>
            </div>

            <div class="d-flex flex-column flex-sm-row gap-2 mt-4">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-check me-1" aria-hidden="true"></i>
                    Yes, I&rsquo;ve sold this car
                </button>
                <a class="btn btn-secondary btn-lg"
                   href="<?= $verifySelfAttr ?>?vericode=<?= $verifyCodeAttr ?>">
                    Cancel &mdash; I still own this car
                </a>
            </div>
        </form>

        <p class="text-muted small mt-4 mb-0">
            Confirming tells the registry the car has changed hands. It stays in
            your account marked as sold, its history stays in the registry &mdash;
            and we&rsquo;ll stop asking you to verify it.
        </p>
    </div>
</div>
