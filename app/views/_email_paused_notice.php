<?php

declare(strict_types=1);

if (count(get_included_files()) == 1) {
    die();
}

/**
 * _email_paused_notice.php
 *
 * The "email paused" notice on usersc/account.php (#1899). It tells an owner
 * that verification emails to one or more of their car addresses stopped,
 * and links to the two Account Settings controls that fix it. The notice
 * changes no state. Its only actions are links, a dismiss button that
 * writes sessionStorage, and disclosure toggles.
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

use ElanRegistry\Car\EmailNoticeBuilder;

/**
 * @var array{
 *   addresses: list<array{
 *     address: string,
 *     suppressed: array{cause: 'owner_optout'|'brevo_complaint', date: ?string}|null,
 *     bounced: array{date: ?string}|null
 *   }>,
 *   overflowCount: int,
 *   hasSuppressed: bool,
 *   hasBounced: bool,
 *   contentHash: string
 * }|null $emailNotice
 * @var int $ownerId
 * @var string $us_url_root
 */
if (empty($emailNotice)) {
    return;
}

$_en = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * Join escaped, bolded addresses as "a", "a and b" or "a, b and c".
 *
 * @param list<string> $addresses
 */
$_enList = static function (array $addresses) use ($_en): string {
    $items = array_map(static fn (string $a): string => '<strong>' . $_en($a) . '</strong>', $addresses);
    $last  = array_pop($items);
    return $items === [] ? (string) $last : implode(', ', $items) . ' and ' . $last;
};

$_enDate = static function (?string $date) use ($_en): ?string {
    if ($date === null) {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed === false ? null : '<strong>' . $_en($parsed->format('j F Y')) . '</strong>';
};

$_enOptOut    = [];
$_enComplaint = [];
$_enBounced   = [];
foreach ($emailNotice['addresses'] as $_enEntry) {
    if ($_enEntry['suppressed'] !== null) {
        if ($_enEntry['suppressed']['cause'] === EmailNoticeBuilder::CAUSE_BREVO_COMPLAINT) {
            $_enComplaint[] = $_enEntry;
        } else {
            $_enOptOut[] = $_enEntry;
        }
    }
    if ($_enEntry['bounced'] !== null) {
        $_enBounced[] = $_enEntry;
    }
}
$_enAddresses = static fn (array $entries): array => array_column($entries, 'address');

$_enSettingsUrl = $_en($us_url_root . 'usersc/user_settings.php');
$_enCarsUrl     = $_en($us_url_root . 'app/owner/cars/index.php');
$_enDismissKey  = 'er.emailNotice.dismissed.' . $ownerId . '.' . $emailNotice['contentHash'];
$_enOverflow    = $emailNotice['overflowCount'];
$_enLabelledBy  = trim(
    ($emailNotice['hasSuppressed'] ? 'email-notice-suppressed-title ' : '')
    . ($emailNotice['hasBounced'] ? 'email-notice-bounced-title' : '')
);
?>
<div id="email-paused-notice" class="er-email-notice" role="status"
     aria-labelledby="<?= $_en($_enLabelledBy) ?>"
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
    .er-email-notice__line--bounced {
        border-left-color: var(--er-warning);
        background: rgba(var(--er-warning-rgb), 0.1);
    }
    .er-email-notice__title { font-weight: 700; color: var(--er-primary); margin-bottom: 0.25rem; }
    .er-email-notice__line--bounced .er-email-notice__title { color: var(--er-neutral-dark); }
    .er-email-notice__line--bounced .er-email-notice__icon { color: var(--er-warning); }
    .er-email-notice__why {
        padding: 0;
        color: var(--er-link);
        text-decoration: underline;
        font-size: 1rem;
    }
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

    <?php if ($emailNotice['hasSuppressed']): ?>
    <div class="er-email-notice__line er-email-notice__line--suppressed">
        <p class="er-email-notice__title" id="email-notice-suppressed-title">
            <i class="fas fa-envelope me-2 er-email-notice__icon" aria-hidden="true"></i>Your car-verification emails are paused
        </p>
        <?php if ($_enOptOut !== []): ?>
        <p class="mb-2">You asked us to stop sending them to <?= $_enList($_enAddresses($_enOptOut)) ?>, and we have.</p>
        <?php endif; ?>
        <?php if ($_enComplaint !== []): ?>
        <p class="mb-2">Our email provider flagged <?= $_enList($_enAddresses($_enComplaint)) ?> as unreachable or unwanted, so we stopped sending to be on the safe side.</p>
        <?php endif; ?>
        <?php if ($_enOptOut === [] && $_enComplaint === []): ?>
        <p class="mb-2">Verification emails are paused for one or more of your email addresses.</p>
        <?php endif; ?>
        <?php if ($_enComplaint === []): ?>
        <p class="mb-2">Nothing is wrong with your account. You can turn them back on whenever you like.</p>
        <?php else: ?>
        <p class="mb-2">Nothing is wrong with your account. For an address our email provider flagged, please contact the registry and an admin can turn them back on.</p>
        <?php endif; ?>
        <?php if ($_enOptOut !== [] || $_enComplaint !== []): ?>
        <p class="mb-2">
            <button type="button" class="btn btn-link er-email-notice__why" data-bs-toggle="collapse"
                    data-bs-target="#email-notice-why-suppressed" aria-expanded="false"
                    aria-controls="email-notice-why-suppressed">Why am I seeing this?</button>
        </p>
        <div class="collapse" id="email-notice-why-suppressed">
            <ul class="mb-2">
                <?php foreach ($_enOptOut as $_enEntry): ?>
                    <?php $_enWhen = $_enDate($_enEntry['suppressed']['date']); ?>
                <li><?= $_enWhen !== null ? 'On ' . $_enWhen . ' you' : 'You' ?> asked us to stop sending verification emails to <strong><?= $_en($_enEntry['address']) ?></strong>.</li>
                <?php endforeach; ?>
                <?php foreach ($_enComplaint as $_enEntry): ?>
                    <?php $_enWhen = $_enDate($_enEntry['suppressed']['date']); ?>
                <li><?= $_enWhen !== null ? 'On ' . $_enWhen . ' our' : 'Our' ?> email provider flagged <strong><?= $_en($_enEntry['address']) ?></strong> as unreachable or unwanted. To respect that, we stopped sending. If it was accidental (spam filters sometimes do this on their own), please contact the registry and an admin can start them again.</li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <?php if ($_enOptOut !== [] || $_enComplaint === []): ?>
        <div class="d-grid d-sm-block">
            <a class="btn btn-primary" href="<?= $_enSettingsUrl ?>#resume-emails">Turn emails back on</a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($emailNotice['hasBounced']): ?>
    <div class="er-email-notice__line er-email-notice__line--bounced">
        <p class="er-email-notice__title" id="email-notice-bounced-title">
            <i class="fas fa-exclamation-triangle me-2 er-email-notice__icon" aria-hidden="true"></i>We can't reach you by email
        </p>
        <?php if ($_enBounced !== []): ?>
        <p class="mb-2">Messages we sent to <?= $_enList($_enAddresses($_enBounced)) ?> were returned as undeliverable, so your car-verification emails are paused. Updating your email address will start them again automatically.</p>
        <p class="mb-2">
            <button type="button" class="btn btn-link er-email-notice__why" data-bs-toggle="collapse"
                    data-bs-target="#email-notice-why-bounced" aria-expanded="false"
                    aria-controls="email-notice-why-bounced">Why am I seeing this?</button>
        </p>
        <div class="collapse" id="email-notice-why-bounced">
            <ul class="mb-2">
                <?php foreach ($_enBounced as $_enEntry): ?>
                    <?php $_enWhen = $_enDate($_enEntry['bounced']['date']); ?>
                <li><?= $_enWhen !== null ? 'Since ' . $_enWhen . ', emails' : 'Emails' ?> to <strong><?= $_en($_enEntry['address']) ?></strong> have been returned by your email provider. Usually this means the address has a typo, the mailbox is full, or it is no longer in use.</li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php else: ?>
        <p class="mb-2">Messages to one or more of your email addresses were returned as undeliverable, so your car-verification emails are paused. Updating your email address will start them again automatically.</p>
        <?php endif; ?>
        <div class="d-grid d-sm-block">
            <a class="btn btn-primary" href="<?= $_enSettingsUrl ?>#account-email">Update my email address</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($_enOverflow > 0): ?>
    <p class="mb-3">This notice shows <?= count($emailNotice['addresses']) ?> addresses, and <?= $_enOverflow ?> more <?= $_enOverflow === 1 ? 'is' : 'are' ?> also affected. See <a href="<?= $_enCarsUrl ?>">your cars</a> for the full list.</p>
    <?php endif; ?>
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
