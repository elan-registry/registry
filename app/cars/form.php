<?php
// form.php was renamed to edit.php. This redirect keeps old links and cron email URLs working.
require_once '../../users/init.php';
Redirect::to($us_url_root . 'app/cars/edit.php');
