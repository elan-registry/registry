<?php
// This is a user-facing page
/*
UserSpice 5
An Open Source PHP User Management System
by the UserSpice Team at http://UserSpice.com

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/
// error_reporting(E_ALL);
// ini_set('display_errors', 1);
ini_set('allow_url_fopen', 1);
// Security headers (including X-Frame-Options: SAMEORIGIN) are set globally via
// usersc/includes/security_headers.php loaded during UserSpice initialization
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'usersc/includes/elanregistry_prep.php';

use ElanRegistry\Input;
use ElanRegistry\LogCategories;

$pw_settings = $db->query("SELECT * FROM us_password_strength WHERE id = 1")->first();
if (!isset($pw_settings->meter_active)) {
    $pw_settings->meter_active = 0;
    $pw_settings->enforce_rules = 0;
}
$socials = $db->query("SELECT * FROM plg_social_logins WHERE built_in = 0 ORDER BY `provider`;")->results();

$hooks = getMyHooks();

if ($user->isLoggedIn()) {
    Redirect::to($us_url_root.'index.php');
}

includeHook($hooks, 'pre');

$form_method = 'POST';
$form_action = 'join.php';
$vericode = uniqid().randomstring(15);
$hashedVericode = hashVericode($vericode);

//Decide whether or not to use email activation
$act = $db->query('SELECT * FROM email')->first()->email_act;

$form_valid = false;
$errors = []; // Populated on verification-email send failure; iterated below to surface each one.

//If you say in email settings that you do NOT want email activation,
//new users are active in the database, otherwise they will become
//active after verifying their email.
if ($act == 1 || $settings->no_passwords == 1) {
    $pre = 0;
} else {
    $pre = 1;
}

if (Input::existsPost()) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        logger(0, LogCategories::LOG_CATEGORY_REGISTRATION_FAILED,
            'join.php: Registration blocked — CSRF token check failed',
            ['stage' => 'csrf', 'user_agent' => $user_agent ?? '']);
        include $abs_us_root.$us_url_root.'usersc/scripts/token_error.php';
    }

    // Check rate limit for registration attempts before processing
    if (!checkRateLimit('registration_attempt')) {
        $errors[] = getRateLimitErrorMessage('registration_attempt');
        logger(0, LogCategories::LOG_CATEGORY_REGISTRATION_FAILED,
            'join.php: Registration blocked — rate limit exceeded',
            ['stage' => 'rate_limit', 'user_agent' => $user_agent ?? '']);
        usError(getRateLimitErrorMessage('registration_attempt'));
        Redirect::to(currentPage());
        exit;
    }

    $fname = ucfirst(Input::raw('fname') ?? '');
    $lname = ucfirst(Input::raw('lname') ?? '');
    $email = Input::raw('email') ?? '';
    $username = Input::raw('username') ?? '';

    $validation = new Validate();
        if (pluginActive('userInfo', true)) {
            $is_not_email = false;
        } else {
            $is_not_email = true;
        }
        $valArray = [
            'username' => [
                  'display' => lang('GEN_UNAME'),
                  'is_not_email' => $is_not_email,
                  'required' => true,
                  'min' => $settings->min_un,
                  'max' => $settings->max_un,
                  'unique' => 'users',
            ],
            'fname' => [
                  'display' => lang('GEN_FNAME'),
                  'required' => true,
                  'min' => 1,
                  'max' => 60,
            ],
            'lname' => [
                  'display' => lang('GEN_LNAME'),
                  'required' => true,
                  'min' => 1,
                  'max' => 60,
            ],
            'email' => [
                  'display' => lang('GEN_EMAIL'),
                  'required' => true,
                  'valid_email' => true,
                  'unique' => 'users',
                  'min' => 5,
                  'max' => 100,
            ],
        ];
        if($settings->no_passwords == 0){
            $valArray['password'] = [
                    'display' => lang('PW_PASS'),
                    'required' => true,
                    'min' => $settings->min_pw,
                    'max' => $settings->max_pw,
            ];
            $valArray['confirm'] = [
                    'display' => lang('PW_CONF'),
                    'required' => true,
                    'matches' => 'password',
            ];

        }else{
            $_POST['password'] = randomstring(25);
        }
        $validation->check($_POST, $valArray);

    if ($eventhooks = getMyHooks(['page' => 'joinAttempt'])) {
        includeHook($eventhooks, 'body');
    }

    if($pw_settings->meter_active == 1 && $pw_settings->enforce_rules == 1){
        $doubleCheckPassword = userSpicePasswordStrength(Input::get('password'));
        if($doubleCheckPassword['isValid'] == false){
            //inject error before processing
            $validation->addError([lang("JOIN_INVALID_PW"), 'password']);
        }
    }

    if ($validation->passed()) {
            $form_valid = true;
            //add user to the database
            $user = new User();
            $join_date = date('Y-m-d H:i:s');
            $params = [
                      'fname' => $fname,
                      'email' => $email,
                      'username' => $username,
                      'vericode' => $vericode,
                      'join_vericode_expiry' => $settings->join_vericode_expiry,
                        ];
            
            if($act == 1 || $settings->no_passwords == 1){
                $vericode_expiry = date('Y-m-d H:i:s', strtotime("+$settings->join_vericode_expiry hours", strtotime(date('Y-m-d H:i:s'))));
            }else{
                $vericode_expiry = date('Y-m-d H:i:s');
            }

            try {
                if(isset($_SESSION['us_lang'])){
                  $newLang = $_SESSION['us_lang'];
                }else{
                  $newLang = $settings->default_language;
                }
                $fields = [
                    'username' => $username,
                    'fname' => $fname,
                    'lname' => $lname,
                    'email' => $email,
                    'password' => password_hash(Input::get('password', true), PASSWORD_BCRYPT, ['cost' => 13]),
                    'permissions' => 1,
                    'join_date' => $join_date,
                    'email_verified' => $pre,
                    'vericode' => $hashedVericode,
                    'vericode_expiry' => $vericode_expiry,
                    'oauth_tos_accepted' => true,
                    'language'=>$newLang,
                    'active'=>1
                    ];

                $theNewId = $user->create($fields);

                // Record successful registration
                handleAuthSuccess('registration_attempt', $theNewId, $email, [], [
                    'username' => $username,
                    'email' => $email,
                    'user_agent' => $user_agent ?? ''
                ]);

                $params['user_id'] = $theNewId;
                includeHook($hooks, 'post');
                $emailSent = false;
                if ($act == 1 || $settings->no_passwords == 1) {
                    //Verify email address settings
                    $to = rawurlencode($email);
                    $subject = html_entity_decode($settings->site_name, ENT_QUOTES);
                    $body = email_body('_email_template_verify.php', $params);

                    if ($body === '') {
                        logger($theNewId, LogCategories::LOG_CATEGORY_EMAIL_ERROR,
                            'join.php: email_body() returned empty — template missing or failed',
                            ['template' => '_email_template_verify.php']);
                        $errors[] = 'Email could not be sent. Please try again or contact the administrator.';
                    } else {
                        $email_result = email($to, $subject, $body);
                        $emailSent = ($email_result === true);
                        if (!$emailSent) {
                            $safeToLog = preg_replace('/[\r\n\t]/', '', $email);
                            logger($theNewId, LogCategories::LOG_CATEGORY_EMAIL_ERROR,
                                'join.php: Registration verification email SEND FAILED to ' . $safeToLog);
                            $errors[] = 'Email could not be sent. Please try again or contact the administrator.';
                        }
                    }
                }
            } catch (Exception $e) {
                handleAuthFailure('registration_attempt', null, $email, [], [
                    'username_attempted' => $username,
                    'email_attempted' => $email,
                    'error' => $e->getMessage(),
                    'user_agent' => $user_agent ?? ''
                ]);

                if ($eventhooks = getMyHooks(['page' => 'joinFail'])) {
                    includeHook($eventhooks, 'body');
                }

                $safeToLog = preg_replace('/[\r\n\t]/', ' ', $e->getMessage()) ?? '[message unavailable]';
                logger($theNewId ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
                    'join.php: User creation failed — ' . get_class($e),
                    [
                        'exception_class' => get_class($e),
                        'message'         => $safeToLog,
                        'file'            => $e->getFile(),
                        'line'            => $e->getLine(),
                    ]);

                usError('We could not complete your registration. Please try again or contact the administrator.');
                Redirect::to(currentPage());
                exit;
            }
          //this allows the plugin hook to kill the post but it must delete the created user
            include $abs_us_root.$us_url_root.'usersc/scripts/during_user_creation.php';

            if ($act == 1 || $settings->no_passwords == 1) {
                if (!$emailSent) {
                    logger($theNewId, LogCategories::LOG_CATEGORY_USER, 'Registration completed. Verification email delivery failed — see EmailError log.');
                    foreach ($errors as $err) {
                        usError($err);
                    }
                    Redirect::to(currentPage());
                } else {
                    logger($theNewId, LogCategories::LOG_CATEGORY_USER, 'Registration completed and verification email sent.');
                    Redirect::to($us_url_root . "users/complete.php?action=thank_you_verify");
                }


            } else {
                logger($theNewId, LogCategories::LOG_CATEGORY_USER, 'Registration completed.');
                if (file_exists($abs_us_root.$us_url_root.'usersc/views/_joinThankYou.php')) {

                    Redirect::to($us_url_root . "users/complete.php?action=thank_you_join");

                } else {
                    Redirect::to($us_url_root . "users/complete.php?action=thank_you");
                }

            }

    } else {
        // Record failed registration attempt
        handleAuthFailure('registration_attempt', null, $email, [], [
            'username_attempted' => $username,
            'email_attempted'    => $email,
            'validation_errors'  => $validation->_errors,
            'user_agent'         => $user_agent ?? '',
        ]);

        // Log the failure — generic message avoids confirming whether the email exists
        logger(0, LogCategories::LOG_CATEGORY_REGISTRATION_FAILED,
            'join.php: Registration failed — ' . count($validation->_errors ?? []) . ' validation error(s)',
            [
                'stage'        => 'validation',
                'error_count'  => count($validation->_errors ?? []),
                'user_agent'   => $user_agent ?? '',
            ]);

        // Silently notify the existing account holder if this email is already taken (#1406) —
        // the generic failure message below is shown regardless of whether this fires; the
        // notification is an ADDITIONAL side effect, never an alternative to that message.
        // The whole block is wrapped in try/catch: checkRateLimit()/recordRateLimit()/new \User()
        // all touch the DB directly and are not guarded by RegistrationRecoveryNotifier's own
        // try/catch (that only covers its internals) — an uncaught DB error here would fatal
        // instead of falling through to the generic failure message below, which would itself
        // be a distinguishing (enumeration-adjacent) failure mode.
        $fuser = null;
        // Timing-equalization floor: captured before any branch-specific work
        // and enforced unconditionally in the finally block below, so EVERY
        // outcome (email exists, doesn't exist, rate-limited, malformed email,
        // or a caught DB error) takes the same minimum wall-clock time. A
        // one-sided sleep() applied only to the "doesn't exist" no-op branch
        // would leave the "exists" branch (DB write + email_body() render +
        // synchronous email() send — plausibly sub-second for a transactional
        // email API) measurably FASTER, reopening via response latency the
        // exact enumeration oracle this fix closes for response content.
        $recoveryTimingFloorSeconds = 2.0;
        $recoveryStartTime = microtime(true);
        try {
            // Rate-limit key is case-normalized: the users.email column uses a
            // case-insensitive collation (utf8mb4_unicode_ci), so new \User(...,
            // 'forceEmail') below resolves 'Victim@x.com' and 'victim@x.com' to
            // the same account. Without normalizing here too, an attacker could
            // vary the submitted email's case on each attempt to dodge the
            // email_max cap while still targeting that one real account.
            $rateLimitEmailKey = !empty($email) ? mb_strtolower($email) : $email;
            if (!empty($email) && checkRateLimit('registration_recovery_email', null, $rateLimitEmailKey)) {
                // 'forceEmail' pins the lookup to the email column regardless of format —
                // this branch can be reached with a malformed $email (e.g. validation failed
                // on valid_email), and without this a non-email string would otherwise fall
                // through to a username-column lookup and could overwrite an unrelated
                // username-matched user's vericode.
                $fuser = new \User($email, 'forceEmail');
                $notifier = new \ElanRegistry\RegistrationRecoveryNotifier(dbi());
                $notifier->notifyIfAccountExists($fuser, $email, $settings);

                // Record the attempt so the per-email rate limit actually accumulates —
                // checkRateLimit() only counts prior recorded attempts; without this call
                // the limit above never engages and an attacker could email-bomb a victim
                // via repeated registration attempts with their address.
                //
                // Deliberately record success=false (not the notification's own success/
                // failure) on every pass: RateLimit::check() only counts success=false rows
                // toward the tight email_max cap, while total_max counts EVERY attempt
                // (success and failure alike). The abuse this limit exists to stop
                // (repeatedly targeting one real, existing email) is exactly the
                // success=true case, so recording it as success=false is what makes
                // email_max actually engage for that scenario.
                recordRateLimit('registration_recovery_email', false, null, $rateLimitEmailKey);
            }
        } catch (\Throwable $e) {
            $knownUserId = ($fuser !== null && $fuser->exists()) ? (int) $fuser->data()->id : 0;
            $safeToLog = preg_replace('/[\r\n\t]/', ' ', $e->getMessage()) ?? '[message unavailable]';
            logger($knownUserId, LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
                'join.php: recovery-notification side effect failed — ' . get_class($e) . ': ' . $safeToLog);
        } finally {
            // A blocking sleep here is only DoS-safe because it's bounded by
            // registration_attempt's own per-IP and total hourly rate limits
            // (current values in usersc/includes/rate_limits.php), checked earlier
            // in this file, not by anything in this block — an attacker can't
            // cheaply amplify worker-blocking beyond that ceiling.
            $recoveryElapsedSeconds = microtime(true) - $recoveryStartTime;
            $recoveryRemainingSeconds = $recoveryTimingFloorSeconds - $recoveryElapsedSeconds;
            if ($recoveryRemainingSeconds > 0) {
                usleep((int) round($recoveryRemainingSeconds * 1_000_000));
            }
        }

        // Show a generic message regardless of the specific reason (prevents email enumeration)
        usError('Check your inbox — if an account exists, we have sent you a sign-in link. Otherwise, please check your information and try again.');

        Redirect::to(currentPage());
    } //Validation
} //Input exists



if ($settings->registration == 1) {
    if(file_exists($abs_us_root.$us_url_root.'usersc/views/_join.php')){
      require($abs_us_root.$us_url_root.'usersc/views/_join.php');
    }else{
      require $abs_us_root.$us_url_root.'users/views/_join.php';
    }

} else {
  if(file_exists($abs_us_root.$us_url_root.'usersc/views/_joinDisabled.php')){
    require $abs_us_root.$us_url_root.'usersc/views/_joinDisabled.php';
  }else{
    require $abs_us_root.$us_url_root.'users/views/_joinDisabled.php';
  }
}
includeHook($hooks, 'bottom');
?>

<!-- Location Picker Styles -->
<link rel="stylesheet" href="<?=$us_url_root?>app/assets/css/location-picker.min.css?v=<?= ASSET_VERSION ?>">

<!-- Location Picker Script -->
<script src="<?=$us_url_root?>app/assets/js/location-picker.min.js?v=<?= ASSET_VERSION ?>"></script>

<script type="text/javascript" nonce="<?= htmlspecialchars($userspice_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
    $(document).ready(function(){
        // Initialize Location Picker for registration
        if (document.getElementById('location-picker-registration')) {
            const urlRoot = '<?php echo $us_url_root; ?>';

            const locationPicker = new LocationPicker({
                containerId: 'location-picker-registration',
                csrfToken: '<?=Token::generate()?>',
                urlRoot: urlRoot,
                showGPS: true,
                required: true,
                // Location is a required field — a GPS failure here can block
                // registration the same way a blocked submit does, and would
                // otherwise be just as invisible server-side. Reuses the join
                // page's own failure beacon (#1690) rather than adding a
                // second reporting path. See app/assets/js/join-form-beacon.js.
                onGPSError: function (error) {
                    if (typeof window.elanReportJoinFailure === 'function') {
                        window.elanReportJoinFailure('location_gps_failed', 'code=' + (error && error.code));
                    }
                }
            });
        }

        $('.password_view_control').hover(function () {
            $('#password').attr('type', 'text');
            $('#confirm').attr('type', 'text');
        }, function () {
            $('#password').attr('type', 'password');
            $('#confirm').attr('type', 'password');
        });
    });
</script>

<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>