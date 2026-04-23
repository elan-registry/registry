<?php
declare(strict_types=1);

/*
Branded forgot password confirmation page for the Lotus Elan Registry.
Overrides the stock UserSpice template (users/views/_forgot_password_sent.php).
The controller at users/forgot_password.php checks for this override file first.
*/
?>
<div class="container mt-4">
  <div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-4">
      <div class="card registry-card text-center">
        <div class="card-body py-5">
          <div class="mb-3">
            <i class="fas fa-envelope-open-text fa-3x text-success"></i>
          </div>
          <h2 class="h4 mb-3">Check Your Email</h2>
          <p class="text-muted mb-3">
            We've sent password reset instructions to your email address.
          </p>
          <?php $expiry = (int) $settings->reset_vericode_expiry; ?>
          <?php if ($expiry > 0): ?>
            <p class="text-muted mb-4">
              The reset link will expire in
              <strong><?= $expiry ?> <?= lang("T_MINUTES"); ?></strong>.
            </p>
          <?php endif; ?>
          <a href="<?= htmlspecialchars($us_url_root, ENT_QUOTES, 'UTF-8') ?>users/login.php" class="btn btn-outline-primary">
            <i class="fas fa-arrow-left mr-2"></i>Back to Login
          </a>
        </div>
      </div>
    </div>
  </div>
</div>
