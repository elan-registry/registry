<?php

declare(strict_types=1);

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
    // Get the cars data with proper validation
    $carData = new Car();
    $carData->findAll();

    if (!$carData->data() || !is_array($carData->data())) {
        throw new RuntimeException('No car data available for map markers');
    }

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

        // Handle null or empty image strings safely - try JSON decode first, fallback to comma-separated
        $carImages = [];
        if (!empty($car->image)) {
            $decoded = json_decode($car->image);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $carImages = $decoded;
            } else {
                // Fallback to comma-separated for legacy data
                $carImages = explode(',', $car->image);
            }
        }
        // Add to XML document node
        $node = $doc->createElement("marker");
        $newnode = $parnode->appendChild($node);

        // Set marker attributes with null safety and proper type casting
        $newnode->setAttribute("id", (string)($car->id ?? ""));
        $newnode->setAttribute("name", ($car->year ?? "") . "-" . ($car->series ?? "") . "-" . ($car->chassis ?? ""));
        $newnode->setAttribute("series", (string)($car->series ?? ""));
        $newnode->setAttribute("year", (string)($car->year ?? ""));
        $newnode->setAttribute("variant", (string)($car->variant ?? ""));
        $count = count($carImages);
        if ($count != 0) {
            // Include car ID in image path for proper subdirectory access
            $newnode->setAttribute("image", $car->id . '/' . $carImages[0]);
        } else {
            $newnode->setAttribute("image", "");
        }
        $newnode->setAttribute("url", (string)($car->website ?? ""));
        $newnode->setAttribute("type", (string)($car->type ?? ""));
        $newnode->setAttribute("city", (string)($car->city ?? ""));
        $newnode->setAttribute("state", (string)($car->state ?? ""));
        $newnode->setAttribute("country", (string)($car->country ?? ""));
        $newnode->setAttribute("owner", trim((string)($car->fname ?? "")));
        $newnode->setAttribute("lat", (string)random((float)($car->lat ?? 0)));
        $newnode->setAttribute("lng", (string)random((float)($car->lon ?? 0)));
    }

    echo $doc->saveXML();

} catch (DatabaseException $e) {
    // Log database-specific errors
    logger($user->data()->id ?? 0, 'DatabaseError', 'Failed to fetch car data for map markers: ' . $e->getMessage());
    echo '<?xml version="1.0" encoding="utf-8"?><markers></markers>';
} catch (RuntimeException $e) {
    // Log runtime errors
    logger($user->data()->id ?? 0, 'SystemError', 'Map markers data error: ' . $e->getMessage());
    echo '<?xml version="1.0" encoding="utf-8"?><markers></markers>';
} catch (Exception $e) {
    // Log unexpected errors with UserSpice logger integration
    logger($user->data()->id ?? 0, 'SystemError', 'Map markers XML generation failed: ' . $e->getMessage());
    echo '<?xml version="1.0" encoding="utf-8"?><markers></markers>';
}

/**
 * Add small random offset to coordinates to prevent pin stacking
 *
 * @param float $num Original coordinate value
 * @return float Coordinate with random offset applied
 */
function random(float $num): float
{
    return $num + (rand(-1000, 1000) / 10000);
}