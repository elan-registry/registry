<?php
require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/container_close.php'; //custom template container
require_once $abs_us_root . $us_url_root . 'users/includes/page_footer.php';

// Include ApplicationVersion class for version display
require_once $abs_us_root . $us_url_root . 'app/version.php';
?>
<div class="<?= $settings->container_open_class ?>">
  <div class="row">
    <div class="col-12 text-center">
      <footer>
        <br>
        <div class="mb-2">
          <a href="<?= $us_url_root ?>app/privacy.php" class="text-muted me-3">Privacy Policy</a>
        </div>
        &copy; <?php echo date("Y"); ?> <?= $settings->copyright; ?>
        <br>
        <small class="text-muted">Version: <?= ApplicationVersion::get(); ?></small>
      </footer>
      <br>
    </div>
  </div>
</div>

<?php
require_once($abs_us_root . $us_url_root . 'users/includes/html_footer.php');

// // jQuery
// echo html_entity_decode($settings->elan_jquery_cdn);

// // Bootstrap Core CSS
// echo html_entity_decode($settings->elan_bootstrap_js_cdn);

// // Popper
// echo html_entity_decode($settings->elan_popper_cdn);

// // Custom Fonts/Animation/Styling from FontAwsome 
// echo html_entity_decode($settings->elan_fontawesome_cdn);
?>

<?php
// Only load Google Analytics in production environment
// Prevents development tracking pollution and localhost URL errors
$is_production = !in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1', 'localhost:9999']);
if ($is_production): ?>
<!-- Global site tag (gtag.js) - Google Analytics 4 -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-WS56C3X5NR"></script>
<script>
  window.dataLayer = window.dataLayer || [];

  function gtag() {
    dataLayer.push(arguments);
  }
  gtag('js', new Date());

  gtag('config', 'G-WS56C3X5NR');
</script>
<?php endif; ?>