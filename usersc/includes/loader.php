<?php
/*
This file is included at the very end of init.php
You can use it to include other files or run functions even
if you are not using the UserSpice template system

************************************************
VERY IMPORTANT
************************************************
Because it is used in parser files and api calls,
DO NOT EVER use it to do anything that will echo text to the screen
or it will break important functionality.
*/

// Load global application configuration
require_once $abs_us_root . $us_url_root . 'usersc/includes/config.php';

// Load server globals for environment detection and URL construction
require_once $abs_us_root . $us_url_root . 'usersc/includes/server_globals.php';
