
<?php

/**
 * Generates an XML document of car markers for Google Maps integration.
 *
 * Source: https://developers.google.com/maps/documentation/javascript/mysql-to-maps#domfunctions
 *
 * Requires authentication and pulls car data from the database.
 * Outputs XML with car attributes for use in map marker rendering.
 */

require_once '../../users/init.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Set error handling and content type
ini_set('display_errors', 0);
error_reporting(0);

// Clean any output that might interfere with XML
ob_clean();
header("Content-type: text/xml; charset=utf-8");

try {
    // Get the cars data
    $carData = new Car();
    $carData->findAll();

    // Start XML file, create parent node
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = false;  // Don't format to avoid whitespace issues
    $doc->encoding = 'UTF-8';
    $parnode = $doc->appendChild($doc->createElement('markers'));

    // Iterate through the rows, adding XML nodes for each car
    foreach ($carData->data() as $car) {
        // Skip cars without valid coordinates
        if (empty($car->lat) || empty($car->lon) || $car->lat == 0 || $car->lon == 0) {
            continue;
        }
        
        // Handle null or empty image strings safely
        $carImages = !empty($car->image) ? explode(',', $car->image) : [];
        // Add to XML document node
        $node = $doc->createElement("marker");
        $newnode = $parnode->appendChild($node);

        // Set marker attributes with null safety
        $newnode->setAttribute("id", $car->id ?? "");
        $newnode->setAttribute("series", $car->series ?? "");
        $newnode->setAttribute("year", $car->year ?? "");
        $newnode->setAttribute("variant", $car->variant ?? "");
        $count = count($carImages);
        if ($count != 0) {
            // Include car ID in image path for proper subdirectory access
            $newnode->setAttribute("image", $car->id . '/' . $carImages[0]);
        } else {
            $newnode->setAttribute("image", "");
        }
        $newnode->setAttribute("url", $car->website ?? "");
        $newnode->setAttribute("type", $car->type ?? "");
        $newnode->setAttribute("lat", random($car->lat ?? 0));
        $newnode->setAttribute("lng", random($car->lon ?? 0));
    }

    echo $doc->saveXML();

} catch (Exception $e) {
    // Output minimal valid XML on error
    echo '<?xml version="1.0" encoding="utf-8"?><markers></markers>';
}

// Randomize the lat/lon info so pins don't stack on top of each other
function random($num)
{
    return $num + (rand(-1000, 1000) / 10000);
}
