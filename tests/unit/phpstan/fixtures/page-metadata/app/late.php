<?php

$pageDescription = 'Set early.';
require_once __DIR__ . '/../users/init.php';

if (!securePage($php_self)) {
    die();
}

$pageTitle = 'Set too late';
