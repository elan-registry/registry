<?php
if (count(get_included_files()) == 1) { die(); }

/**
 * _verify_optout_confirm.php
 *
 * Both states of `app/verify/verify_car.php?vericode=...&action=optout` —
 * the one-click opt-out from verification emails (#1883):
 *
 *   pre-suppression  — the confirmation card, with the POST form that performs
 *                      the opt-out. Nothing has changed yet.
 *   post-suppression — the success card. Rendered after the POST's 303
 *                      redirect lands back here, and also on first arrival if
 *                      the owner is already suppressed (idempotent: the same
 *                      success framing, never an error).
 *
 * The distinction is `$optOutAlreadySuppressed`, which verify_car.php derives
 * from the car's stored `email_suppressed` — so the post-POST render and a
 * later revisit are the same state, needing no extra "did we just write?"
 * flag. Opting out fans out across every car the owner has, so the card talks
 * about their whole account, not just the car the vericode resolved to.
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
 *   $optOutCarCount           int     How many cars the opt-out covers
 *   $optOutAlreadySuppressed  bool    True once suppression is in effect
 */

$verifyCar ??= null;
$verifyPhoto ??= null;
$verifyCode ??= '';
$verifySelfUrl ??= '';
$optOutCarCount ??= 0;
$optOutAlreadySuppressed ??= false;

if ($verifyCar === null) {
    return;
}

$verifyCodeAttr = htmlspecialchars($verifyCode, ENT_QUOTES, 'UTF-8');
$verifySelfAttr = htmlspecialchars($verifySelfUrl, ENT_QUOTES, 'UTF-8');

// findByOwner() counts every car the owner has; a count of 0 would mean the
// car the vericode resolved to had vanished between the two queries, so floor
// at 1 rather than telling the owner about "your 0 registered cars".
$carCount = max(1, (int) $optOutCarCount);
$carNoun  = $carCount === 1 ? 'car' : 'cars';
?>
<div class="card registry-card">
    <div class="card-header card-header-er-primary">
        <h2 class="h5 mb-0 card-header-er-primary-text">
            <i class="fas <?= $optOutAlreadySuppressed ? 'fa-circle-check' : 'fa-envelope-circle-check' ?> me-2"
               aria-hidden="true"></i>
            <?= $optOutAlreadySuppressed
                ? 'You&rsquo;re unsubscribed from verification emails'
                : 'Stop verification emails' ?>
        </h2>
    </div>

    <?php include __DIR__ . '/_verify_hero.php'; ?>

    <div class="card-body">
        <?php if ($optOutAlreadySuppressed): ?>
            <p>
                We won&rsquo;t send you any more verification emails &mdash; not for
                this car, and not for
                <?= $carCount === 1
                    ? 'any other car you register'
                    : 'any of your ' . $carCount . ' registered cars' ?>.
                Your cars stay in the registry exactly as they are; only the
                emails stop.
            </p>

            <p class="text-muted small border-top pt-3 mb-0">
                Changed your mind, or unsubscribed by mistake? Email the registrar at
                <a href="mailto:registrar@elanregistry.org">registrar@elanregistry.org</a>
                and we&rsquo;ll turn them back on.
                <?php /* Self-service resume from Account Settings is #1365, not yet built. */ ?>
            </p>
        <?php else: ?>
            <p>
                Verification emails are how we check the registry entries are still
                right &mdash; we send them at most twice a year. If you&rsquo;d
                rather not get them, we&rsquo;ll stop.
            </p>

            <div class="alert alert-info" role="alert">
                <i class="fas fa-circle-info me-2" aria-hidden="true"></i>
                You won&rsquo;t receive verification emails for any of your
                <strong><?= $carCount ?> registered <?= $carNoun ?></strong>.
                Nothing else changes: your <?= $carNoun ?> and
                <?= $carCount === 1 ? 'its' : 'their' ?> history stay in the
                registry, and we&rsquo;ll still email you about your account when
                you ask us to.
            </div>

            <form method="POST" action="<?= $verifySelfAttr ?>?vericode=<?= $verifyCodeAttr ?>&amp;action=optout">
                <input type="hidden" name="vericode" value="<?= $verifyCodeAttr ?>">

                <div class="d-flex flex-column flex-sm-row gap-2 mt-4">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-envelope-circle-check me-1" aria-hidden="true"></i>
                        Yes, stop sending me these
                    </button>
                    <a class="btn btn-secondary btn-lg"
                       href="<?= $verifySelfAttr ?>?vericode=<?= $verifyCodeAttr ?>">
                        Cancel &mdash; keep sending them
                    </a>
                </div>
            </form>

            <p class="text-muted small mt-4 mb-0">
                Nothing changes until you press the button above. If you unsubscribe
                and later change your mind, email the registrar at
                <a href="mailto:registrar@elanregistry.org">registrar@elanregistry.org</a>.
                <?php /* Self-service resume from Account Settings is #1365, not yet built. */ ?>
            </p>
        <?php endif; ?>
    </div>
</div>
