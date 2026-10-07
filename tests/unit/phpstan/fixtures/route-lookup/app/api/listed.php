<?php

$user = $db->query('SELECT id, email FROM users WHERE id = ?', [$id]);
$car = new Car($carId);
$count = $db->query('SELECT COUNT(*) FROM cars_hist');
$sql = <<<SQL
    SELECT chassis
    FROM `cars`
    WHERE id = {$carId}
    SQL;
