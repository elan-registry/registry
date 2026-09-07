<?php
declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarImageProcessor;
use ElanRegistry\Car\CarValidator;
use ElanRegistry\ChassisValidator;
use ElanRegistry\Exceptions\CarConcurrentModificationException;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\Input;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;
use ElanRegistry\OwnerContactRefresher;
use ElanRegistry\Resize;
use ElanRegistry\UploadPathGuard;

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

$errors     = [];
$chassis_override_used = false; // Track if chassis validation override was used
$cardetails = [];

$targetFilePath = $abs_us_root . $us_url_root . ELAN_IMAGE_DIR;
$targetURL = $us_url_root . ELAN_IMAGE_DIR;

if ($method !== 'POST' || empty($_POST)) {
    ApiResponse::error('No data received', 400)->send();
}

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
            buildCarDetails($cardetails, $errors);
            buildImageDetails($cardetails);

            if (!empty($errors)) {
                ApiResponse::validationError(
                    ['general' => implode('; ', $errors)],
                    'Cannot add car: validation errors'
                )->withData('cardetails', $cardetails)
                ->withLogging(
                    $user->data()->id,
                    LogCategories::LOG_CATEGORY_VALIDATION_ERROR,
                    'Car creation validation failed: ' . json_encode($errors)
                )->send();
            }

            uploadImages($cardetails, $errors);

            if (!empty($errors)) {
                ApiResponse::validationError(
                    ['general' => implode('; ', $errors)],
                    'Cannot add car: image upload failed'
                )->withData('cardetails', $cardetails)
                ->withLogging(
                    $user->data()->id,
                    LogCategories::LOG_CATEGORY_FILE_ERROR,
                    'Car add aborted: image upload errors: ' . json_encode($errors)
                )->send();
            }

            addCar($cardetails, $errors);
            mvTmpImages($cardetails, $errors);

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
        } catch (\Throwable $e) {
            ApiResponse::serverError('Failed to add car: An unexpected error occurred.')
                ->withLogging(
                    $user->data()->id,
                    LogCategories::LOG_CATEGORY_CAR_ERRORS,
                    'Car add unexpected error: ' . $e->getMessage()
                )->send();
        }
        // Every code path above calls ->send() (return type: never) — no break needed.

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
            buildCarDetails($cardetails, $errors, $car_id);
            buildImageDetails($cardetails);

            if (!empty($errors)) {
                ApiResponse::validationError(
                    ['general' => implode('; ', $errors)],
                    'Cannot update car: validation errors'
                )->withData('cardetails', $cardetails)
                ->withLogging(
                    $user->data()->id,
                    LogCategories::LOG_CATEGORY_VALIDATION_ERROR,
                    'Car update validation failed: ' . json_encode($errors)
                )->send();
            }

            uploadImages($cardetails, $errors);

            if (!empty($errors)) {
                ApiResponse::validationError(
                    ['general' => implode('; ', $errors)],
                    'Cannot update car: image upload failed'
                )->withData('cardetails', $cardetails)
                ->withLogging(
                    $user->data()->id,
                    LogCategories::LOG_CATEGORY_FILE_ERROR,
                    'Car update aborted: image upload errors: ' . json_encode($errors)
                )->send();
            }

            updateCar($cardetails, $errors);

            if (!empty($errors)) {
                ApiResponse::validationError(
                    ['general' => implode('; ', $errors)],
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
        } catch (\Throwable $e) {
            ApiResponse::serverError('Failed to update car: An unexpected error occurred.')
                ->withLogging(
                    $user->data()->id,
                    LogCategories::LOG_CATEGORY_CAR_ERRORS,
                    'Car update unexpected error: ' . $e->getMessage()
                )->send();
        }
        // Every code path above calls ->send() (return type: never) — no break needed.

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
        // Use the read-path guard (isSafeFilename) here — consistent with
        // buildImageDetails() and decodeAndProcessImages(), which also use it
        // to handle legacy filenames stored in the DB. removeImage() performs
        // no filesystem deletion (DB JSON only), so the permissive guard is
        // correct and safe. basename() is defence-in-depth; isSafeFilename()
        // independently rejects traversal because '/' is not in [\w\-.].
        $file = basename((string)Input::raw('file'));
        if (!CarImageProcessor::isSafeFilename($file)) {
            ApiResponse::error('Invalid image filename')
                ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY, 'removeImages: invalid filename: ' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8'))
                ->send();
        }
        removeImage($car_id, $file);
        break;

    default:
        ApiResponse::error('No valid action', 400)
            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Invalid action: ' . $action)
            ->send();
}


/**
 * Update an existing car record
 *
 * @param array $cardetails Car data to update
 * @param array $errors     Errors array passed by reference — appended to on failure
 * @return void
 */
function updateCar(array &$cardetails, array &$errors): void
{
    global $user;

    try {
        $car = new Car();

        // The owner_last_updated freshness clock drives verification-email
        // eligibility, so it may only reset on a genuine owner self-edit.
        // Admins and editors reach this same endpoint when editing someone
        // else's car (see the hasPerm([2, 3]) branch in the updateCar auth
        // guard above), and those edits must leave the clock untouched.
        // user_id here is the car row's owner, loaded by buildCarDetails()
        // from the database — not a client-supplied value.
        $isOwnerInitiated = (int) ($cardetails['user_id'] ?? 0) === (int) $user->data()->id;

        $car->update($cardetails, $isOwnerInitiated);
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
 * @param array $errors     Errors array passed by reference — appended to on failure
 * @return void
 */
function addCar(array &$cardetails, array &$errors): void
{
    global $user;

    try {
        $car = new Car();

        $car->create($cardetails);
        $cardetails['id'] = $car->data()->id;
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
 * @param array    $cardetails Car details array to populate
 * @param array    $errors     Errors array passed by reference — appended to on failure
 * @param int|null $carId      Optional car ID for updates
 * @return void
 */
function buildCarDetails(array &$cardetails, array &$errors, ?int $carId = null): void
{
    global $user;
    global $db;

    // Get the combined user+profile
    if ($carId) {
        $car = new Car($carId);
        $carData = $car->data();
        if ($carData !== null) {
            foreach ($carData as $key => $value) {
                $cardetails[$key] = $value;
            }

            // Refresh the car's owner-contact columns from the owner's current
            // profile so edits to a stale car row don't perpetuate outdated
            // contact data. Must be constructed from the CAR's owner
            // (user_id, just loaded above from the DB row), never the
            // logged-in $user — an admin or editor may be editing someone
            // else's car, and using the session user would overwrite the
            // member's contact details with staff's own.
            //
            // Security invariant: no client input may reach $cardetails['user_id']
            // at any point before this line. That holds today because $cardetails
            // is initialized empty above (see the top of this function), the
            // caller never pre-populates it, and all Input::raw() consumers
            // (updateYear/updateModel/updateChassis/updateColor/updateEngine/
            // updatePurchasedate/updateSolddate/updateComments) run
            // AFTER this refresh, at the end of buildCarDetails(). Moving this
            // Owner construction below those input-processing calls, or having
            // the caller pre-populate $cardetails before calling buildCarDetails(),
            // would let a client-supplied user_id substitute another owner's
            // contact details onto this car — do not reorder.
            //
            // The merge itself lives in OwnerContactRefresher so it is reachable
            // from a test; this file cannot be require()'d (every path exits).
            $carOwner = new Owner((int) $cardetails['user_id']);
            $refresher = new OwnerContactRefresher();
            if ($refresher->hasLoadableOwner($carOwner)) {
                if (!$refresher->hasValidWebsite($carOwner)) {
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_OWNER_ERRORS,
                        'buildCarDetails: Car ID ' . $carId . ' owner ' . (int) $cardetails['user_id']
                        . ' has an invalid profile website; skipping website refresh on this car');
                }
                $cardetails = $refresher->refresh($cardetails, $carOwner);
            } else {
                $ownerId = $cardetails['user_id'] !== null ? (int) $cardetails['user_id'] : null;
                $ownerDescription = $ownerId === null
                    ? 'has no owner (user_id is null)'
                    : 'owner ' . $ownerId . ' could not be loaded';
                logger($user->data()->id, LogCategories::LOG_CATEGORY_OWNER_ERRORS,
                    'buildCarDetails: Car ID ' . $carId . ' ' . $ownerDescription . '; skipping owner-contact refresh');
            }
        } else {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ACTIONS,
                'buildCarDetails: Car ID ' . $carId . ' not found or failed to load for user_id=' . $user->data()->id);
            return;
        }
    } else {
        $ownerId = (int)$user->data()->id;
        $owner = new Owner($ownerId);
        $ownerData = $owner->data();

        /*  Add the User/profile information to the record. Routed through
            OwnerContactRefresher — the same class the edit branch uses — so
            an invalid profile website is skipped here too, rather than
            reaching Car::create() and throwing CarValidationException for a
            field this page no longer has an input for. user_id and
            join_date are outside ownerContactFields()'s scope and are
            assigned explicitly. */
        $refresher = new OwnerContactRefresher();
        if (!$refresher->hasValidWebsite($owner)) {
            logger($ownerId, LogCategories::LOG_CATEGORY_OWNER_ERRORS,
                'buildCarDetails: new car for owner ' . $ownerId
                . ' has an invalid profile website; skipping website on this car');
        }
        $cardetails = $refresher->refresh($cardetails, $owner);
        $cardetails['user_id']      = $ownerData->id;
        $cardetails['join_date']    = $ownerData->join_date;

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
        $cardetails['comments']     = null;
    }
    updateYear($cardetails, $errors);
    updateModel($cardetails, $errors);
    updateChassis($cardetails, $errors);
    updateColor($cardetails);
    updateEngine($cardetails);
    updatePurchasedate($cardetails, $errors);
    updateSolddate($cardetails, $errors);
    updateComments($cardetails);
}

/**
 * Update car year from form input
 *
 * @param array $cardetails Car details array to update
 * @param array $errors     Errors array passed by reference
 * @return void
 */
function updateYear(array &$cardetails, array &$errors): void
{
    $year = Input::raw('year');
    if ($year !== null && $year !== '') {
        $cardetails['year'] = $year;
    } else {
        $errors[] = "Please select Year";
    }
}

/**
 * Update car model information from form input
 *
 * @param array $cardetails Car details array to update
 * @param array $errors     Errors array passed by reference
 * @return void
 */
function updateModel(array &$cardetails, array &$errors): void
{
    global $user;

    $model = Input::raw('model');
    if ($model !== null && $model !== '') {
        $cardetails['model'] = $model;
        try {
            [$cardetails['series'], $cardetails['variant'], $cardetails['type']] = CarValidator::parseModel($cardetails['model']);
        } catch (CarValidationException $e) {
            $errors[] = 'Invalid model format — please select a model from the dropdown';
            logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'updateModel: invalid model string: "' . $model . '": ' . $e->getMessage());
            return;
        }
    } else {
        $errors[] = "Please select Model";
    }
}

/**
 * Update car chassis number from form input with centralized validation
 *
 * @param array $cardetails Car details array to update
 * @param array $errors     Errors array passed by reference
 * @return void
 */
function updateChassis(array &$cardetails, array &$errors): void
{
    global $chassis_override_used, $user;

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
    } else {
        $cardetails['engine'] = null;
    }
}

/**
 * Update car purchase date from form input
 *
 * @param array $cardetails Car details array to update
 * @param array $errors     Errors array passed by reference
 * @return void
 */
function updatePurchasedate(array &$cardetails, array &$errors): void
{
    $raw = Input::raw('purchasedate');
    if ($raw !== null && $raw !== '') {
        $parsed = DateTime::createFromFormat('Y-m-d', $raw);
        if (!$parsed || $parsed->format('Y-m-d') !== $raw) {
            $errors[] = 'Invalid purchase date — use YYYY-MM-DD format with a real calendar date';
            return;
        }
        $cardetails['purchasedate'] = $raw;
    } else {
        $cardetails['purchasedate'] = null;
    }
}

/**
 * Update car sold date from form input
 *
 * @param array $cardetails Car details array to update
 * @param array $errors     Errors array passed by reference
 * @return void
 */
function updateSolddate(array &$cardetails, array &$errors): void
{
    $raw = Input::raw('solddate');
    if ($raw !== null && $raw !== '') {
        $parsed = DateTime::createFromFormat('Y-m-d', $raw);
        if (!$parsed || $parsed->format('Y-m-d') !== $raw) {
            $errors[] = 'Invalid sold date — use YYYY-MM-DD format with a real calendar date';
            return;
        }
        $cardetails['solddate'] = $raw;
    } else {
        $cardetails['solddate'] = null;
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
    global $chassis_override_used;

    // Update 'comments'
    $comments = Input::raw('comments');
    if ($comments !== null && $comments !== '') {
        $cardetails['comments'] = $comments;
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
    global $user;

    $requestedOrder = array_values(array_filter(
        explode(',', Input::raw('filenames') ?? '')
    ));

    // Use the read-path guard (isSafeFilename) so legacy filenames already in the
    // DB survive a reorder-only submission intact. New browser-supplied filenames
    // (e.g. "my-photo.jpg") also pass this guard; uploadImages() replaces them
    // with secure server-side names before the final DB write.
    //
    // Double-write pattern: this value is the FINAL write when no new files are
    // uploaded (uploadImages() returns early on the 'blob' sentinel). When files
    // ARE uploaded, uploadImages() unconditionally overwrites $cardetails['image']
    // with its own isValidFilename()-filtered list before returning.
    $safeOrder = [];
    $invalid   = [];
    foreach ($requestedOrder as $filename) {
        if (CarImageProcessor::isSafeFilename($filename)) {
            $safeOrder[] = $filename;
        } else {
            $invalid[] = $filename;
        }
    }

    if (!empty($invalid)) {
        $userId = isset($user) ? (int) $user->data()->id : 0;
        logger($userId, LogCategories::LOG_CATEGORY_SECURITY,
            'buildImageDetails: filtering unsafe filename(s): '
            . htmlspecialchars(implode(', ', $invalid), ENT_QUOTES, 'UTF-8'));
    }

    $cardetails['image'] = json_encode($safeOrder);
}

function uploadImages(array &$cardetails, array &$errors): void
{
    global $targetFilePath;
    global $user;

    // Image resize dimensions
    $thumbnailSizes = explode(',', ELAN_IMAGE_THUMBNAIL_SIZES);
    $imageSizes = array_map('intval', array_map('trim', $thumbnailSizes));


    // Do I have any new files?
    if (!isset($_FILES['file']['name'][0]) || $_FILES['file']['name'][0] == 'blob') {
        if (empty($cardetails['id'])) {
            // New car with no uploaded files: clear any phantom filenames that
            // buildImageDetails() may have written from the filenames POST param.
            // For updateCar, the filenames represent the existing image order and
            // must be preserved, so this branch only runs for addCar.
            $cardetails['image'] = json_encode([]);
        }
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

    // Ensure the upload destination resolves inside the configured upload
    // directory — a path that resolves elsewhere (traversal, or a sibling
    // directory sharing the same prefix) must never be written to.
    if (!UploadPathGuard::isWithinTarget($targetFilePath, $filePath)) {
        logger($user->data()->id, LogCategories::LOG_CATEGORY_FILE_ERROR,
            'uploadImages: path guard failed — realpath() returned false or traversal detected'
            . ' (targetFilePath=' . htmlspecialchars($targetFilePath, ENT_QUOTES, 'UTF-8')
            . ', filePath=' . htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8') . ')');
        throw new ImageProcessingException("Invalid upload path detected");
    }

    if (!is_dir($filePath)) {
        // Create directory with secure permissions (755)
        if (!mkdir($filePath, 0755, true)) {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_FILE_ERROR, "Failed to create upload directory: " . $filePath);
            throw new ImageProcessingException("Failed to create upload directory");
        }
    }

    $requestedOrder = array_values(array_filter(explode(',', Input::raw('filenames') ?? '')));

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
                CarImageProcessor::validateFileUpload($fileInfo);

                // Get and validate MIME type
                $mimeType = CarImageProcessor::getMimeType($tempFile);
                $extension = CarImageProcessor::getExtension($mimeType);
                
                // Generate cryptographically secure filename
                $newFileName = CarImageProcessor::generateSecureFilename($extension);

                if (move_uploaded_file($tempFile, $filePath . $newFileName)) {
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
                        } catch (\Throwable $e) {
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
                    
                    if (!$resizeSuccess) {
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
    $requestedOrder = array_values(array_filter(
        $requestedOrder,
        static fn(string $f) => CarImageProcessor::isValidFilename($f)
    ));
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
    } catch (\Throwable $e) {
        ApiResponse::serverError('Failed to fetch images')
            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ERRORS,
                'fetchImages unexpected error [' . get_class($e) . ']: ' . $e->getMessage())
            ->send();
    }
}

/**
 * Move temporary images to permanent car directory
 *
 * Only called from the addCar path (after buildImageDetails() and
 * uploadImages() have both run), so every filename in $cardetails['image']
 * has already been through uploadImages()'s isValidFilename() filter (or, for
 * a no-upload addCar, cleared to an empty list) — the legacy-format-skip
 * branch inside the loop below is therefore not currently reachable, but is
 * kept as a defensive no-op skip. See the inline comment on that branch.
 *
 * Per file (base filename), moving from temp storage can touch multiple
 * files on disk: the original upload plus every resized thumbnail variant.
 * $unmovedFilenames only tracks whether the BASE file failed to move — see
 * the inline comment above the inner loop for why.
 *
 * @param array $cardetails Car details containing ID and image info
 * @param array $errors     Errors array passed by reference — appended to on failure
 * @return void
 */
function mvTmpImages(array &$cardetails, array &$errors): void
{
    global $targetFilePath;
    global $user;

    $tempPath = $targetFilePath . 'temp' . '/';
    $filePath = $targetFilePath . $cardetails['id'] . '/';
    $userId   = isset($user) ? (int)$user->data()->id : 0;

    // Images can be encoded as JSON or simple CSV
    $carImages = json_decode($cardetails['image']);
    if (is_null($carImages)) {
        $carImages = explode(',', $cardetails['image']);
    }

    $unmovedFilenames = [];

    if (!is_dir($filePath)) {
        if (!mkdir($filePath, 0755, true)) {
            logger($userId, LogCategories::LOG_CATEGORY_FILE_ERROR, "mvTmpImages: failed to create directory: {$filePath}");
            $errors[] = 'Failed to create image directory for car ID ' . $cardetails['id'];
            $unmovedFilenames = array_map('strval', $carImages);
            $carImages = [];
        }
    }

    foreach ($carImages as $carimage) {
        if (!CarImageProcessor::isValidFilename((string) $carimage)) {
            // NOTE: not currently reachable via addCar — see the trace in this
            // function's docblock. Kept as a defensive skip in case a future
            // caller reaches mvTmpImages() with a legacy-format filename: no
            // temp file would exist for it, so the continue is a safe no-op.
            logger($userId, LogCategories::LOG_CATEGORY_CAR_ACTIONS,
                'mvTmpImages: skipping legacy-format filename (no temp file to move): '
                . htmlspecialchars((string) $carimage, ENT_QUOTES, 'UTF-8'));
            $unmovedFilenames[] = (string) $carimage;
            continue;
        }
        $tmpfile = pathinfo($carimage);

        // Each base filename's glob matches BOTH the original upload and every
        // "{basename}-resized-{size}.{ext}" thumbnail variant created by
        // uploadImages() (see ELAN_IMAGE_THUMBNAIL_SIZES usage above). Only the
        // base (non-resized) file's presence/move-success determines whether the
        // image displays at all (CarImageProcessor::decodeAndProcessImages()
        // checks is_file() on the base filename only) — a resized variant is
        // purely derived/optional. So $unmovedFilenames, which drives the
        // compensating Car::removeImages() cleanup below, must only receive
        // $carimage when the BASE file itself failed to move or was never found;
        // a failed variant move is still logged/reported via $errors[] but must
        // not strip the base filename from cars.image.
        $baseMoved = false;
        $baseFound = false;

        foreach (glob($tempPath . $tmpfile['filename'] . '*' . $tmpfile['extension']) as $name) {
            $file = pathinfo($name);
            $isBase = !CarImageProcessor::isResizedVariant($file['basename']);

            if ($isBase) {
                $baseFound = true;
            }

            if (!rename($name, $filePath . $file['basename'])) {
                logger($userId, LogCategories::LOG_CATEGORY_FILE_ERROR, "mvTmpImages: failed to move {$name} to {$filePath}{$file['basename']}");
                $errors[] = "Failed to move image file: {$file['basename']}";
            } elseif ($isBase) {
                $baseMoved = true;
            }
        }

        if (!$baseFound || !$baseMoved) {
            $unmovedFilenames[] = (string) $carimage;
        }
    }

    if (!empty($unmovedFilenames)) {
        try {
            $car = new Car((int) $cardetails['id']);
            $result = $car->removeImages($unmovedFilenames);
            if ($result['updated']) {
                $cardetails['image'] = $car->data()->image;
            } elseif ($result['casConflict']) {
                logger($userId, LogCategories::LOG_CATEGORY_FILE_ERROR,
                    "mvTmpImages: CAS conflict cleaning up unmoved images for car ID {$cardetails['id']}");
                $errors[] = 'Failed to clean up unmoved image references for car ID ' . $cardetails['id'];
            } else {
                // Unexpected: none of $unmovedFilenames matched the car's stored image
                // list, even though they were just decoded from that same field above.
                // Should not happen in correct operation — surface it rather than let a
                // real data-consistency bug pass silently.
                logger($userId, LogCategories::LOG_CATEGORY_FILE_ERROR,
                    "mvTmpImages: removeImages() no-op for car ID {$cardetails['id']} — "
                    . 'none of the unmoved filenames were found in the stored image list: '
                    . implode(', ', $unmovedFilenames));
                $errors[] = 'Failed to clean up unmoved image references for car ID ' . $cardetails['id'];
            }
        } catch (\Throwable $e) {
            // Cleanup is best-effort: the car row is already committed by this point
            // (addCar() ran before mvTmpImages()), so a DB error here must not be
            // allowed to look like car creation itself failed. Log and fall through —
            // $errors[] from the move failure above already drives the caller's
            // "images could not be moved" response.
            logger($userId, LogCategories::LOG_CATEGORY_FILE_ERROR,
                'mvTmpImages: unexpected error cleaning up unmoved images for car ID '
                . $cardetails['id'] . ' [' . get_class($e) . ']: ' . $e->getMessage());
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
    } catch (CarConcurrentModificationException $e) {
        ApiResponse::error('Image list changed — please refresh and try again.')
            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ERRORS,
                "removeImage CAS conflict: carId={$carID}")
            ->send();
    } catch (CarDatabaseException $e) {
        ApiResponse::serverError('Failed to remove image')
            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ERRORS,
                "removeImage DB error: carId={$carID}, error: " . $e->getMessage())
            ->send();
    } catch (ElanRegistryException $e) {
        ApiResponse::serverError('Failed to remove image')
            ->withLogging(
                $user->data()->id,
                LogCategories::LOG_CATEGORY_CAR_ERRORS,
                "removeImage error: carId: {$carID}, error: " . $e->getMessage()
            )->send();
    } catch (\Throwable $e) {
        ApiResponse::serverError('Failed to remove image')
            ->withLogging(
                $user->data()->id,
                LogCategories::LOG_CATEGORY_CAR_ERRORS,
                'removeImage unexpected error [' . get_class($e) . "]: carId: {$carID}, error: " . $e->getMessage()
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

