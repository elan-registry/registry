<?php
require_once '../../users/init.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}


$db = DB::getInstance();

$query = $db->query("SELECT * FROM email");
$base_url = $query->first()->verify_url;

$verify_url = $base_url . $us_url_root . "app/verify/verify_car.php";

// Fixed: Replaced deprecated users_carsview with actual cars table
$carQ = $db->query("SELECT * FROM cars WHERE mtime < DATE_SUB(NOW(), INTERVAL 16 YEAR) ORDER BY mtime ASC LIMIT 1");
$carData = $carQ->results();  // Results as an array

// Set verification codes

foreach ($carData as $car) {
    echo "<hr>Send email for car:" . $car->id . "<br>";
    // Update the verification code
    $verificationCode = md5(uniqid(rand(), true));
    $db->query("UPDATE cars SET vericode = ? WHERE id = ?", [$verificationCode, $car->id]);

    // Delete the cars_hist entry for adding the vericode
    $id = $db->query('SELECT car_id, id, MAX(timestamp) AS max FROM cars_hist where car_id = ? GROUP BY id, car_id ORDER BY `max` DESC LIMIT 1', [$car->id])->first()->id;
    $db->deleteById("cars_hist", $id);


    if ($car->image && file_exists($abs_us_root . $us_url_root . $settings->elan_image_dir . $car->image)) {
        $image = '<img src="' . $base_url . $us_url_root . $settings->elan_image_dir . $car->image . '">';
    } else {
        $image = 'No image';
    }
    // Generate CSRF tokens for secure verification links
    $verifyToken = Token::generate();
    $soldToken = Token::generate();
    $editToken = Token::generate();
    
    $verify_btn = $verify_url . '?code=' . urlencode($verificationCode) . "&action=verify&token=" . urlencode($verifyToken);
    $sold_btn   = $verify_url . '?code=' . urlencode($verificationCode) . "&action=sold&token=" . urlencode($soldToken);
    $edit_btn   = $verify_url . '?code=' . urlencode($verificationCode) . "&action=edit&token=" . urlencode($editToken);


    $to = $car->email;
    $subject = "Lotus Elan Registry - Request for Information Verification";

    // Get the email template
    ob_start();
    include('_email_template.php');
    $body = ob_get_contents();
    ob_get_clean();
    email($to, $subject, $body); // PHPMailer
}
