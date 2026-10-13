<?php

declare(strict_types=1);

if (count(get_included_files()) == 1) {
    die();
}

/**
 * _email_paused_notice.php
 *
 * The email notice on usersc/account.php (#1899, #2356). It tells an owner
 * that registry email to their address is blocked, bounced or opted out,
 * and links to the controls that fix it. The notice changes no state. Its
 * only actions are links and a dismiss button that writes sessionStorage.
 *
 * Caller must set:
 *   $emailNotice      ?array   Return value of EmailNoticeBuilder::buildForOwner().
 *                              Null renders nothing.
 *   $ownerId          int      Logged-in owner id (part of the dismissal key)
 *   $us_url_root      string   UserSpice URL root
 *   $userspice_nonce  ?string  CSP nonce for the inline script
 *
 * The builder returns raw values. This file escapes every value it prints.
 */

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EmailNoticeBuilder;

/**
 * @var array{
 *   kind: 'blocked'|'bounced'|'opted_out',
 *   address: ?string,
 *   date: ?string,
 *   cause: ?string,
 *   alsoOptedOut: bool,
 *   optedOutDate: ?string,
 *   contentHash: string
 * }|null $emailNotice
 * @var int $ownerId
 * @var string $us_url_root
 */
if (empty($emailNotice)) {
    return;
}

$_en = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$_enDate = static function (?string $date) use ($_en): ?string {
    if ($date === null) {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed === false ? null : '<strong>' . $_en($parsed->format('j F Y')) . '</strong>';
};

$_enCauseText = [
    CarRepository::BLOCKED_CAUSE_SPAM         => 'a message to this address was reported as spam',
    CarRepository::BLOCKED_CAUSE_UNSUBSCRIBED => 'this address unsubscribed from registry email',
    CarRepository::BLOCKED_CAUSE_BLOCKLIST    => 'an administrator blocked this address',
];

$_enKind      = $emailNotice['kind'];
$_enAddress   = '<strong>' . $_en($emailNotice['address'] ?? '') . '</strong>';
$_enWhen      = $_enDate($emailNotice['date']);
$_enCause     = $_enCauseText[$emailNotice['cause'] ?? ''] ?? null;
if ($_enKind === EmailNoticeBuilder::KIND_BLOCKED) {
    $_enMessage = 'Email to ' . $_enAddress . ' is blocked' . ($_enCause !== null ? ' because ' . $_en($_enCause) : '') . '.';
} elseif ($_enKind === EmailNoticeBuilder::KIND_BOUNCED) {
    $_enMessage = 'Email to ' . $_enAddress . ' bounced' . ($_enWhen !== null ? ' on ' . $_enWhen : '') . '.';
} else {
    $_enMessage = 'You opted out of registry email' . ($_enWhen !== null ? ' on ' . $_enWhen : '') . '.';
}
$_enLineClass = $_enKind === EmailNoticeBuilder::KIND_OPTED_OUT ? 'opted-out' : 'problem';
$_enIcon      = $_enKind === EmailNoticeBuilder::KIND_OPTED_OUT ? 'fa-envelope' : 'fa-exclamation-triangle';

$_enSettingsUrl = $_en($us_url_root . 'usersc/user_settings.php');
$_enContactUrl  = $_en($us_url_root . 'app/owner/contact/index.php');
$_enDismissKey  = 'er.emailNotice.dismissed.' . $ownerId . '.' . $emailNotice['contentHash'];
?>
<div id="email-paused-notice" class="er-email-notice" role="status"
     aria-labelledby="email-notice-message"
     data-dismiss-key="<?= $_en($_enDismissKey) ?>">
    <style>
    /* Only account.php shows this notice, so its rules stay here and not in
       customizer.css (account.php keeps its own one-off rules local too). */
    .er-email-notice {
        position: relative;
        background: #fff;
        border: 1px solid rgba(var(--er-primary-rgb), 0.2);
        border-radius: 0.5rem;
        padding: 1rem 3.25rem 0.25rem 1rem;
        margin-bottom: 1.5rem;
        font-size: 1rem;
    }
    .er-email-notice__line {
        border-left: 5px solid var(--er-primary);
        background: var(--er-primary-light);
        border-radius: 0.375rem;
        padding: 0.75rem 1rem;
        margin-bottom: 0.75rem;
    }
    .er-email-notice__line--problem {
        border-left-color: var(--er-warning);
        background: rgba(var(--er-warning-rgb), 0.1);
    }
    .er-email-notice__icon { color: var(--er-primary); }
    .er-email-notice__line--problem .er-email-notice__icon { color: var(--er-warning); }
    .er-email-notice__dismiss {
        position: absolute;
        top: 0.25rem;
        right: 0.25rem;
        width: 44px;
        height: 44px;
        padding: 0;
        box-sizing: border-box;
    }
    </style>
    <script nonce="<?= $_en($userspice_nonce ?? '') ?>">
    (function () {
        var notice = document.getElementById('email-paused-notice');
        try {
            if (notice && window.sessionStorage.getItem(notice.dataset.dismissKey) === '1') {
                notice.remove();
            }
        } catch (e) {
            // Storage is blocked: show the notice.
        }
    }());
    </script>
    <button type="button" class="btn-close er-email-notice__dismiss"
            aria-label="Dismiss this notice until your next visit"></button>

    <div class="er-email-notice__line er-email-notice__line--<?= $_enLineClass ?>">
        <p class="mb-2 text-break" id="email-notice-message">
            <i class="fas <?= $_enIcon ?> me-2 er-email-notice__icon" aria-hidden="true"></i><?= $_enMessage ?>
        </p>
        <?php if ($_enKind !== EmailNoticeBuilder::KIND_OPTED_OUT): ?>
        <p class="mb-2">Change your email address or contact an admin.</p>
        <div class="d-grid gap-2 d-sm-block mb-2">
            <a class="btn btn-primary me-sm-2" href="<?= $_enSettingsUrl ?>#account-email">Change email</a>
            <a class="btn btn-outline-primary" href="<?= $_enContactUrl ?>">Contact an admin</a>
        </div>
            <?php if ($emailNotice['alsoOptedOut']): ?>
        <p class="mb-2">You also opted out of registry email.</p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($_enKind === EmailNoticeBuilder::KIND_OPTED_OUT || $emailNotice['alsoOptedOut']): ?>
        <div class="d-grid d-sm-block mb-2">
            <a class="btn <?= $emailNotice['alsoOptedOut'] ? 'btn-outline-primary' : 'btn-primary' ?>" href="<?= $_enSettingsUrl ?>#resume-emails">Resume</a>
        </div>
        <?php endif; ?>
    </div>
</div>
<script nonce="<?= $_en($userspice_nonce ?? '') ?>">
(function () {
    var notice = document.getElementById('email-paused-notice');
    if (!notice) {
        return;
    }
    notice.querySelector('.er-email-notice__dismiss').addEventListener('click', function () {
        try {
            window.sessionStorage.setItem(notice.dataset.dismissKey, '1');
        } catch (e) {
            // Storage is blocked: hide the notice for this page view only.
        }
        notice.remove();
        var heading = document.querySelector('#page-wrapper h1');
        if (heading) {
            heading.setAttribute('tabindex', '-1');
            heading.focus();
        }
    });
}());
</script>
