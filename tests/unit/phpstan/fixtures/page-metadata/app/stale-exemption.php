<?php

$pageTitle = 'Now Has A Title';
$pageDescription = 'This page is still on the exemption list.';
require_once '../users/init.php';

if (!securePage($php_self)) {
    die();
}
