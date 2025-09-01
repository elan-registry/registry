<?php

/**
 * editCar.php - Car management endpoint
 * 
 * Handles AJAX requests for car creation, updates, and image management.
 * Provides secure file upload with validation and CSRF protection.
 * 
 * @author Elan Registry Team
 * @copyright 2025
 */

// Check to see if the chassis number is taken
require_once '../../../users/init.php';

$settings = getSettings();  // Get global settings from plugin

// A place to put some messages
$errors     = [];
$successes  = [];
$cardetails = [];


$targetFilePath = $abs_us_root . $us_url_root . $settings->elan_image_dir;
$targetURL = $us_url_root . $settings->elan_image_dir;

//Forms posted now process it
if (!empty($_POST)) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        include_once($abs_us_root . $us_url_root . 'usersc/scripts/token_error.php');
    } else {
        $db = DB::getInstance();

        $action = Input::get('action');
        switch ($action) {
            case "addCar":
                buildCarDetails($cardetails);
                buildImageDetails($cardetails);
                if (empty($errors)) {
                    uploadImages($cardetails);
                    addCar($cardetails);
                    mvTmpImages($cardetails);
                } else {
                    $errors[] = 'Add_Car: Cannot add record';
                }
                break;
            case "updateCar":
                buildCarDetails($cardetails, Input::get('carid'));
                buildImageDetails($cardetails);
                if (empty($errors)) {
                    uploadImages($cardetails); // On update I know the car number
                    updateCar($cardetails);
                } else {
                    $errors[] = 'Update_Car: Cannot add record';
                }
                break;
            case "fetchImages":
                $carid = Input::get('carID');
                fetchImages($carid);
                break;
            case "removeImages":
                $carid = Input::get('carID');
                $file = Input::get('file');
                removeImage($carid, $file);
                break;
            default:
                $errors[] = "No valid action";
        }

        $response = array(
            'status'     => (!empty($errors)) ? 'error' : 'success',
            'action'     => $action,
            'info'       => array_merge($successes, $errors),
            'cardetails' => $cardetails
        );
        logger(
            $user->data()->id,
            "ElanRegistry: ",
            "Action: " . $response['action'] .
                " Status: " . $response['status'] . "  carID: " . $cardetails['id'] . " Messages: " .
                json_encode($response['info']) . " Data: " . json_encode($response['cardetails'])
        );

        // Blanks instead of NULL for display
        foreach ($response['cardetails'] as $key => $value) {
            if (is_null($value)) {
                $response['cardetails'][$key] = "";
            }
        }
        echo json_encode($response);
    } // End Post with data
}


/**
 * Update an existing car record
 * 
 * @param array $cardetails Car data to update
 * @return void Updates global $errors and $successes arrays
 */
function updateCar(array &$cardetails): void
{
    global $errors;
    global $successes;

    $car = new Car();

    // Update
    if ($car->update($cardetails)) {
        $successes[] = 'Update Car ID: ' . $car->data()->id;
        $successes[] = 'Update BY ID: ' . $car->data()->user_id;
    } else {
        $errors[] = 'Update Car ERROR';
    }
}
/**
 * Create a new car record
 * 
 * @param array $cardetails Car data to create
 * @return void Updates global $errors and $successes arrays
 */
function addCar(array &$cardetails): void
{
    global $errors;
    global $successes;
    $car = new Car();

    if ($car->create($cardetails)) {
        $successes[] = 'Add Car ID: ' . $car->data()->id;
        $successes[] = 'Added by User ID: ' . $car->data()->user_id;
        $cardetails['id'] = $car->data()->id;
    } else {
        $errors[] = 'Car Create ERROR';
    }
}

/**
 * Build car details from form input and existing data
 * 
 * @param array $cardetails Car details array to populate
 * @param int|null $carId Optional car ID for updates
 * @return void
 */
function buildCarDetails(array &$cardetails, ?int $carId = null): void
{
    global $user;
    global $errors;
    global $successes;
    global $db;

    // Get the combined user+profile
    if ($carId) {
        $car = new Car($carId);
        foreach ($car->data() as $key => $value) {
            $cardetails[$key] = $value;
        }
    } else {
        $userQ = $db->findById($user->data()->id, "usersview")->results()[0];

        /*  Add the User/profile information to the record */
        $cardetails['user_id']      = $userQ->id;
        $cardetails['email']        = $userQ->email;
        $cardetails['fname']        = $userQ->fname;
        $cardetails['lname']        = $userQ->lname;
        $cardetails['join_date']    = $userQ->join_date;
        $cardetails['city']         = $userQ->city;
        $cardetails['state']        = $userQ->state;
        $cardetails['country']      = $userQ->country;
        $cardetails['lat']          = $userQ->lat;
        $cardetails['lon']          = $userQ->lon;

        $cardetails['id']           = null;
        $cardetails['year']         = null;
        $cardetails['model']        = null;
        $cardetails['series']       = null;
        $cardetails['variant']      = null;
        $cardetails['type']         = null;
        $cardetails['chassis']      = null;
        $cardetails['color']        = null;
        $cardetails['engine']       = null;
        $cardetails['purchasedate'] = null;
        $cardetails['solddate']     = null;
        $cardetails['website']      = null;
        $cardetails['comments']     = null;
    }
    updateYear($cardetails);
    updateModel($cardetails);
    updateChassis($cardetails);
    updateColor($cardetails);
    updateEngine($cardetails);
    updatePurchasedate($cardetails);
    updateSolddate($cardetails);
    updateWebsite($cardetails);
    updateComments($cardetails);
}

/**
 * Update car year from form input
 * 
 * @param array $cardetails Car details array to update
 * @return void
 */
function updateYear(array &$cardetails): void
{
    global $errors, $successes;
    
    //Update Year
    if (Input::get('year')) {
        $cardetails['year'] =  Input::get('year');
        $successes[] = 'Year Updated (' . $cardetails['year'] . ')';
    } else {
        $errors[] = "Please select Year";
    }
}

/**
 * Update car model information from form input
 * 
 * @param array $cardetails Car details array to update
 * @return void
 */
function updateModel(array &$cardetails): void
{
    global $errors, $successes;
    
    // Update 'model'
    if (Input::get('model')) {
        $cardetails['model'] = Input::get('model');
        // Model isn't really a thing.
        // We need to explode it into the proper columns
        list($series, $variant, $type) = explode('|', $cardetails['model']);
        /* MST value is from form, so I shouldn't have to do this but to be safe ... */
        $cardetails['series'] = filter_var($series, FILTER_UNSAFE_RAW);
        $cardetails['variant'] = filter_var($variant, FILTER_UNSAFE_RAW);
        $cardetails['type'] = filter_var($type, FILTER_UNSAFE_RAW);

        $successes[] = 'Model Updated (' . $cardetails['model'] . ')';
    } else {
        $errors[] = "Please select Model";
    }
}

/**
 * Update car chassis number from form input with comprehensive validation
 * 
 * @param array $cardetails Car details array to update
 * @return void
 */
function updateChassis(array &$cardetails): void
{
    global $errors, $successes;
    
    // Check if validation override is enabled
    $chassisOverride = Input::get('chassis_override') === '1';
    
    // Update 'chassis'
    if (Input::get('chassis')) {
        $cardetails['chassis'] = Input::get('chassis');
        $chassis = $cardetails['chassis'];
        $chassisLength = strlen($chassis);
        $year = (int)$cardetails['year'];
        $variant = $cardetails['variant'];
        $series = $cardetails['series'];
        
        $validationPassed = false;
        $errorReason = '';
        
        // Race cars: year-specific format validation
        if (strcmp($variant, 'Race') === 0) {
            if ($year === 1963) {
                // 1963: 26-R-xx only
                if (preg_match('/^26-R-\d{2}$/', $chassis)) {
                    $validationPassed = true;
                } else {
                    $errorReason = '1963 race cars must use format 26-R-xx (e.g., 26-R-01)';
                }
            } elseif ($year === 1964) {
                // 1964: 26-R-xx OR 26-S2-xx
                if (preg_match('/^26-R-\d{2}$/', $chassis) || preg_match('/^26-S2-\d{2}$/', $chassis)) {
                    $validationPassed = true;
                } else {
                    $errorReason = '1964 race cars must use format 26-R-xx or 26-S2-xx (e.g., 26-R-01 or 26-S2-01)';
                }
            } elseif ($year === 1965 || $year === 1966) {
                // 1965-1966: 26-S2-xx only
                if (preg_match('/^26-S2-\d{2}$/', $chassis)) {
                    $validationPassed = true;
                } else {
                    $errorReason = $year . ' race cars must use format 26-S2-xx (e.g., 26-S2-01)';
                }
            } else {
                // Other years with race models - use 26-R-xx format as default
                if (preg_match('/^26-R-\d{2}$/', $chassis)) {
                    $validationPassed = true;
                } else {
                    $errorReason = $year . ' race cars must use format 26-R-xx (e.g., 26-R-01)';
                }
            }
        } else {
            // Production cars validation
            if ($year < 1970) {
                // Pre-1970: 4 digits only
                if (is_numeric($chassis) && $chassisLength === 4) {
                    $validationPassed = true;
                } else {
                    if (!is_numeric($chassis)) {
                        $errorReason = 'Pre-1970 chassis must be numeric (4 digits only, e.g., 1234)';
                    } else {
                        $errorReason = 'Pre-1970 chassis must be exactly 4 digits (e.g., 1234)';
                    }
                }
            } elseif ($year >= 1970) {
                // Post-Jan 1970: YYMMBBXXXXC format (11 characters)
                if ($chassisLength === 11) {
                    $base = substr($chassis, 0, 10);
                    $suffix = strtoupper(substr($chassis, 10, 1));
                    
                    // Model-specific letter validation
                    if (strpos($series, '+2') !== false) {
                        // +2 models can only use L, M, N
                        $validSuffixes = ['L', 'M', 'N'];
                        $modelType = '+2';
                        $allowedCodes = 'L, M, N';
                    } else {
                        // Elan models can only use A-K (excluding I)
                        $validSuffixes = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K'];
                        $modelType = 'Elan';
                        $allowedCodes = 'A-K (excluding I)';
                    }
                    
                    if (is_numeric($base) && in_array($suffix, $validSuffixes)) {
                        $validationPassed = true;
                    } else {
                        if (!is_numeric($base)) {
                            $errorReason = 'First 10 characters must be numeric in YYMMBBXXXXC format (e.g., 7301019999B)';
                        } else {
                            $errorReason = $modelType . ' models require letter codes: ' . $allowedCodes . ' (current: "' . $suffix . '")';
                        }
                    }
                } elseif ($year === 1970 && $chassisLength === 5) {
                    // 1970 transition year: also allow legacy 5-character format
                    $base = substr($chassis, 0, 4);
                    $suffix = strtoupper(substr($chassis, 4, 1));
                    
                    $validSuffixes = (strpos($series, '+2') !== false) ? ['L', 'M', 'N'] : ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K'];
                    
                    if (is_numeric($base) && in_array($suffix, $validSuffixes)) {
                        $validationPassed = true;
                    } else {
                        if (!is_numeric($base)) {
                            $errorReason = '1970 transition format: First 4 characters must be numeric plus letter (e.g., 1234A)';
                        } else {
                            $modelType = (strpos($series, '+2') !== false) ? '+2' : 'Elan';
                            $allowedCodes = (strpos($series, '+2') !== false) ? 'L, M, N' : 'A-K (excluding I)';
                            $errorReason = '1970 ' . $modelType . ' models require letter codes: ' . $allowedCodes . ' (current: "' . $suffix . '")';
                        }
                    }
                } else {
                    if ($year === 1970) {
                        $errorReason = '1970 chassis must be 5 characters (legacy format) or 11 characters (new YYMMBBXXXXC format)';
                    } else {
                        $errorReason = 'Post-1970 chassis must be 11 characters in YYMMBBXXXXC format (e.g., 7301019999B)';
                    }
                }
            }
        }
        
        // Handle validation result
        if ($validationPassed) {
            $successes[] = 'Chassis Updated (' . $cardetails['chassis'] . ')';
        } elseif ($chassisOverride) {
            $successes[] = 'Chassis Updated with Override (' . $cardetails['chassis'] . ') - Warning: ' . $errorReason;
        } else {
            $errors[] = 'Chassis Validation Failed: ' . $errorReason;
        }
    } else {
        $errors[] = "Please enter chassis number";
    }
}

function updateColor(&$cardetails)
{
    // Update 'color'
    if (Input::get('color')) {
        $cardetails['color'] = Input::get('color');
        $successes[] = 'Color Updated (' . $cardetails['color'] . ')';
    } else {
        $cardetails['color'] = null;
    }
}

function updateEngine(&$cardetails)
{
    // Update 'engine'
    if (Input::get('engine')) {
        $cardetails['engine'] = Input::get('engine');
        $cardetails['engine'] = str_replace(" ", "", strtoupper(trim($cardetails['engine'])));
        $successes[] = 'Engine Updated (' . $cardetails['engine'] . ')';
    } else {
        $cardetails['engine'] = null;
    }
}

function updatePurchasedate(&$cardetails)
{
    // Update 'purchasedate'
    if (Input::get('purchasedate')) {
        $cardetails['purchasedate'] = Input::get('purchasedate');
        $cardetails['purchasedate'] = date("Y-m-d", strtotime($cardetails['purchasedate']));
        $successes[] = 'Purchase Date Updated (' . $cardetails['purchasedate'] . ')';
    } else {
        $cardetails['purchasedate'] = null;
    }
}

function updateSolddate(&$cardetails)
{
    // Update 'solddate'
    if (Input::get('solddate')) {
        $cardetails['solddate'] = Input::get('solddate');
        $cardetails['solddate'] = date("Y-m-d", strtotime($cardetails['solddate']));
        $successes[] = 'Sold Date Updated (' . $cardetails['solddate'] . ')';
    } else {
        $cardetails['solddate'] = null;
    }
}

function updateWebsite(&$cardetails)
{
    // Update 'website'
    if (Input::get('website')) {
        $cardetails['website'] = Input::get('website');
        $successes[] = 'Website Updated (' . $cardetails['website'] . ')';
    } else {
        $cardetails['website'] = null;
    }
}

function updateComments(&$cardetails)
{
    // Update 'comments'
    if (Input::get('comments')) {
        $cardetails['comments'] = Input::get('comments');
        $successes[] = 'Comments Updated (' . $cardetails['comments'] . ')';
    } else {
        $cardetails['comments'] = null;
    }
}

function buildImageDetails(&$cardetails)
{
    // This needs to happen before processinging new files to the event the order changes
    // without adding new files

    $requestedOrder = array_filter(explode(',', Input::get('filenames')));
    $cardetails['image'] = json_encode($requestedOrder);

    // Order of all images in the dropzone
    // Do I have any new files?
    if ($_FILES['file']['name'][0] == 'blob') {
        $successes[] = 'No image';
    }
}

function uploadImages(&$cardetails)
{
    global $targetFilePath;
    global $errors;
    global $successes;
    global $user;

    // Image resize dimensions - consider moving to configuration
    $imageSizes = [100, 300, 600, 1024, 2048];


    // Do I have any new files?
    if ($_FILES['file']['name'][0] == 'blob') {
        $successes[] = 'No image';
        return;
    }
    // Secure path construction with validation
    if (empty($cardetails['id'])) {
        $filePath = $targetFilePath . 'temp' . '/';
    } else {
        // Validate car ID is numeric to prevent directory traversal
        $carId = filter_var($cardetails['id'], FILTER_VALIDATE_INT);
        if ($carId === false || $carId <= 0) {
            throw new Exception("Invalid car ID for file upload");
        }
        $filePath = $targetFilePath . $carId . '/';
    }

    // Ensure the path is within expected directory structure
    $realTargetPath = realpath($targetFilePath);
    $realFilePath = realpath(dirname($filePath));
    
    if ($realFilePath === false || strpos($realFilePath, $realTargetPath) !== 0) {
        throw new Exception("Invalid upload path detected");
    }

    if (!is_dir($filePath)) {
        // Create directory with secure permissions (755)
        if (!mkdir($filePath, 0755, true)) {
            throw new Exception("Failed to create upload directory");
        }
    }

    $requestedOrder = array_filter(explode(',', Input::get('filenames')));

    //  $_FILES['file']['tmp_name'] is an array so have to use loop
    foreach ($_FILES['file']['tmp_name'] as $key => $value) {
        $name  = $_FILES['file']['name'][$key];
        $tempFile = $_FILES['file']['tmp_name'][$key];

        if ($tempFile !== '') { //  deal with empty file name
            try {
                // Create file info array for validation
                $fileInfo = [
                    'name' => $_FILES['file']['name'][$key],
                    'tmp_name' => $tempFile,
                    'error' => $_FILES['file']['error'][$key],
                    'size' => $_FILES['file']['size'][$key]
                ];
                
                // Validate file upload security constraints
                validateFileUpload($fileInfo);
                
                // Get and validate MIME type
                $mimeType = getMimeType($tempFile);
                $extension = getExtension($mimeType);
                
                // Generate cryptographically secure filename
                $newFileName = generateSecureFilename($extension);

                if (move_uploaded_file($tempFile, $filePath . $newFileName)) {
                    $successes[] = "Photo has been uploaded " . $name . " as " . $newFileName;

                    //  Create resized images
                    $fileinfo = pathinfo($filePath . $newFileName);
                    $filename = $fileinfo['filename'];
                    $extension = $fileinfo['extension'];

                    foreach ($imageSizes as $size) {
                        $thumbname = $filePath . $filename . "-resized-" . $size . "." . $extension;

                        $resizeObj = new Resize($filePath . $newFileName);
                        $resizeObj->resizeImage($size, $size, 'auto');
                        $resizeObj->saveImage($thumbname, 80);
                        $successes[] = " Created " . $thumbname;
                    }
                    arrayReplaceValue($requestedOrder, $name, $newFileName);
                } else {
                    $errors[] = "Photo failed to upload " . $name . " as " . $newFileName;
                    logger(
                        $user->data()->id,
                        "ElanRegistry",
                        "ERROR: File upload failed for carId: " .
                            Input::get('carid') . " File: " . $name . " Target: " . $newFileName
                    );
                }
            } catch (Exception $e) {
                // Log security violation and reject file
                $errors[] = "File upload rejected: " . $e->getMessage();
                logger(
                    $user->data()->id,
                    "ElanRegistry",
                    "SECURITY: File upload rejected for carId: " .
                        Input::get('carid') . " File: " . $name . " Reason: " . $e->getMessage()
                );
            }
        }
    }
    $cardetails['image'] = json_encode($requestedOrder);
}

/**
 * Fetch images for a specific car
 * 
 * @param int $carid Car ID
 * @return void Outputs JSON response and exits
 */
function fetchImages(int $carid): void
{
    $car = new Car($carid);

    $response = [
        'status' => 'success',
        'images' => $car->images(),
    ];

    echo json_encode($response);
    exit;
}

/**
 * Move temporary images to permanent car directory
 * 
 * @param array $cardetails Car details containing ID and image info
 * @return void
 */
function mvTmpImages(array &$cardetails): void
{
    global $targetFilePath;

    $tempPath = $targetFilePath . 'temp' . '/';

    $filePath = $targetFilePath . $cardetails['id'] . '/';
    if (!is_dir($filePath)) {
        mkdir($filePath, 0755, true);
    }

    // Get the car images
    // Turn images into array
    // Images can be encoded as JSON or simple CSV
    $carImages = json_decode($cardetails['image']);

    if (is_null($carImages)) {
        $carImages = explode(',', $cardetails['image']);
    }

    foreach ($carImages as $carimage) {
        $tmpfile = pathinfo($carimage);

        foreach (glob($tempPath . $tmpfile['filename'] . '*' . $tmpfile['extension']) as $name) {
            $file = pathinfo($name);

            rename($name, $filePath . $file['basename']);
        }
    }
}

/**
 * Remove an image from a car's image list
 * 
 * @param int $carID Car ID
 * @param string $file Image filename to remove
 * @return void Outputs JSON response and exits
 */
function removeImage(int $carID, string $file): void
{
    global $db;
    global $user;

    $car = $db->findById($carID, 'cars')->results()[0];
    $carImages = explode(',', $car->image);
    $imageIndex = array_search($file, $carImages, true);

    if ($imageIndex !== false) {
        unset($carImages[$imageIndex]);
        // Write the new array to the DB
        $images = implode(',', $carImages);
        $db->update("cars", $carID, ["image" => $images]);

        $response = [
            'status' => 'success',
            'count'   => count($carImages),
            'images' => $carImages
        ];
    } else {
        logger($user->data()->id, "ElanRegistry", "ERROR: removeImage carId: " . $carID . " Image not found: " . $file);
        $response = [
            'status' => 'error',
            'info'   => "image not found"
        ];
    }
    echo json_encode($response);
    exit;
}

/**
 * Replace a value in an array with a new value
 * 
 * @param array $array Array to modify
 * @param mixed $value Value to find and replace
 * @param mixed $replacement New value
 * @return void
 */
function arrayReplaceValue(array &$array, $value, $replacement): void
{
    $key = array_search($value, $array, true);
    if ($key !== false) {
        $array[$key] = $replacement;
    }
}

/**
 * Get file extension from MIME type
 * 
 * @param string $mimeType MIME type to convert
 * @return string File extension
 * @throws Exception If MIME type is not supported
 */
function getExtension(string $mimeType): string
{
    // Comprehensive secure image type validation
    $allowedExtensions = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];
    
    if (!isset($allowedExtensions[$mimeType])) {
        throw new Exception("Unsupported file type: " . $mimeType);
    }
    
    return $allowedExtensions[$mimeType];
}

/**
 * Get MIME type of uploaded file with security validation
 * 
 * @param string $file File path to analyze
 * @return string MIME type
 * @throws Exception If unable to determine type or type is invalid
 */
function getMimeType(string $file): string
{
    // Secure MIME type detection with multiple validation layers
    $mimeType = false;
    
    // Primary method: Use finfo (most reliable)
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file);
        finfo_close($finfo);
    } elseif (function_exists('mime_content_type')) {
        $mimeType = mime_content_type($file);
    } else {
        throw new Exception("Unable to determine file MIME type");
    }
    
    // Additional validation: Check if detected MIME type is in our allowlist
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mimeType, $allowedTypes, true)) {
        throw new Exception("Invalid file type detected: " . $mimeType);
    }
    
    return $mimeType;
}

/**
 * Generate cryptographically secure filename
 * 
 * @param string $extension File extension
 * @return string Secure filename
 */
function generateSecureFilename(string $extension): string
{
    // Use cryptographically secure random bytes instead of uniqid()
    $randomBytes = random_bytes(16);
    return 'img_' . bin2hex($randomBytes) . '.' . $extension;
}

/**
 * Validate file upload security constraints
 * 
 * @param array $file File upload array from $_FILES
 * @param int $maxSize Maximum file size in bytes (default 5MB)
 * @return bool Always returns true if validation passes
 * @throws Exception If validation fails
 */
function validateFileUpload(array $file, int $maxSize = 5242880): bool // Default 5MB
{
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("File upload error: " . $file['error']);
    }
    
    // Check file size (default 5MB limit)
    if ($file['size'] > $maxSize) {
        throw new Exception("File too large. Maximum size: " . ($maxSize / 1024 / 1024) . "MB");
    }
    
    // Verify the file was actually uploaded via HTTP POST
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new Exception("Invalid file upload");
    }
    
    // Additional security: Check for minimum file size (avoid empty files)
    if ($file['size'] < 100) {
        throw new Exception("File too small - minimum 100 bytes required");
    }
    
    return true;
}
