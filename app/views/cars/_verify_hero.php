<?php
if (count(get_included_files()) == 1) { die(); }

/**
 * _verify_hero.php
 *
 * Car-identity hero shared by every state of app/verify/verify_car.php
 * (landing page, verify/sold confirmations, and the terminal success and
 * already-sold states). Its job is the mis-click guard described in the
 * mockup: an owner with several Elans must be able to see at a glance
 * *which* car the link refers to before acting on it.
 *
 * Follows the site's existing car-hero grammar (`registry-card bg-primary`
 * with a Lotus Yellow top border) used by app/owner/cars/details.php, rather
 * than the mockup's own throwaway `sc-hero` class.
 *
 * Caller must set:
 *   $verifyCar   object  Car row (year, series, variant, chassis, color, id)
 *   $verifyPhoto ?string Absolute-from-root URL of the -resized-300 primary
 *                        photo variant, or null when the car has no photo
 *
 * All values are escaped here — callers pass raw DB values.
 */

$verifyCar ??= null;
$verifyPhoto ??= null;

if ($verifyCar === null) {
    return;
}

$verifyCarName = trim(sprintf(
    '%s Lotus Elan %s',
    (string) ($verifyCar->year ?? ''),
    (string) ($verifyCar->series ?? '')
));
if (!empty($verifyCar->variant)) {
    $verifyCarName .= ' (' . $verifyCar->variant . ')';
}
?>
<div class="card registry-card bg-primary text-white mb-0" style="border-top: 5px solid var(--er-accent);">
    <div class="card-body">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <?php if ($verifyPhoto !== null): ?>
                <img src="<?= htmlspecialchars($verifyPhoto, ENT_QUOTES, 'UTF-8') ?>"
                     width="96" height="72" class="rounded flex-shrink-0"
                     style="object-fit: cover;"
                     alt="Primary photo of this Lotus Elan">
            <?php endif; ?>
            <div>
                <h1 class="h4 mb-1 card-header-er-primary-text">
                    <i class="fas fa-car me-2" aria-hidden="true"></i>
                    <?= htmlspecialchars($verifyCarName, ENT_QUOTES, 'UTF-8') ?>
                </h1>
                <div class="text-white-75">
                    Registry No. #<?= (int) ($verifyCar->id ?? 0) ?>
                </div>
            </div>
        </div>
    </div>
</div>
