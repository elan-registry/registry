<?php
// Runs after username/password are verified and the user is logged in,
// but before they are redirected to their starting page.
// $dest is set by login.php from the session (stored by securePage).

if (isset($dest) && !empty($dest)) {
    // User was redirected to login from a protected page - send them back
    Redirect::sanitized($dest);
} else {
    Redirect::to($us_url_root . 'usersc/account.php');
}
