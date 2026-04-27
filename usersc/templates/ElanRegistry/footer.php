<?php

declare(strict_types=1);

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
          <a href="<?= $us_url_root ?>docs/guide-viewer.php?doc=PRIVACY.md" class="text-muted me-3">Privacy Policy</a>
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
?>

