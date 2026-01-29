<?php
define('USERSPICE_ACTIVE_LOGGING', false);

// Load UserSpice core autoloader
require_once __DIR__ . '/classes/class.autoloader.php';

// Set secure session cookie parameters before starting session
session_set_cookie_params([
    'lifetime' => 0,           // Session cookie (expires when browser closes)
    'path' => '/',             // Available across entire site
    'domain' => '',            // Use default domain
    'secure' => isset($_SERVER['HTTPS']), // Only send over HTTPS if available
    'httponly' => true,        // Prevent JavaScript access to session cookie
    'samesite' => 'Strict'     // CSRF protection - only send with same-site requests
]);

ini_set('session.cookie_httponly', 1);
session_start();

$abs_us_root = $_SERVER['DOCUMENT_ROOT'];

$self_path = explode("/", $_SERVER['PHP_SELF']);
$self_path_length = count($self_path);
$file_found = false;

for ($i = 1; $i < $self_path_length; $i++) {
    array_splice($self_path, $self_path_length - $i, $i);
    $us_url_root = implode("/", $self_path) . "/";

    if (file_exists($abs_us_root . $us_url_root . 'z_us_root.php')) {
        $file_found = true;
        break;
    } else {
        $file_found = false;
    }
}

// Load custom application autoloader for usersc/classes/
// Must be loaded after path variables are set and after UserSpice autoloader
$customAutoloaderPath = $abs_us_root . $us_url_root . 'usersc/classes/class.autoloader.php';
if (file_exists($customAutoloaderPath)) {
    require_once $customAutoloaderPath;
}

require_once $abs_us_root . $us_url_root . 'users/helpers/helpers.php';

// Load encrypted environment variables
// SecureEnvPHP autoloaded via helpers.php → usersc/vendor/autoload.php
use SecureEnvPHP\SecureEnvPHP;

(new SecureEnvPHP())->parse($abs_us_root . $us_url_root . '.env.enc', $abs_us_root . $us_url_root . '.env.key');

// Set config
$GLOBALS['config'] = array(
    'mysql'      => array(
        // Set config
        'host'         => getenv('DB_HOST'),
        'username'     => getenv('DB_USER'),
        'password'     => getenv('DB_PASS'),
        'db'           => getenv('DB_NAME'),
        // PDO options: match production driver behavior
        // MYSQL_ATTR_INIT_COMMAND disables strict SQL mode (required for
        // UserSpice INSERT statements that omit columns without defaults)
        // ATTR_STRINGIFY_FETCHES matches production driver which returns
        // strings from DB, so type bugs surface in dev/test
        'options'      => array(
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode = ''",
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ),
    ),
    'remember'        => array(
        'cookie_name'   => 'pmqesoxiw318374csb',
        'cookie_expiry' => 604800  //One week, feel free to make it longer
    ),
    'session' => array(
        'session_name' => 'user',
        'token_name' => 'token',
    )
);

//If you changed your UserSpice or UserCake database prefix
//put it here.
$db_table_prefix = "uc_";  //Old database prefix

//adding more ids to this array allows people to access everything, whether offline or not. Use caution.
$master_account = [1];

$currentPage = currentPage();

//Check to see if user has a remember me cookie
if (Cookie::exists(Config::get('remember/cookie_name')) && !Session::exists(Config::get('session/session_name'))) {
    $hash = Cookie::get(Config::get('remember/cookie_name'));
    $hashCheck = DB::getInstance()->query("SELECT * FROM users_session WHERE hash = ? AND uagent = ?", array($hash, Session::uagent_no_version()));

    if ($hashCheck->count()) {
        $user = new User($hashCheck->first()->user_id);
        $inst = Config::get('session/session_name');
        $_SESSION[$inst . '_login_method'] = "cookie";
        $user->login();
    }
}

//Check to see that user is logged in on a temporary password
$user = new User();

//Check to see that user is verified
if ($user->isLoggedIn()) {
    if ($user->data()->email_verified == 0 && $currentPage != 'verify.php' && $currentPage != 'logout.php' && $currentPage != 'verify_thankyou.php') {
        // Redirect::to('users/verify.php');  Hot fix:  We patched a bug in new installs of v5.3.6 where you could find yourself at a 404 through an obscure set of circumstances.  I'm not going to risk editing init.php to fix it,
        Redirect::to($us_url_root . 'users/verify.php');
    }
}
$timezone_string = 'America/Los_Angeles';
date_default_timezone_set($timezone_string);

require_once $abs_us_root . $us_url_root . "users/includes/loader.php";
