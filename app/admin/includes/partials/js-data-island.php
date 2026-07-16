<?php
declare(strict_types=1);

/*
 * JS data island for admin pages. Emits the URL root and CSRF token so
 * client-side scripts (ElanRegistryAPI) can read them from the DOM.
 *
 * Expects the including page scope to provide $us_url_root (framework global)
 * and $csrfToken (the page's Token::generate() value).
 */
?>
<script>
window.elanUrlRoot = <?= json_encode((string)$us_url_root) ?>;
document.documentElement.setAttribute('data-csrf-token', '<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>');
</script>
