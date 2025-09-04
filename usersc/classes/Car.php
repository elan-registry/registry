<?php

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
        $settings = getSettings();  // Get global settings from plugin

        // Get the logged in user information
        if (isset($user) && $user->isLoggedIn()) {
            $this->_owner = $user->data(); // TODO this should be from the user/profile JOIN
        }

        if ($id) {
            $this->imageDir = $settings->elan_image_dir  . $id . '/';
            $this->find($id);
        }
        return true;
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
            throw new Exception('No data provided for car creation');
        }
        
        // Validate required fields
        $this->validateRequiredFields($fields, ['chassis', 'model', 'year']);
        
        // Validate and sanitize individual fields
        $fields = $this->validateAndSanitizeFields($fields);

        $fields['ctime'] = date('Y-m-d G:i:s');
        if (!empty($fields['images'])) {
            try {
                $fields['image'] = json_encode($fields['images']);
                if ($fields['image'] === false) {
                    throw new Exception('Failed to encode images as JSON');
                }
                unset($fields['images']);
            } catch (Exception $e) {
                error_log("Car class: Image encoding error during create: " . $e->getMessage());
                throw new Exception('Error processing car images: ' . $e->getMessage());
            }
        }

        if (!$this->_db->insert($this->tableName, $fields)) {
            throw new Exception($this->_db->errorString());
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
            throw new Exception('No data or ID provided for car update');
        }
        
        if (!is_numeric($fields['id']) || $fields['id'] <= 0) {
            throw new Exception('Invalid car ID provided for update');
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
                    throw new Exception('Failed to encode images as JSON');
                }
                unset($fields['images']);
            } catch (Exception $e) {
                error_log("Car class: Image encoding error during update: " . $e->getMessage());
                throw new Exception('Error processing car images: ' . $e->getMessage());
            }
        }

        if (!$this->_db->update($this->tableName, $fields['id'], $fields)) {
            throw new Exception('There was a problem updating.');
        } else {
            $this->find($fields['id']);  // Populate the car with the data
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
                        error_log("Car class: Unable to determine image type for file: {$file}");
                    }
                } catch (Exception $e) {
                    $images[$key]['type'] = 'unknown';
                    error_log("Car class: Exception getting image type for {$file}: " . $e->getMessage());
                }
                
                try {
                    $mimeType = @mime_content_type($file);
                    if ($mimeType !== false) {
                        $images[$key]['mime'] = $mimeType;
                    } else {
                        $images[$key]['mime'] = 'application/octet-stream';
                        error_log("Car class: Unable to determine MIME type for file: {$file}");
                    }
                } catch (Exception $e) {
                    $images[$key]['mime'] = 'application/octet-stream';
                    error_log("Car class: Exception getting MIME type for {$file}: " . $e->getMessage());
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
            throw new Exception('Image filename cannot be empty');
        }

        // Ensure car exists
        if (!$this->exists()) {
            throw new Exception('Car not found');
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
            throw new Exception('Failed to encode images as JSON');
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
                throw new Exception('Database update failed');
            }
        } catch (Exception $e) {
            throw new Exception('Failed to remove image from database: ' . $e->getMessage());
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
    public function delete(string $reason = 'Administrative deletion'): bool
    {
        global $user;
        
        // Ensure car exists
        if (!$this->exists()) {
            throw new Exception('Car not found - cannot delete');
        }

        // Validate we have a valid user for audit purposes
        if (!isset($user) || !$user->isLoggedIn()) {
            throw new Exception('User authentication required for car deletion');
        }

        $carId = $this->_data->id;
        $chassis = $this->_data->chassis ?? 'Unknown';

        try {
            // Start transaction for data integrity
            $this->_db->query("START TRANSACTION");

            // Create audit trail entry before deletion
            $historyFields = [
                'car_id' => $carId,
                'comments' => "Car ID $carId ($chassis) permanently deleted by admin " . $user->data()->id . ". Reason: $reason",
                'operation' => 'DELETE',
                'ctime' => date('Y-m-d G:i:s'),
                'mtime' => date('Y-m-d G:i:s')
            ];
            
            $historyInserted = $this->_db->insert('cars_hist', $historyFields);
            if (!$historyInserted) {
                throw new Exception('Failed to create audit trail entry');
            }

            // Remove car-user relationships
            $carUserDeleted = $this->_db->query("DELETE FROM car_user WHERE car_id = ?", [$carId]);
            if ($this->_db->error()) {
                throw new Exception('Failed to delete car-user relationships: ' . $this->_db->errorString());
            }

            // Remove the car record itself
            $carDeleted = $this->_db->query("DELETE FROM cars WHERE id = ?", [$carId]);
            if ($this->_db->error()) {
                throw new Exception('Failed to delete car record: ' . $this->_db->errorString());
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
            throw new Exception('Car deletion failed: ' . $e->getMessage());
        }
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
    public function getDataTablesData($request, $table = 'cars')
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
    private function validateColumnName($columnName, $tableName)
    {
        // Define allowed columns for each table (based on actual schema)
        $allowedColumns = [
            'cars' => [
                'id', 'username', 'ctime', 'mtime', 'vericode', 'last_verified', 'ModifiedBy',
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
