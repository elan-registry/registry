<?php

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

$carThatExists = 1;
$carThatDoesnotExist = 2;
$testCars = array($carThatDoesnotExist, $carThatExists);


$response = "Messages";
$tests = [
    'testFind'    => 'Find a car',
    'testExists'  => 'Does a car exist',
    'testData'    => 'Get data for a car as key value pair',
    'testHistory' => 'Get history of car as array.  Newest record first',
    'testFactory' => 'Get Factory information',
    'testImages'  => 'Get array of images and data on the images',
    'testCreate'  => 'Create a car',
    'testUpdate'  => 'Update a car',
    'testfindAll' => 'Find all cars',
    'testOwner'   => 'Get information the owner of a car'
];
//Forms posted now process it
if (!empty($_POST)) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        include($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
    } else {
        $command = Input::get('command');

        switch ($command) {
            case 'testFind':
                $response = testFind();
                break;

            case 'testHistory':
                $response =  testHistory();
                break;

            case 'testFactory':
                $response =  testFactory();
                break;

            case 'testExists':
                $response = testExists();
                break;

            case 'testData':
                $response = testData();
                break;

            case 'testImages':
                $response = testImages();
                break;

            case 'testCreate':
                $response = testCreate();
                break;

            case 'testUpdate':
                $response = testUpdate();
                break;

            case 'testfindAll':
                $response =  testfindAll();
                break;

            case 'testOwner':
                $response = testOwner();
                break;

            default:
                $response =  "No command<br>";
        }
    }
}


function testFind()
{
    // Dump existing cars.  ID 2 does not exist
    global $testCars;

    $response = '<strong>Test</strong>
    <p>Find ' . count($testCars) . ' cars.</p>
    <strong>Expected Results:</strong>
    <p>Car #2 does not exist</p><hr>';

    foreach ($testCars as $id) {
        $response .= "<u>Dump car id " . $id . "</u><br>";
        $car = new Car($id);

        if ($car->exists()) {
            $response .= " Found<br>";
            $response .= mydump($car->data());
            $response .= "<br>";
        } else {
            $response .= "   Does not exist - Expected for carID 2 <br><br>";
        }
    }

    return $response;
}

function testCreate()
{
    $response = '<strong>Test</strong>
    <p>Create a new car.</p>
    <strong>Expected Results:</strong>
    <p>Car is succesfully create and dumped.  All fields should be populated</p><hr>';


    $fields = array(
        // Car Information
        // 'username'   => 'value', // No longer used
        // 'ctime'         => 'value',  //  Set by CLASS
        // 'mtime'      => 'value',  // Set by DB
        'vericode'      => 'Vericode',  // varchar(32)
        'last_verified' => date('Y-m-d G:i:s'),  // timestamp
        // 'ModifiedBy' => 'value',  // No longer used
        'model'         => 'model',  // varchar(30)	
        'series'        => 'series', // varchar(12)	
        'variant'       => 'series', // varchar(15)	
        'year'          => '1966', // varchar(4)
        'type'          => 'typ', // char(3)
        'chassis'       => 'chassis',  // varchar(15)
        'color'         => 'color',  // varchar(25)
        'engine'        => 'engine', // varchar(15)
        'purchasedate'  => '1980-01-01', //date
        'solddate'      => '1980-01-02', // date
        'comments'      => 'comments', //text
        'website'       => 'website',  // varchar(100) - Currently in user area 
        'image'         => 'image1,image2',  // text - Needs to be renamed in DB to images
        // Owner Information 
        'user_id'       => 'user_id', // int(11)
        'email'         => 'email', // varchar(155)
        'fname'         => 'fname', // varchar(155)
        'lname'         => 'lname', // varchar(155)
        'join_date'     => '1980-01-01 00:00:00', // datetime
        'city'          => 'city', // varchar(100)	
        'state'         => 'state',  // varchar(100)	
        'country'       => 'country',  // varchar(100)	
        'lat'           => '1',  // float
        'lon'           => '2'  // float
    );

    $car = new Car();

    $car->create($fields);

    $response .= "Created car id " . $car->data()->id . "</br>";
    $response .= mydump($car->data());

    return $response;
}
function testUpdate()
{
    $response = '<strong>testUpdate</strong>
    <p>Update car 1488</p>
    <strong>Expected Results:</strong>
    <p>Comment will be updated with current date/time</p><hr>';

    $carId = 1488;
    $fields = array(
        'comments' => date('Y-m-d G:i:s')
    );

    $car = new Car();

    $car->update($fields, $carId);

    $response .= mydump($car->data());

    return $response;
}
function testfindAll()
{
    $response = '<strong>testList</strong><hr>';

    $car = new Car();

    $car->findAll();

    $count = count($car->data());
    $response .= 'Returned ' . $count . ' records<br><br>';

    $response .= mydump($car->data());

    return $response;
}

function testExists()
{
    global $testCars;
    global $carThatExists;
    global $carThatDoesnotExist;

    $response = '<p><strong>testExists</strong></p>
    <strong>Expected Results:</strong>
    <p>Car ' . $carThatExists . ': TRUE</p>
    <p>Car ' . $carThatDoesnotExist . ': FALSE</p><hr>';

    foreach ($testCars as $id) {
        $car = new Car($id);

        $response .= $car->exists() ? "Car $id TRUE<br>" : "Car $id FALSE<br>";
    }
    return $response;
}
function testData()
{
    global $carThatExists;

    $response = '<strong>testData</strong><hr>';

    $car = new Car($carThatExists);

    $response .= mydump($car->data());

    return $response;
}
function testFactory()
{
    global $carThatExists;

    $response = '<strong>testFactory</strong><hr>';

    $car = new Car($carThatExists);

    $response .= mydump($car->factory());

    return $response;
}
function testHistory()
{
    global $carThatExists;

    $response = '<strong>testHistory</strong><hr>';

    $car = new Car($carThatExists);

    $response .= mydump($car->history());

    return $response;
}
function testImages()
{
    global $carThatExists;

    $response = '<strong>testImages</strong><hr>';

    $car = new Car($carThatExists);

    $response .= mydump($car->images());

    return $response;
}
function testOwner()
{
    global $carThatExists;

    $response = '<strong>testOwner</strong><hr>';

    $car = new Car($carThatExists);

    $response .= mydump($car->owner());

    return $response;
}
//preformatted var_dump function

function mydump($var)
{
    ob_start();
    dump($var);

    $out = ob_get_contents();
    ob_end_clean();

    return $out;
}


?>
<style>
    hr {
        height: 2px;
        border-width: 0;
        color: gray;
        background-color: gray;
    }
</style>
<div id='page-wrapper'>
    <!-- Page Content -->
    <div class='container'>
        <!-- Heading Row -->
        <div class='row'>
            <div class='col-lg-3'>
                <div class='card card-default'>
                    <div class='card-header'>
                        <h1>Pick a test</h1>
                    </div>
                    <div class='card-body'>
                        <form action='test_class.php' method='post'>

                            <?php
                            foreach ($tests as $key => $test) { ?>
                                <fieldset class="form-group">
                                    <div class="form-check">
                                        <input type="radio" class="form-check-input" name="command" id="<?= $key ?>" value="<?= $key ?>"">
                                        <label class=" form-check-label"> <?= $test ?> </label>
                                    </div>
                                </fieldset>

                            <?php
                            }
                            ?>
                            <!-- End Image panel -->
                            <input type='hidden' name='csrf' id='csrf' value='<?= Token::generate(); ?>' />
                            <input type='submit' name='submit' id='submit' class=' btn btn-success' value='Run Test' />

                        </form>
                    </div> <!-- card-body -->
                </div> <!-- card -->
            </div>
            <div class='col-lg-9'>
                <div class='card card-default'>
                    <div class='card-header'>
                        <h1>Results</h1>
                    </div>
                    <div class='card-body'>
                        <?= $response ?>
                    </div> <!-- card-body -->
                </div> <!-- card -->
            </div>
        </div>
    </div>
</div>