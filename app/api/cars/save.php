<?php
declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\Car\Car;
use ElanRegistry\ChassisValidator;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\Input;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;
use ElanRegistry\Resize;

/**
 * save.php - Car management endpoint
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
    $settings->elan_image_thumbnail_sizes = '100,300,768,1024,2048';
}

// A place to put some messages
$errors     = [];
$successes  = [];
$chassis_override_used = false; // Track if chassis validation override was used
$cardetails = [];


$targetFilePath = $abs_us_root . $us_url_root . $settings->elan_image_dir;
$targetURL = $us_url_root . $settings->elan_image_dir;

if ($method !== 'POST' || empty($_POST)) {
    ApiResponse::error('No data received', 400)->send();
}

if (!empty($_POST)) {
    if (!$user->isLoggedIn()) {
        ApiResponse::unauthorized('Login required')
            ->withLogging(0, LogCategories::LOG_CATEGORY_ACCESS_DENIED, 'Unauthenticated access attempt to edit.php')
            ->send();
    }

    $token = Input::get('csrf');
    if (!Token::check($token)) {
        ApiResponse::forbidden('Invalid CSRF token')
            ->withLogging($user->data() !== null ? $user->data()->id : 0, LogCategories::LOG_CATEGORY_SECURITY, 'CSRF check failed in edit.php')
            ->send();
    }

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
                    )->withData('cardetails', $cardetails)
                    ->withLogging(
                        $user->data()->id,
                        LogCategories::LOG_CATEGORY_VALIDATION_ERROR,
                        'Car creation validation failed: ' . json_encode($errors)
                    )->send();
                }

                uploadImages($cardetails);

                if (!empty($errors)) {
                    ApiResponse::validationError(
                        ['general' => $errors],
                        'Cannot add car: image upload failed'
                    )->withData('cardetails', $cardetails)
                    ->withLogging(
                        $user->data()->id,
                        LogCategories::LOG_CATEGORY_FILE_ERROR,
                        'Car add aborted: image upload errors: ' . json_encode($errors)
                    )->send();
                }

                addCar($cardetails);
                mvTmpImages($cardetails);

                if (!empty($errors)) {
                    ApiResponse::serverError('Car saved but images could not be moved from temp storage')
                        ->withData('cardetails', $cardetails)
                        ->withLogging(
                            $user->data()->id,
                            LogCategories::LOG_CATEGORY_FILE_ERROR,
                            'Car ID ' . ($cardetails['id'] ?? 'unknown') . ' saved but image move errors: ' . json_encode($errors)
                        )->send();
                }

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
                        LogCategories::LOG_CATEGORY_CAR_ACTIONS,
                        'Car added: ID ' . $cardetails['id']
                    )->send();

            } catch (ElanRegistryException $e) {
                ApiResponse::serverError('Failed to add car: ' . $e->getUserMessage())
                    ->withLogging(
                        $user->data()->id,
                        LogCategories::LOG_CATEGORY_CAR_ERRORS,
                        'Car add error: ' . $e->getMessage()
                    )->send();
            } catch (\Exception $e) {
                ApiResponse::serverError('Failed to add car: An unexpected error occurred.')
                    ->withLogging(
                        $user->data()->id,
                        LogCategories::LOG_CATEGORY_CAR_ERRORS,
                        'Car add unexpected error: ' . $e->getMessage()
                    )->send();
            }
            break;

        case "updateCar":
            $car_id = (int)Input::get('car_id');
            if ($car_id <= 0) {
                ApiResponse::error('Invalid car ID', 400)
                    ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'updateCar: invalid car_id in request')
                    ->send();
            }
            $carForAuth = new Car($car_id);
            if (!$carForAuth->data() || ($user->data()->id != $carForAuth->data()->user_id && !hasPerm([2, 3]))) {
                ApiResponse::error('Unauthorized', 403)
                    ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_ACCESS_DENIED, 'updateCar: unauthorized for car ' . $car_id)
                    ->send();
            }
            try {
                buildCarDetails($cardetails, $car_id);
                buildImageDetails($cardetails);

                if (!empty($errors)) {
                    ApiResponse::validationError(
                        ['general' => $errors],
                        'Cannot update car: validation errors'
                    )->withData('cardetails', $cardetails)
                    ->withLogging(
                        $user->data()->id,
                        LogCategories::LOG_CATEGORY_VALIDATION_ERROR,
                        'Car update validation failed: ' . json_encode($errors)
                    )->send();
                }

                uploadImages($cardetails);

                if (!empty($errors)) {
                    ApiResponse::validationError(
                        ['general' => $errors],
                        'Cannot update car: image upload failed'
                    )->withData('cardetails', $cardetails)
                    ->withLogging(
                        $user->data()->id,
                        LogCategories::LOG_CATEGORY_FILE_ERROR,
                        'Car update aborted: image upload errors: ' . json_encode($errors)
                    )->send();
                }

                updateCar($cardetails);

                if (!empty($errors)) {
                    ApiResponse::validationError(
                        ['general' => $errors],
                        'Cannot save car: update operation failed'
                    )->withData('cardetails', $cardetails)
                    ->withLogging(
                        $user->data()->id,
                        LogCategories::LOG_CATEGORY_CAR_ERRORS,
                        'Car update failed post-save validation: ' . json_encode($errors)
                    )->send();
                }

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
                        LogCategories::LOG_CATEGORY_CAR_ACTIONS,
                        'Car updated: ID ' . $cardetails['id']
                    )->send();

            } catch (ElanRegistryException $e) {
                ApiResponse::serverError('Failed to update car: ' . $e->getUserMessage())
                    ->withLogging(
                        $user->data()->id,
                        LogCategories::LOG_CATEGORY_CAR_ERRORS,
                        'Car update error: ' . $e->getMessage()
                    )->send();
            } catch (\Exception $e) {
                ApiResponse::serverError('Failed to update car: An unexpected error occurred.')
                    ->withLogging(
                        $user->data()->id,
                        LogCategories::LOG_CATEGORY_CAR_ERRORS,
                        'Car update unexpected error: ' . $e->getMessage()
                    )->send();
            }
            break;

        case "fetchImages":
            $car_id = (int)Input::get('carID');
            $carForAuth = new Car($car_id);
            if (!$carForAuth->data() || ($user->data()->id != $carForAuth->data()->user_id && !hasPerm([2, 3]))) {
                ApiResponse::forbidden('Unauthorized')
                    ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_ACCESS_DENIED, 'fetchImages: unauthorized for car ' . $car_id)
                    ->send();
            }
            fetchImages($car_id);
            break;

        case "removeImages":
            $car_id = (int)Input::get('carID');
            $carForAuth = new Car($car_id);
            if (!$carForAuth->data() || ($user->data()->id != $carForAuth->data()->user_id && !hasPerm([2, 3]))) {
                ApiResponse::forbidden('Unauthorized')
                    ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_ACCESS_DENIED, 'removeImages: unauthorized for car ' . $car_id)
                    ->send();
            }
            $file = basename((string)Input::get('file'));
            removeImage($car_id, $file);
            break;

        default:
            ApiResponse::error('No valid action', 400)
                ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Invalid action: ' . $action)
                ->send();
    }
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
        logger($user->data()->id, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Car Update Validation Error: ' . $e->getMessage());
        $errors[] = $e->getUserMessage();
    } catch (ElanRegistryException $e) {
        logger($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ERRORS, 'Car Update Error: ' . $e->getMessage());
        $errors[] = $e->getUserMessage();
    } catch (\Throwable $e) {
        logger($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ERRORS, 'Car Update Unexpected Error (' . get_class($e) . '): ' . $e->getMessage());
        $errors[] = 'Car Update failed due to an unexpected error.';
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
        logger($user->data()->id, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Car Creation Validation Error: ' . $e->getMessage());
        $errors[] = $e->getUserMessage();
    } catch (ElanRegistryException $e) {
        logger($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ERRORS, 'Car Creation Error: ' . $e->getMessage());
        $errors[] = $e->getUserMessage();
    } catch (\Throwable $e) {
        logger($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ERRORS, 'Car Creation Unexpected Error (' . get_class($e) . '): ' . $e->getMessage());
        $errors[] = 'Car Creation failed due to an unexpected error.';
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
        $carData = $car->data();
        if ($carData !== null) {
            foreach ($carData as $key => $value) {
                $cardetails[$key] = $value;
            }
        }
    } else {
        $ownerId = (int)$user->data()->id;
        $owner = new Owner($ownerId);
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

    $year = Input::raw('year');
    if ($year !== null && $year !== '') {
        $cardetails['year'] = $year;
        $successes[] = 'Year: ' . htmlspecialchars($year, ENT_QUOTES, 'UTF-8');
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

    $model = Input::raw('model');
    if ($model !== null && $model !== '') {
        $cardetails['model'] = $model;
        // model is a composite "series|variant|type" from a fixed dropdown — explode into columns
        list($series, $variant, $type) = explode('|', $cardetails['model']);
        $cardetails['series'] = $series;
        $cardetails['variant'] = $variant;
        $cardetails['type'] = $type;

        $successes[] = 'Model: ' . htmlspecialchars($model, ENT_QUOTES, 'UTF-8');
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
    $chassisOverrideRaw = Input::raw('chassis_override');
    $chassisOverride = ($chassisOverrideRaw === '1');

    $chassis = Input::raw('chassis');
    if ($chassis !== null && $chassis !== '') {
        $cardetails['chassis'] = $chassis;
        $year = (int)$cardetails['year'];
        $model = $cardetails['model']; // Contains series|variant|type format
        
        // Use centralized chassis validator
        try {
            $validator = new ChassisValidator();
            $result = $validator->validate($chassis, $year, $model, $chassisOverride);
        } catch (ElanRegistryException $e) {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'ChassisValidator ElanRegistryException for chassis "' . htmlspecialchars($chassis, ENT_QUOTES, 'UTF-8') . '": ' . $e->getMessage());
            $errors[] = 'Chassis validation error: ' . $e->getUserMessage();
            return;
        } catch (\Throwable $e) {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Unexpected ChassisValidator error for chassis "' . htmlspecialchars($chassis, ENT_QUOTES, 'UTF-8') . '": ' . $e->getMessage());
            $errors[] = 'An unexpected error occurred validating the chassis number. Please try again.';
            return;
        }
        
        // Handle validation result
        if ($result['valid']) {
            $cardetails['chassis_override'] = $result['override_used'] ? 1 : 0;
            $label = htmlspecialchars($cardetails['chassis'], ENT_QUOTES, 'UTF-8');
            $successes[] = 'Chassis: ' . $label . ($result['override_used'] ? ' (Override used)' : '');
            if ($result['override_used']) {
                $chassis_override_used = true; // Track that override was used for comments
            }
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
    $color = Input::raw('color');
    if ($color !== null && $color !== '') {
        $cardetails['color'] = $color;
        $successes[] = 'Color: ' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
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
    $engine = Input::raw('engine');
    if ($engine !== null && $engine !== '') {
        $cardetails['engine'] = str_replace(" ", "", strtoupper(trim($engine)));
        $successes[] = 'Engine: ' . htmlspecialchars($cardetails['engine'], ENT_QUOTES, 'UTF-8');
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
    global $errors;
    $raw = Input::raw('purchasedate');
    if ($raw !== null && $raw !== '') {
        $parsed = DateTime::createFromFormat('Y-m-d', $raw);
        if (!$parsed || $parsed->format('Y-m-d') !== $raw) {
            $errors[] = 'Invalid purchase date — use YYYY-MM-DD format with a real calendar date';
            return;
        }
        $cardetails['purchasedate'] = $raw;
        $successes[] = 'Purchase Date: ' . $raw;
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
    global $errors;
    $raw = Input::raw('solddate');
    if ($raw !== null && $raw !== '') {
        $parsed = DateTime::createFromFormat('Y-m-d', $raw);
        if (!$parsed || $parsed->format('Y-m-d') !== $raw) {
            $errors[] = 'Invalid sold date — use YYYY-MM-DD format with a real calendar date';
            return;
        }
        $cardetails['solddate'] = $raw;
        $successes[] = 'Sold Date: ' . $raw;
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
    global $errors;
    $website = Input::raw('website');
    if ($website !== null && $website !== '') {
        if (!filter_var($website, FILTER_VALIDATE_URL)) {
            $errors[] = 'Website URL must start with http:// or https:// (e.g. https://example.com)';
            return;
        }
        $scheme = strtolower((string) parse_url($website, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            $errors[] = 'Website URL must start with http:// or https://';
            return;
        }
        $cardetails['website'] = $website;
        $successes[] = 'Website: ' . htmlspecialchars($website, ENT_QUOTES, 'UTF-8');
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
    $comments = Input::raw('comments');
    if ($comments !== null && $comments !== '') {
        $cardetails['comments'] = $comments;
        $successes[] = 'Comments: Updated';
    } else {
        $cardetails['comments'] = null;
    }
    
    // If chassis override was used, add audit note once (skip if already present)
    if (isset($chassis_override_used) && $chassis_override_used === true
        && strpos((string) $cardetails['comments'], 'CHASSIS VALIDATION OVERRIDDEN:') === false
    ) {
        $overrideNote = 'CHASSIS VALIDATION OVERRIDDEN: ' . date('Y-m-d H:i:s');
        $cardetails['comments'] = $cardetails['comments']
            ? $cardetails['comments'] . "\n" . $overrideNote
            : $overrideNote;
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
    global $settings;

    // Image resize dimensions from settings
    $thumbnailSizesString = isset($settings->elan_image_thumbnail_sizes) && !empty($settings->elan_image_thumbnail_sizes)
        ? $settings->elan_image_thumbnail_sizes
        : '100,300,768,1024,2048'; // Default fallback
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
            throw new ImageProcessingException("Invalid car ID for file upload");
        }
        $filePath = $targetFilePath . $carId . '/';
    }

    // Ensure the path is within expected directory structure
    $realTargetPath = realpath($targetFilePath);
    $realFilePath = realpath(dirname($filePath));

    if ($realFilePath === false || strpos($realFilePath, $realTargetPath) !== 0) {
        throw new ImageProcessingException("Invalid upload path detected");
    }

    if (!is_dir($filePath)) {
        // Create directory with secure permissions (755)
        if (!mkdir($filePath, 0755, true)) {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_FILE_ERROR, "Failed to create upload directory: " . $filePath);
            throw new ImageProcessingException("Failed to create upload directory");
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
                            logger(
                                $user->data()->id,
                                LogCategories::LOG_CATEGORY_FILE_ERROR,
                                'Image resize failed for carId: ' . Input::get('car_id') .
                                    ' file: ' . $newFileName . ' size: ' . $size . ' error: ' . $e->getMessage()
                            );
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
                        LogCategories::LOG_CATEGORY_FILE_ERROR,
                        "ERROR: File upload failed for carId: " .
                            Input::get('car_id') . " File: " . $name . " Target: " . $newFileName
                    );
                }
            } catch (ElanRegistryException $e) {
                // Log security violation and reject file
                $errors[] = "File upload rejected: " . $e->getUserMessage();
                logger(
                    $user->data()->id,
                    LogCategories::LOG_CATEGORY_FILE_ERROR,
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
                ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'fetchImages: Invalid car ID')
                ->send();
        }

        $car = new Car($car_id);

        // Check if car exists
        if (!$car->exists()) {
            ApiResponse::notFound('Car not found')
                ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ERRORS, "fetchImages: Car not found: {$car_id}")
                ->send();
        }

        $images = $car->images();

        ApiResponse::success('Images retrieved successfully')
            ->withData('images', $images)
            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ACTIONS, "Images fetched for car: {$car_id}")
            ->send();

    } catch (ElanRegistryException $e) {
        ApiResponse::serverError('Failed to fetch images')
            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ERRORS, 'fetchImages error: ' . $e->getMessage())
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
    global $errors;
    global $user;

    $tempPath = $targetFilePath . 'temp' . '/';
    $filePath = $targetFilePath . $cardetails['id'] . '/';
    $userId   = isset($user) ? (int)$user->data()->id : 0;

    if (!is_dir($filePath)) {
        if (!mkdir($filePath, 0755, true)) {
            logger($userId, LogCategories::LOG_CATEGORY_FILE_ERROR, "mvTmpImages: failed to create directory: {$filePath}");
            $errors[] = 'Failed to create image directory for car ID ' . $cardetails['id'];
            return;
        }
    }

    // Images can be encoded as JSON or simple CSV
    $carImages = json_decode($cardetails['image']);
    if (is_null($carImages)) {
        $carImages = explode(',', $cardetails['image']);
    }

    foreach ($carImages as $carimage) {
        $tmpfile = pathinfo($carimage);

        foreach (glob($tempPath . $tmpfile['filename'] . '*' . $tmpfile['extension']) as $name) {
            $file = pathinfo($name);
            if (!rename($name, $filePath . $file['basename'])) {
                logger($userId, LogCategories::LOG_CATEGORY_FILE_ERROR, "mvTmpImages: failed to move {$name} to {$filePath}{$file['basename']}");
                $errors[] = "Failed to move image file: {$file['basename']}";
            }
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
                ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ERRORS, "removeImage: Car not found: {$carID}")
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
                    LogCategories::LOG_CATEGORY_CAR_ACTIONS,
                    "Image removed: carId: {$carID}, image: {$file}"
                )->send();
        } else {
            // Image not found in car's image list
            ApiResponse::error('Image not found', 404)
                ->withLogging(
                    $user->data()->id,
                    LogCategories::LOG_CATEGORY_CAR_ERRORS,
                    "removeImage: Image not found - carId: {$carID}, file: {$file}"
                )->send();
        }
    } catch (ElanRegistryException $e) {
        // Log error and return error response
        ApiResponse::serverError('Failed to remove image')
            ->withLogging(
                $user->data()->id,
                LogCategories::LOG_CATEGORY_CAR_ERRORS,
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
function arrayReplaceValue(array &$array, mixed $value, mixed $replacement): void
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
 * @throws ImageProcessingException If MIME type is not supported
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
        throw new ImageProcessingException("Unsupported file type: " . $mimeType);
    }

    return $allowedExtensions[$mimeType];
}

/**
 * Get MIME type of uploaded file with security validation
 *
 * @param string $file File path to analyze
 * @return string MIME type
 * @throws ImageProcessingException If unable to determine type or type is invalid
 */
function getMimeType(string $file): string
{
    // Secure MIME type detection with multiple validation layers
    $mimeType = false;

    // Primary method: Use finfo (most reliable)
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file);
    } elseif (function_exists('mime_content_type')) {
        $mimeType = mime_content_type($file);
    } else {
        throw new ImageProcessingException("Unable to determine file MIME type");
    }

    // Additional validation: Check if detected MIME type is in our allowlist
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mimeType, $allowedTypes, true)) {
        throw new ImageProcessingException("Invalid file type detected: " . $mimeType);
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
 * @throws ImageProcessingException If validation fails
 */
function validateFileUpload(array $file, int $maxSize = 5242880): bool // Default 5MB
{
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new ImageProcessingException("File upload error: " . $file['error']);
    }

    // Check file size (default 5MB limit)
    if ($file['size'] > $maxSize) {
        throw new ImageProcessingException("File too large. Maximum size: " . ($maxSize / 1024 / 1024) . "MB");
    }

    // Verify the file was actually uploaded via HTTP POST
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new ImageProcessingException("Invalid file upload");
    }

    // Additional security: Check for minimum file size (avoid empty files)
    if ($file['size'] < 100) {
        throw new ImageProcessingException("File too small - minimum 100 bytes required");
    }

    return true;
}
