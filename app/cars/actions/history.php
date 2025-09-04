<?php

// Get the car history
require_once '../../../users/init.php';

//Forms posted now process it
if (!empty($_POST)) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
    } else {
        $draw = Input::get('draw');
        $carID = Input::get('car_id');

        if (empty($carID)) {
            echo json_encode(array(
                'draw' => $draw, 
                'recordsTotal' => 0, 
                'recordsFiltered' => 0, 
                'history' => [],
                'error' => 'Car ID not provided'
            ));
            return;
        }

        $car = new Car($carID);
        $carHist = $car->history();
        $count   = count($carHist);
        $error   = ""; // Place holder for error messages.  If there is text in here it issues a pop-up.  Do not include if there is no error.

        echo json_encode(array('draw' => $draw, 'recordsTotal' => $count, 'recordsFiltered' => $count, 'history' => $carHist));
    }
}
