<li class="nav-item">
  <a class="nav-link" href="<?= $us_url_root ?>app/cars/index.php"><i class="fa fa-fw fa-car" aria-hidden="true"></i> List Cars</a>
</li>

<?php if ($user->isLoggedIn()): ?>
  <li class="nav-item">
    <a class="nav-link btn btn-success btn-sm text-white ml-1" href="<?= $us_url_root ?>app/cars/edit.php"><i class="fa fa-fw fa-plus" aria-hidden="true"></i> Add Car</a>
  </li>
<?php endif; ?>

<li class="nav-item">
  <a class="nav-link" href="<?= $us_url_root ?>app/reports/statistics.php"><i class="fa fa-fw fa-pie-chart" aria-hidden="true"></i> Statistics</a>
</li>

<li class="nav-item dropdown">
  <a class="nav-link dropdown-toggle" href="#" id="referenceDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
    <i class="fa fa-fw fa-book" aria-hidden="true"></i> Reference
  </a>
  <div class="dropdown-menu" aria-labelledby="referenceDropdown">
    <a class="dropdown-item" href="<?= $us_url_root ?>docs/reference/identification-guide.php"><i class="fa fa-fw fa-search" aria-hidden="true"></i> Identification Guide</a>
    <a class="dropdown-item" href="<?= $us_url_root ?>docs/reference/chassis-validation.php"><i class="fa fa-fw fa-barcode" aria-hidden="true"></i> Chassis Validation</a>
    <a class="dropdown-item" href="<?= $us_url_root ?>app/cars/factory.php"><i class="fa fa-fw fa-list-alt" aria-hidden="true"></i> Production Records</a>
    <div class="dropdown-divider"></div>
    <a class="dropdown-item" href="<?= $us_url_root ?>docs/reference/index.php"><i class="fa fa-fw fa-folder-open" aria-hidden="true"></i> Reference Library</a>
  </div>
</li>

<li class="nav-item">
  <a class="nav-link" href="<?= $us_url_root ?>docs/car-stories.php"><i class="fa fa-fw fa-book-open" aria-hidden="true"></i> Car Stories</a>
</li>

<li class="nav-item">
  <a class="nav-link" href="<?= $us_url_root ?>docs/guides/index.php"><i class="fa fa-fw fa-question-circle" aria-hidden="true"></i> Guides</a>
</li>

<?php if ($user->isLoggedIn()): ?>

  <?php if (isset($notifications) && $settings->notifications == 1): ?>
    <li class="nav-item">
      <a class="nav-link" href="#" onclick="displayNotifications('new')" id="notificationsTrigger" data-toggle="modal" data-target="#notificationsModal" aria-label="Notifications">
        <span class="fa fa-fw fa-bell-o"></span>
        <span id="notifCount" class="badge badge-pill badge-primary" style="margin-top: -5px;"><?= (int)$notifications->getUnreadCount(); ?></span>
      </a>
    </li>
  <?php endif; ?>

  <?php if (checkMenu(2, $user->data()->id)): ?>
    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <i class="fa fa-fw fa-cogs" aria-hidden="true"></i> Admin
      </a>
      <div class="dropdown-menu" aria-labelledby="adminDropdown">
        <a class="dropdown-item" href="<?= $us_url_root ?>app/admin/manage-consolidated.php"><i class="fa fa-fw fa-car" aria-hidden="true"></i> Manage Registry</a>
        <a class="dropdown-item" href="<?= $us_url_root ?>docs/admin/index.php"><i class="fa fa-fw fa-question-circle" aria-hidden="true"></i> Admin Guide</a>
        <div class="dropdown-divider"></div>
        <a class="dropdown-item" href="<?= $us_url_root ?>users/admin.php"><i class="fa fa-fw fa-cogs" aria-hidden="true"></i> Admin Dashboard</a>
      </div>
    </li>
  <?php endif; ?>

  <li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" id="accountDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
      <i class="fa fa-fw fa-user" aria-hidden="true"></i> <?= echousername($user->data()->id); ?>
    </a>
    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="accountDropdown">
      <a class="dropdown-item" href="<?= $us_url_root ?>users/account.php"><i class="fa fa-fw fa-user" aria-hidden="true"></i> Account</a>
      <a class="dropdown-item" href="<?= $us_url_root ?>app/contact/index.php"><i class="fa fa-fw fa-comments" aria-hidden="true"></i> Feedback</a>
      <div class="dropdown-divider"></div>
      <a class="dropdown-item" href="<?= $us_url_root ?>users/logout.php"><i class="fa fa-fw fa-sign-out" aria-hidden="true"></i> Logout</a>
    </div>
  </li>

<?php else: ?>

  <?php if ($settings->registration == 1): ?>
    <li class="nav-item">
      <a class="nav-link" href="<?= $us_url_root ?>users/join.php"><i class="fa fa-fw fa-plus-square" aria-hidden="true"></i> Register</a>
    </li>
  <?php endif; ?>

  <li class="nav-item">
    <a class="nav-link" href="<?= $us_url_root ?>users/login.php"><i class="fa fa-fw fa-sign-in" aria-hidden="true"></i> Log In</a>
  </li>

<?php endif; ?>
