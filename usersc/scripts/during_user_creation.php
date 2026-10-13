<?php
use ElanRegistry\LogCategories;
use ElanRegistry\Migration\DeliverabilityMigrationQueries;

/** @var int $theNewId The script that includes this file sets it. */

// This script is really useful for doing additional things when a user is created.

// You have access to two things that will really be helpful.
//
// You have the new user id for your new user. Comment out below to see it.

//You also have access to everything that was submitted in the form.

//If you added additional fields to the join form, you can process them here.
//For example, in additional_join_form_fields.php we have a sample form field called account_id.
// You may wish to do additional validation, but we'll keep it simple. Uncomment out the code below to test it.

// Create a profiles entry if it does not exist (it shouldn't)

$check = $db->query("SELECT id FROM profiles WHERE user_id = ?", [$theNewId])->count();
if ($check < 1) {
    $now = new DateTime();
    // Read from the users row, not from the caller's scope: several UserSpice
    // paths include this script, and not all of them define $join_date.
    // join.php sends the sign-up mail before this script runs, so the
    // address-since time must be join_date, not now, or a bounce on that
    // mail does not match (#2101). The matching step that reads
    // email_address_since comes in #2359.
    $newUser = $db->query("SELECT email, join_date FROM users WHERE id = ?", [$theNewId])->first();
    if (!$newUser) {
        // A failed SELECT also returns no row, so name the error when there is one.
        $readError = $db->error() ? ": {$db->errorString()}" : '';
        logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "users row not found during user creation for user_id={$theNewId}; no profile row created{$readError}");
    } else {
        $insertResult = $db->insert('profiles', [
            'user_id' => $theNewId,
            'bio' => "automatically added - " . $now->format('Y-m-d H:i:s'),
            'email_address_since' => $newUser->join_date,
            'email_address_seen' => DeliverabilityMigrationQueries::normalizeAddress($newUser->email),
        ]);
        if (!$insertResult) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "Failed to create profile during user creation for user_id={$theNewId}: {$db->errorString()}");
        }
    }
}


// The format of the array is ['column_name'=>Data_for_column]
$city = Input::get('city');
$state = Input::get('state');
$country = Input::get('country');
$lat = Input::get('lat');
$lon = Input::get('lon');

// Build location update array
$locationData = [
    'city' => $city,
    'state' => $state,
    'country' => $country
];

// Add coordinates if provided by location picker
if (!empty($lat) && !empty($lon)) {
    $locationData['lat'] = (float)$lat;
    $locationData['lon'] = (float)$lon;
} else {
    logger(0, LogCategories::LOG_CATEGORY_USER, "New user {$theNewId}: no coordinates provided during registration; location picker may not have been used");
}

// Update profile with all location data at once
$updateResult = $db->update('profiles', ["user_id", "=", $theNewId], $locationData);
if (!$updateResult) {
    logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "Failed to set location during user creation for user_id={$theNewId}");
}

// Even if you do not want to add additional fields to the the join form, this is a great opportunity to add this user to another database table.
// Get creative!

// The script below will automatically login a user who just registered if email activation is not turned on
$e = $db->query("SELECT email_act FROM email")->first();
if ($e->email_act != 1) {
    $user = new User();
    // $remember must be false, not 'off': loginEmail() tests it for truth, and
    // any non-empty string sets a remember-me token the user did not ask for (#2378).
    $login = $user->loginEmail(Input::get('email'), trim(Input::get('password')), false);
    if (!$login) {
        Redirect::to('login.php?err=There+was+a+problem+logging+you+in+automatically.');
    }
    //where the user goes just after login is in usersc/scripts/custom_login_script.php
}
