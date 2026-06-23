<?php
declare(strict_types=1);

/*
Branded forgot password form for the Lotus Elan Registry.
Overrides the stock UserSpice template with registry card layout
matching the join flow in _join.php.
*/
?>
<div class="container mt-4">
  <div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-4">
      <div class="card registry-card">
        <div class="card-header card-header-er-primary">
          <h2 class="mb-0 card-header-er-primary-text"><i class="fas fa-key"></i> <strong><?= lang("PW_RESET"); ?></strong></h2>
        </div>
        <div class="card-body">
          <?php if (!empty($errors)) { display_errors($errors); } ?>
          <form action="" method="post" id="pwReset">
            <div class="mb-3">
              <label for="email" class="form-label"><?= lang("GEN_EMAIL"); ?></label>
              <div class="input-group">
                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                <input type="email" name="email" id="email" class="form-control"
                       placeholder="your.email@example.com" autofocus autocomplete="email">
              </div>
            </div>
            <input type="hidden" name="csrf" value="<?= Token::generate(); ?>">
            <?php addTurnstile(); ?>
            <div class="mt-3 text-center">
              <button type="submit" name="forgotten_password" value="1" class="btn btn-primary btn-lg">
                <i class="fas fa-paper-plane me-2"></i><?= lang("GEN_RESET"); ?>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
