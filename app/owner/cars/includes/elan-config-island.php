<?php
declare(strict_types=1);

$elanThumbnailSize = 100;
$elanResponsiveSize = 300;
if (!empty($settings->elan_image_thumbnail_sizes)) {
    $elanSizes = explode(',', $settings->elan_image_thumbnail_sizes);
    $elanThumbnailSize = intval(trim($elanSizes[0]));
    if (count($elanSizes) >= 2) {
        $elanResponsiveSize = intval(trim($elanSizes[1]));
    }
}
?>
<script nonce="<?= htmlspecialchars($userspice_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
window.ELAN_CONFIG = {
    THUMBNAIL_SIZE: <?= $elanThumbnailSize ?>,
    RESPONSIVE_SIZE: <?= $elanResponsiveSize ?>
};
</script>
