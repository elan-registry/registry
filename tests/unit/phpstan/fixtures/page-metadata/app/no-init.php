<?php

$pageTitle = 'No Init';
$pageDescription = 'This page never requires init.php.';

if (!securePage($php_self)) {
    die();
}
