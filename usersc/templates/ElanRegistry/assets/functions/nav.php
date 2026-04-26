<li class="nav-item">
  <a class="nav-link" href="<?= $us_url_root ?>"><i class="fa fa-fw fa-home" aria-hidden="true"></i> Home</a>
</li>

<li class="nav-item">
  <a class="nav-link" href="<?= $us_url_root ?>app/cars/index.php"><i class="fa fa-fw fa-car" aria-hidden="true"></i> List Cars</a>
</li>

<?php if ($user->isLoggedIn()): ?>
  <li class="nav-item">
    <a class="nav-link" href="<?= $us_url_root ?>app/cars/edit.php"><i class="fa fa-fw fa-plus" aria-hidden="true"></i> Add Car</a>
  </li>
<?php elseif ($settings->registration == 1): ?>
  <li class="nav-item">
    <a class="nav-link" href="<?= $us_url_root ?>users/join.php"><i class="fa fa-fw fa-plus-square" aria-hidden="true"></i> Register</a>
  </li>
<?php endif; ?>

<li class="nav-item">
  <a class="nav-link" href="<?= $us_url_root ?>app/reports/statistics.php"><i class="fa fa-fw fa-pie-chart" aria-hidden="true"></i> Statistics</a>
</li>

<li class="nav-item dropdown">
  <a class="nav-link dropdown-toggle" href="#" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
    <i class="fa fa-fw fa-wrench" aria-hidden="true"></i> Technical Resources
  </a>
  <div class="dropdown-menu">
    <a class="dropdown-item" href="<?= $us_url_root ?>app/cars/identify.php"><i class="fa fa-fw fa-binoculars" aria-hidden="true"></i> Identification Guide</a>
    <a class="dropdown-item" href="<?= $us_url_root ?>app/cars/factory.php"><i class="fa fa-fw fa-list-alt" aria-hidden="true"></i> Factory Data</a>
    <a class="dropdown-item" href="<?= $us_url_root ?>docs/reference-library.php"><i class="fa fa-fw fa-book" aria-hidden="true"></i> Reference Library — Tech Manuals</a>
  </div>
</li>

<li class="nav-item">
  <a class="nav-link" href="<?= $us_url_root ?>docs/car-stories.php"><i class="fa fa-fw fa-book" aria-hidden="true"></i> Car Stories</a>
</li>

<li class="nav-item">
  <a class="nav-link" href="<?= $us_url_root ?>docs/faq/index.php"><i class="fa fa-fw fa-question-circle" aria-hidden="true"></i> FAQ</a>
</li>

<?php if ($user->isLoggedIn()): ?>

  <li class="nav-item">
    <a class="nav-link" href="<?= $us_url_root ?>app/contact/index.php"><i class="fa fa-fw fa-comments" aria-hidden="true"></i> Feedback</a>
  </li>

  <?php if ($settings->notifications == 1): ?>
    <li class="nav-item">
      <a class="nav-link" href="#" onclick="displayNotifications('new')" id="notificationsTrigger" data-toggle="modal" data-target="#notificationsModal">
        <span class="fa fa-fw fa-bell-o"></span>
        <span id="notifCount" class="badge badge-pill badge-primary" style="margin-top: -5px;"><?= (int)$notifications->getUnreadCount(); ?></span>
      </a>
    </li>
  <?php endif; ?>

  <?php if (checkMenu(2, $user->data()->id)): ?>
    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle" href="#" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <i class="fa fa-fw fa-cogs" aria-hidden="true"></i> Admin
      </a>
      <div class="dropdown-menu">
        <a class="dropdown-item" href="<?= $us_url_root ?>app/admin/manage-consolidated.php"><i class="fa fa-fw fa-car" aria-hidden="true"></i> Manage Registry</a>
        <a class="dropdown-item" href="<?= $us_url_root ?>docs/faq/admin/index.php"><i class="fa fa-fw fa-question-circle" aria-hidden="true"></i> Admin Guide</a>
        <div class="dropdown-divider"></div>
        <a class="dropdown-item" href="<?= $us_url_root ?>users/admin.php"><i class="fa fa-fw fa-cogs" aria-hidden="true"></i> Admin Dashboard</a>
      </div>
    </li>
  <?php endif; ?>

  <li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
      <i class="fa fa-fw fa-user" aria-hidden="true"></i> <?= echousername($user->data()->id); ?>
    </a>
    <div class="dropdown-menu dropdown-menu-right">
      <a class="dropdown-item" href="<?= $us_url_root ?>users/account.php"><i class="fa fa-fw fa-user" aria-hidden="true"></i> Account</a>
      <div class="dropdown-divider"></div>
      <a class="dropdown-item" href="<?= $us_url_root ?>users/logout.php"><i class="fa fa-fw fa-sign-out" aria-hidden="true"></i> Logout</a>
    </div>
  </li>

<?php else: ?>

  <li class="nav-item">
    <a class="nav-link" href="<?= $us_url_root ?>users/login.php"><i class="fa fa-fw fa-sign-in" aria-hidden="true"></i> Login</a>
  </li>

<?php endif; ?>
