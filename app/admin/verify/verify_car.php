<?php
require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

if (!securePage($php_self)) {
    die();
}

$query = $db->query("SELECT * FROM email");
$base_url = $query->first()->verify_url;

$message = '';
$redirect = $base_url . $us_url_root;

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
        logger(0, LogCategories::LOG_CATEGORY_SECURITY, "CSRF token validation failed for car verification: " . $request_uri);
        header('refresh:5;url=' . $base_url . $us_url_root);
        exit;
    }

    // Validate verification code exists and is unique
    $carQ = $db->query('SELECT * FROM cars WHERE vericode = ?', [$code]);
    if ($db->count() != 1) {
        echo "<h2>Verification code not found or invalid</h2><br>";
        logger(0, LogCategories::LOG_CATEGORY_SECURITY, "Invalid verification code attempted: " . $code . " from IP: " . $remote_addr);
        header('refresh:5;url=' . $base_url . $us_url_root);
        exit;
    }
    $car = $carQ->first();
    
    // Additional security: Check if verification code is not empty/null
    if (empty($car->vericode) || $car->vericode !== $code) {
        echo "<h2>Verification failed - security check</h2><br>";
        logger($car->user_id, LogCategories::LOG_CATEGORY_SECURITY, "Verification code security check failed for car ID: " . $car->id);
        header('refresh:5;url=' . $base_url . $us_url_root);
        exit;
    }

    // Shared helper: call a Car state-change method, then relabel the most-recent
    // audit history row so the verification report can distinguish it from ordinary
    // owner edits (the cars_update trigger records every cars table write as 'UPDATE').
    // Exits immediately on failure — this page renders output directly, so throwing
    // or returning would leave a broken partial page.
    $applyCarStateChange = function (
        string $method,
        array $histUpdate,
        string $logMessage
    ) use ($car, $db, $base_url, $us_url_root): void {
        try {
            $carObj = new Car((int) $car->id);
            $carObj->$method();
        } catch (\ElanRegistry\Exceptions\CarException $e) {
            logger($car->user_id, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, "Verification action '{$method}' failed for car ID {$car->id}: " . $e->getMessage());
            echo "<h2>An error occurred processing your request. Please contact the registry.</h2>";
            header('refresh:5;url=' . $base_url . $us_url_root);
            exit;
        } catch (\Throwable $e) {
            logger($car->user_id, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, "Unexpected error during verification action '{$method}' for car ID {$car->id}: " . $e->getMessage());
            echo "<h2>An error occurred processing your request. Please contact the registry.</h2>";
            header('refresh:5;url=' . $base_url . $us_url_root);
            exit;
        }

        $histResult = $db->query(
            'SELECT id FROM cars_hist WHERE car_id = ? ORDER BY timestamp DESC, id DESC LIMIT 1',
            [(int) $car->id]
        )->first();
        if (empty($histResult) || empty($histResult->id)) {
            logger($car->user_id, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, "No cars_hist row found for car ID {$car->id} after '{$method}' — audit label not applied");
            return;
        }

        if (!$db->update('cars_hist', $histResult->id, $histUpdate)) {
            logger($car->user_id, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, "Failed to apply audit label to cars_hist row {$histResult->id} for car ID {$car->id} after '{$method}'");
        }

        logger($car->user_id, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $logMessage);
    };

    switch ($action) {
        case 'verify':
            $message = "<h2>Thank you for verifying your car</h2><p>Taking you to the details...</p>";

            $applyCarStateChange(
                'markVerified',
                ['operation' => 'VERIFIED'],
                "Car verified successfully - ID: {$car->id} Chassis: {$car->chassis}"
            );

            $redirect = $base_url . $us_url_root . 'app/cars/details.php?car_id=' . (int) $car->id;
            break;

        case 'edit':
            $message = "<h3>Thank you for updating your car.  Taking you to the Login Screen where you can edit yor information...</h3>";

            logger($car->user_id, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, "Car edit request via verification - ID: {$car->id} Chassis: {$car->chassis}");

            $redirect = $base_url . $us_url_root . 'usersc/account.php?';
            break;

        case 'sold':
            $message = "<h2>Thank you for letting me know you sold the car.  I'll update the records.</h2><p>Taking you to the details...</p>";

            $applyCarStateChange(
                'markSold',
                ['operation' => 'VERIFIED SOLD', 'comments' => 'Owner reported car sold'],
                "Car reported as sold via verification - ID: {$car->id} Chassis: {$car->chassis}"
            );

            $redirect = $base_url . $us_url_root . 'app/cars/details.php?car_id=' . (int) $car->id;
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
