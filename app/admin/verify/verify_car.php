<?php
require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

$query = $db->query("SELECT * FROM email");
$base_url = $query->first()->verify_url;


if (Input::exists('get') && Input::get('code') && Input::get('action')) {
    $code = Input::get('code');
    $action = Input::get('action');
    $token = Input::get('token');
    
    // Validate and sanitize inputs
    $code = htmlspecialchars(strip_tags($code), ENT_QUOTES, 'UTF-8');
    $action = htmlspecialchars(strip_tags($action), ENT_QUOTES, 'UTF-8');
    
    // Validate action parameter - only allow specific actions
    $validActions = ['verify', 'edit', 'sold'];
    if (!in_array($action, $validActions)) {
        echo "<h2>Invalid action specified</h2><br>";
        header('refresh:5;url=' . $base_url . $us_url_root);
        exit;
    }
    
    // CSRF Protection: Validate token for state-changing operations
    if (!$token || !Token::check($token)) {
        echo "<h2>Security token validation failed</h2><br>";
        logger(0, "Security", "CSRF token validation failed for car verification: " . $_SERVER['REQUEST_URI']);
        header('refresh:5;url=' . $base_url . $us_url_root);
        exit;
    }

    // Validate verification code exists and is unique
    $carQ = $db->query('SELECT * FROM cars WHERE vericode = ?', [$code]);
    if ($db->count() != 1) {
        echo "<h2>Verification code not found or invalid</h2><br>";
        logger(0, "Security", "Invalid verification code attempted: " . $code . " from IP: " . $_SERVER['REMOTE_ADDR']);
        header('refresh:5;url=' . $base_url . $us_url_root);
        exit;
    }
    $car = $carQ->first();
    
    // Additional security: Check if verification code is not empty/null
    if (empty($car->vericode) || $car->vericode !== $code) {
        echo "<h2>Verification failed - security check</h2><br>";
        logger($car->user_id, "Security", "Verification code security check failed for car ID: " . $car->id);
        header('refresh:5;url=' . $base_url . $us_url_root);
        exit;
    }

    switch ($action) {
        case 'verify':
            $message =  "<h2>Thank you for verifying your car</h2><p>Taking you to the details...</p>";

            // Update last_verified time
            $db->update("cars", $car->id, ["last_verified" =>  date('Y-m-d G:i:s')]);
            // Update the History record to show verified
            // Find the history record
            $hist_id = $db->query('SELECT car_id, id, MAX(timestamp) AS max FROM cars_hist where car_id = ? GROUP BY id, car_id ORDER BY `max` DESC LIMIT 1', [$car->id])->first()->id;
            $db->update("cars_hist", $hist_id, ["operation" => "VERIFIED"]);

            // Log successful verification
            logger($car->user_id, "Car Verification", "Car verified successfully - ID: " . $car->id . " Chassis: " . $car->chassis);

            // Redirect to the car detail page
            $redirect = $base_url . $us_url_root . 'app/cars/details.php?car_id=' . $car->id;
            break;

        case 'edit':
            $message = "<h3>Thank you for updating your car.  Taking you to the Login Screen where you can edit yor information...</h3>";
            
            // Log edit request
            logger($car->user_id, "Car Verification", "Car edit request via verification - ID: " . $car->id . " Chassis: " . $car->chassis);
            
            $redirect = $base_url . $us_url_root . 'usersc/account.php?';
            break;

        case 'sold':
            $message =  "<h2>Thank you for letting me know you sold the car.  I'll update the records.</h2><p>Taking you to the details...</p>";

            $db->update("cars", $car->id, ["last_verified" =>  date('Y-m-d G:i:s')]);
            // Update the History record to show verified
            // Find the history record
            $hist_id = $db->query('SELECT car_id, id, MAX(timestamp) AS max FROM cars_hist where car_id = ? GROUP BY id, car_id ORDER BY `max` DESC LIMIT 1', [$car->id])->first()->id;
            $db->update("cars_hist", $hist_id, ["operation" => "VERIFIED SOLD", "comments" => "Owner reported car sold"]);
            
            // Log sold notification
            logger($car->user_id, "Car Verification", "Car reported as sold via verification - ID: " . $car->id . " Chassis: " . $car->chassis);
            
            $redirect = $base_url . $us_url_root . 'app/cars/details.php?car_id=' . $car->id;
            break;

        default:
            break;
    }
}
?>


<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">
            <div class="row">
                <div class="col-12">
                    <div class="card card-default">
                        <div class="card-header">
                            <h2><strong>Car Verification</strong></h2>
                        </div>
                        <div class="card-body">
                            <?php
                            echo $message;
                            header('refresh:5;url=' . $redirect);
                            ?>
                        </div> <!-- card-body -->
                    </div> <!-- car -->
                </div> <!-- row -->
            </div><!-- row -->
        </div> <!-- well -->
    </div>
    <!--container -->
</div> <!-- page -->
<!-- End of main content section -->

<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; //custom template footer
