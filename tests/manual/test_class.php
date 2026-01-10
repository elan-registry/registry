<?php

require_once 'users/init.php';


// Dump existing cars.  ID 2 does not exist
// $cars = array(1, 2, 1412);
$cars = array(1, 1481);

foreach ($cars as $id) {
    echo "<h2>Dump car id " . $id . "</h2><br>";
    $car = new Car($id);

    if ($car->exists()) {
        dump($car->data());
    } else {
        echo "   Does not exist <br>";
    }
}
exit;

// Create a car

$fields = array(
    'year' => '1966',
    'images' => [
        'image1',
        'image2',
        'img_601c1c88b5aa67.07757198.jpg',
        'img_5ff391578d9be6.04210270.jpg'
    ],
);


$newCar = new Car();

$newCar->create($fields);

echo "Created car id " . $newCar->data()->id . "</br>";
dump($newCar->data());

// $fields = array(
//     'year' => '1967',
//     'email' => 'jim@unibrain.org',
//     'images' => array('image1', 'image2', 'image3'),
// );
// $newCar->update($fields, $newCar->data()->id);

// dump($newCar->data());
