<?php

$encoded = serialize(['a' => 1]);
$decoded = \unserialize('a:0:{}');
$form->serialize();
$json = json_encode(['a' => 1]);
