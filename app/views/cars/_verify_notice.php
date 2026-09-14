<?php
if (count(get_included_files()) == 1) { die(); }

/**
 * _verify_notice.php
 *
 * Terminal states of app/verify/verify_car.php — the three screens that
 * present an outcome rather than an action:
 *
 *   'verified' — the record is confirmed (the state the verify POST's 303
 *                redirect lands on, and what a later revisit renders)
 *   'sold'     — the car is already recorded as sold (idempotent: a repeat
 *                POST is a strict no-op and renders this)
 *   'invalid'  — the generic expired-or-invalid-link page
 *
 * The first two keep the hero so the owner can see WHICH car this was about.
 * The 'invalid' state deliberately shows no car at all: it is rendered for
 * codes that resolved to nothing as well as for codes that expired, and the
 * two must be indistinguishable.
 *
 * Caller must set:
 *   $verifyNoticeState   string  'verified' | 'sold' | 'invalid'
 *   $verifyNoticeIcon    string  Font Awesome icon class
 *   $verifyNoticeHeading string  Headline text
 *   $verifyNoticeBody    string  Body paragraph
 * And, for the non-invalid states:
 *   $verifyCar     object  Car row
 *   $verifyPhoto   ?string Primary photo URL or null
 *   $verifyEditUrl string  Edit-page URL, or the login URL when logged out
 */

$verifyNoticeState ??= 'invalid';
$verifyNoticeIcon ??= 'fa-circle-info';
$verifyNoticeHeading ??= '';
$verifyNoticeBody ??= '';
$verifyCar ??= null;
$verifyPhoto ??= null;
$verifyEditUrl ??= '';

$isInvalid = $verifyNoticeState === 'invalid';
$iconClass = $isInvalid ? 'text-muted' : 'text-primary';
?>
<div class="card registry-card">
    <?php if (!$isInvalid && $verifyCar !== null): ?>
        <?php include __DIR__ . '/_verify_hero.php'; ?>
    <?php endif; ?>

    <div class="card-body text-center">
        <div class="mb-3">
            <i class="fas <?= htmlspecialchars($verifyNoticeIcon, ENT_QUOTES, 'UTF-8') ?> fa-3x <?= $iconClass ?>"
               aria-hidden="true"></i>
        </div>

        <h2 class="h5 <?= $isInvalid ? '' : 'text-primary fw-bold' ?>">
            <?= htmlspecialchars($verifyNoticeHeading, ENT_QUOTES, 'UTF-8') ?>
        </h2>

        <p><?= htmlspecialchars($verifyNoticeBody, ENT_QUOTES, 'UTF-8') ?></p>

        <?php if ($verifyNoticeState === 'sold'): ?>
            <p>
                If you know the new owner, we&rsquo;d love it if you pointed them at
                <strong>elanregistry.org</strong> &mdash; the car&rsquo;s story
                continues with them.
            </p>
            <p class="text-muted small border-top pt-3 mb-0">
                Marked it sold by mistake, or got the date wrong? The car is still in
                your account &mdash;
                <a href="<?= htmlspecialchars($verifyEditUrl, ENT_QUOTES, 'UTF-8') ?>">sign in and open its edit page</a>
                to fix the sold date, or email the registrar at
                <a href="mailto:registrar@elanregistry.org">registrar@elanregistry.org</a>.
            </p>
        <?php elseif ($verifyNoticeState === 'verified'): ?>
            <p class="text-muted small border-top pt-3 mb-0">
                Something in the record needs changing after all?
                <a href="<?= htmlspecialchars($verifyEditUrl, ENT_QUOTES, 'UTF-8') ?>">Sign in and open its edit page</a>
                to bring it up to date.
            </p>
        <?php else: ?>
            <p class="text-muted small border-top pt-3 mb-0">
                You can still reach your cars by
                <a href="<?= htmlspecialchars($us_url_root, ENT_QUOTES, 'UTF-8') ?>usersc/login.php">signing in</a>,
                or email the registrar at
                <a href="mailto:registrar@elanregistry.org">registrar@elanregistry.org</a>
                and we&rsquo;ll sort it out.
            </p>
        <?php endif; ?>
    </div>
</div>
