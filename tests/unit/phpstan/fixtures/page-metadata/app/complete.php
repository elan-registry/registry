<?php

$pageTitle = 'Complete Page';
$pageDescription = 'A page that follows the convention.';
require_once '../users/init.php';

if (!securePage($php_self)) {
    die();
}
