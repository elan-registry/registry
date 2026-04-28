<!-- ElanRegistry navigation menu using the Customizer us_menu CSS class system. -->
<ul class='us_menu horizontal dark' style=' z-index: 50;' id='us_menu_1_638b71f2ed026'>
  <div class='us_brand full_screen'>
    <a href="<?= $us_url_root ?>">
      <img src="<?= $us_url_root ?>usersc/images/logo-100x100.png" alt="Lotus Elan Registry" class="img-fluid" style="max-height:100px;width:auto">
    </a>
  </div>
  <div class='flex-grow-1'></div>

  <div class='us_menu_mobile_wrapper'>
    <div class='us_brand'>
      <a href="<?= $us_url_root ?>">
        <img src="<?= $us_url_root ?>usersc/images/logo-100x100.png" alt="Lotus Elan Registry" class="img-fluid" style="max-height:50px;width:auto">
      </a>
    </div>

    <div class='us_menu_mobile_control' data-target='1_638b71f2ed026'>
      <i class='fa fa-bars'></i>
    </div>
  </div>

  <li class=''>
    <a class='' href='<?= $us_url_root ?>app/cars/index.php'>
      <i class='fa fa-car'></i>
      <span class='labelText'>List Cars</span>
    </a>
  </li>

  <?php if (isset($user) && $user->isLoggedIn()): ?>
    <li class=''>
      <a class='btn btn-success btn-sm text-white ms-1' href='<?= $us_url_root ?>app/cars/edit.php'>
        <i class='fa fa-plus'></i>
        <span class='labelText'>Add Car</span>
      </a>
    </li>
  <?php endif; ?>

  <li class=''>
    <a class='' href='<?= $us_url_root ?>app/reports/statistics.php'>
      <i class='fa fa-pie-chart'></i>
      <span class='labelText'>Statistics</span>
    </a>
  </li>

  <li class='dropdown'>
    <a class='sub-toggle' href='#' id='menu_1_638b71f2ed026_dropdown_reference' role='button' aria-haspopup='true' aria-expanded='false' data-target='#menu_1_638b71f2ed026_dropdown_reference'>
      <i class='fa fa-book'></i>
      <span class='labelText'>Reference</span>
      <span class='caret'></span>
    </a>
    <ul class='us_sub-menu' aria-labelledby='menu_1_638b71f2ed026_dropdown_reference' style=' z-index: 50;'>
      <li class=''>
        <a class='' href='<?= $us_url_root ?>docs/reference/index.php'>
          <i class='fa fa-folder-open'></i>
          <span class='labelText'>Reference Library</span>
        </a>
      </li>
      <li class=''>
        <a class='' href='<?= $us_url_root ?>docs/reference/identification-guide.php'>
          <i class='fa fa-search'></i>
          <span class='labelText'>Identification Guide</span>
        </a>
      </li>
      <li class=''>
        <a class='' href='<?= $us_url_root ?>docs/reference/chassis-validation.php'>
          <i class='fa fa-barcode'></i>
          <span class='labelText'>Chassis Validation</span>
        </a>
      </li>
      <li class=''>
        <a class='' href='<?= $us_url_root ?>app/cars/factory.php'>
          <i class='fa fa-list-alt'></i>
          <span class='labelText'>Production Records</span>
        </a>
      </li>
      <li class=''>
        <a class='' href='<?= $us_url_root ?>docs/reference/paint-colors.php'>
          <i class='fa fa-palette'></i>
          <span class='labelText'>Paint Colors</span>
        </a>
      </li>
    </ul>
  </li>

  <li class=''>
    <a class='' href='<?= $us_url_root ?>docs/car-stories.php'>
      <i class='fa fa-book-open'></i>
      <span class='labelText'>Car Stories</span>
    </a>
  </li>

  <li class=''>
    <a class='' href='<?= $us_url_root ?>docs/guides/index.php'>
      <i class='fa fa-question-circle'></i>
      <span class='labelText'>Guides</span>
    </a>
  </li>

  <?php if (isset($user) && $user->isLoggedIn()): ?>

    <?php if (isset($notifications) && $settings->notifications == 1): ?>
      <li class=''>
        <a class='' href='#' onclick="displayNotifications('new')" id='notificationsTrigger' data-bs-toggle='modal' data-bs-target='#notificationsModal' aria-label='Notifications'>
          <i class='fa fa-bell-o'></i>
          <span id='notifCount' class='badge rounded-pill bg-primary' style='margin-top: -5px;'><?= (int)$notifications->getUnreadCount(); ?></span>
        </a>
      </li>
    <?php endif; ?>

    <?php if (checkMenu(2, $user->data()->id)): ?>
      <li class='dropdown'>
        <a class='sub-toggle' href='#' id='menu_1_638b71f2ed026_dropdown_admin' role='button' aria-haspopup='true' aria-expanded='false' data-target='#menu_1_638b71f2ed026_dropdown_admin'>
          <i class='fa fa-cogs'></i>
          <span class='labelText'>Admin</span>
          <span class='caret'></span>
        </a>
        <ul class='us_sub-menu' aria-labelledby='menu_1_638b71f2ed026_dropdown_admin' style=' z-index: 50;'>
          <li class=''>
            <a class='' href='<?= $us_url_root ?>app/admin/manage-consolidated.php'>
              <i class='fa fa-car'></i>
              <span class='labelText'>Manage Registry</span>
            </a>
          </li>
          <li class=''>
            <a class='' href='<?= $us_url_root ?>docs/admin/index.php'>
              <i class='fa fa-question-circle'></i>
              <span class='labelText'>Admin Guide</span>
            </a>
          </li>
          <div class='dropdown-divider'></div>
          <li class=''>
            <a class='' href='<?= $us_url_root ?>users/admin.php'>
              <i class='fa fa-cogs'></i>
              <span class='labelText'>Admin Dashboard</span>
            </a>
          </li>
        </ul>
      </li>
    <?php endif; ?>

    <li class='dropdown'>
      <a class='sub-toggle' href='#' id='menu_1_638b71f2ed026_dropdown_account' role='button' aria-haspopup='true' aria-expanded='false' data-target='#menu_1_638b71f2ed026_dropdown_account'>
        <i class='fa fa-user'></i>
        <span class='labelText'><?= echousername($user->data()->id); ?></span>
        <span class='caret'></span>
      </a>
      <ul class='us_sub-menu' aria-labelledby='menu_1_638b71f2ed026_dropdown_account' style=' z-index: 50;'>
        <li class=''>
          <a class='' href='<?= $us_url_root ?>users/account.php'>
            <i class='fa fa-user'></i>
            <span class='labelText'>Account</span>
          </a>
        </li>
        <li class=''>
          <a class='' href='<?= $us_url_root ?>app/contact/index.php'>
            <i class='fa fa-comments'></i>
            <span class='labelText'>Feedback</span>
          </a>
        </li>
        <div class='dropdown-divider'></div>
        <li class=''>
          <a class='' href='<?= $us_url_root ?>users/logout.php'>
            <i class='fa fa-sign-out'></i>
            <span class='labelText'>Logout</span>
          </a>
        </li>
      </ul>
    </li>

  <?php else: ?>

    <?php if ($settings->registration == 1): ?>
      <li class=''>
        <a class='' href='<?= $us_url_root ?>users/join.php'>
          <i class='fa fa-plus-square'></i>
          <span class='labelText'>Register</span>
        </a>
      </li>
    <?php endif; ?>

    <li class=''>
      <a class='' href='<?= $us_url_root ?>users/login.php'>
        <i class='fa fa-sign-in'></i>
        <span class='labelText'>Log In</span>
      </a>
    </li>

  <?php endif; ?>
</ul>
