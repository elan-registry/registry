<?php
declare(strict_types=1);

/**
 * editCar.php - Car management endpoint
 * 
 * Handles AJAX requests for car creation, updates, and image management.
 * Provides secure file upload with validation and CSRF protection.
 * 
 * @author Elan Registry Team
 * @copyright 2025
 */

// Start output buffering to prevent any HTML from being output before JSON
ob_start();

// Check to see if the chassis number is taken
require_once '../../../users/init.php';

$settings = getSettings();  // Get global settings from plugin

// Ensure settings have default values for image configuration
if (!isset($settings->elan_image_upload_max_size)) {
    $settings->elan_image_upload_max_size = 2;
}
if (!isset($settings->elan_image_display_max_size)) {
    $settings->elan_image_display_max_size = 2048;
}
if (!isset($settings->elan_image_thumbnail_sizes)) {
    $settings->elan_image_thumbnail_sizes = '100,300,600,1024,2048';
}

// A place to put some messages
$errors     = [];
$successes  = [];
$chassis_override_used = false; // Track if chassis validation override was used
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
                try {
                    buildCarDetails($cardetails);
                    buildImageDetails($cardetails);

                    if (!empty($errors)) {
                        ApiResponse::validationError(
                            ['general' => $errors],
                            'Cannot add car: validation errors'
                        )->withLogging(
                            $user->data()->id,
                            'ValidationError',
                            'Car creation validation failed: ' . json_encode($errors)
                        )->send();
                    }

                    uploadImages($cardetails);
                    addCar($cardetails);
                    mvTmpImages($cardetails);

                    // Blanks instead of NULL for display
                    foreach ($cardetails as $key => $value) {
                        if (is_null($value)) {
                            $cardetails[$key] = "";
                        }
                    }

                    ApiResponse::success('Car added successfully')
                        ->withData('cardetails', $cardetails)
                        ->withLogging(
                            $user->data()->id,
                            'CarActions',
                            'Car added: ID ' . $cardetails['id']
                        )->send();

                } catch (ElanRegistryException $e) {
                    ApiResponse::serverError('Failed to add car: ' . $e->getMessage())
                        ->withLogging(
                            $user->data()->id,
                            'CarErrors',
                            'Car add error: ' . $e->getMessage()
                        )->send();
                }
                break;

            case "updateCar":
                try {
                    buildCarDetails($cardetails, (int)Input::get('car_id'));
                    buildImageDetails($cardetails);

                    if (!empty($errors)) {
                        ApiResponse::validationError(
                            ['general' => $errors],
                            'Cannot update car: validation errors'
                        )->withLogging(
                            $user->data()->id,
                            'ValidationError',
                            'Car update validation failed: ' . json_encode($errors)
                        )->send();
                    }

                    uploadImages($cardetails);
                    updateCar($cardetails);

                    // Blanks instead of NULL for display
                    foreach ($cardetails as $key => $value) {
                        if (is_null($value)) {
                            $cardetails[$key] = "";
                        }
                    }

                    ApiResponse::success('Car updated successfully')
                        ->withData('cardetails', $cardetails)
                        ->withLogging(
                            $user->data()->id,
                            'CarActions',
                            'Car updated: ID ' . $cardetails['id']
                        )->send();

                } catch (ElanRegistryException $e) {
                    ApiResponse::serverError('Failed to update car: ' . $e->getMessage())
                        ->withLogging(
                            $user->data()->id,
                            'CarErrors',
                            'Car update error: ' . $e->getMessage()
                        )->send();
                }
                break;

            case "fetchImages":
                $car_id = (int)Input::get('carID');
                fetchImages($car_id);
                break;

            case "removeImages":
                $car_id = (int)Input::get('carID');
                $file = Input::get('file');
                removeImage($car_id, $file);
                break;

            default:
                ApiResponse::error('No valid action', 400)
                    ->withLogging($user->data()->id, 'ValidationError', 'Invalid action: ' . $action)
                    ->send();
        }
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
    global $user;

    try {
        $car = new Car();

        // Update
        if ($car->update($cardetails)) {
            $successes[] = 'Update Car ID: ' . $car->data()->id;
            $successes[] = 'Update BY ID: ' . $car->data()->user_id;
        } else {
            $errors[] = 'Update Car ERROR';
        }
    } catch (CarValidationException $e) {
        logger($user->data()->id, 'ValidationError', 'Car Update Validation Error: ' . $e->getMessage());
        $errors[] = 'Car Update Validation Error: ' . $e->getMessage();
    } catch (ElanRegistryException $e) {
        logger($user->data()->id, 'CarErrors', 'Car Update Error: ' . $e->getMessage());
        $errors[] = 'Car Update Error: ' . $e->getMessage();
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
    global $user;
    
    try {
        $car = new Car();

        if ($car->create($cardetails)) {
            $successes[] = 'Add Car ID: ' . $car->data()->id;
            $successes[] = 'Added by User ID: ' . $car->data()->user_id;
            $cardetails['id'] = $car->data()->id;
        } else {
            $errors[] = 'Car Create ERROR';
        }
    } catch (CarValidationException $e) {
        logger($user->data()->id, 'ValidationError', 'Car Creation Validation Error: ' . $e->getMessage());
        $errors[] = 'Car Creation Validation Error: ' . $e->getMessage();
    } catch (ElanRegistryException $e) {
        logger($user->data()->id, 'CarErrors', 'Car Creation Error: ' . $e->getMessage());
        $errors[] = 'Car Creation Error: ' . $e->getMessage();
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
        $ownerId = (int)$user->data()->id;
        $owner = new ElanRegistryOwner($ownerId);
        $ownerData = $owner->data();

        /*  Add the User/profile information to the record */
        $cardetails['user_id']      = $ownerData->id;
        $cardetails['email']        = $ownerData->email;
        $cardetails['fname']        = $ownerData->fname;
        $cardetails['lname']        = $ownerData->lname;
        $cardetails['join_date']    = $ownerData->join_date;
        $cardetails['city']         = $ownerData->city;
        $cardetails['state']        = $ownerData->state;
        $cardetails['country']      = $ownerData->country;
        $cardetails['lat']          = $ownerData->lat;
        $cardetails['lon']          = $ownerData->lon;

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
    // Add CSRF token for Car class validation
    $cardetails['token'] = Input::get('csrf');
    
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
        $successes[] = 'Year: ' . $cardetails['year'];
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

        $successes[] = 'Model: ' . $cardetails['model'];
    } else {
        $errors[] = "Please select Model";
    }
}

/**
 * Update car chassis number from form input with centralized validation
 * 
 * @param array $cardetails Car details array to update
 * @return void
 */
function updateChassis(array &$cardetails): void
{
    global $errors, $successes, $chassis_override_used, $user;
    
    // Check if validation override is enabled
    // Checkbox only sends value when checked, so check if parameter exists and has value '1'
    $chassisOverrideRaw = Input::get('chassis_override');
    $chassisOverride = ($chassisOverrideRaw === '1');
    
    // Update 'chassis'
    if (Input::get('chassis')) {
        $cardetails['chassis'] = Input::get('chassis');
        $chassis = $cardetails['chassis'];
        $year = (int)$cardetails['year'];
        $model = $cardetails['model']; // Contains series|variant|type format
        
        // Use centralized chassis validator
        $validatorPath = '../../../usersc/classes/ChassisValidator.php';
        if (!file_exists($validatorPath)) {
            logger($user->data()->id, 'SystemError', 'ChassisValidator class file not found at: ' . realpath($validatorPath));
            $errors[] = 'ChassisValidator class file not found at: ' . realpath($validatorPath);
            return;
        }
        
        require_once $validatorPath;
        
        try {
            $validator = new ChassisValidator();
            $result = $validator->validate($chassis, $year, $model, $chassisOverride);
        } catch (ElanRegistryException $e) {
            $errors[] = 'Chassis validation error: ' . $e->getMessage();
            return;
        }
        
        // Handle validation result
        if ($result['valid'] && !$result['override_used']) {
            $successes[] = 'Chassis: ' . $cardetails['chassis'];
        } elseif ($result['valid'] && $result['override_used']) {
            $successes[] = 'Chassis: ' . $cardetails['chassis'] . ' (Override used)';
            $chassis_override_used = true; // Track that override was used for comments
        } else {
            $errors[] = '<strong>ERROR:</strong> Chassis Validation Failed: ' . $result['error_reason'];
        }
    } else {
        $errors[] = "Please enter chassis number";
    }
}

/**
 * Update car color from form input
 *
 * @param array $cardetails Car details array to update
 * @return void
 */
function updateColor(array &$cardetails): void
{
    // Update 'color'
    if (Input::get('color')) {
        $cardetails['color'] = Input::get('color');
        $successes[] = 'Color: ' . $cardetails['color'];
    } else {
        $cardetails['color'] = null;
    }
}

/**
 * Update car engine number from form input
 *
 * @param array $cardetails Car details array to update
 * @return void
 */
function updateEngine(array &$cardetails): void
{
    // Update 'engine'
    if (Input::get('engine')) {
        $cardetails['engine'] = Input::get('engine');
        $cardetails['engine'] = str_replace(" ", "", strtoupper(trim($cardetails['engine'])));
        $successes[] = 'Engine: ' . $cardetails['engine'];
    } else {
        $cardetails['engine'] = null;
    }
}

/**
 * Update car purchase date from form input
 *
 * @param array $cardetails Car details array to update
 * @return void
 */
function updatePurchasedate(array &$cardetails): void
{
    // Update 'purchasedate'
    if (Input::get('purchasedate')) {
        $cardetails['purchasedate'] = Input::get('purchasedate');
        $cardetails['purchasedate'] = date("Y-m-d", strtotime($cardetails['purchasedate']));
        $successes[] = 'Purchase Date: ' . $cardetails['purchasedate'];
    } else {
        $cardetails['purchasedate'] = null;
    }
}

/**
 * Update car sold date from form input
 *
 * @param array $cardetails Car details array to update
 * @return void
 */
function updateSolddate(array &$cardetails): void
{
    // Update 'solddate'
    if (Input::get('solddate')) {
        $cardetails['solddate'] = Input::get('solddate');
        $cardetails['solddate'] = date("Y-m-d", strtotime($cardetails['solddate']));
        $successes[] = 'Sold Date: ' . $cardetails['solddate'];
    } else {
        $cardetails['solddate'] = null;
    }
}

/**
 * Update car website URL from form input
 *
 * @param array $cardetails Car details array to update
 * @return void
 */
function updateWebsite(array &$cardetails): void
{
    // Update 'website'
    if (Input::get('website')) {
        $cardetails['website'] = Input::get('website');
        $successes[] = 'Website: ' . $cardetails['website'];
    } else {
        $cardetails['website'] = null;
    }
}

/**
 * Update car comments from form input with chassis override audit trail
 *
 * @param array $cardetails Car details array to update
 * @return void
 */
function updateComments(array &$cardetails): void
{
    global $successes, $chassis_override_used;
    
    // Update 'comments'
    if (Input::get('comments')) {
        $cardetails['comments'] = Input::get('comments');
        $successes[] = 'Comments: Updated';
    } else {
        $cardetails['comments'] = null;
    }
    
    // If chassis override was used, append audit note to comments
    if (isset($chassis_override_used) && $chassis_override_used === true) {
        $overrideNote = "\nCHASSIS VALIDATION OVERRIDDEN: " . date('Y-m-d H:i:s') . " - Admin override used for chassis validation.";
        
        if ($cardetails['comments']) {
            // Append to existing comments with line break
            $cardetails['comments'] .= "\n" . $overrideNote;
        } else {
            // Set as new comment if no existing comments
            $cardetails['comments'] = $overrideNote;
        }
    }
}

/**
 * Build and encode image details from form input
 *
 * @param array $cardetails Car details array to update with image encoding
 * @return void
 */
function buildImageDetails(array &$cardetails): void
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

function uploadImages(array &$cardetails): void
{
    global $targetFilePath;
    global $errors;
    global $successes;
    global $user;

    // Image resize dimensions from settings
    $thumbnailSizesString = isset($settings->elan_image_thumbnail_sizes) && !empty($settings->elan_image_thumbnail_sizes) 
        ? $settings->elan_image_thumbnail_sizes 
        : '100,300,600,1024,2048'; // Default fallback
    $thumbnailSizes = explode(',', $thumbnailSizesString);
    $imageSizes = array_map('intval', array_map('trim', $thumbnailSizes));


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
            logger($user->data()->id, 'FileError', "Failed to create upload directory: " . $filePath);
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
                    $successes[] = "Photo uploaded: " . $name;

                    //  Create resized images
                    $fileinfo = pathinfo($filePath . $newFileName);
                    $filename = $fileinfo['filename'];
                    $extension = $fileinfo['extension'];
                    $resizeSuccess = true;

                    foreach ($imageSizes as $size) {
                        $thumbname = $filePath . $filename . "-resized-" . $size . "." . $extension;

                        try {
                            $resizeObj = new Resize($filePath . $newFileName);
                            $resizeObj->resizeImage($size, $size, 'auto');
                            $resizeObj->saveImage($thumbname, 80);
                        } catch (ElanRegistryException $e) {
                            $resizeSuccess = false;
                            break;
                        }
                    }
                    
                    if ($resizeSuccess) {
                        $successes[] = "Image resize: Success";
                    } else {
                        $errors[] = "Image resize: Failed";
                    }
                    arrayReplaceValue($requestedOrder, $name, $newFileName);
                } else {
                    $errors[] = "Photo failed to upload " . $name . " as " . $newFileName;
                    logger(
                        $user->data()->id,
                        "FileError",
                        "ERROR: File upload failed for carId: " .
                            Input::get('car_id') . " File: " . $name . " Target: " . $newFileName
                    );
                }
            } catch (ElanRegistryException $e) {
                // Log security violation and reject file
                $errors[] = "File upload rejected: " . $e->getMessage();
                logger(
                    $user->data()->id,
                    "FileError",
                    "SECURITY: File upload rejected for carId: " .
                        Input::get('car_id') . " File: " . $name . " Reason: " . $e->getMessage()
                );
            }
        }
    }
    $cardetails['image'] = json_encode($requestedOrder);
}

/**
 * Fetch images for a specific car
 *
 * @param int $car_id Car ID
 * @return void Outputs JSON response and exits
 */
function fetchImages(int $car_id): void
{
    global $user;

    try {
        // Validate car ID
        if (empty($car_id) || $car_id <= 0) {
            ApiResponse::error('Invalid car ID', 400)
                ->withLogging($user->data()->id, 'ValidationError', 'fetchImages: Invalid car ID')
                ->send();
        }

        $car = new Car($car_id);

        // Check if car exists
        if (!$car->exists()) {
            ApiResponse::notFound('Car not found')
                ->withLogging($user->data()->id, 'CarErrors', "fetchImages: Car not found: {$car_id}")
                ->send();
        }

        $images = $car->images();

        ApiResponse::success('Images retrieved successfully')
            ->withData('images', $images)
            ->withLogging($user->data()->id, 'CarActions', "Images fetched for car: {$car_id}")
            ->send();

    } catch (ElanRegistryException $e) {
        ApiResponse::serverError('Failed to fetch images')
            ->withLogging($user->data()->id, 'CarErrors', 'fetchImages error: ' . $e->getMessage())
            ->send();
    }
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
 * Uses Car class method to replace direct database access and ensure proper validation.
 *
 * @param int $carID Car ID
 * @param string $file Image filename to remove
 * @return void Outputs JSON response and exits
 *
 * @see https://github.com/unibrain1/elanregistry/issues/247 Issue #247: Fix removeImage() direct database access
 */
function removeImage(int $carID, string $file): void
{
    global $user;

    try {
        // Use Car class for proper validation and data handling
        $car = new Car($carID);
        if (!$car->exists()) {
            ApiResponse::notFound('Car not found')
                ->withLogging($user->data()->id, 'CarErrors', "removeImage: Car not found: {$carID}")
                ->send();
        }

        // Use Car class method to remove image
        $imageRemoved = $car->removeImage($file);

        if ($imageRemoved) {
            // Log successful removal
            ApiResponse::success('Image removed successfully')
                ->withData('count', count($car->images()))
                ->withData('images', array_column($car->images(), 'basename'))
                ->withLogging(
                    $user->data()->id,
                    'CarActions',
                    "Image removed: carId: {$carID}, image: {$file}"
                )->send();
        } else {
            // Image not found in car's image list
            ApiResponse::error('Image not found', 404)
                ->withLogging(
                    $user->data()->id,
                    'CarErrors',
                    "removeImage: Image not found - carId: {$carID}, file: {$file}"
                )->send();
        }
    } catch (ElanRegistryException $e) {
        // Log error and return error response
        ApiResponse::serverError('Failed to remove image')
            ->withLogging(
                $user->data()->id,
                'CarErrors',
                "removeImage error: carId: {$carID}, error: " . $e->getMessage()
            )->send();
    }
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
