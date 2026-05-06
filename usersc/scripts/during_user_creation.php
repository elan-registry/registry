<?php
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
    $db->insert('profiles', ['user_id' => $theNewId, 'bio' => "automatically added - " . $now->format('Y-m-d H:i:s')]);
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
}

// Update profile with all location data at once
$db->update('profiles', ["user_id", "=", $theNewId], $locationData);

// Even if you do not want to add additional fields to the the join form, this is a great opportunity to add this user to another database table.
// Get creative!

// The script below will automatically login a user who just registered if email activation is not turned on
$e = $db->query("SELECT email_act FROM email")->first();
if ($e->email_act != 1) {
    $user = new User();
    $login = $user->loginEmail(Input::get('email'), trim(Input::get('password')), 'off');
    if (!$login) {
        Redirect::to('login.php?err=There+was+a+problem+logging+you+in+automatically.');
    }
    //where the user goes just after login is in usersc/scripts/custom_login_script.php
}
