<?php

declare(strict_types=1);

/**
 *
 *  Car is a class for managing Car data
 *
 *  Car is a class that is used for creating, updating and retrieving information
 * about a Car for the Lotus Elan Registry
 *
 *  @author Jim Boone
 *  @version $Revision: 0.1 $
 *  @access public
 */

class Car
{
    private const CHASSIS_SUFFIX_LENGTH = 5;
    
    private $_db;
    private $_data;
    private $_history;
    private $_images;
    private $_factory;
    private $_owner;
    private $tableName = 'cars';
    private $historyTableName = 'cars_hist';
    private $imageDir = '';

    /**
     * Instantiates the Car object.
     *
     * @param int|null $id Optional Car ID. If given, the information for Car will be populated.
     * @return void
     */
    public function __construct(?int $id = null)
    {
        global $user; // Get the logged in user

        $this->_db = DB::getInstance();

        // Get global settings from plugin with fallback
        if (function_exists('getSettings')) {
            $settings = getSettings();
        } else {
            // Fallback: Query settings table directly if plugin not available
            $settingsQuery = $this->_db->query('SELECT * FROM settings WHERE id = ?', [1]);
            $settings = $settingsQuery->count() > 0 ? $settingsQuery->first() : null;
        }

        // Get the logged in user information
        if (isset($user) && $user->isLoggedIn()) {
            $this->_owner = $user->data(); // TODO this should be from the user/profile JOIN
        }

        if ($id && $settings) {
            $this->imageDir = $settings->elan_image_dir  . $id . '/';
            $this->find($id);
        }
    }

    /**
     * Creates a Car in the Database
     *
     * @param array $fields Key value pairs for car data
     * @return bool True if car is created
     * @throws Exception If validation fails or database operation fails
     */
    public function create(array $fields = []): bool
    {
        $settings = getSettings();  // Get global settings from plugin

        if (empty($fields)) {
            throw new CarCreationException('No data provided for car creation');
        }
        
        // CSRF Protection
        if (!isset($fields['token']) || !Token::check($fields['token'])) {
            throw new CarCreationException('Invalid CSRF token provided');
        }
        
        // Remove token from fields array after validation (token should not be stored in database)
        unset($fields['token']);
        
        // Validate required fields
        $this->validateRequiredFields($fields, ['chassis', 'model', 'year']);
        
        // Validate and sanitize individual fields
        $fields = $this->validateAndSanitizeFields($fields);

        $fields['ctime'] = date('Y-m-d G:i:s');
        if (!empty($fields['images'])) {
            try {
                $fields['image'] = json_encode($fields['images']);
                if ($fields['image'] === false) {
                    throw new ImageProcessingException('Failed to encode images as JSON');
                }
                unset($fields['images']);
            } catch (Exception $e) {
                logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_FILE_ERROR, "Car class: Image encoding error during create: " . $e->getMessage());
                throw new ImageProcessingException('Error processing car images: ' . $e->getMessage());
            }
        }

        if (!$this->_db->insert($this->tableName, $fields)) {
            logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Car creation failed: ' . $this->_db->errorString());
            throw new CarCreationException('Database error during car creation: ' . $this->_db->errorString());
        } else {
            $id = $this->_db->lastId();
            $this->find($id);  // Populate the car with the data
            $this->imageDir = $settings->elan_image_dir  . $id . '/';
            $this->_db->insert('car_user', array('userid' => $this->data()->user_id, 'car_id' => $id));
            return true;
        }
    }
    /**
     * Update an existing car record
     *
     * @param array $fields Car data to update
     * @return bool True if update succeeds
     * @throws Exception If validation fails or database operation fails
     */
    public function update(array $fields = []): bool
    {
        if (empty($fields) || !isset($fields['id'])) {
            logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Car update failed: No data or ID provided');
            throw new CarValidationException('No data or ID provided for car update');
        }
        
        // CSRF Protection
        if (!isset($fields['token']) || !Token::check($fields['token'])) {
            logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Car update failed: Invalid CSRF token');
            throw new CarValidationException('Invalid CSRF token provided');
        }
        
        // Remove token from fields array after validation (token should not be stored in database)
        unset($fields['token']);
        
        if (!is_numeric($fields['id']) || $fields['id'] <= 0) {
            throw new CarValidationException('Invalid car ID provided for update');
        }
        
        // Validate and sanitize fields (excluding id which is already validated)
        $fieldsToValidate = $fields;
        unset($fieldsToValidate['id']);
        if (!empty($fieldsToValidate)) {
            $validatedFields = $this->validateAndSanitizeFields($fieldsToValidate, false);
            $fields = array_merge(['id' => $fields['id']], $validatedFields);
        }

        $fields['mtime'] = date('Y-m-d G:i:s');
        if (!empty($fields['images'])) {
            try {
                $fields['image'] = json_encode($fields['images']);
                if ($fields['image'] === false) {
                    throw new ImageProcessingException('Failed to encode images as JSON');
                }
                unset($fields['images']);
            } catch (Exception $e) {
                logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_FILE_ERROR, "Car class: Image encoding error during update: " . $e->getMessage());
                throw new ImageProcessingException('Error processing car images: ' . $e->getMessage());
            }
        }

        // Filter fields to only include valid cars table columns
        $validCarFields = [
            'id', 'user_id', 'year', 'model', 'series', 'variant', 'type',
            'chassis', 'color', 'engine', 'purchasedate', 'solddate',
            'website', 'comments', 'image', 'mtime',
            // Owner information fields
            'email', 'fname', 'lname', 'join_date', 'city', 'state', 'country', 'lat', 'lon'
        ];
        
        $filteredFields = array_intersect_key($fields, array_flip($validCarFields));
        
        // Extract ID for update method and remove from fields array
        $carId = $filteredFields['id'];
        unset($filteredFields['id']);
        
        // Remove empty fields that might cause issues with UserSpice
        $filteredFields = array_filter($filteredFields, function($value) {
            return $value !== '' && $value !== null;
        });
        
        $updateResult = $this->_db->update($this->tableName, $carId, $filteredFields);
        
        // Log database errors for debugging
        if ($this->_db->error()) {
            logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_CAR_UPDATE, 'DB error string: ' . $this->_db->errorString());
        }
        
        // Check if there was an actual database error vs UserSpice returning false for "no changes"
        if (!$updateResult && $this->_db->error()) {
            logger($fields['user_id'] ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'Car update failed: ' . $this->_db->errorString());
            throw new CarValidationException('Database update failed - check logs for details');
        } else {
            // UserSpice returned false but no error means "no changes needed" - treat as success
            $this->find($carId);  // Populate the car with the data
        }

        return true;
    }
    /**
     * Find car by ID or return all cars
     *
     * @param int|null $carID Car ID to find
     * @return bool True if found, false otherwise
     */
    public function find(?int $carID = null): bool
    {
        global $us_url_root;
        global $abs_us_root;
        

        if (is_null($carID)) {
            return $this->findAll();
        }

        $data = $this->_db->get($this->tableName, ['id', '=', $carID]);

        if ($data->count() === 0) {
            return false;
        }

        $this->_data = $data->first();
        // Get the car images
        // Turn images into array
        // Images can be encoded as JSON or simple CSV
        $carImages = null;
        
        if (!is_null($this->_data->image) && !empty($this->_data->image)) {
            $carImages = json_decode($this->_data->image);
            
            if (is_null($carImages)) {
                $carImages = explode(',', $this->_data->image);
            }
        } else {
            $carImages = [];
        }

        $images = [];
        foreach ($carImages as $key => $carimage) {
            $temp = pathinfo($abs_us_root . $us_url_root . $this->imageDir . $carImages[$key]);
            $file = $temp['dirname'] . "/" . $temp['basename'];
            if (is_file($file)) {
                // Do not include name if file does not exist
                $images[$key] = $temp;
                $images[$key]['path'] = $us_url_root . $this->imageDir . $images[$key]['basename'];
                $images[$key]['size'] = filesize($file);
                
                // Safely get image type and MIME type with comprehensive error handling
                try {
                    $imageType = @exif_imagetype($file);
                    if ($imageType !== false) {
                        $images[$key]['type'] = image_type_to_extension($imageType, false);
                    } else {
                        $images[$key]['type'] = 'unknown';
                        logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, "Car class: Unable to determine image type for file: {$file}");
                    }
                } catch (Exception $e) {
                    $images[$key]['type'] = 'unknown';
                    logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, "Car class: Exception getting image type for {$file}: " . $e->getMessage());
                }
                
                try {
                    $mimeType = @mime_content_type($file);
                    if ($mimeType !== false) {
                        $images[$key]['mime'] = $mimeType;
                    } else {
                        $images[$key]['mime'] = 'application/octet-stream';
                        logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, "Car class: Unable to determine MIME type for file: {$file}");
                    }
                } catch (Exception $e) {
                    $images[$key]['mime'] = 'application/octet-stream';
                    logger(0, LogCategories::LOG_CATEGORY_FILE_ERROR, "Car class: Exception getting MIME type for {$file}: " . $e->getMessage());
                }
            }
        }
        $this->_images =  array_values($images);  // Reindex in case a file didn't exist

        // Get the car history
        $data = $this->_db->query("SELECT * from $this->historyTableName WHERE car_id = ? ORDER BY timestamp DESC", [$carID]);
        if ($data->count()) {
            $this->_history = $data->results();
        } else {
            $this->_history = null;
        }

        // Search in the elan_factory_info for details on the car.
        // The car.chassis can either match exactly (car.chassis = elan_factory_info.serial )
        //    or
        // The right most 5 digits of the car.chassis (post 1970 and some 1969) will =  elan_factory_info.serial

        $search = [];
        if (!is_null($this->_data->chassis) && !empty($this->_data->chassis)) {
            $search = array($this->_data->chassis, substr($this->_data->chassis, -self::CHASSIS_SUFFIX_LENGTH));
        }

        $factory = null;
        foreach ($search as $serialNumber) {
            $factory = $this->_db->query('SELECT * FROM elan_factory_info WHERE serial = ? ', [$serialNumber]);
            // Did it return anything?
            if ($factory->count()) {
                if (!is_null($factory->first()->suffix) && $factory->first()->suffix !== "") {
                    $factory->first()->suffix = $factory->first()->suffix .
                        " (" . $this->suffixtotext($factory->first()->suffix) . ")";
                }
                $this->_factory = $factory->first();
                break; // Found a match, no need to continue
            } else {
                $this->_factory = null;
            }
        }

        // Get the car owner
        // Owner Information is copied from the _data section for now but could be retrieved from DB
        $this->_owner = [
            'user_id'   => $this->_data->user_id,
            'email'     => $this->_data->email,
            'fname'     => $this->_data->fname,
            'lname'     => $this->_data->lname,
            'join_date' => $this->_data->join_date,
            'city'      => $this->_data->city,
            'state'     => $this->_data->state,
            'country'   => $this->_data->country,
            'lat'       => $this->_data->lat,
            'lon'       => $this->_data->lon
        ];

        return true;
    }
    /**
     * Find all cars
     *
     * @return bool Always returns true
     */
    public function findAll(): bool
    {
        $this->_data = $this->_db->findAll($this->tableName)->results();

        return true;
    }
    /**
     * Check if car data exists
     *
     * @return bool True if car data exists
     */
    public function exists(): bool
    {
        return (!empty($this->_data)) ? true : false;
    }
    /**
     * Get car data
     *
     * @return mixed Car data object or array
     */
    public function data(): mixed
    {
        return $this->_data;
    }
    /**
     * Get car history
     *
     * @return array|null Car history array or null
     */
    public function history(): ?array
    {
        return $this->_history;
    }
    /**
     * Get factory information for this car
     *
     * @return object|null Factory data object or null
     */
    public function factory(): ?object
    {
        return $this->_factory;
    }
    /**
     * Get car images
     *
     * @return array Array of image information
     */
    public function images(): array
    {
        return $this->_images ?? [];
    }

    /**
     * Remove an image from the car's image list
     *
     * Replaces direct database access with proper Car class method for image removal.
     * Uses JSON format for image storage and maintains data validation.
     *
     * @param string $filename Image filename to remove
     * @return bool True if image was removed successfully, false otherwise
     * @throws Exception If validation fails or database operation fails
     * 
     * @see https://github.com/unibrain1/elanregistry/issues/247 Issue #247: Fix removeImage() direct database access
     */
    public function removeImage(string $filename): bool
    {
        // Validate input
        if (empty($filename)) {
            throw new ImageProcessingException(CarErrorMessages::getMessage('image_filename_empty'));
        }

        // Ensure car exists
        if (!$this->exists()) {
            throw new CarNotFoundException(CarErrorMessages::getMessage('car_not_found'));
        }

        // Get current images
        $currentImages = [];
        if (!is_null($this->_data->image) && !empty($this->_data->image)) {
            $imageData = json_decode($this->_data->image);
            if ($imageData !== null) {
                $currentImages = is_array($imageData) ? $imageData : [$imageData];
            } else {
                // Fallback to CSV format for backward compatibility
                $currentImages = explode(',', $this->_data->image);
            }
        }

        // Find and remove the image
        $imageIndex = array_search($filename, $currentImages, true);
        if ($imageIndex === false) {
            // Image not found - this is not an error condition, return false
            return false;
        }

        // Remove the image from array
        unset($currentImages[$imageIndex]);
        
        // Reindex array to prevent gaps
        $currentImages = array_values($currentImages);

        // Update database with JSON format
        $imageJson = empty($currentImages) ? '' : json_encode($currentImages);
        if ($imageJson === false && !empty($currentImages)) {
            throw new ImageProcessingException(CarErrorMessages::getMessage('image_encoding_failed'));
        }

        try {
            $updateSuccess = $this->_db->update('cars', $this->_data->id, ['image' => $imageJson]);
            
            if ($updateSuccess) {
                // Update local data to reflect the change
                $this->_data->image = $imageJson;
                
                // Clear cached images to force reload
                $this->_images = null;
                
                return true;
            } else {
                throw new Exception(CarErrorMessages::getAdminMessage('database_update_failed'));
            }
        } catch (Exception $e) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('image_remove_failed', ['error' => $e->getMessage()]);
            logger(0, LogCategories::LOG_CATEGORY_CAR_ACTIONS, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('image_remove_failed'));
        }
    }

    /**
     * Delete the car and all associated records
     * 
     * Replaces direct database access in car management operations with proper 
     * Car class method. Includes transaction support and comprehensive audit trails.
     * 
     * @param string $reason Reason for deletion (for audit trail)
     * @return bool True if deletion was successful, false otherwise
     * @throws Exception If validation fails or database operation fails
     * 
     * @see https://github.com/unibrain1/elanregistry/issues/248 Issue #248: Replace direct DB access in car management
     */
    public function delete(string $reason = 'Administrative deletion', ?string $token = null): bool
    {
        global $user;
        
        // CSRF Protection - Check token if provided
        if ($token !== null && !Token::check($token)) {
            throw new CarDeletionException(CarErrorMessages::getMessage('csrf_token_invalid', 'admin', ['operation' => 'car deletion']));
        }
        
        // Ensure car exists
        if (!$this->exists()) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('car_not_found_delete', ['id' => 'unknown']);
            logger(0, LogCategories::LOG_CATEGORY_CAR_DELETION, $technicalMsg);
            throw new CarNotFoundException(CarErrorMessages::getMessage('car_not_found_delete'));
        }

        // Validate we have a valid user for audit purposes
        if (!isset($user) || !$user->isLoggedIn()) {
            throw new CarDeletionException(CarErrorMessages::getMessage('user_auth_required', 'admin', ['operation' => 'car deletion']));
        }

        $carId = $this->_data->id;
        $chassis = $this->_data->chassis ?? 'Unknown';

        try {
            // Start transaction for data integrity
            $this->_db->query("START TRANSACTION");

            // Create audit trail entry before deletion
            $historyFields = [
                'operation' => 'DELETE',
                'car_id' => $carId,
                'comments' => "Car ID $carId ($chassis) permanently deleted by admin " . $user->data()->id . ". Reason: $reason",
                'ctime' => $this->_data->ctime ?? date('Y-m-d G:i:s'),
                'mtime' => date('Y-m-d G:i:s'),
                'model' => $this->_data->model ?? '',
                'series' => $this->_data->series ?? '',
                'variant' => $this->_data->variant ?? '',
                'year' => $this->_data->year ?? '',
                'type' => $this->_data->type ?? '',
                'chassis' => $this->_data->chassis ?? '',
                'color' => $this->_data->color ?? '',
                'engine' => $this->_data->engine ?? '',
                'purchasedate' => $this->_data->purchasedate ?? null,
                'solddate' => $this->_data->solddate ?? null,
                'image' => $this->_data->image ?? ''
            ];
            
            $historyInserted = $this->_db->insert('cars_hist', $historyFields);
            if (!$historyInserted) {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('audit_trail_failed', ['operation' => 'car deletion']);
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_DELETION, $technicalMsg);
                throw new Exception(CarErrorMessages::getAdminMessage('audit_trail_failed', ['operation' => 'car deletion']));
            }

            // Remove car-user relationships
            $carUserDeleted = $this->_db->query("DELETE FROM car_user WHERE car_id = ?", [$carId]);
            if ($this->_db->error()) {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('car_relationship_failed', ['error' => $this->_db->errorString()]);
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_DELETION, $technicalMsg);
                throw new Exception(CarErrorMessages::getAdminMessage('car_relationship_failed'));
            }

            // Remove the car record itself
            $carDeleted = $this->_db->query("DELETE FROM cars WHERE id = ?", [$carId]);
            if ($this->_db->error()) {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => $this->_db->errorString()]);
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_DELETION, $technicalMsg);
                throw new Exception(CarErrorMessages::getAdminMessage('database_update_failed'));
            }

            // Commit transaction
            $this->_db->query("COMMIT");
            
            // Clear local data since car no longer exists
            $this->_data = null;
            $this->_images = null;
            $this->_factory = null;
            $this->_owner = null;

            return true;
            
        } catch (Exception $e) {
            // Rollback on any error
            $this->_db->query("ROLLBACK");
            $technicalMsg = CarErrorMessages::getTechnicalMessage('operation_failed', ['operation' => 'Car deletion', 'error' => $e->getMessage()]);
            logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_DELETION, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('operation_failed', 'admin'));
        }
    }

    /**
     * Transfer car ownership to a different user
     * 
     * Replaces direct database access in car management operations with proper 
     * Car class method. Includes validation, transaction support, and audit trails.
     * 
     * @param int $newUserId The user ID to transfer ownership to
     * @param string $reason Reason for transfer (for audit trail)
     * @return bool True if transfer was successful, false otherwise
     * @throws Exception If validation fails or database operation fails
     * 
     * @see https://github.com/unibrain1/elanregistry/issues/248 Issue #248: Replace direct DB access in car management
     */
    public function transfer(int $newUserId, string $reason = 'Administrative transfer', string $operationType = 'NEWOWNER'): bool
    {
        global $user;
        
        // Ensure car exists
        if (!$this->exists()) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('car_not_found_transfer', ['id' => 'unknown']);
            logger(0, LogCategories::LOG_CATEGORY_CAR_TRANSFER, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('car_not_found_transfer'));
        }

        // Validate we have a valid user for audit purposes
        if (!isset($user) || !$user->isLoggedIn()) {
            throw new CarTransferException(CarErrorMessages::getMessage('user_auth_required', 'admin', ['operation' => 'car transfer']));
        }

        // Get complete user data with profile information
        $targetUser = getUserWithProfile($newUserId);
        if (!$targetUser) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('user_not_found', ['user_id' => $newUserId]);
            logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_TRANSFER, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('user_not_found'));
        }

        $carId = $this->_data->id;
        $chassis = $this->_data->chassis ?? 'Unknown';

        try {
            // Start transaction for data integrity
            $this->_db->query("START TRANSACTION");

            // Prepare fields with new owner information including profile data
            $updateFields = [
                'id' => $carId,
                'token' => Token::generate(), // Generate CSRF token for internal update
                'user_id' => $targetUser->id,
                'email' => $targetUser->email ?? '',
                'fname' => $targetUser->fname ?? '',
                'lname' => $targetUser->lname ?? '',
                'join_date' => $targetUser->join_date ?? date('Y-m-d G:i:s'),
                'city' => $targetUser->city ?? '',
                'state' => $targetUser->state ?? '',
                'country' => $targetUser->country ?? '',
                'lat' => $targetUser->lat ?? null,
                'lon' => $targetUser->lon ?? null,
                'website' => $targetUser->website ?? ''
            ];

            // Use Car class update method to maintain validation and consistency
            $updateSuccess = $this->update($updateFields);
            if (!$updateSuccess) {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => 'Car update method returned false']);
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_TRANSFER, $technicalMsg);
                throw new Exception(CarErrorMessages::getAdminMessage('database_update_failed'));
            }

            // Update the car_user relationship table
            $relationshipUpdated = $this->_db->query("UPDATE car_user SET userid = ? WHERE car_id = ?", [$newUserId, $carId]);
            if ($this->_db->error()) {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('car_relationship_failed', ['error' => $this->_db->errorString()]);
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_TRANSFER, $technicalMsg);
                throw new Exception(CarErrorMessages::getAdminMessage('car_relationship_failed'));
            }

            // Note: History is automatically logged by database trigger on cars UPDATE

            // Commit transaction
            $this->_db->query("COMMIT");

            // Refresh local data to reflect the changes
            $this->find($carId);

            // Create specific operation history record (NEWOWNER for reassign, TRANSFER for approval)
            // This is done AFTER commit and refresh to ensure we have the updated car data
            $ownerName = $targetUser->fname && $targetUser->lname
                ? "{$targetUser->fname} {$targetUser->lname}"
                : "User ID $newUserId";

            $historyFields = [
                'operation' => $operationType,
                'car_id' => $carId,
                'comments' => $reason,
                'ctime' => $this->_data->ctime ?? date('Y-m-d G:i:s'), // Use original car creation time
                'mtime' => date('Y-m-d G:i:s'), // Current modification time
                'model' => $this->_data->model ?? '',
                'series' => $this->_data->series ?? '',
                'variant' => $this->_data->variant ?? '',
                'year' => $this->_data->year ?? '',
                'type' => $this->_data->type ?? '',
                'chassis' => $this->_data->chassis ?? '',
                'color' => $this->_data->color ?? '',
                'engine' => $this->_data->engine ?? '',
                'purchasedate' => $this->_data->purchasedate ?? null,
                'solddate' => $this->_data->solddate ?? null,
                'image' => $this->_data->image ?? '',
                'user_id' => $targetUser->id,
                'email' => $targetUser->email ?? '',
                'fname' => $targetUser->fname ?? '',
                'lname' => $targetUser->lname ?? '',
                'join_date' => $targetUser->join_date ?? null,
                'city' => $targetUser->city ?? '',
                'state' => $targetUser->state ?? '',
                'country' => $targetUser->country ?? '',
                'lat' => $targetUser->lat ?? null,
                'lon' => $targetUser->lon ?? null,
                'website' => $targetUser->website ?? ''
            ];

            $historyInserted = $this->_db->insert('cars_hist', $historyFields);
            if (!$historyInserted) {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('audit_trail_failed', ['operation' => $operationType]);
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_TRANSFER, $technicalMsg);
                // Don't throw exception here since the main transaction is already committed
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_TRANSFER, 'Warning: Transfer completed but history record creation failed');
            }

            return true;
            
        } catch (Exception $e) {
            // Rollback on any error
            $this->_db->query("ROLLBACK");
            $technicalMsg = CarErrorMessages::getTechnicalMessage('operation_failed', ['operation' => 'Car ownership transfer', 'error' => $e->getMessage()]);
            logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_TRANSFER, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('operation_failed', 'admin'));
        }
    }

    /**
     * Merge another car's history into this car and delete the old car
     * 
     * Replaces direct database access in car management operations with proper 
     * Car class method. Includes transaction support and comprehensive audit trails
     * for car merging operations.
     * 
     * @param int $oldCarId The car ID to merge into this car (will be deleted)
     * @param string $reason Reason for merge (for audit trail)
     * @return bool True if merge was successful, false otherwise
     * @throws Exception If validation fails or database operation fails
     * 
     * @see https://github.com/unibrain1/elanregistry/issues/248 Issue #248: Replace direct DB access in car management
     */
    public function merge(int $oldCarId, string $reason = 'Administrative merge'): bool
    {
        global $user;
        
        // Ensure this car exists
        if (!$this->exists()) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('car_not_found_merge', ['id' => 'target']);
            logger(0, LogCategories::LOG_CATEGORY_CAR_MERGE, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('car_not_found_merge'));
        }

        // Validate we have a valid user for audit purposes
        if (!isset($user) || !$user->isLoggedIn()) {
            throw new CarMergeException(CarErrorMessages::getMessage('user_auth_required', 'admin', ['operation' => 'car merge']));
        }

        // Validate old car exists
        $oldCar = new Car($oldCarId);
        if (!$oldCar->exists()) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('merge_source_not_found', ['id' => $oldCarId]);
            logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_MERGE, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('merge_source_not_found'));
        }

        // Prevent merging a car with itself
        if ($oldCarId === $this->_data->id) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('car_merge_self', ['id' => $oldCarId]);
            logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_MERGE, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('car_merge_self'));
        }

        $newCarId = $this->_data->id;
        $newChassis = $this->_data->chassis ?? 'Unknown';
        $oldChassis = $oldCar->data()->chassis ?? 'Unknown';

        try {
            // Start transaction for data integrity
            $this->_db->query("START TRANSACTION");

            // Transfer all history records from old car to new car
            $historyTransferred = $this->_db->query("UPDATE cars_hist SET car_id = ? WHERE car_id = ?", [$newCarId, $oldCarId]);
            if ($this->_db->error()) {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('car_history_transfer_failed', ['error' => $this->_db->errorString()]);
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_MERGE, $technicalMsg);
                throw new Exception(CarErrorMessages::getAdminMessage('car_history_transfer_failed'));
            }

            // Remove car-user relationships for old car
            $carUserDeleted = $this->_db->query("DELETE FROM car_user WHERE car_id = ?", [$oldCarId]);
            if ($this->_db->error()) {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('car_relationship_failed', ['error' => $this->_db->errorString()]);
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_MERGE, $technicalMsg);
                throw new Exception(CarErrorMessages::getAdminMessage('car_relationship_failed'));
            }

            // Delete the old car record
            $oldCarDeleted = $this->_db->query("DELETE FROM cars WHERE id = ?", [$oldCarId]);
            if ($this->_db->error()) {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => $this->_db->errorString()]);
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_MERGE, $technicalMsg);
                throw new Exception(CarErrorMessages::getAdminMessage('database_update_failed'));
            }

            // Create audit trail entry for the merge operation
            $historyFields = [
                'operation' => 'MERGE',
                'car_id' => $newCarId,
                'comments' => "Car $oldChassis (ID: $oldCarId) was merged into car $newChassis (ID: $newCarId) by admin " . $user->data()->id . ". Reason: $reason",
                'ctime' => $this->_data->ctime ?? date('Y-m-d G:i:s'),
                'mtime' => date('Y-m-d G:i:s'),
                'model' => $this->_data->model ?? '',
                'series' => $this->_data->series ?? '',
                'variant' => $this->_data->variant ?? '',
                'year' => $this->_data->year ?? '',
                'type' => $this->_data->type ?? '',
                'chassis' => $this->_data->chassis ?? '',
                'color' => $this->_data->color ?? '',
                'engine' => $this->_data->engine ?? '',
                'purchasedate' => $this->_data->purchasedate ?? null,
                'solddate' => $this->_data->solddate ?? null,
                'image' => $this->_data->image ?? ''
            ];
            
            $historyInserted = $this->_db->insert('cars_hist', $historyFields);
            if (!$historyInserted) {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('audit_trail_failed', ['operation' => 'car merge']);
                logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_MERGE, $technicalMsg);
                throw new Exception(CarErrorMessages::getAdminMessage('audit_trail_failed', ['operation' => 'car merge']));
            }

            // Commit transaction
            $this->_db->query("COMMIT");
            
            // Clear cached data to force reload of history
            $this->_history = null;

            return true;
            
        } catch (Exception $e) {
            // Rollback on any error
            $this->_db->query("ROLLBACK");
            $technicalMsg = CarErrorMessages::getTechnicalMessage('operation_failed', ['operation' => 'Car merge', 'error' => $e->getMessage()]);
            logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_MERGE, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('operation_failed', 'admin'));
        }
    }

    /**
     * Set verification code for the car
     * 
     * Provides proper Car class method for verification code management,
     * replacing any direct database access patterns.
     * 
     * @param string $verificationCode The verification code to set
     * @return bool True if verification code was set successfully, false otherwise
     * @throws Exception If validation fails or database operation fails
     * 
     * @see https://github.com/unibrain1/elanregistry/issues/249 Issue #249: Add verification methods to Car class
     */
    public function setVerificationCode(string $verificationCode): bool
    {
        // Ensure car exists
        if (!$this->exists()) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('car_not_found_verification', ['id' => 'unknown']);
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('car_not_found_verification'));
        }

        // Validate verification code format (basic validation)
        if (empty($verificationCode) || strlen($verificationCode) < 8) {
            throw new Exception(CarErrorMessages::getMessage('invalid_verification_code'));
        }

        try {
            $updateSuccess = $this->_db->update('cars', $this->_data->id, ['vericode' => $verificationCode]);
            
            if ($updateSuccess) {
                // Update local data to reflect the change
                $this->_data->vericode = $verificationCode;
                return true;
            } else {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => 'Database update returned false']);
                logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
                throw new Exception(CarErrorMessages::getMessage('database_update_failed'));
            }
        } catch (Exception $e) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('verification_code_failed', ['error' => $e->getMessage()]);
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('verification_code_failed'));
        }
    }

    /**
     * Mark car as verified
     * 
     * Provides proper Car class method for verification status management,
     * replacing any direct database access patterns.
     * 
     * @return bool True if car was marked as verified successfully, false otherwise
     * @throws Exception If validation fails or database operation fails
     * 
     * @see https://github.com/unibrain1/elanregistry/issues/249 Issue #249: Add verification methods to Car class
     */
    public function markVerified(): bool
    {
        // Ensure car exists
        if (!$this->exists()) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('car_not_found_verify', ['id' => 'unknown']);
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('car_not_found_verify'));
        }

        try {
            $currentDateTime = date('Y-m-d G:i:s');
            $updateSuccess = $this->_db->update('cars', $this->_data->id, ['last_verified' => $currentDateTime]);
            
            if ($updateSuccess) {
                // Update local data to reflect the change
                $this->_data->last_verified = $currentDateTime;
                return true;
            } else {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => 'Database update returned false']);
                logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
                throw new Exception(CarErrorMessages::getMessage('database_update_failed'));
            }
        } catch (Exception $e) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('verification_mark_failed', ['error' => $e->getMessage()]);
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('verification_mark_failed'));
        }
    }

    /**
     * Mark car as sold
     * 
     * Provides proper Car class method for sold status management,
     * replacing any direct database access patterns.
     * 
     * @param string|null $soldDate Optional sold date (defaults to current date)
     * @return bool True if car was marked as sold successfully, false otherwise
     * @throws Exception If validation fails or database operation fails
     * 
     * @see https://github.com/unibrain1/elanregistry/issues/249 Issue #249: Add verification methods to Car class
     */
    public function markSold(?string $soldDate = null): bool
    {
        // Ensure car exists
        if (!$this->exists()) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('car_not_found_sold', ['id' => 'unknown']);
            logger(0, LogCategories::LOG_CATEGORY_CAR_SOLD, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('car_not_found_sold'));
        }

        // Use current date if none provided
        if ($soldDate === null) {
            $soldDate = date('Y-m-d');
        }

        // Basic date format validation
        if (!DateTime::createFromFormat('Y-m-d', $soldDate)) {
            throw new Exception(CarErrorMessages::getMessage('invalid_sold_date', 'user', ['date' => $soldDate]));
        }

        try {
            $updateSuccess = $this->_db->update('cars', $this->_data->id, ['solddate' => $soldDate]);
            
            if ($updateSuccess) {
                // Update local data to reflect the change
                $this->_data->solddate = $soldDate;
                return true;
            } else {
                $technicalMsg = CarErrorMessages::getTechnicalMessage('database_update_failed', ['error' => 'Database update returned false']);
                logger(0, LogCategories::LOG_CATEGORY_CAR_SOLD, $technicalMsg);
                throw new Exception(CarErrorMessages::getMessage('database_update_failed'));
            }
        } catch (Exception $e) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('sold_mark_failed', ['error' => $e->getMessage()]);
            logger(0, LogCategories::LOG_CATEGORY_CAR_SOLD, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('sold_mark_failed'));
        }
    }

    /**
     * Find a car by its verification code
     * 
     * Provides proper Car class method for verification code lookup,
     * replacing any direct database query patterns.
     * 
     * @param string $verificationCode The verification code to search for
     * @return Car|null Car object if found, null if not found
     * @throws Exception If database operation fails
     * 
     * @see https://github.com/unibrain1/elanregistry/issues/249 Issue #249: Add verification methods to Car class
     */
    public static function findByVerificationCode(string $verificationCode): ?Car
    {
        // Validate verification code format
        if (empty($verificationCode)) {
            return null;
        }

        try {
            $db = DB::getInstance();
            $result = $db->query('SELECT * FROM cars WHERE vericode = ?', [$verificationCode]);
            
            if ($result->count() > 0) {
                $carData = $result->first();
                $car = new Car($carData->id);
                return $car->exists() ? $car : null;
            } else {
                return null;
            }
        } catch (Exception $e) {
            $technicalMsg = CarErrorMessages::getTechnicalMessage('unexpected_error', ['error' => $e->getMessage()]);
            logger(0, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, $technicalMsg);
            throw new Exception(CarErrorMessages::getMessage('unexpected_error'));
        }
    }
    
    /**
     * Find all cars owned by a specific user
     * 
     * Factory method for retrieving Car objects by owner ID.
     * Follows standard OOP pattern for data retrieval operations.
     * 
     * @param int $ownerID User ID of the car owner
     * @return array Array of Car objects owned by the user
     * @throws CarValidationException If owner ID is invalid
     * 
     * @see https://github.com/unibrain1/elanregistry/issues/276 Issue #276: Move findByOwner to Car class
     */
    public static function findByOwner(int $ownerID): array
    {
        // Input validation
        if ($ownerID <= 0) {
            throw new CarValidationException('Invalid owner ID provided');
        }
        
        $db = DB::getInstance();
        $carQ = $db->query("SELECT id FROM cars WHERE user_id = ?", array($ownerID))->results();
        $cars = [];

        foreach ($carQ as $key => $car) {
            $cars[$key] = new Car($car->id);
        }
        
        return $cars;
    }
    
    /**
     * Get car owner information
     *
     * @return array|object Owner information
     */
    public function owner(): array|object
    {
        return $this->_owner;
    }
    /**
     * Secure DataTables server-side processing for cars and factory tables
     * 
     * @param array $request DataTables request parameters (sanitized via Input::get)
     * @param string $table Table type ('cars' or 'factory')
     * @return array DataTables response array
     */
    public function getDataTablesData(array $request, string $table = 'cars'): array
    {
        // Validate and sanitize table parameter
        $validTables = [
            'cars' => 'cars',
            'factory' => 'elan_factory_info'
        ];
        
        if (!isset($validTables[$table])) {
            throw new Exception("Invalid table specified");
        }
        
        $tableName = $validTables[$table];
        
        // Extract and validate DataTables parameters
        $draw = (int) $request['draw'];
        $start = (int) $request['start'];
        $length = (int) $request['length'];
        $searchValue = isset($request['search']['value']) ? trim($request['search']['value']) : '';
        
        // Build ORDER BY clause securely
        $orderClauses = [];
        if (isset($request['order']) && is_array($request['order'])) {
            foreach ($request['order'] as $order) {
                $columnIndex = (int) $order['column'];
                $direction = strtoupper($order['dir']) === 'DESC' ? 'DESC' : 'ASC';
                
                if (isset($request['columns'][$columnIndex]['data'])) {
                    $columnName = $this->validateColumnName($request['columns'][$columnIndex]['data'], $tableName);
                    if ($columnName) {
                        $orderClauses[] = "`{$columnName}` {$direction}";
                    }
                }
            }
        }
        $orderBy = !empty($orderClauses) ? 'ORDER BY ' . implode(', ', $orderClauses) : 'ORDER BY id ASC';
        
        // Build WHERE clause for search
        $searchWhere = '';
        $searchParams = [];
        if (!empty($searchValue)) {
            $searchConditions = [];
            if (isset($request['columns']) && is_array($request['columns'])) {
                foreach ($request['columns'] as $column) {
                    if (isset($column['searchable']) && $column['searchable'] === 'true' && isset($column['data'])) {
                        $columnName = $this->validateColumnName($column['data'], $tableName);
                        if ($columnName) {
                            $searchConditions[] = "`{$columnName}` LIKE ?";
                            $searchParams[] = "%{$searchValue}%";
                        }
                    }
                }
            }
            
            if (!empty($searchConditions)) {
                $searchWhere = 'AND (' . implode(' OR ', $searchConditions) . ')';
            }
        }
        
        // Get total records without filtering
        $totalRecords = $this->_db->query("SELECT COUNT(*) as count FROM `{$tableName}`")->first()->count;
        
        // Get total records with filtering
        $totalFiltered = $totalRecords;
        if (!empty($searchWhere)) {
            $filterQuery = "SELECT COUNT(*) as count FROM `{$tableName}` WHERE 1 {$searchWhere}";
            $totalFiltered = $this->_db->query($filterQuery, $searchParams)->first()->count;
        }
        
        // Get the actual data
        $dataQuery = "SELECT * FROM `{$tableName}` WHERE 1 {$searchWhere} {$orderBy} LIMIT {$start}, {$length}";
        $data = $this->_db->query($dataQuery, $searchParams)->results();
        
        return [
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalFiltered,
            'data' => $data
        ];
    }
    
    /**
     * Validate column names to prevent SQL injection
     * 
     * @param string $columnName Column name to validate
     * @param string $tableName Table name for context
     * @return string|false Validated column name or false if invalid
     */
    private function validateColumnName(string $columnName, string $tableName): string|false
    {
        // Define allowed columns for each table (based on actual schema)
        $allowedColumns = [
            'cars' => [
                'id', 'ctime', 'mtime', 'vericode', 'last_verified', 'ModifiedBy',
                'model', 'series', 'variant', 'year', 'type', 'chassis', 'color', 'engine',
                'purchasedate', 'solddate', 'comments', 'image', 'user_id', 'email', 'fname',
                'lname', 'join_date', 'city', 'state', 'country', 'lat', 'lon', 'website'
            ],
            'elan_factory_info' => [
                'id', 'year', 'month', 'batch', 'type', 'serial', 'suffix',
                'engineletter', 'enginenumber', 'gearbox', 'color', 'builddate', 'note'
            ]
        ];
        
        if (!isset($allowedColumns[$tableName])) {
            return false;
        }
        
        // Check if column name is in the allowed list
        if (in_array($columnName, $allowedColumns[$tableName], true)) {
            return $columnName;
        }
        
        return false;
    }

    /**
     * Convert suffix code to descriptive text
     *
     * @param string $suffix Suffix code
     * @return string Description of the suffix
     */
    private function suffixtotext(string $suffix): string
    {
        $s = strtoupper($suffix);

        switch ($s) {
            case "A":
                $desc = "S4 FHC UK Market";
                break;
            case "B":
                $desc = "S4 FHC Export";
                break;
            case "C":
                $desc = "S4 DHC UK Market";
                break;
            case "D":
                $desc = "S4 DHC Export";
                break;
            case "E":
                $desc = "S4 S/E FHC UK Market";
                break;
            case "F":
                $desc = "S4 S/E FHC Export";
                break;
            case "G":
                $desc = "S4 S/E DHC UK Market";
                break;
            case "H":
                $desc = "S4 S/E DHC Export";
                break;
            case "J":
                $desc = "S4 FHC Federal";
                break;
            case "K":
                $desc = "S4 DHC Federal";
                break;
            case "L":
                $desc = "+2S and +2S/130 UK Market";
                break;
            case "M":
                $desc = "+2S and +2S/130 Export";
                break;
            case "N":
                $desc = "+2S and +2S/130 Federal";
                break;

            default:
                $desc = "Unknown suffix: " . $suffix;
        }
        return $desc;
    }
    
    /**
     * Validate that required fields are present and not empty
     *
     * @param array $fields Fields to validate
     * @param array $requiredFields List of required field names
     * @throws Exception If any required field is missing or empty
     */
    private function validateRequiredFields(array $fields, array $requiredFields): void
    {
        foreach ($requiredFields as $field) {
            if (!isset($fields[$field]) || empty(trim($fields[$field]))) {
                throw new Exception("Required field '{$field}' is missing or empty");
            }
        }
    }
    
    /**
     * Validate and sanitize car fields
     *
     * @param array $fields Fields to validate and sanitize
     * @param bool $requireAll Whether all validations are required (create) or optional (update)
     * @return array Validated and sanitized fields
     * @throws Exception If validation fails
     */
    private function validateAndSanitizeFields(array $fields, bool $requireAll = true): array
    {
        $validatedFields = [];
        
        foreach ($fields as $key => $value) {
            switch ($key) {
                case 'chassis':
                    if (!empty($value)) {
                        $validatedFields[$key] = $this->sanitizeString($value, 50);
                        if (strlen($validatedFields[$key]) < 3) {
                            throw new Exception('Chassis number must be at least 3 characters long');
                        }
                    } elseif ($requireAll) {
                        throw new Exception('Chassis number is required');
                    }
                    break;
                    
                case 'model':
                    if (!empty($value)) {
                        $validatedFields[$key] = $this->sanitizeString($value, 100);
                    } elseif ($requireAll) {
                        throw new Exception('Model is required');
                    }
                    break;
                    
                case 'year':
                    if (!empty($value)) {
                        if (!is_numeric($value) || $value < 1963 || $value > 1974) {
                            throw new Exception('Year must be between 1963 and 1974 (Lotus Elan production years)');
                        }
                        $validatedFields[$key] = (int)$value;
                    } elseif ($requireAll) {
                        throw new Exception('Year is required');
                    }
                    break;
                    
                case 'series':
                case 'variant':
                case 'type':
                case 'color':
                case 'engine':
                    if (!empty($value)) {
                        $validatedFields[$key] = $this->sanitizeString($value, 100);
                    }
                    break;
                    
                case 'comments':
                    if (!empty($value)) {
                        $validatedFields[$key] = $this->sanitizeString($value, 5000);
                    }
                    break;
                    
                case 'purchasedate':
                case 'solddate':
                    if (!empty($value)) {
                        $date = DateTime::createFromFormat('Y-m-d', $value);
                        if (!$date || $date->format('Y-m-d') !== $value) {
                            throw new Exception("Invalid date format for {$key}. Use YYYY-MM-DD format");
                        }
                        $validatedFields[$key] = $value;
                    }
                    break;
                    
                case 'email':
                    if (!empty($value)) {
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            throw new Exception('Invalid email address format');
                        }
                        $validatedFields[$key] = filter_var($value, FILTER_SANITIZE_EMAIL);
                    }
                    break;
                    
                case 'website':
                    if (!empty($value)) {
                        if (!filter_var($value, FILTER_VALIDATE_URL)) {
                            throw new Exception('Invalid website URL format');
                        }
                        $validatedFields[$key] = filter_var($value, FILTER_SANITIZE_URL);
                    }
                    break;
                    
                case 'user_id':
                    if (!empty($value)) {
                        if (!is_numeric($value) || $value <= 0) {
                            throw new Exception('Invalid user ID');
                        }
                        $validatedFields[$key] = (int)$value;
                    }
                    break;
                    
                // Geographic fields
                case 'city':
                case 'state':
                case 'country':
                    if (!empty($value)) {
                        $validatedFields[$key] = $this->sanitizeString($value, 100);
                    }
                    break;
                    
                case 'lat':
                case 'lon':
                    if (!empty($value)) {
                        if (!is_numeric($value) || abs($value) > 180) {
                            throw new Exception("Invalid {$key} coordinate");
                        }
                        $validatedFields[$key] = (float)$value;
                    }
                    break;
                    
                // Pass through other fields (like images, ctime, mtime, etc.)
                default:
                    $validatedFields[$key] = $value;
                    break;
            }
        }
        
        return $validatedFields;
    }
    
    /**
     * Sanitize string input
     *
     * @param string $input Input string to sanitize
     * @param int $maxLength Maximum allowed length
     * @return string Sanitized string
     */
    private function sanitizeString(string $input, int $maxLength): string
    {
        // Remove HTML tags and trim whitespace
        $sanitized = trim(strip_tags($input));
        
        // Limit length
        if (strlen($sanitized) > $maxLength) {
            $sanitized = substr($sanitized, 0, $maxLength);
        }
        
        return $sanitized;
    }
}
