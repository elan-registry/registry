<?php

declare(strict_types=1);

if (count(get_included_files()) == 1) { die(); }

/**
 * Map scripts and styles for _location_map_card.php, shared by
 * app/owner/cars/details.php and app/views/cars/_verify_landing.php.
 * Include it after the card, because car-details-map.js reads #map at once.
 * It emits nothing when the car has no usable position.
 *
 * Caller must set:
 *   $carData         object Car row (Car::data() or a plain `cars` row)
 *   $us_url_root     string UserSpice URL root
 *   $userspice_nonce string CSP nonce for the inline config script
 *
 * The defaults below let PHPStan analyze this file alone.
 */
$carData ??= null;
$us_url_root ??= '/';
$userspice_nonce ??= '';

// Keep this rule the same as in _location_map_card.php. If the two differ,
// the page shows an empty map box or loads MapLibre with no box to fill.
// A zero lat or a zero lon means no position.
$_hasLocationMap = is_object($carData)
    && is_numeric($carData->lat ?? null) && (float)$carData->lat != 0
    && is_numeric($carData->lon ?? null) && (float)$carData->lon != 0;

if (!$_hasLocationMap) {
    return;
}
?>
<link rel="stylesheet" href="<?= $us_url_root ?>usersc/css/maplibre-gl.css?v=<?= ASSET_VERSION ?>">
<script src="<?= $us_url_root ?>usersc/js/maplibre-gl.min.js?v=<?= ASSET_VERSION ?>" data-cfasync="false"></script>
<script nonce="<?= htmlspecialchars((string)$userspice_nonce, ENT_QUOTES, 'UTF-8') ?>">
window.carDetailsMapConfig = {
    lat: <?= (float)$carData->lat ?>,
    lon: <?= (float)$carData->lon ?>,
    series: <?= json_encode((string)($carData->series ?? ''), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    styleUrl: <?= json_encode($us_url_root . 'usersc/js/versatiles-colorful.json', JSON_HEX_TAG | JSON_HEX_AMP) ?>
};
</script>
<script src='<?= $us_url_root ?>app/assets/js/car-details-map.min.js?v=<?= ASSET_VERSION ?>'></script>
<style>
@media (max-width: 575.98px) {
    #map { height: 220px !important; }
}
.elan-marker {
    width: 18px; height: 18px;
    border-radius: 50% 50% 50% 0;
    border: 2px solid rgba(0,0,0,0.4);
    transform: rotate(-45deg);
}
.elan-marker-wrapper {
    width: 22px; height: 22px;
    display: flex; align-items: center; justify-content: center;
    transform: rotate(45deg);
}
.elan-marker.s1     { background: #e53e3e; }
.elan-marker.s2     { background: #3182ce; }
.elan-marker.s3     { background: #d69e2e; }
.elan-marker.s4     { background: #e2e8f0; border-color: rgba(0,0,0,0.5); }
.elan-marker.sprint { background: #805ad5; }
.elan-marker.plus2  { background: #38a169; }
.elan-marker.unknown{ background: #718096; }
</style>
