<?php

declare(strict_types=1);

if (count(get_included_files()) == 1) { die(); }

/**
 * Location card, shared by app/owner/cars/details.php and
 * app/views/cars/_verify_landing.php. It draws the #map box when the car
 * has a usable position, else a placeholder. _location_map_scripts.php
 * loads the map into the box.
 *
 * Caller must set:
 *   $carData    object  Car row (Car::data() or a plain `cars` row)
 *   $headingTag string  Card heading element, h1-h6 (default h3)
 *
 * The defaults below let PHPStan analyze this file alone. When $carData is
 * missing or is not an object, the card draws nothing and logs one entry.
 * A car with no usable position gets the placeholder and no log entry.
 */
$carData ??= null;
$headingTag ??= 'h3';

if (!is_object($carData)) {
    logger(0, ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        '_location_map_card.php: $carData is ' . get_debug_type($carData) . ', not an object. Card not drawn.');
    return;
}

$headingTag = in_array($headingTag, ['h1','h2','h3','h4','h5','h6'], true) ? $headingTag : 'h3';
// Keep this rule the same as in _location_map_scripts.php. If the two differ,
// the page shows an empty map box or loads MapLibre with no box to fill.
// A zero lat or a zero lon means no position.
$_hasLocationMap = is_numeric($carData->lat ?? null) && (float)$carData->lat != 0
    && is_numeric($carData->lon ?? null) && (float)$carData->lon != 0;
?>
<div class="card registry-card">
    <div class="card-header">
        <<?= $headingTag ?> class="mb-0"><i class="fas fa-map-marked-alt text-primary" aria-hidden="true"></i> Location</<?= $headingTag ?>>
    </div>
    <div class="card-body">
        <?php if ($_hasLocationMap): ?>
            <div class="map-container map-container-small" style="height: 100%;">
                <div id="map" role="region" aria-label="Map of the car's approximate location" style="height: 400px; width: 100%;"></div>
            </div>
            <div class="mt-2 text-center">
                <small class="text-muted">
                    <i class="fas fa-info-circle" aria-hidden="true"></i>
                    Approximate location
                </small>
            </div>
        <?php else: ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-map-marker-alt fa-3x mb-3" aria-hidden="true"></i>
                <p>Location information not available for this car.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
