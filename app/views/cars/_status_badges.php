<?php

declare(strict_types=1);

if (count(get_included_files()) == 1) { die(); }

/**
 * _status_badges.php
 *
 * Draws the status badges (Sold, Verified, New) for one car. CarBadges owns
 * which keys show and in which order. This partial only draws the keys it
 * receives, so the precedence rules stay in one place.
 *
 * Callers: the account page hero, the Vehicle Information card
 * (_vehicle_info_card.php, which the account page, the car details page, and
 * the public vericode landing page use), and the design-system page. The cars
 * list draws the same badges in JS (car-list.js) from
 * CarBadges::definitions().
 *
 * Caller must set:
 *   $badgeKeys  list<string>  Badge keys, for example from CarBadges::forCar()
 *   $badgeStyle string        'stamp' (rotated, account hero and details card)
 *                             or 'flat' (pill, cars list style)
 *
 * Optional:
 *   $badgeDefs  array         Badge definitions. Defaults to
 *                             CarBadges::definitions(). Tests set it to
 *                             supply a definition with hostile text.
 *
 * Unknown keys draw nothing. An empty list draws nothing (no wrapper). When
 * $badgeKeys is not an array, the partial draws nothing and logs one entry.
 * The partial unsets $badgeKeys, $badgeStyle, and $badgeDefs on every exit,
 * so a later include in the same scope cannot draw stale keys.
 * Bootstrap starts the tooltips from data-bs-toggle="tooltip"
 * (users/includes/html_footer.php). There is no title or aria-label:
 * Bootstrap adds aria-describedby when the tooltip shows.
 */

$badgeKeys ??= [];
$badgeStyle ??= 'flat';
$badgeDefs ??= \ElanRegistry\Car\CarBadges::definitions();

if (!is_array($badgeKeys)) {
    logger(0, ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        '_status_badges.php: $badgeKeys is ' . get_debug_type($badgeKeys) . ', not an array. No badges drawn.');
    unset($badgeKeys, $badgeStyle, $badgeDefs);
    return;
}
if (!is_array($badgeDefs)) {
    unset($badgeKeys, $badgeStyle, $badgeDefs);
    return;
}

$_badgeStampClass = $badgeStyle === 'stamp' ? ' er-badge--stamp' : '';

foreach ($badgeKeys as $_badgeKey) {
    if (!is_string($_badgeKey) || !isset($badgeDefs[$_badgeKey]) || !is_array($badgeDefs[$_badgeKey])) {
        continue;
    }
    $_badge   = $badgeDefs[$_badgeKey];
    $_tone    = htmlspecialchars((string)($_badge['tone'] ?? ''), ENT_QUOTES, 'UTF-8');
    $_tooltip = htmlspecialchars((string)($_badge['tooltip'] ?? ''), ENT_QUOTES, 'UTF-8');
    $_label   = htmlspecialchars((string)($_badge['label'] ?? ''), ENT_QUOTES, 'UTF-8');
    $_icon    = isset($_badge['icon']) ? htmlspecialchars((string)$_badge['icon'], ENT_QUOTES, 'UTF-8') : null;
    ?><span class="er-badge er-badge--<?= $_tone ?><?= $_badgeStampClass ?>" data-bs-toggle="tooltip" data-bs-title="<?= $_tooltip ?>" tabindex="0"><?php if ($_icon !== null): ?><span aria-hidden="true"><?= $_icon ?></span> <?php endif; ?><?= $_label ?></span>
<?php
}

unset($badgeKeys, $badgeStyle, $badgeDefs);
