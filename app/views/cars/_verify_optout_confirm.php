<?php
if (count(get_included_files()) == 1) { die(); }

/**
 * _verify_optout_confirm.php
 *
 * The one-click opt-out link in verification emails (#1883). The opt-out
 * stops all registry email except account mail (#2360). This partial renders
 * both states of `app/verify/verify_car.php?vericode=...&action=optout`:
 *
 *   before opt-out — the confirmation card, with the POST form that performs
 *                    the opt-out. Nothing has changed yet.
 *   after opt-out  — the success card. Rendered after the POST's 303
 *                    redirect lands back here, and also on first arrival if
 *                    the owner already opted out (idempotent: the same
 *                    success framing, never an error).
 *
 * The distinction is `$optOutAlreadyOptedOut`, which verify_car.php derives
 * from the owner's `profiles.email_opted_out` only — so the post-POST render
 * and a later revisit are the same state, needing no extra "did we just
 * write?" flag. `email_blocked` and `email_bounced` never change this page.
 * The opt-out is stored on the owner's profile and covers every car
 * the owner has, so the card talks about their whole account, not just the
 * car the vericode resolved to.
 *
 * NO CSRF TOKEN — see the rationale in app/verify/verify_car.php's docblock.
 * The vericode carried in the hidden field IS the credential; the visitor
 * arrives from an email with no UserSpice session to bind a token to.
 *
 * Caller must set:
 *   $verifyCar                object  Car row resolved from the vericode
 *   $verifyPhoto              ?string Primary photo URL (-resized-300) or null
 *   $verifyCode               string  The plaintext vericode (format-validated)
 *   $verifySelfUrl            string  URL of this page, without query string
 *   $optOutCarCount           ?int    How many cars the opt-out covers, or null
 *                                     when verify_car.php's count query failed
 *                                     (the count is unknown, not zero)
 *   $optOutAlreadyOptedOut    bool    True once the owner's opt-out is in effect
 *
 * Reads the UserSpice global $us_url_root for the Account Settings link.
 */

$verifyCar ??= null;
$verifyPhoto ??= null;
$verifyCode ??= '';
$verifySelfUrl ??= '';
$optOutCarCount = $optOutCarCount ?? null;
$optOutAlreadyOptedOut ??= false;

if ($verifyCar === null) {
    return;
}

$verifyCodeAttr = htmlspecialchars($verifyCode, ENT_QUOTES, 'UTF-8');
$verifySelfAttr = htmlspecialchars($verifySelfUrl, ENT_QUOTES, 'UTF-8');
// The owner resumes emails from Account Settings (#1895). Signed out, the page sends them to log in first.
$resumeSettingsAttr = htmlspecialchars(($us_url_root ?? '/') . 'usersc/user_settings.php#resume-emails', ENT_QUOTES, 'UTF-8');

// findByOwner() counts every car the owner has; a count of 0 would mean the
// car the vericode resolved to had vanished between the two queries, so floor
// at 1 rather than telling the owner about "your 0 registered cars". A count
// of null means the query itself failed — that is "unknown", not "1", and is
// kept distinct below so the copy can speak generically instead of asserting
// a fabricated number.
$carCount = $optOutCarCount === null ? null : max(1, (int) $optOutCarCount);
$carNoun  = $carCount === 1 ? 'car' : 'cars';
?>
<div class="card registry-card">
    <div class="card-header card-header-er-primary">
        <h2 class="h5 mb-0 card-header-er-primary-text">
            <i class="fas <?= $optOutAlreadyOptedOut ? 'fa-circle-check' : 'fa-envelope-circle-check' ?> me-2"
               aria-hidden="true"></i>
            <?= $optOutAlreadyOptedOut
                ? 'You&rsquo;re unsubscribed from registry email'
                : 'Stop registry email' ?>
        </h2>
    </div>

    <?php include __DIR__ . '/_verify_hero.php'; ?>

    <div class="card-body">
        <?php if ($optOutAlreadyOptedOut): ?>
            <p>
                We stopped all registry email to you except account mail
                (password reset, sign-in, and email change).
                Your cars stay in the registry exactly as they are. Only the
                emails stop.
            </p>

            <p class="text-muted small border-top pt-3 mb-0">
                Changed your mind, or unsubscribed by mistake? Turn email back on in your
                <a href="<?= $resumeSettingsAttr ?>">Account Settings</a>.
                If you cannot sign in, email the registrar at
                <a href="mailto:registrar@elanregistry.org">registrar@elanregistry.org</a>.
            </p>
        <?php else: ?>
            <p>
                Verification emails are how we check that the registry entries are
                still right. We send them at most twice a year.
            </p>

            <div class="alert alert-primary">
                <i class="fas fa-circle-info me-2" aria-hidden="true"></i>
                This stops all registry email to you except account mail
                (password reset, sign-in, and email change).
                <?php if ($carCount === null): ?>
                    <strong>Your registered cars</strong> and their history stay in
                    the registry.
                <?php else: ?>
                    Your <strong><?= $carCount ?> registered <?= $carNoun ?></strong>
                    and <?= $carCount === 1 ? 'its' : 'their' ?> history stay in the
                    registry.
                <?php endif; ?>
            </div>

            <?php // No CSRF token by design: the vericode is the credential. See the "NO CSRF TOKEN" header in app/verify/verify_car.php. ?>
            <form method="POST" action="<?= $verifySelfAttr ?>?vericode=<?= $verifyCodeAttr ?>&amp;action=optout">
                <input type="hidden" name="vericode" value="<?= $verifyCodeAttr ?>">

                <div class="d-flex flex-column flex-sm-row gap-2 mt-4">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-envelope-circle-check me-1" aria-hidden="true"></i>
                        Yes, stop registry email
                    </button>
                    <a class="btn btn-secondary btn-lg"
                       href="<?= $verifySelfAttr ?>?vericode=<?= $verifyCodeAttr ?>">
                        Cancel &mdash; keep getting email
                    </a>
                </div>
            </form>

            <p class="text-muted small mt-4 mb-0">
                Nothing changes until you press the button above. If you unsubscribe
                and later change your mind, turn email back on in your
                <a href="<?= $resumeSettingsAttr ?>">Account Settings</a>.
                If you cannot sign in, email the registrar at
                <a href="mailto:registrar@elanregistry.org">registrar@elanregistry.org</a>.
            </p>
        <?php endif; ?>
    </div>
</div>
