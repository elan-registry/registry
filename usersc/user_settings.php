<?php

declare(strict_types=1);

/*
UserSpice 4
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
?>
<?php
require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';
?>


<?php
use ElanRegistry\AppConstants;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use ElanRegistry\Exceptions\OwnerUpdateException;
use ElanRegistry\Exceptions\OwnerValidationException;
use ElanRegistry\Input;
use ElanRegistry\InputSanitizer;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;

if (!securePage($php_self)) {
    die();
}

// $master_account and $currentPage are set as globals by users/init.php
// (required above) — PHPStan can't trace assignments across the include
// boundary, so re-bind them locally here for both static analysis and
// clarity about where these values come from.
global $master_account, $currentPage;

//dealing with if the user is logged in
if ($user->isLoggedIn() && !checkMenu(2, $user->data()->id) && ($settings->site_offline == 1) && (!in_array($user->data()->id, $master_account)) && ($currentPage != 'login.php') && ($currentPage != 'maintenance.php')) {
    $user->logout();
    Redirect::to($us_url_root . 'users/maintenance.php');
}


$emailQ = $db->query("SELECT * FROM email");
$emailR = $emailQ->first();

$errors = [];
$successes = [];
$userId = (int)$user->data()->id;

// Built once for both the resume-verification-emails POST branch and the
// GET-time visibility/count logic below, as in app/admin/index.php.
$repo = new CarRepository(dbi());
$verifier = new CarVerificationManager($repo);

if (!function_exists('userSettingsHistoryFields')) {
    /**
     * Build a cars_hist snapshot row for an owner self-service action on this
     * page (Resume verification emails, #1895).
     *
     * The third copy of the same snapshot shape: verifyHistoryFields() in
     * app/verify/verify_car.php (owner actions from an email link, with a
     * $soldDate parameter) and verifyHistoryFieldsForAdminAction() in
     * app/admin/index.php (admin actions, no $soldDate). This copy has no
     * $soldDate either, because this action never sets a sale date. The three
     * are kept separate until a refactor merges them.
     *
     * @param object $carData   PRE-CHANGE car snapshot returned by the CarVerificationManager
     * @param string $operation cars_hist.operation value (varchar(32))
     * @param string $comments  Free-text audit note
     * @return array<string, mixed> Field map for CarRepository::insertHistory()
     * @throws OwnerDatabaseException If the car's owner cannot be loaded
     */
    function userSettingsHistoryFields(object $carData, string $operation, string $comments): array
    {
        $owner = (new Owner((int) $carData->user_id))->data();

        // A half-populated audit row is worse than no change: fail and let the
        // caller's transaction roll the car mutation back too.
        if ($owner === null) {
            throw new OwnerDatabaseException(
                'user_settings.php: owner ' . (int) $carData->user_id
                . " could not be loaded while building the {$operation} history snapshot for car "
                . (int) $carData->id
            );
        }

        return [
            'operation'             => $operation,
            'car_id'                => (int) $carData->id,
            'comments'              => $comments,
            'ctime'                 => $carData->ctime ?? date(AppConstants::DATETIME_FORMAT),
            'mtime'                 => date(AppConstants::DATETIME_FORMAT),
            'model'                 => $carData->model ?? '',
            'series'                => $carData->series ?? '',
            'variant'               => $carData->variant ?? '',
            // cars_hist.year is SMALLINT UNSIGNED NULL. Strict mode rejects ''.
            'year'                  => $carData->year ?? null,
            'type'                  => $carData->type ?? '',
            'chassis'               => $carData->chassis ?? '',
            'color'                 => $carData->color ?? '',
            'engine'                => $carData->engine ?? '',
            'purchasedate'          => $carData->purchasedate ?? null,
            'solddate'              => $carData->solddate ?? null,
            'email_bounced'         => $carData->email_bounced ?? 0,
            'email_bounced_address' => $carData->email_bounced_address ?? null,
            'email_suppressed'      => $carData->email_suppressed ?? 0,
            'verification_attempts'       => $carData->verification_attempts ?? 0,
            'verification_attempts_since' => $carData->verification_attempts_since ?? null,
            'image'                 => $carData->image ?? '',
            'user_id'               => (int) $carData->user_id,
            'email'                 => $carData->email ?? ($owner->email ?? ''),
            'fname'                 => $owner->fname ?? '',
            'lname'                 => $owner->lname ?? '',
            'join_date'             => $owner->join_date ?? null,
            'city'                  => $owner->city ?? '',
            'state'                 => $owner->state ?? '',
            'country'               => $owner->country ?? '',
            'lat'                   => $owner->lat ?? null,
            'lon'                   => $owner->lon ?? null,
            'website'               => $owner->website ?? '',
        ];
    }
}

$validation = new Validate();
$userdetails = $user->data();
// Get the profile ID for the direct website-clear write further down.
$profileQ = $db->query("SELECT id FROM profiles WHERE user_id = ?", [$userId]);
$profileId = (int)$profileQ->results()[0]->id;
// USER ID is in $user_id .  Use the USER ID to get the users Profile information
$userQ = $db->query("SELECT * FROM profiles LEFT JOIN users ON user_id = users.id WHERE user_id = ?", [$userId]);
if ($userQ->count() > 0) {
    $profiledetails = $userQ->first();

    /* Set the city, state, country for geolocation.  If there is an update of any of these values they will be overwritten */
    $city = (string)$profiledetails->city;
    $state = (string)$profiledetails->state;
    $country = (string)$profiledetails->country;
} else {
    // Every owner gets a profiles row via Owner::create()'s single
    // user+profile transaction, so a missing row here means data
    // corruption for an otherwise-authenticated user. $profiledetails is
    // used unconditionally below (city/state/country/website comparisons),
    // so this is unrecoverable for the rest of the page — log and stop
    // rather than continue into undefined-variable territory.
    logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_USER, "USER_SETTING(59) something is wrong with the user profile ");
    echo "<h2>An error occurred loading your account. Please contact the registry.</h2>";
    exit;
}


//Forms posted
if (!empty($_POST)) {
    $token = $_POST['csrf'] ?? '';
    if (!Token::check($token)) {
        include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
    } elseif (isset($_POST['resume_verification_emails'])) {
        // Owner self-service resume (#1895). This branch owns the whole
        // request: the profile-update logic in the else branch never runs
        // with it. The owner is $userId from the session only. No id from
        // the POST body is read.
        //
        // One transaction covers the fan-out and every audit row, so a car
        // never ends up cleared with no cars_hist record of who asked.
        //
        // $committed tells the catch below whether the change is already
        // saved. After commit(), a fault in logger(), usSuccess() or
        // Redirect::to() must not roll back (a no-op) or tell the owner that
        // nothing was changed.
        $committed = false;
        try {
            // Inside the try: PDO runs in ERRMODE_EXCEPTION, so a failed BEGIN
            // throws, and the catch below must give the owner a message.
            $repo->beginTransaction();
            $resumedCars = $verifier->clearSuppressedForOwnerByOwner($userId);
            foreach ($resumedCars as $beforeCar) {
                if (!$repo->insertHistory(userSettingsHistoryFields(
                    $beforeCar,
                    'SUPPRESSION CLEARED BY OWNER',
                    'Owner action via Account Settings (Resume verification emails)'
                ))) {
                    // Read the error before any other query resets the shared connection state.
                    throw new CarDatabaseException(
                        'user_settings.php: audit trail insert failed for SUPPRESSION CLEARED BY OWNER on car '
                        . (int) $beforeCar->id . " for owner {$userId}: "
                        . ($repo->errorString() ?: 'unknown')
                    );
                }
            }
            $repo->commit();
            $committed = true;

            logger($userId, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, sprintf(
                'user_settings.php: SUPPRESSION CLEARED BY OWNER applied for owner %d (%d cars changed)',
                $userId,
                count($resumedCars)
            ));
            // The owner cannot clear a Brevo complaint (only an admin can),
            // so say which cars stay paused instead of a plain "resumed".
            $complaintCarCount = count($verifier->findBrevoComplaintCarIds($userId));
            if ($complaintCarCount > 0) {
                usSuccess(sprintf(
                    'Verification emails have been resumed where you paused them. %d of your cars %s paused because our email provider flagged the address. Please contact the registry to turn %s back on.',
                    $complaintCarCount,
                    $complaintCarCount === 1 ? 'stays' : 'stay',
                    $complaintCarCount === 1 ? 'it' : 'them'
                ));
            } else {
                usSuccess('Verification emails have been resumed for your cars.');
            }
            Redirect::to($us_url_root . 'usersc/user_settings.php');
            exit;
        } catch (\Throwable $e) {
            // \Throwable, not only ElanRegistryException: a narrower catch
            // would leave the transaction open for the rest of the request.
            if ($committed) {
                logger($userId, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, sprintf(
                    'user_settings.php: SUPPRESSION CLEARED BY OWNER committed for owner %d, '
                    . 'but a post-commit step failed [%s]: %s',
                    $userId,
                    get_class($e),
                    $e->getMessage()
                ));
                $errors[] = 'Verification emails were resumed, but the confirmation could not be shown. Reload this page to check.';
            } else {
                // Guard the rollback: on a dropped connection ROLLBACK throws
                // too, and an unguarded call would lose $e. Same pattern as
                // Owner::syncOwnerFieldsToCars().
                try {
                    $repo->rollback();
                } catch (\Throwable $rollbackFailure) {
                    logger($userId, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, sprintf(
                        'user_settings.php: SUPPRESSION CLEARED BY OWNER rollback failed for owner %d '
                        . 'while handling [%s] %s (rollback error: %s)',
                        $userId,
                        get_class($e),
                        $e->getMessage(),
                        $rollbackFailure->getMessage()
                    ));
                }
                if ($e instanceof ElanRegistryException) {
                    // CarDatabaseException from the manager or the audit
                    // insert, and OwnerDatabaseException from the history
                    // snapshot.
                    logger($userId, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED,
                        "user_settings.php: SUPPRESSION CLEARED BY OWNER failed for owner {$userId}: " . $e->getMessage());
                } else {
                    logger($userId, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, sprintf(
                        'user_settings.php: SUPPRESSION CLEARED BY OWNER unexpected error [%s] for owner %d: %s',
                        get_class($e),
                        $userId,
                        $e->getMessage()
                    ));
                }
                $errors[] = 'Verification emails could not be resumed. Nothing was changed. Please try again or contact support.';
            }
        }
    } else {
        // Owner-contact fields (fname, lname, email, city, state, country, lat,
        // lon, website) that passed validation below. They are written and
        // synced to the owner's cars in one Owner::updateProfileAndSync() call
        // after all blocks run. A value that failed validation never goes into
        // this array, so it never reaches users/profiles or the cars.
        // The success messages and log lines for these fields are held back
        // until that write succeeds.
        $ownerFields = [];
        // The new address when the email_act == 0 branch writes users.email
        // directly. Null otherwise.
        $directNewEmail = null;
        $websiteCleared = false;
        $ownerSuccesses = [];
        $ownerLogLines = [];

        //Update display name
        //if (($settings->change_un == 0) || (($settings->change_un == 2) && ($user->data()->un_changed == 1)))
        if ($userdetails->username != $_POST['username'] && ($settings->change_un == 1 || (($settings->change_un == 2) && ($user->data()->un_changed == 0)))) {
            $displayname = Input::raw('username');
            $fields = [
                'username' => $displayname,
                'un_changed' => 1,
            ];
            $validation->check($_POST, [
                'username' => [
                    'display' => 'Username',
                    'required' => true,
                    'unique_update' => 'users,' . $userId,
                    'min' => $settings->min_un,
                    'max' => $settings->max_un
                ]
            ]);
            if ($validation->passed()) {
                if (($settings->change_un == 2) && ($user->data()->un_changed == 1)) {
                    Redirect::to($us_url_root . 'users/user_settings.php?err=Username+has+already+been+changed+once.');
                }
                $db->update('users', $userId, $fields);
                $successes[] = 'Username updated.';
                logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_USER, 'Changed username from $userdetails->username to $displayname.');
            } else {
                //validation did not pass
                foreach ($validation->errors() as $error) {
                    $errors[] = $error;
                }
            }
        } else {
            $displayname = $userdetails->username;
        }
        //Update first name
        if ($userdetails->fname != $_POST['fname']) {
            $fname = ucfirst(Input::raw('fname') ?? '');
            $validation->check($_POST, [
                'fname' => [
                    'display' => 'First Name',
                    'required' => true,
                    'min' => 1,
                    'max' => 25
                ]
            ]);
            if ($validation->passed()) {
                $ownerFields['fname'] = $fname;
                $ownerSuccesses[] = 'First name updated.';
                $ownerLogLines[] = "Changed fname from $userdetails->fname to $fname.";
            } else {
                //validation did not pass
                foreach ($validation->errors() as $error) {
                    $errors[] = $error;
                }
            }
        } else {
            $fname = $userdetails->fname;
        }
        //Update last name
        if ($userdetails->lname != $_POST['lname']) {
            $lname = ucfirst(Input::raw('lname') ?? '');
            $validation->check($_POST, [
                'lname' => [
                    'display' => 'Last Name',
                    'required' => true,
                    'min' => 1,
                    'max' => 25
                ]
            ]);
            if ($validation->passed()) {
                $ownerFields['lname'] = $lname;
                $ownerSuccesses[] = 'Last name updated.';
                $ownerLogLines[] = "Changed lname from $userdetails->lname to $lname.";
            } else {
                //validation did not pass
                foreach ($validation->errors() as $error) {
                    $errors[] = $error;
                }
            }
        } else {
            $lname = $userdetails->lname;
        }
        // Extend user_setttings.php with some PROFILE information
        // Update Location (city, state, country, lat, lon)
        $locationChanged = false;
        $newCity = Input::raw('city') ?? '';
        $newState = Input::raw('state') ?? '';
        $newCountry = Input::raw('country') ?? '';
        $newLat = Input::raw('lat') ?? '';
        $newLon = Input::raw('lon') ?? '';

        // If all location fields are empty but the user has existing location data,
        // JS pre-population likely failed — preserve existing values to prevent false change detection
        if (empty($newCity) && empty($newCountry) && !empty($profiledetails->city)) {
            $newCity    = $profiledetails->city;
            $newState   = $profiledetails->state ?? '';
            $newCountry = $profiledetails->country;
            $newLat     = (string)($profiledetails->lat ?? '');
            $newLon     = (string)($profiledetails->lon ?? '');
        }

        // Check if any location field changed
        if ($profiledetails->city != $newCity ||
            $profiledetails->state != $newState ||
            $profiledetails->country != $newCountry) {
            $locationChanged = true;
        }

        if ($locationChanged) {
            // Validate location fields
            $validation->check($_POST, [
                'city' => [
                    'display' => 'City',
                    'required' => true,
                    'min' => 1,
                    'max' => 255
                ],
                'state' => [
                    'display' => 'State',
                    'required' => true,
                    'min' => 1,
                    'max' => 255
                ],
                'country' => [
                    'display' => 'Country',
                    'required' => true,
                    'min' => 1,
                    'max' => 255
                ]
            ]);

            if ($validation->passed()) {
                // Build location update array
                $locationFields = [
                    'city' => ucfirst($newCity),
                    'state' => ucfirst($newState),
                    'country' => ucfirst($newCountry)
                ];

                // Add coordinates if provided by location picker. Owner::update()
                // validates them as numeric and in range.
                $hasCoordinates = !empty($newLat) && !empty($newLon);
                if ($hasCoordinates) {
                    $locationFields['lat'] = $newLat;
                    $locationFields['lon'] = $newLon;
                }

                $ownerFields = array_merge($ownerFields, $locationFields);

                $city = $locationFields['city'];
                $state = $locationFields['state'];
                $country = $locationFields['country'];
                $geoResult = [
                    'lat' => $locationFields['lat'] ?? null,
                    'lon' => $locationFields['lon'] ?? null
                ];

                if ($hasCoordinates) {
                    $ownerSuccesses[] = 'Location updated successfully.';
                } else {
                    $ownerSuccesses[] = 'Location text updated. Use the location picker to add coordinates for map display.';
                }
                $ownerLogLines[] = "Updated location to: $city, $state, $country" .
                    (isset($locationFields['lat']) ? " ({$locationFields['lat']}, {$locationFields['lon']})" : '');
            } else {
                // Validation did not pass
                foreach ($validation->errors() as $error) {
                    $errors[] = $error;
                }
                $city = $profiledetails->city;
                $state = $profiledetails->state;
                $country = $profiledetails->country;
                $geoResult = [];
            }
        } else {
            $city = $profiledetails->city;
            $state = $profiledetails->state;
            $country = $profiledetails->country;
            $geoResult = [];
        }

        if (!empty($geoResult) && isset($geoResult['lat']) && isset($geoResult['lon'])) {
            $ownerSuccesses[] = 'Lat/Lon updated.';
            $ownerLogLines[] = 'Successfully updated lat/lon: ' . json_encode($geoResult);
        }

        //Update Website
        if ($profiledetails->website != $_POST['website']) {
            // Sanitize URL by removing illegal characters manually (replacing deprecated FILTER_SANITIZE_URL)
            $websiteUrl = preg_replace('/[^a-zA-Z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]/', '', trim(Input::raw('website') ?? ''));

            // Validate URL format then restrict to http/https schemes (empty = clear the field)
            if ($websiteUrl === '') {
                // Written directly: Owner::update() drops empty values, so it
                // cannot clear the website. $websiteCleared below pushes the
                // clear onto the owner's cars even when no other field changed.
                if ($db->update('profiles', $profileId, ['website' => $websiteUrl])) {
                    $websiteCleared = true;
                    $ownerSuccesses[] = 'Website removed.';
                    $ownerLogLines[] = "Changed website from {$profiledetails->website} to (empty).";
                } else {
                    $errors[] = 'Failed to remove website. Please try again.';
                    logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "Failed to clear website for user {$userId}");
                }
            } elseif (!filter_var($websiteUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'Website URL must start with http:// or https:// (e.g. https://example.com)';
                logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, "Invalid website URL rejected for user {$userId}");
            } else {
                $websiteScheme = strtolower((string) parse_url($websiteUrl, PHP_URL_SCHEME));
                if (!in_array($websiteScheme, ['http', 'https'], true)) {
                    $errors[] = 'Website URL must use http:// or https:// (e.g. https://example.com)';
                    logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_SECURITY, "Non-http/https website scheme rejected for user {$userId}: scheme='{$websiteScheme}'");
                } else {
                    $ownerFields['website'] = $websiteUrl;
                    $ownerSuccesses[] = 'Website updated.';
                    $ownerLogLines[] = "Changed website from {$profiledetails->website} to {$websiteUrl}.";
                }
            }
        } else {
            $website = $profiledetails->website;
        }

        // END Extend user_setttings.php with some PROFILE information

        if (!empty($_POST['password']) || $userdetails->email != $_POST['email'] || !empty($_POST['resetPin'])) {
            //Check password for email or pw update
            if (is_null($userdetails->password) || password_verify(Input::get('old'), $user->data()->password)) {

                //Update email
                if ($userdetails->email != $_POST['email']) {
                    $email = Input::get('email');
                    $confemail = Input::get('confemail');
                    $validation->check($_POST, [
                        'email' => [
                            'display' => 'Email',
                            'required' => true,
                            'valid_email' => true,
                            'unique_update' => 'users,' . $userId,
                            'min' => 3,
                            'max' => 75
                        ]
                    ]);
                    if ($validation->passed()) {
                        if ($confemail == $email) {
                            if ($emailR->email_act == 0) {
                                // users.email changes now, so the new address must
                                // reach the cars: queue it with the other owner
                                // fields. The email_act == 1 branch below only
                                // stages email_new — users.email is unchanged until
                                // the user confirms via users/verify.php, so it must
                                // NOT queue the address. Input::raw(), not $email:
                                // $email is HTML-encoded by Input::get().
                                $directNewEmail = Input::raw('email') ?? '';
                                $ownerFields['email'] = $directNewEmail;
                                $ownerSuccesses[] = 'Email updated.';
                                $ownerLogLines[] = "Changed email from $userdetails->email to $email.";
                            }
                            if ($emailR->email_act == 1) {
                                $vericode = randomstring(15);
                                $vericode_expiry = date("Y-m-d H:i:s", strtotime("+$settings->join_vericode_expiry hours", strtotime(date("Y-m-d H:i:s"))));
                                $db->update('users', $userId, ['email_new' => $email, 'vericode' => hashVericode($vericode), 'vericode_expiry' => $vericode_expiry]);
                                //Send the email
                                $options = [
                                    'fname' => $user->data()->fname,
                                    'email' => rawurlencode($user->data()->email),
                                    'vericode' => $vericode,
                                    'user_id' => $userId,
                                    'join_vericode_expiry' => $settings->join_vericode_expiry
                                ];
                                $subject = 'Verify Your Email';
                                $body =  email_body('_email_template_verify_new.php', $options);

                                if ($body === '') {
                                    logger($userId, LogCategories::LOG_CATEGORY_EMAIL_ERROR,
                                        'user_settings.php: email_body() returned empty — template missing or failed',
                                        ['template' => '_email_template_verify_new.php']);
                                    $errors[] = 'Email could not be sent. Please try again or contact the administrator.';
                                }

                                if ($body !== '') {
                                    $email_sent = email($email, $subject, $body);
                                    if ($email_sent !== true) {
                                        try {
                                            $safeToLog = InputSanitizer::stripHeaderInjectionChars($email);
                                        } catch (\RuntimeException $sanitizeException) {
                                            $safeToLog = '(unloggable address — ' . $sanitizeException->getMessage() . ')';
                                        }
                                        logger($userId, LogCategories::LOG_CATEGORY_EMAIL_ERROR,
                                            'user_settings.php: verify-email SEND FAILED for user ' . $userId . ' to ' . $safeToLog);
                                        $errors[] = 'Email NOT sent due to error. Please contact site administrator.';
                                    } else {
                                        $successes[] = "Email request received. Please check your email to perform verification. Be sure to check your Spam and Junk folder as the verification link expires in {$settings->join_vericode_expiry} hours.";
                                    }
                                    if ($emailR->email_act == 1) {
                                        logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_USER, "Requested change email from $userdetails->email to $email. Verification email sent.");
                                    }
                                }
                            }
                        } else {
                            $errors[] = 'Your email did not match.';
                        }
                    } else {
                        //validation did not pass
                        foreach ($validation->errors() as $error) {
                            $errors[] = $error;
                        }
                    }
                } else {
                    $email = $userdetails->email;
                }
                if (!empty($_POST['password'])) {
                    $validation->check($_POST, [
                        'password' => [
                            'display' => 'New Password',
                            'required' => true,
                            'min' => $settings->min_pw,
                            'max' => $settings->max_pw,
                        ],
                        'confirm' => [
                            'display' => 'Confirm New Password',
                            'required' => true,
                            'matches' => 'password',
                        ],
                    ]);
                    foreach ($validation->errors() as $error) {
                        $errors[] = $error;
                    }
                    if (empty($errors) && Input::get('old') != Input::get('password')) {
                        //process
                        $new_password_hash = password_hash(Input::get('password'), PASSWORD_BCRYPT, ['cost' => 12]);
                        $user->update(['password' => $new_password_hash, 'force_pr' => 0, 'vericode' => hashVericode(randomstring(15)),], $user->data()->id);
                        $successes[] = 'Password updated.';
                        logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_USER, 'Updated password.');
                        if ($settings->session_manager == 1) {
                            $passwordResetKillSessions = passwordResetKillSessions();
                            if (is_numeric($passwordResetKillSessions)) {
                                if ($passwordResetKillSessions == 1) {
                                    $successes[] = 'Successfully Killed 1 Session';
                                }
                                if ($passwordResetKillSessions > 1) {
                                    $successes[] = "Successfully Killed $passwordResetKillSessions Session";
                                }
                            } else {
                                $errors[] = 'Failed to kill active sessions, Error: ' . $passwordResetKillSessions;
                            }
                        }
                    } else {
                        if (Input::get('old') == Input::get('password')) {
                            $errors[] = 'Your old password cannot be the same as your new';
                        }
                    }
                }
                if (!empty($_POST['resetPin']) && Input::get('resetPin') == 1) {
                    $user->update(['pin' => null]);
                    logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_USER, 'Reset PIN');
                    $successes[] = 'Reset PIN';
                    $successes[] = 'You can set a new PIN the next time you require verification';
                }
            } else {
                $errors[] = 'Current password verification failed. Update failed. Please try again.';
            }
        }

        // Write the owner's contact fields and push them onto their cars once,
        // after every block above has validated its field. Gated on any field
        // being queued, or a website clear, rather than on coordinates: a pure
        // city/state text change, or a name/website/email change, must sync
        // too (#1873), and so must clearing the website to empty (#1891).
        //
        // getCarsOwned()/syncOwnerFieldsToCars() throw OwnerDatabaseException on a DB
        // failure (#1505 PR B) rather than silently returning []/0 — this page has no
        // exception handling elsewhere, so a DB blip here must degrade gracefully
        // rather than crash the whole settings page after the profile fields
        // already saved. Matches the defensive-wrap precedent from PR A (#1816).
        if ($ownerFields !== [] || $websiteCleared) {
            // new Owner($userId) is split into its own try block, before the write,
            // so a failure here (nothing written yet) is never confused with a
            // failure inside the try block below (the write may have committed).
            // Both find() and updateProfileAndSync() can throw OwnerDatabaseException,
            // and conflating "nothing was written" with "it was written, syncing
            // failed" told the owner their changes were saved when they were not.
            // Reports $ownerSuccesses/$ownerLogLines exactly once, from whichever
            // path below actually reaches it. Idempotent: a path that already
            // reported (e.g. the success path) must not report again if a later
            // \Throwable from inside the same try block reaches a catch that
            // also calls this.
            $ownerFieldsReported = false;
            $reportOwnerWrite = function () use (&$successes, &$ownerFieldsReported, $ownerSuccesses, $ownerLogLines, $userId): void {
                if ($ownerFieldsReported) {
                    return;
                }
                $ownerFieldsReported = true;
                $successes = array_merge($successes, $ownerSuccesses);
                foreach ($ownerLogLines as $logLine) {
                    logger($userId, LogCategories::LOG_CATEGORY_USER, $logLine);
                }
            };

            // Reports only the website-clear's own success message and log
            // line — never $ownerSuccesses as a whole, which may include other
            // fields that were never written when this runs (e.g. the owner
            // failed to load, or update() rejected an unrelated field). Those
            // other fields' own outcome is reported separately by whichever
            // catch calls this. Idempotent for the same reason as $reportOwnerWrite.
            $websiteClearReported = false;
            $reportWebsiteCleared = function () use (&$successes, &$websiteClearReported, $userId, $profiledetails): void {
                if ($websiteClearReported) {
                    return;
                }
                $websiteClearReported = true;
                $successes[] = 'Website removed.';
                logger($userId, LogCategories::LOG_CATEGORY_USER, "Changed website from {$profiledetails->website} to (empty).");
            };

            // The profile write committed before this runs. A failure here must
            // not tell the owner that the email change failed, so log it only.
            $clearBouncedForNewEmail = function (string $newEmail) use ($repo, $userId): void {
                try {
                    $cleared = $repo->clearBouncedForUser($userId, $newEmail);
                    if ($cleared > 0) {
                        logger($userId, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED,
                            "user_settings.php: cleared bounce flag on {$cleared} car(s) "
                            . "for user {$userId} after direct email change.");
                    }
                } catch (\Throwable $bounceException) {
                    logger($userId, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                        "user_settings.php: bounce-clear after direct email change failed for user {$userId}: "
                        . $bounceException->getMessage());
                }
            };

            try {
                $owner = new Owner($userId);
            } catch (OwnerDatabaseException $e) {
                logger($userId, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                    "user_settings.php: failed to load owner {$userId} before profile update: " . $e->getMessage());
                if ($websiteCleared) {
                    // The direct website-clear write above already committed —
                    // only the reload needed to sync it to the cars failed. Say
                    // so accurately instead of the generic "not saved": the
                    // clear itself did save, it just has not reached the cars.
                    // $ownerFields (if any) was never attempted — report only
                    // the clear, not $ownerSuccesses as a whole.
                    $reportWebsiteCleared();
                    $errors[] = 'Website removed, but could not be synchronized to your cars. Please contact support if this persists.';
                    if ($ownerFields !== []) {
                        $errors[] = 'Your other changes could not be saved. Please try again.';
                    }
                } else {
                    $errors[] = 'Your changes could not be saved. Please try again.';
                }
                $owner = null;
            }

            if ($owner !== null) {
                try {
                    // A website-only clear carries no field for update() to write
                    // (it drops empty values), so $ownerFields can be empty here —
                    // sync the already-cleared value straight from the reloaded
                    // owner instead of calling updateProfileAndSync(), which
                    // requires at least one field.
                    $syncResult = $ownerFields !== []
                        ? $owner->updateProfileAndSync($ownerFields)
                        : $owner->syncOwnerFieldsToCars();
                    $reportOwnerWrite();
                    if ($ownerFields !== [] && $directNewEmail !== null) {
                        // The email_act == 0 path changed users.email directly.
                        // Clear the bounce flags the same way the confirmed-change
                        // hook (sync_owner_email_on_verify.php) does, so the
                        // account notice's "updating your email address will start
                        // them again" is true on both paths.
                        $clearBouncedForNewEmail($directNewEmail);
                    }
                    // Cars in $syncResult's skipped bucket (no longer owned by this user) are
                    // intentionally not reported here — there's nothing actionable for the
                    // owner, since the car isn't theirs anymore. isCompleteSuccess() already
                    // treats a skip-only outcome as success.
                    if ($syncResult->isCompleteSuccess()) {
                        if ($syncResult->updatedCount() > 0) {
                            $successes[] = "Owner details synchronized to {$syncResult->updatedCount()} car(s).";
                        }
                    } else {
                        // totalCount() includes skipped cars, so the denominator can
                        // exceed updated + failed. Name the skips too, or the count
                        // reads as unexplained missing cars (#1954).
                        $syncError = sprintf(
                            'Owner details saved, but synchronized to only %d of %d car(s). %s',
                            $syncResult->updatedCount(),
                            $syncResult->totalCount(),
                            $syncResult->failedCarsPhrase()
                        );
                        if ($syncResult->skippedCount() > 0) {
                            $syncError .= ' ' . $syncResult->skippedCarsPhrase();
                        }
                        $errors[] = $syncError . ' Please contact support if this persists.';
                    }
                } catch (OwnerValidationException | OwnerUpdateException $e) {
                    // update() rejected or rolled back the write, so $ownerFields'
                    // own success messages stay unsent — but a website clear
                    // outside $ownerFields may have already committed on its own
                    // (direct write, above), so sync it here rather than leave it
                    // stale until some other field happens to change later.
                    logger($userId, $e->getLogCategory(),
                        "user_settings.php: owner profile update failed for user {$userId}: " . $e->getMessage());
                    $errors[] = $e->getUserMessage();
                    if ($websiteCleared) {
                        // Report the clear as soon as it's known to have committed
                        // (the direct write, above), before attempting the sync —
                        // otherwise a sync failure here would leave the clear's own
                        // audit log line unwritten, unlike every other path that
                        // reaches a committed clear.
                        $reportWebsiteCleared();
                        try {
                            // Check the result the same way as the main path: a
                            // per-car failure is in the result, not thrown.
                            $clearSyncResult = $owner->syncOwnerFieldsToCars();
                            if (!$clearSyncResult->isCompleteSuccess()) {
                                $clearSyncError = sprintf(
                                    'Website removed, but synchronized to only %d of %d car(s). %s',
                                    $clearSyncResult->updatedCount(),
                                    $clearSyncResult->totalCount(),
                                    $clearSyncResult->failedCarsPhrase()
                                );
                                if ($clearSyncResult->skippedCount() > 0) {
                                    $clearSyncError .= ' ' . $clearSyncResult->skippedCarsPhrase();
                                }
                                $errors[] = $clearSyncError . ' Please contact support if this persists.';
                            }
                        } catch (\Throwable $syncException) {
                            logger($userId, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                                "user_settings.php: website-clear sync failed for user {$userId}: " . $syncException->getMessage());
                            $errors[] = 'Website removed, but could not be synchronized to your cars. Please contact support if this persists.';
                        }
                    }
                } catch (OwnerDatabaseException | CarDatabaseException $e) {
                    // CarDatabaseException is a sibling of OwnerDatabaseException, not a
                    // subclass — both must be named explicitly. syncOwnerFieldsToCars()
                    // propagates either on an infrastructure fault (deadlock, lock-wait
                    // timeout) rather than reporting it as a per-car failure, and this page
                    // has no other handler: without this, a routine deadlock would replace
                    // the settings page with a fatal error after the profile fields
                    // already saved successfully. $owner loaded successfully (its own try
                    // block above already handled a find() failure), and update() throws
                    // neither of these types, so reaching this catch means the profile
                    // write committed: report the saved fields too.
                    $reportOwnerWrite();
                    logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
                        "user_settings.php: car owner-field sync failed for user {$userId}: " . $e->getMessage());
                    $errors[] = 'Owner details saved, but car synchronization encountered an error. Please contact support if this persists.';
                } catch (\Throwable $e) {
                    // \Throwable, not Exception: PHP's Error hierarchy (TypeError et al.)
                    // does not extend Exception, and syncOwnerFieldsToCars() builds its
                    // field bundle from untyped $_data properties. The consequence here is
                    // worse than at the admin endpoint: an escaping Error would discard
                    // every queued usError()/usSuccess() message below and skip the PRG
                    // redirect, blanking the page AFTER the profile writes above already
                    // committed — so the owner sees a crash, retries, finds their new
                    // values displayed, and concludes it worked while the cars stay stale.
                    $reportOwnerWrite();
                    logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
                        'user_settings.php: unexpected ' . get_class($e)
                        . " during owner-field sync for user {$userId}: " . $e->getMessage());
                    $errors[] = 'Owner details saved, but car synchronization encountered an error. Please contact support if this persists.';
                }
            }
        }
    }

    if (!empty($errors)) {
        foreach ($errors as $error) {
            usError($error);
        }
    }
    if (!empty($successes)) {
        foreach ($successes as $success) {
            usSuccess($success);
        }
    }

    // PRG redirect on success only; on error, fall through so the form re-renders
    // with current values and session flash messages from usError() are displayed.
    if (empty($errors)) {
        Redirect::to($us_url_root . 'usersc/account.php');
    }
}
// Re-fetch so the re-rendered form (see the fall-through above) shows the
// just-written values, not the stale pre-update ones still in $userdetails.
$user2 = new User();
$userdetails = $user2->data();

$userQ2 = $db->query('SELECT * FROM profiles LEFT JOIN users ON user_id = users.id WHERE user_id = ?', [$userId]);
if ($userQ2->count() > 0) {
    $profiledetails = $userQ2->first();
} else {
    echo 'USER_SETTING(390) something is wrong with the user profile <br>';
}

// Two flags can pause an owner's emails, so either one shows the control:
// - profiles.email_suppressed = 1 (the owner opted out). This blocks every
//   owned car, also a car added after the opt-out that keeps
//   cars.email_suppressed = 0 (see CarRepository::findVerificationEligible()).
//   So the count is every owned car.
// - cars.email_suppressed = 1 on one or more cars, with the profile flag at 0.
//   A Brevo spam or unsubscribe event sets only the flag on that one car
//   (EmailEventApplier::apply()). So the count is those cars only.
// clearSuppressedForOwnerByOwner() clears both kinds: its fan-out reads each
// car's own flag, whatever the profile flag is. The one exception is a car
// whose suppression is a Brevo complaint: only an admin can clear it, so
// $complaintPausedCount tells the owner to contact the registry. PDO
// returns the columns as int|string, so cast before the strict comparisons.
$profileSuppressed = (int) ($profiledetails->email_suppressed ?? 0) === 1;
$emailSuppressed = $profileSuppressed;
$pausedCarCount = null;
$complaintPausedCount = 0;
try {
    $ownedCars = $repo->findVerificationStateByOwner($userId);
    $pausedCarCount = $profileSuppressed
        ? count($ownedCars)
        : count(array_filter($ownedCars, static fn(object $car): bool => (int) $car->email_suppressed === 1));
    $emailSuppressed = $profileSuppressed || $pausedCarCount > 0;
    $complaintPausedCount = count($verifier->findBrevoComplaintCarIds($userId));
} catch (ElanRegistryException $e) {
    // With the profile flag set, the count is copy, not a gate: keep the
    // control and show generic text. With the profile flag at 0, the per-car
    // flags are unknown, so the control stays hidden until the next load.
    logger($userId, LogCategories::LOG_CATEGORY_EMAIL_BOUNCED,
        "user_settings.php: owner car suppression lookup failed for owner {$userId}: " . $e->getMessage());
}

?>
<div id="page-wrapper">
    <div class="container">
        <div class="well">
            <div class="row">
                <div class="col-12 col-md-10">
                    <h1>Update your user settings</h1> <br>

                    <?php if ($emailSuppressed): ?>
                        <section id="resume-emails" class="mb-4">
                            <p>
                                <?php if ($pausedCarCount !== null): ?>
                                    Verification emails are currently paused for <?= htmlspecialchars((string) $pausedCarCount, ENT_QUOTES, 'UTF-8') ?> of your cars.
                                <?php else: ?>
                                    Verification emails are currently paused for your cars.
                                <?php endif; ?>
                            </p>
                            <form name='resumeVerificationEmails' action='user_settings.php' method='post'>
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars(Token::generate(), ENT_QUOTES, 'UTF-8') ?>" />
                                <button class="btn btn-primary" type="submit" name="resume_verification_emails" value="1">Resume verification emails</button>
                            </form>
                            <?= $complaintPausedCount > 0
                                ? '<p class="mt-2 mb-0">' . (int) $complaintPausedCount
                                    . (($complaintPausedCount === 1) ? ' car was' : ' cars were')
                                    . ' paused because our email provider flagged the address. This button does not turn those back on. Please contact the registry for help.</p>'
                                : '' ?>
                        </section>
                    <?php endif; ?>

                    <form name='updateAccount' action='user_settings.php' method='post'>

                        <div class="mb-3">
                            <label for="username">Username</label>
                            <?php if (($settings->change_un == 0) || (($settings->change_un == 2) && ($userdetails->un_changed == 1))) {
                            ?>
                                <div class="input-group">
                                    <input class='form-control' type='text' id='username' name='username' value='<?= htmlspecialchars($userdetails->username ?? '', ENT_QUOTES, 'UTF-8') ?>' readonly />
                                    <span class="input-group-text" data-bs-toggle="tooltip" title="<?php if ($settings->change_un == 0) {
                                                                                                    ?>The Administrator has disabled changing usernames.<?php
                                                                                                                                                    }
                                                                                                                                                    if (($settings->change_un == 2) && ($userdetails->un_changed == 1)) {
                                                                                                                                                        ?>The Administrator set username changes to occur only once and you have done so already.<?php
                                                                                                                                                                                                                                                } ?>">Why can't I change this?</span>
                                </div>
                            <?php
                            } else {
                            ?>
                                <input class='form-control' type='text' id='username' name='username' value='<?= htmlspecialchars($userdetails->username ?? '', ENT_QUOTES, 'UTF-8') ?>'>
                            <?php
                            } ?>
                        </div>

                        <div class="mb-3">
                            <label for="fname">First Name</label>
                            <input class='form-control' type='text' id='fname' name='fname' value='<?= htmlspecialchars($userdetails->fname ?? '', ENT_QUOTES, 'UTF-8') ?>' />
                        </div>

                        <div class="mb-3">
                            <label for="lname">Last Name</label>
                            <input class='form-control' type='text' id='lname' name='lname' value='<?= htmlspecialchars($userdetails->lname ?? '', ENT_QUOTES, 'UTF-8') ?>' />
                        </div>
                        <!-- Extend user_setttings.php with some PROFILE information -->
                        <div class="mb-3">
                            <label>Location</label>
                            <p class="text-muted small">
                                <i class="fas fa-info-circle"></i>
                                Use GPS button on mobile or search for your location. Your location will be synchronized to all your registered cars.
                            </p>
                            <!-- Location Picker Component -->
                            <div id="location-picker-settings" class="location-picker-container"></div>
                        </div>

                        <div class="mb-3">
                            <label for="website">Website</label>
                            <input class='form-control' type='text' id='website' name='website' value='<?= htmlspecialchars($profiledetails->website ?? '', ENT_QUOTES, 'UTF-8') ?>' />
                            <div class="form-text">Shown to other members on the page for every car you own.</div>
                        </div>
                        <!-- END Extend user_setttings.php with some PROFILE information -->

                        <div class="mb-3" id="account-email">
                            <label for="email">Email</label>
                            <input class='form-control' type='text' id='email' name='email' value='<?= htmlspecialchars($userdetails->email ?? '', ENT_QUOTES, 'UTF-8') ?>' />
                            <?php if (!IS_NULL($userdetails->email_new)) {
                            ?><br />
                                <div class="alert alert-danger">
                                    <p><strong>Please note</strong> there is a pending request to update your email to <?= htmlspecialchars($userdetails->email_new ?? '', ENT_QUOTES, 'UTF-8') ?>.</p>
                                    <p>Please use the verification email to complete this request.</p>
                                    <p>If you need a new verification email, please re-enter the email above and submit the request again.</p>
                                </div><?php
                                    } ?>
                        </div>

                        <div class="mb-3">
                            <label for="confemail">Confirm Email</label>
                            <input class='form-control' type='text' id='confemail' name='confemail' />
                        </div>

                        <div class="mb-3">
                            <label for="password">New Password</label>
                            <div class="input-group" data-container="body">
                                <span class="input-group-text password_view_control" id="addon1"><span class="glyphicon glyphicon-eye-open"></span></span>
                                <input class="form-control" type="password" autocomplete="off" name="password" id="password">
                                <span class="input-group-text pwpopover" id="addon2" data-bs-container="body" data-bs-toggle="popover" data-bs-placement="top" data-bs-content="<?= $settings->min_pw ?> char min, <?= $settings->max_pw ?> max.">?</span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="confirm">Confirm Password</label>
                            <div class="input-group" data-container="body">
                                <span class="input-group-text password_view_control" id="addon3"><span class="glyphicon glyphicon-eye-open"></span></span>
                                <input type="password" autocomplete="off" id="confirm" name="confirm" class="form-control">
                                <span class="input-group-text pwpopover" id="addon4" data-bs-container="body" data-bs-toggle="popover" data-bs-placement="top" data-bs-content="Must match the New Password">?</span>
                            </div>
                        </div>

                        <?php if (!is_null($userdetails->pin)) {
                        ?>
                            <div class="mb-3">
                                <label>Reset PIN
                                    <input type="checkbox" id="resetPin" name="resetPin" value="1" /></label>
                            </div>
                        <?php
                        } ?>

                        <div class="mb-3">
                            <label for="old">Old Password<?php if (!is_null($userdetails->password)) {
                                                ?>, required for changing password, email, or resetting PIN<?php
                                                                                                        } ?></label>
                            <div class="input-group" data-container="body">
                                <span class="input-group-text password_view_control" id="addon6"><span class="glyphicon glyphicon-eye-open"></span></span>
                                <input class='form-control' type='password' id="old" name='old' <?php if (is_null($userdetails->password)) {
                                                                                                ?>disabled<?php
                                                                                                        } ?> />
                                <span class="input-group-text pwpopover" id="addon5" data-bs-container="body" data-bs-toggle="popover" data-bs-placement="top" data-bs-content="Required to change your password">?</span>
                            </div>
                        </div>

                        <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                        <p><input class='btn btn-primary' type='submit' value='Update' /></p>
                        <p><a class="btn btn-info" href="<?= htmlspecialchars($us_url_root . 'usersc/account.php', ENT_QUOTES, 'UTF-8') ?>">Cancel</a></p>

                    </form>
                    <?php
                    if (isset($user->data()->oauth_provider) && $user->data()->oauth_provider != null) {
                        echo "<strong>NOTE:</strong> If you originally signed up with your Google/Facebook account, you will need to use the forgot password link to change your password...unless you're really good at guessing.";
                    }
                    ?>
                </div>
            </div>
        </div>
    </div> <!-- /container -->
</div> <!-- /#page-wrapper -->

<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>
<!-- Location Picker Styles -->
<link rel="stylesheet" href="<?=$us_url_root?>app/assets/css/location-picker.min.css?v=<?= ASSET_VERSION ?>">

<!-- Location Picker Script -->
<script src="<?=$us_url_root?>app/assets/js/location-picker.min.js?v=<?= ASSET_VERSION ?>"></script>

<!-- Place any per-page javascript here -->
<script nonce="<?= htmlspecialchars($userspice_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
    $(document).ready(function() {
        // Initialize Location Picker for user settings
        if (document.getElementById('location-picker-settings')) {
            const urlRoot = '<?php echo $us_url_root; ?>';

            const currentLocation = {
                city: '<?= htmlspecialchars($profiledetails->city ?? '', ENT_QUOTES) ?>',
                state: '<?= htmlspecialchars($profiledetails->state ?? '', ENT_QUOTES) ?>',
                country: '<?= htmlspecialchars($profiledetails->country ?? '', ENT_QUOTES) ?>',
                lat: '<?= $profiledetails->lat ?? '' ?>',
                lon: '<?= $profiledetails->lon ?? '' ?>'
            };

            const locationPicker = new LocationPicker({
                containerId: 'location-picker-settings',
                csrfToken: '<?=Token::generate()?>',
                urlRoot: urlRoot,
                showGPS: true,
                required: true
            });

            // Pre-populate with current location if available
            if (currentLocation.city && currentLocation.country) {
                const displayText = [currentLocation.city, currentLocation.state, currentLocation.country]
                    .filter(Boolean).join(', ');
                document.getElementById('location-picker-settings-input').value = displayText;

                if (currentLocation.lat && currentLocation.lon) {
                    document.getElementById('location-picker-settings-city').value = currentLocation.city;
                    document.getElementById('location-picker-settings-state').value = currentLocation.state;
                    document.getElementById('location-picker-settings-country').value = currentLocation.country;
                    document.getElementById('location-picker-settings-lat').value = currentLocation.lat;
                    document.getElementById('location-picker-settings-lon').value = currentLocation.lon;

                    const selectedDiv = document.getElementById('location-picker-settings-selected');
                    const selectedText = document.getElementById('location-picker-settings-selected-text');
                    const coords = document.getElementById('location-picker-settings-coords');

                    selectedText.textContent = displayText;
                    coords.textContent = currentLocation.lat + ', ' + currentLocation.lon;
                    selectedDiv.classList.remove('d-none');
                }
            }
        }

        $('.password_view_control').hover(function() {
            $('#old').attr('type', 'text');
            $('#password').attr('type', 'text');
            $('#confirm').attr('type', 'text');
        }, function() {
            $('#old').attr('type', 'password');
            $('#password').attr('type', 'password');
            $('#confirm').attr('type', 'password');
        });
    });
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function(el) {
        new bootstrap.Popover(el);
    });
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
        new bootstrap.Tooltip(el);
    });
    document.querySelectorAll('.pwpopover').forEach(function(el) {
        el.addEventListener('click', function() {
            document.querySelectorAll('.pwpopover').forEach(function(other) {
                if (other !== el) {
                    const inst = bootstrap.Popover.getInstance(other);
                    if (inst) {
                        inst.hide();
                    }
                }
            });
        });
    });
</script>
