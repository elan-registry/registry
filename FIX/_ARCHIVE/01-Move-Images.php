<?php

/**
 * Move User Images Script
 *
 * Administrative script to move user images to a new directory and update database settings.
 * Displays progress and uses error reporting for debugging.
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../users/init.php';

// Get the users data
$db = DB::getInstance();

$line = 1; // Where messages go

$imageDir = $abs_us_root . $us_url_root . 'userimages';

?>


<h2>Update location of userimages<br></h2>
<?php
echo date("h:i:sa");
?>
<p>
    <u>Progress</u>
</p>



<?php

# Move images directory
moveDirectory();

# Update user settings in DB
updateimageDir();

# Rename/Move images to <carid>/img_xxx
moveImages();

# Save/Move all other inages to <orphan> directory
orphanImages();

echo "<br><br>Done<br>";

// Return to FIX menu button
echo '<div style="margin-top: 20px; text-align: center;">';
echo '<button onclick="window.opener.location.reload(); window.close();" class="btn btn-outline-primary">';
echo '<i class="fa fa-arrow-left" aria-hidden="true"></i> Return to FIX Menu';
echo '</button>';
echo '</div>';

// Record script completion
try {
    $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", [basename(__FILE__)]);
} catch (Exception $e) {
    // Create table if it doesn't exist
    try {
        $db->query("CREATE TABLE IF NOT EXISTS fix_script_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            script_name VARCHAR(255) NOT NULL,
            completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_script_name (script_name)
        )");
        $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", [basename(__FILE__)]);
    } catch (Exception $create_e) {
        // Silently fail if we can't track completion
    }
}

function moveDirectory()
{
    global $abs_us_root, $us_url_root;
    global $line;

    echo "<b>Moving userimages directory</b></br>";


    $cmd = 'mv ' . $abs_us_root . $us_url_root . 'app/userimages ' . $abs_us_root . $us_url_root . 'userimages';

    system($cmd, $retval);

    myFlush();
}


function updateImageDir()
{
    global $db;
    global $line;

    echo "</br></br><b>Updating elan_user_images setting</b></br>";


    $q = "UPDATE `settings` SET `elan_image_dir`='userimages/'";
    $db->query($q)->results();

    myFlush();
}

function moveImages()
{
    global $abs_us_root, $us_url_root;
    global $db;
    global $line;
    global $imageDir;

    echo "</br></br><b>Moving Images</b></br>";


    $q = "SELECT * FROM cars ORDER BY id ";

    $carData = $db->query($q)->results();

    $count = count($carData);
    $i = 1;

    foreach ($carData as $key => $car) {
        outputMessage($line++, $i . ' of ' . $count . " - carID " . $car->id);

        // if (($car->image !== '') || (!is_null($car->image))) {
        if (!empty($car->image)) {
            $cardir = $imageDir . '/' . $car->id;
            mkdir($cardir, 0755);

            // Get the car images
            // Turn images into array
            // Images can be encoded as JSON or simple CSV
            $carImages = json_decode($car->image);

            if (is_null($carImages)) {
                $carImages = explode(',', $car->image);
            }

            foreach ($carImages as $key => $carimage) {
                $file = pathinfo($carImages[$key]);  // This is the OLD path

                foreach (glob($imageDir . '/' . $file['filename'] . '*' . $file['extension']) as $name) {
                    rename($name, $cardir . '/' . basename($name));
                    outputMessage($line++, " - CarID: " . $car->id . " - moving " . basename($name));
                }
            }
        }
    }
}

function orphanImages()
{
    global $db;
    global $line;
    global $imageDir;

    echo "</br></br><b>Saving Orphan Images</b></br>";


    $orphandir = $imageDir . '/' . 'orphan';

    mkdir($orphandir, 0755);

    foreach (glob($imageDir . '/' . '*' . '.*') as $name) {
        rename($name, $orphandir . '/' . basename($name));
    }
}


/* *****************
    U T I L I T I E S
 ****************** */


function outputMessage($current, $message)
{
    $pad = str_pad($message, 100, '.', STR_PAD_RIGHT);
    echo "<span style='position: absolute;z-index:$current;background:#FFF;'>"
        . " " . date('h:i:sa') . " - " . $pad . "<br></span>";
    myFlush();
}

/**
 * Flush output buffer
 */
function myFlush()
{
    echo str_repeat(' ', 256);
    if (@ob_get_contents()) {
        @ob_end_flush();
    }
    flush();
}
