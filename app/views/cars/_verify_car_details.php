<?php
if (count(get_included_files()) == 1) { die(); }

/**
 * _verify_car_details.php
 *
 * Read-only car identity list shared by the Verify and Sold confirmation
 * cards on app/verify/verify_car.php.
 *
 * Rendered as plain text rather than disabled inputs (per the mockup): these
 * details exist so the owner can confirm it is the right car, and a disabled
 * input would wrongly imply the value is editable-but-locked here. Editing
 * happens on app/owner/cars/edit.php.
 *
 * Caller must set:
 *   $verifyCar object Car row (chassis, color, year, variant)
 */

$verifyCar ??= null;

if ($verifyCar === null) {
    return;
}
?>
<div class="form-section-heading">Car Details</div>
<p class="text-muted small">
    These details are shown so you can check it&rsquo;s the right car &mdash;
    they can&rsquo;t be changed here.
</p>
<dl class="row mb-4">
    <dt class="col-sm-4">Chassis number</dt>
    <dd class="col-sm-8"><?= htmlspecialchars((string) ($verifyCar->chassis ?: 'Not specified'), ENT_QUOTES, 'UTF-8') ?></dd>

    <dt class="col-sm-4">Colour</dt>
    <dd class="col-sm-8"><?= htmlspecialchars((string) ($verifyCar->color ?: 'Not specified'), ENT_QUOTES, 'UTF-8') ?></dd>

    <dt class="col-sm-4">Year</dt>
    <dd class="col-sm-8"><?= htmlspecialchars((string) ($verifyCar->year ?: 'Not specified'), ENT_QUOTES, 'UTF-8') ?></dd>

    <dt class="col-sm-4">Variant</dt>
    <dd class="col-sm-8"><?= htmlspecialchars((string) ($verifyCar->variant ?: 'Not specified'), ENT_QUOTES, 'UTF-8') ?></dd>
</dl>
